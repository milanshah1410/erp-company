<?php

namespace App\Http\Controllers\Central\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Ops panel — read-only viewer over storage/logs/*.log (ops-logs.view).
 * Filenames are basenamed + validated against the directory listing, so no
 * path traversal; large files are tailed (last chunk only), never fully read.
 */
class LogController extends Controller
{
    /** How much of the end of a log file we parse for the viewer. */
    private const TAIL_BYTES = 512 * 1024;

    public function index()
    {
        $files = collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn ($path) => [
                'filename' => basename($path),
                'size' => filesize($path),
                'updated_at' => date('Y-m-d H:i:s', filemtime($path)),
            ])
            ->sortByDesc('updated_at')
            ->values();

        return response()->json(['data' => $files]);
    }

    /** Parsed entries from the tail of one log file, newest first. */
    public function show(Request $request, string $filename)
    {
        $path = $this->resolve($filename);
        $limit = min((int) $request->query('limit', 200), 500);
        $level = strtoupper((string) $request->query('level', ''));

        $entries = $this->parseTail($path);

        if ($level !== '') {
            $entries = array_values(array_filter($entries, fn ($e) => $e['level'] === $level));
        }

        // Newest first, capped.
        $entries = array_slice(array_reverse($entries), 0, $limit);

        return response()->json([
            'data' => [
                'filename' => basename($path),
                'size' => filesize($path),
                'truncated' => filesize($path) > self::TAIL_BYTES,
                'entries' => $entries,
            ],
        ]);
    }

    public function download(string $filename)
    {
        return response()->download($this->resolve($filename));
    }

    private function resolve(string $filename): string
    {
        $path = storage_path('logs/'.basename($filename));
        if (! str_ends_with($path, '.log') || ! is_file($path)) {
            abort(404, 'Log file not found.');
        }

        return $path;
    }

    /**
     * Split the tail of a Laravel log into entries. An entry starts at a
     * "[Y-m-d H:i:s] env.LEVEL: message" header; following lines (stack
     * traces) belong to the same entry.
     */
    private function parseTail(string $path): array
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            abort(500, 'Could not open log file.');
        }
        if ($size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
            fgets($handle); // Discard the (probably partial) first line.
        }
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $entries = [];
        $current = null;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ][\d:.+]+)\]\s+(\w+)\.([A-Z]+):\s?(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $m[1],
                    'level' => $m[3],
                    'message' => $m[4],
                    'context' => '',
                ];
            } elseif ($current !== null && trim($line) !== '') {
                // Cap per-entry context so a giant stack trace can't bloat the payload.
                if (strlen($current['context']) < 8000) {
                    $current['context'] .= $line."\n";
                }
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }
}
