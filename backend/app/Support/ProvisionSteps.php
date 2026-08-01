<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PendingRegistration;
use Throwable;

/**
 * Progress tracker for the onboarding automation (Volume 11 §"Onboarding
 * Automation"): Create Tenant → Select Industry → Select Plan → Migration →
 * Seeder → Enable Modules → Create Admin → Demo Data.
 *
 * ProvisionTenant runs one closure per phase through {@see run()}; each
 * transition is written straight back to the registration row, so a client
 * polling the status endpoint sees real progress rather than a timed animation.
 * The steps are also the post-mortem when a provision fails: the failed phase
 * carries the exception message and everything after it stays `pending`.
 *
 * Ordering note — the doc lists "Create Admin" after "Enable Modules"/"Demo
 * Data"; the admin user is created before demo data because the generator
 * attributes its documents to a user. The list below is therefore in execution
 * order, which is the order the UI renders.
 */
final class ProvisionSteps
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /**
     * The documented phases, in execution order: key => label.
     *
     * @var array<string, string>
     */
    public const STEPS = [
        'tenant' => 'Create Tenant',
        'industry' => 'Select Industry',
        'plan' => 'Select Plan',
        'schema' => 'Migration',
        'seed' => 'Seeder',
        'modules' => 'Enable Modules',
        'admin' => 'Create Admin',
        'demo' => 'Demo Data',
    ];

    /** @var array<string, array{key: string, label: string, status: string, note: string|null, at: string|null}> */
    private array $steps;

    public function __construct(private readonly PendingRegistration $registration)
    {
        $this->steps = self::hydrate($registration->provision_steps);
    }

    /**
     * The initial all-pending list, so a registration shows the full checklist
     * from the moment it is created rather than growing one row at a time.
     *
     * @return list<array{key: string, label: string, status: string, note: string|null, at: string|null}>
     */
    public static function initial(): array
    {
        return array_values(self::hydrate(null));
    }

    /**
     * A registration's steps as stored, back-filled with any phase the row
     * predates — a registration created before this column existed (or before a
     * phase was added) still renders a complete checklist.
     *
     * @param  mixed  $stored
     * @return list<array{key: string, label: string, status: string, note: string|null, at: string|null}>
     */
    public static function forRegistration(PendingRegistration $registration): array
    {
        return array_values(self::hydrate($registration->provision_steps));
    }

    /**
     * Run one phase, recording it as running → done (or failed, re-throwing so
     * the job's own failure handling still rolls the tenant back).
     *
     * The step's note — the one-line "what did it actually do" shown under the
     * label — is either whatever $note() makes of the result, or the result
     * itself when the phase simply returns a string.
     *
     * @template T
     * @param  callable(): T  $work
     * @param  null|callable(T): ?string  $note
     * @return T
     */
    public function run(string $key, callable $work, ?callable $note = null): mixed
    {
        $this->mark($key, self::RUNNING);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->mark($key, self::FAILED, $e->getMessage());

            throw $e;
        }

        $text = $note !== null ? $note($result) : $result;

        $this->mark($key, self::DONE, is_string($text) ? $text : null);

        return $result;
    }

    /** Record a phase that had nothing to do (e.g. no demo data requested). */
    public function skip(string $key, ?string $note = null): void
    {
        $this->mark($key, self::SKIPPED, $note);
    }

    /** Record a phase as complete without wrapping a closure around it. */
    public function done(string $key, ?string $note = null): void
    {
        $this->mark($key, self::DONE, $note);
    }

    /** @return list<array{key: string, label: string, status: string, note: string|null, at: string|null}> */
    public function all(): array
    {
        return array_values($this->steps);
    }

    /**
     * Write a status transition and persist immediately: the value of this
     * table is that a poll mid-provision can read it, so batching the writes
     * to the end would defeat the point. forceFill + save rather than update()
     * so the column does not have to be mass-assignable.
     */
    private function mark(string $key, string $status, ?string $note = null): void
    {
        if (! array_key_exists($key, self::STEPS)) {
            return; // unknown phase: never fail a provision over bookkeeping
        }

        $this->steps[$key] = [
            'key' => $key,
            'label' => self::STEPS[$key],
            'status' => $status,
            'note' => $note,
            'at' => now()->toDateTimeString(),
        ];

        try {
            $this->registration->forceFill(['provision_steps' => $this->all()])->save();
        } catch (Throwable) {
            // Progress reporting must never be the reason a workspace fails to
            // provision — the job carries on and the client falls back to the
            // coarse `status` field.
        }
    }

    /**
     * Merge stored steps over the canonical list, so order and labels always
     * come from self::STEPS and unknown/renamed keys are dropped.
     *
     * @param  mixed  $stored
     * @return array<string, array{key: string, label: string, status: string, note: string|null, at: string|null}>
     */
    private static function hydrate(mixed $stored): array
    {
        $byKey = [];
        foreach (is_array($stored) ? $stored : [] as $row) {
            if (is_array($row) && isset($row['key']) && is_string($row['key'])) {
                $byKey[$row['key']] = $row;
            }
        }

        $steps = [];
        foreach (self::STEPS as $key => $label) {
            $row = $byKey[$key] ?? [];
            $steps[$key] = [
                'key' => $key,
                'label' => $label,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : self::PENDING,
                'note' => is_string($row['note'] ?? null) ? $row['note'] : null,
                'at' => is_string($row['at'] ?? null) ? $row['at'] : null,
            ];
        }

        return $steps;
    }
}
