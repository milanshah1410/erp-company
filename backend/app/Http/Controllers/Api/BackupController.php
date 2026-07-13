<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    private function disk()
    {
        return Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');
    }

    private function basePath(): string
    {
        return config('backup.backup.name');
    }

    /** List available backup archives, newest first. */
    public function index()
    {
        $disk = $this->disk();
        $files = collect($disk->allFiles($this->basePath()))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => [
                'filename' => basename($f),
                'size' => $disk->size($f),
                'created_at' => date('Y-m-d H:i:s', $disk->lastModified($f)),
            ])
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['data' => $files]);
    }

    /** Trigger a fresh backup (database dump + app storage files) synchronously. */
    public function run()
    {
        Artisan::call('backup:run', ['--disable-notifications' => true]);

        return response()->json(['message' => 'Backup completed.', 'output' => Artisan::output()]);
    }

    /** Stream a backup archive to the browser. Restoring is a manual, documented DB-import step. */
    public function download(string $filename)
    {
        $filename = basename($filename);
        $path = $this->basePath().'/'.$filename;
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            abort(404, 'Backup not found.');
        }

        return $disk->download($path);
    }

    public function destroy(string $filename)
    {
        $filename = basename($filename);
        $path = $this->basePath().'/'.$filename;
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            abort(404, 'Backup not found.');
        }
        $disk->delete($path);

        return response()->json(['message' => 'Backup deleted.']);
    }
}
