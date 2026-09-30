<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class RunScheduledBackup extends Command
{
    protected $signature = 'backup:run {--type=auto : Backup type label}';

    protected $description = 'Create a scheduled database backup using the existing backup logic.';

    public function handle(): int
    {
        $enabled = filter_var(
            Setting::getValue('system_backup_config', 'auto_backup_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $enabled && $this->option('type') === 'auto') {
            $this->info('Automatic backups are disabled. Skipping.');
            return self::SUCCESS;
        }

        try {
            $controller = app(\App\Http\Controllers\Api\SettingController::class);

            $request = Request::create('/api/v1/settings/backups', 'POST');
            $request->setUserResolver(fn () => User::whereHas('roles', function ($q) {
                $q->whereIn('slug', ['super-admin', 'admin']);
            })->first());

            $response = $controller->createBackup($request);

            $payload = json_decode($response->getContent(), true) ?: [];
            $filename = $payload['data']['filename'] ?? 'unknown';

            $this->pruneOldBackups();

            Setting::updateOrCreate(
                ['group' => 'system_backup_config', 'key' => 'last_backup_at'],
                ['value' => now()->toIso8601String(), 'type' => 'string']
            );

            $this->info("Backup created: {$filename}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }
    }

    private function pruneOldBackups(): void
    {
        $retention = (int) Setting::getValue('system_backup_config', 'retention', 7);
        if ($retention <= 0) return;

        $dir = storage_path('app/backups');
        if (! File::exists($dir)) return;

        $files = collect(File::files($dir))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $files->slice($retention)->each(function ($file) {
            try { File::delete($file->getPathname()); } catch (\Throwable $e) { /* silent */ }
        });
    }
}