<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use ZipArchive;

/**
 * نسخة احتياطية كاملة: قاعدة البيانات (mysqldump) + الملفات الخاصة (المرفقات والملفات المستوردة).
 * php artisan nours:backup [--keep=14]
 */
class BackupCommand extends Command
{
    protected $signature = 'nours:backup {--keep=14 : عدد النسخ المحتفظ بها}';

    protected $description = 'إنشاء نسخة احتياطية من قاعدة البيانات والملفات الخاصة';

    public function handle(): int
    {
        $dir = storage_path('backups');
        @mkdir($dir, 0750, true);
        $stamp = now()->format('Y-m-d_His');
        $sqlFile = "{$dir}/db_{$stamp}.sql";
        $db = config('database.connections.'.config('database.default'));

        $cnf = tempnam(sys_get_temp_dir(), 'nours');
        file_put_contents($cnf, "[client]\nuser={$db['username']}\npassword=\"{$db['password']}\"\nhost={$db['host']}\nport={$db['port']}\n");
        chmod($cnf, 0600);

        $result = Process::timeout(3600)->run([
            'mysqldump', "--defaults-extra-file={$cnf}", '--single-transaction', '--routines', '--triggers',
            '--default-character-set=utf8mb4', "--result-file={$sqlFile}", $db['database'],
        ]);
        @unlink($cnf);

        if (! $result->successful()) {
            $this->error('فشل mysqldump: '.$result->errorOutput());

            return self::FAILURE;
        }

        $zipPath = "{$dir}/nours_backup_{$stamp}.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($sqlFile, 'database.sql');
        $filesRoot = storage_path('app/private/nours');
        if (is_dir($filesRoot)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $zip->addFile($file->getPathname(), 'files/'.substr($file->getPathname(), strlen($filesRoot) + 1));
            }
        }
        $zip->close();
        @unlink($sqlFile);

        // تدوير النسخ القديمة
        $all = glob("{$dir}/nours_backup_*.zip");
        rsort($all);
        foreach (array_slice($all, (int) $this->option('keep')) as $old) {
            @unlink($old);
        }

        $this->info("تم إنشاء النسخة الاحتياطية: {$zipPath} (".round(filesize($zipPath) / 1024).' KB)');

        return self::SUCCESS;
    }
}
