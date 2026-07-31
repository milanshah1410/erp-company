<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BiometricPunch;
use App\Models\Device;
use App\Models\Employee;
use App\Modules\DeviceManager\Data\ConnectionResult;
use App\Modules\DeviceManager\Data\PrintJob;
use App\Modules\DeviceManager\Data\PrintResult;
use App\Modules\DeviceManager\Data\PunchRecord;
use App\Modules\DeviceManager\Data\ScanResult;
use App\Modules\DeviceManager\Data\WeightReading;
use App\Modules\DeviceManager\Support\DeviceEventLog;
use App\Modules\DeviceManager\Support\DriverFactory;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The Device Manager's application layer: it resolves a device's brand adapter,
 * runs the requested operation, records the outcome in the live event log, and
 * keeps the device's own status/last-error fields current.
 *
 * Simulator mode needs no special handling here — the adapters answer from their
 * built-in fakes, so every code path below (including the punch → attendance
 * ingest) is exercised identically with or without hardware.
 */
class DeviceManagerService
{
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly DeviceEventLog $log,
    ) {}

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public function testConnection(Device $device): ConnectionResult
    {
        try {
            $result = $this->drivers->make($device)->testConnection();
        } catch (Throwable $e) {
            $result = ConnectionResult::fail($e->getMessage(), simulated: $device->isSimulated());
        }

        $device->forceFill([
            'status' => $result->ok ? 'online' : 'error',
            'last_tested_at' => now(),
            'last_error' => $result->ok ? null : mb_substr($result->message, 0, 255),
        ])->save();

        $this->log->record(
            $device,
            'connection',
            $result->ok ? 'success' : 'failed',
            $result->message,
            $result->details,
            durationMs: $result->latencyMs,
        );

        return $result;
    }

    // -----------------------------------------------------------------
    // Barcode
    // -----------------------------------------------------------------

    /** Normalise a raw frame captured by a scan screen. */
    public function decodeScan(Device $device, string $raw): ScanResult
    {
        return $this->run($device, 'scan', function () use ($device, $raw) {
            $result = $this->drivers->barcode($device)->decode($raw);

            return [$result, "Scanned {$result->code}" . ($result->symbology ? " ({$result->symbology})" : ''), $result->toArray()];
        });
    }

    /** Pull a buffered scan, for scanners that queue instead of typing. */
    public function pollScan(Device $device): ?ScanResult
    {
        $result = $this->drivers->barcode($device)->poll();
        if ($result === null) {
            return null;
        }

        $this->log->record($device, 'scan', 'success', "Scanned {$result->code}", $result->toArray());

        return $result;
    }

    // -----------------------------------------------------------------
    // Printing
    // -----------------------------------------------------------------

    public function print(Device $device, PrintJob $job): PrintResult
    {
        return $this->run($device, 'print', function () use ($device, $job) {
            $result = $this->drivers->printer($device)->print($job);

            return [
                $result,
                $result->message ?? 'Print job sent.',
                ['document' => $job->document, 'copies' => $result->copies, 'language' => $result->language, 'bytes' => $result->bytes, 'preview' => $result->preview],
            ];
        }, direction: 'out');
    }

    public function openDrawer(Device $device): PrintResult
    {
        return $this->run($device, 'print', function () use ($device) {
            $result = $this->drivers->printer($device)->openDrawer();

            return [$result, $result->message ?? 'Cash drawer kicked.', ['action' => 'open_drawer']];
        }, direction: 'out');
    }

    // -----------------------------------------------------------------
    // Weighing
    // -----------------------------------------------------------------

    public function weigh(Device $device): WeightReading
    {
        return $this->run($device, 'weight', function () use ($device) {
            $reading = $this->drivers->scale($device)->read();

            return [
                $reading,
                sprintf('%s %s%s', $reading->weight, $reading->unit, $reading->stable ? '' : ' (unstable)'),
                $reading->toArray(),
            ];
        });
    }

    public function tare(Device $device): WeightReading
    {
        return $this->run($device, 'weight', function () use ($device) {
            $reading = $this->drivers->scale($device)->tare();

            return [$reading, "Tared — now reading {$reading->weight} {$reading->unit}", $reading->toArray()];
        }, direction: 'out');
    }

    // -----------------------------------------------------------------
    // RFID
    // -----------------------------------------------------------------

    /** @return \App\Modules\DeviceManager\Data\TagRead[] */
    public function readTags(Device $device, int $durationMs = 1000): array
    {
        return $this->run($device, 'tag', function () use ($device, $durationMs) {
            $tags = $this->drivers->rfid($device)->inventory($durationMs);

            return [
                $tags,
                count($tags) . ' tag(s) read',
                ['count' => count($tags), 'tags' => array_map(fn ($t) => $t->toArray(), $tags)],
            ];
        });
    }

    // -----------------------------------------------------------------
    // Biometric
    // -----------------------------------------------------------------

    /**
     * Pull punches from a terminal and ingest them as BiometricPunch rows, so
     * the existing HR roll-up (biometric-punches/sync → attendance) picks them
     * up unchanged. Returns the ingest tally.
     *
     * @return array{pulled:int,created:int,unmatched:int,duplicates:int}
     */
    public function pullPunches(Device $device, ?DateTimeInterface $since = null): array
    {
        return $this->run($device, 'punch', function () use ($device, $since) {
            $punches = $this->drivers->biometric($device)->fetchPunches($since);
            $stats = $this->ingestPunches($device, $punches);

            return [
                $stats,
                sprintf(
                    'Pulled %d punch(es): %d stored, %d unmatched, %d already present.',
                    $stats['pulled'],
                    $stats['created'],
                    $stats['unmatched'],
                    $stats['duplicates'],
                ),
                $stats,
            ];
        });
    }

    /**
     * Store normalised punches, resolving each device-side user id to an
     * employee via Employee::biometric_user_id.
     *
     * @param  PunchRecord[]  $punches
     * @return array{pulled:int,created:int,unmatched:int,duplicates:int}
     */
    private function ingestPunches(Device $device, array $punches): array
    {
        $stats = ['pulled' => count($punches), 'created' => 0, 'unmatched' => 0, 'duplicates' => 0];
        if ($punches === []) {
            return $stats;
        }

        $byDeviceUser = Employee::query()
            ->whereNotNull('biometric_user_id')
            ->pluck('id', 'biometric_user_id');

        DB::transaction(function () use ($device, $punches, $byDeviceUser, &$stats) {
            foreach ($punches as $punch) {
                $employeeId = $byDeviceUser[$punch->deviceUserId] ?? null;
                if ($employeeId === null) {
                    $stats['unmatched']++;

                    continue;
                }

                // A terminal re-sends its whole recent log on every pull, so the
                // same punch arrives repeatedly. Employee + timestamp is the
                // natural key that keeps attendance from double-counting.
                $exists = BiometricPunch::query()
                    ->where('employee_id', $employeeId)
                    ->where('punched_at', $punch->punchedAt->toDateTimeString())
                    ->exists();

                if ($exists) {
                    $stats['duplicates']++;

                    continue;
                }

                BiometricPunch::create([
                    'biometric_device_id' => $device->biometric_device_id,
                    'employee_id' => $employeeId,
                    'device_user_id' => $punch->deviceUserId,
                    'punched_at' => $punch->punchedAt,
                    'direction' => $punch->direction,
                    'source' => 'device',
                    'processed' => false,
                ]);
                $stats['created']++;
            }
        });

        return $stats;
    }

    // -----------------------------------------------------------------
    // Simulator
    // -----------------------------------------------------------------

    /**
     * Fire one simulated event. Works on any device — a live-mode device is
     * simulated for the duration of the call without being re-saved, so a
     * counter can be exercised while its hardware is unplugged.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function simulate(Device $device, array $params = []): array
    {
        // Force simulator mode in memory only — never persisted.
        $wasMode = $device->mode;
        $device->mode = 'simulator';

        try {
            return match ($device->type) {
                'barcode' => $this->simulateScan($device, $params),
                'printer' => $this->simulatePrint($device, $params),
                'weight_scale' => $this->simulateWeight($device, $params),
                'rfid' => $this->simulateTags($device, $params),
                'biometric' => $this->simulatePunch($device, $params),
                default => throw new InvalidArgumentException("Nothing to simulate for a {$device->type} device."),
            };
        } finally {
            $device->mode = $wasMode;
        }
    }

    private function simulateScan(Device $device, array $params): array
    {
        $result = $this->drivers->barcode($device)->simulateScan($params['code'] ?? null);
        $this->log->record($device, 'scan', 'success', "Mock scan {$result->code}", $result->toArray());

        return ['scan' => $result->toArray()];
    }

    private function simulatePrint(Device $device, array $params): array
    {
        $job = PrintJob::fromArray($params['job'] ?? $this->sampleJob($params));
        $result = $this->drivers->printer($device)->print($job);
        $this->log->record($device, 'print', $result->ok ? 'success' : 'failed', $result->message, [
            'document' => $job->document,
            'language' => $result->language,
            'bytes' => $result->bytes,
            'preview' => $result->preview,
        ], direction: 'out');

        return ['print' => $result->toArray(), 'job' => $job->toArray()];
    }

    private function simulateWeight(Device $device, array $params): array
    {
        $weight = isset($params['weight']) && $params['weight'] !== '' ? (float) $params['weight'] : null;
        $stable = ! array_key_exists('stable', $params) || (bool) $params['stable'];

        $reading = $this->drivers->scale($device)->simulateRead($weight, $stable);
        $this->log->record(
            $device,
            'weight',
            'success',
            sprintf('Fake weight %s %s%s', $reading->weight, $reading->unit, $reading->stable ? '' : ' (unstable)'),
            $reading->toArray(),
        );

        return ['weight' => $reading->toArray()];
    }

    private function simulateTags(Device $device, array $params): array
    {
        $epcs = $this->splitList($params['epcs'] ?? null);
        $count = max(1, min(50, (int) ($params['count'] ?? 3)));

        $tags = $this->drivers->rfid($device)->simulateInventory($epcs, $count);
        $this->log->record($device, 'tag', 'success', count($tags) . ' virtual tag(s) read', [
            'count' => count($tags),
            'tags' => array_map(fn ($t) => $t->toArray(), $tags),
        ]);

        return ['tags' => array_map(fn ($t) => $t->toArray(), $tags)];
    }

    private function simulatePunch(Device $device, array $params): array
    {
        $ids = $this->splitList($params['device_user_ids'] ?? null);
        $count = max(1, min(50, (int) ($params['count'] ?? 2)));
        $direction = ($params['direction'] ?? 'in') === 'out' ? 'out' : 'in';

        $punches = $this->drivers->biometric($device)->simulatePunches($ids, $count, $direction);

        // Ingest by default: a virtual fingerprint event that does not reach
        // attendance would not test anything worth testing.
        $stats = ($params['ingest'] ?? true)
            ? $this->ingestPunches($device, $punches)
            : ['pulled' => count($punches), 'created' => 0, 'unmatched' => 0, 'duplicates' => 0];

        $this->log->record($device, 'punch', 'success', sprintf(
            '%d virtual punch(es): %d stored, %d unmatched.',
            $stats['pulled'],
            $stats['created'],
            $stats['unmatched'],
        ), ['stats' => $stats, 'punches' => array_map(fn ($p) => $p->toArray(), $punches)]);

        return [
            'punches' => array_map(fn ($p) => $p->toArray(), $punches),
            'stats' => $stats,
        ];
    }

    /** A representative job per printer document type, for the preview button. */
    private function sampleJob(array $params): array
    {
        $document = $params['document'] ?? 'receipt';

        return match ($document) {
            'label', 'barcode' => [
                'document' => 'label',
                'title' => 'Cotton Kurta',
                'fields' => ['sku' => 'KRT-COT-M-BLU', 'barcode' => '8901234567890', 'mrp' => '1,299.00', 'variant' => 'M / Blue'],
                'copies' => (int) ($params['copies'] ?? 1),
            ],
            'price_tag' => [
                'document' => 'price_tag',
                'title' => 'Cotton Kurta',
                'fields' => ['sku' => 'KRT-COT-M-BLU', 'barcode' => '8901234567890', 'mrp' => '1,299.00', 'price' => '999.00'],
                'copies' => (int) ($params['copies'] ?? 1),
            ],
            default => [
                'document' => 'receipt',
                'title' => 'Sample Store',
                'lines' => [
                    'Bill: SIM-0001',
                    date('d-m-Y H:i'),
                    str_repeat('-', 32),
                    'Cotton Kurta  1 x 999.00     999.00',
                    'Dupatta       2 x 250.00     500.00',
                    str_repeat('-', 32),
                    'Subtotal                    1499.00',
                    'GST 5%                         74.95',
                    'Total                       1573.95',
                ],
                'fields' => ['barcode' => 'SIM-0001'],
                'copies' => (int) ($params['copies'] ?? 1),
            ],
        };
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Run an operation, log it either way, and keep the device's status honest.
     * The callback returns [result, logMessage, logPayload].
     *
     * @template T
     *
     * @param  callable():array{0:T,1:string,2:array}  $operation
     * @return T
     */
    private function run(Device $device, string $event, callable $operation, string $direction = 'in')
    {
        $startedAt = microtime(true);

        try {
            [$result, $message, $payload] = $operation();
        } catch (Throwable $e) {
            $this->log->record(
                $device,
                $event,
                'failed',
                $e->getMessage(),
                ['exception' => class_basename($e)],
                $direction,
                (int) round((microtime(true) - $startedAt) * 1000),
            );

            $device->forceFill([
                'status' => 'error',
                'last_error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            throw $e instanceof RuntimeException || $e instanceof InvalidArgumentException
                ? $e
                : new RuntimeException($e->getMessage(), 0, $e);
        }

        $this->log->record(
            $device,
            $event,
            'success',
            $message,
            $payload,
            $direction,
            (int) round((microtime(true) - $startedAt) * 1000),
        );

        if ($device->status !== 'online' || $device->last_error !== null) {
            $device->forceFill(['status' => 'online', 'last_error' => null])->save();
        }

        return $result;
    }

    /**
     * Split a comma/newline separated list from the simulator form.
     *
     * @return string[]|null
     */
    private function splitList(mixed $value): ?array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = preg_split('/[\s,;]+/', trim($value)) ?: [];
        } else {
            return null;
        }

        $items = array_values(array_filter(array_map('trim', array_map('strval', $items)), fn ($v) => $v !== ''));

        return $items === [] ? null : $items;
    }
}
