<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    private const MAX_SQL_BYTES = 1073741824;

    private string $directory;

    public function __construct()
    {
        $this->directory = storage_path('app/private/database-backups');
    }

    public function isSupported(): bool
    {
        return config('database.default') === 'mysql';
    }

    public function hasSafetyBackup(): bool
    {
        return is_file($this->safetyBackupPath());
    }

    public function safetyBackupPath(): string
    {
        return $this->directory.'/latest-safety.sql.gz';
    }

    public function createBackup(): string
    {
        $this->assertSupported();
        $this->ensureDirectory();

        $sqlPath = $this->temporaryPath('backup-');
        $gzipPath = $this->temporaryPath('backup-', '.sql.gz');

        try {
            $this->dump($sqlPath);
            $this->assertDumpHeader($sqlPath);
            $this->compress($sqlPath, $gzipPath);
            chmod($gzipPath, 0600);

            return $gzipPath;
        } catch (Throwable $error) {
            File::delete($gzipPath);

            throw $error;
        } finally {
            File::delete($sqlPath);
        }
    }

    public function createSafetyBackup(): void
    {
        $backupPath = $this->createBackup();

        try {
            if (! rename($backupPath, $this->safetyBackupPath())) {
                throw new RuntimeException('Could not retain the safety backup.');
            }

            chmod($this->safetyBackupPath(), 0600);
        } finally {
            File::delete($backupPath);
        }
    }

    public function restore(string $uploadedBackupPath): void
    {
        $this->assertSupported();
        $this->ensureDirectory();

        $sqlPath = $this->temporaryPath('restore-', '.sql');
        $maintenanceEnabled = false;

        try {
            $this->expandAndValidate($uploadedBackupPath, $sqlPath);
            Artisan::call('down');
            $maintenanceEnabled = true;

            try {
                $this->createSafetyBackup();
            } catch (Throwable $error) {
                throw new RuntimeException('safety_backup_failed', 0, $error);
            }

            try {
                $this->import($sqlPath);
                if (Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']) !== 0) {
                    throw new RuntimeException('restore_migration_failed');
                }
                DB::purge('mysql');
            } catch (Throwable $restoreError) {
                try {
                    $safetySqlPath = $this->temporaryPath('rollback-', '.sql');
                    try {
                        $this->expandAndValidate($this->safetyBackupPath(), $safetySqlPath);
                        $this->import($safetySqlPath);
                    } finally {
                        File::delete($safetySqlPath);
                    }
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('restore_and_rollback_failed', 0, $rollbackError);
                }

                throw new RuntimeException('restore_failed_and_recovered', 0, $restoreError);
            }
        } finally {
            File::delete($sqlPath);

            if ($maintenanceEnabled) {
                Artisan::call('up');
            }
        }
    }

    private function assertSupported(): void
    {
        if (! $this->isSupported()) {
            throw new RuntimeException('Database backup and restore require MySQL.');
        }
    }

    private function ensureDirectory(): void
    {
        File::ensureDirectoryExists($this->directory, 0700);
        chmod($this->directory, 0700);
    }

    private function temporaryPath(string $prefix, string $suffix = ''): string
    {
        $path = tempnam($this->directory, $prefix);

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary backup file.');
        }

        chmod($path, 0600);

        if ($suffix === '') {
            return $path;
        }

        $suffixedPath = $path.$suffix;
        if (! rename($path, $suffixedPath)) {
            File::delete($path);

            throw new RuntimeException('Could not create a temporary backup file.');
        }

        return $suffixedPath;
    }

    private function dump(string $sqlPath): void
    {
        $credentialsPath = $this->credentialsFile();

        try {
            $connection = config('database.connections.mysql');
            $database = (string) $connection['database'];
            $process = new Process([
                'mysqldump',
                '--defaults-extra-file='.$credentialsPath,
                '--single-transaction',
                '--quick',
                '--routines',
                '--events',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set='.($connection['charset'] ?? 'utf8mb4'),
                '--result-file='.$sqlPath,
                $database,
            ]);
            $process->setTimeout(null);
            $process->mustRun();
        } finally {
            File::delete($credentialsPath);
        }
    }

    private function compress(string $sqlPath, string $gzipPath): void
    {
        $input = fopen($sqlPath, 'rb');
        $output = gzopen($gzipPath, 'wb9');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }

            throw new RuntimeException('Could not compress the database backup.');
        }

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read the database dump.');
                }
                if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not compress the database backup.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }

    private function expandAndValidate(string $gzipPath, string $sqlPath): void
    {
        $input = @gzopen($gzipPath, 'rb');
        $output = fopen($sqlPath, 'wb');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new \InvalidArgumentException('File backup harus berupa arsip gzip yang valid.');
        }

        $totalBytes = 0;
        $readError = null;
        set_error_handler(static function (int $severity, string $message) use (&$readError): bool {
            $readError = $message;

            return true;
        });

        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw new \InvalidArgumentException('File backup rusak atau tidak lengkap.');
                }
                if ($chunk === '' && ! gzeof($input)) {
                    throw new \InvalidArgumentException('File backup rusak atau tidak lengkap.');
                }

                $totalBytes += strlen($chunk);
                if ($totalBytes > self::MAX_SQL_BYTES) {
                    throw new \InvalidArgumentException('Ukuran isi backup melebihi batas 1 GiB.');
                }

                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not prepare the database dump.');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
            restore_error_handler();
        }

        if ($readError !== null || $totalBytes === 0) {
            throw new \InvalidArgumentException('File backup rusak atau tidak lengkap.');
        }
        $this->assertDumpHeader($sqlPath);

        chmod($sqlPath, 0600);
    }

    private function assertDumpHeader(string $sqlPath): void
    {
        $input = fopen($sqlPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not read the database dump.');
        }

        try {
            $header = fread($input, 16384);
        } finally {
            fclose($input);
        }

        if ($header === false || (! str_contains($header, 'MySQL dump') && ! str_contains($header, 'MariaDB dump'))) {
            throw new \InvalidArgumentException('Unggah file backup database MySQL yang dibuat oleh fitur ini.');
        }
    }

    private function import(string $sqlPath): void
    {
        $this->dropExistingTables();
        DB::purge('mysql');
        $credentialsPath = $this->credentialsFile();
        $input = fopen($sqlPath, 'rb');

        if ($input === false) {
            File::delete($credentialsPath);

            throw new RuntimeException('Could not open the database dump.');
        }

        try {
            $connection = config('database.connections.mysql');
            $process = new Process([
                'mysql',
                '--defaults-extra-file='.$credentialsPath,
                '--binary-mode',
                '--default-character-set='.($connection['charset'] ?? 'utf8mb4'),
                '--database='.(string) $connection['database'],
            ]);
            $process->setInput($input);
            $process->setTimeout(null);
            $process->mustRun();
        } finally {
            fclose($input);
            File::delete($credentialsPath);
        }

        DB::purge('mysql');
        DB::connection('mysql')->getPdo();
    }

    private function dropExistingTables(): void
    {
        $tables = DB::connection('mysql')->select(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (['VIEW', 'BASE TABLE'] as $type) {
                foreach ($tables as $table) {
                    if ($table->TABLE_TYPE !== $type) {
                        continue;
                    }

                    $name = '`'.str_replace('`', '``', $table->TABLE_NAME).'`';
                    DB::connection('mysql')->statement('DROP '.($type === 'VIEW' ? 'VIEW' : 'TABLE')." IF EXISTS {$name}");
                }
            }
        } finally {
            DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function credentialsFile(): string
    {
        $this->ensureDirectory();
        $path = tempnam($this->directory, 'mysql-client-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary MySQL credentials file.');
        }

        $connection = config('database.connections.mysql');
        $options = [
            'user' => (string) $connection['username'],
            'password' => (string) $connection['password'],
            'host' => (string) $connection['host'],
            'port' => (string) $connection['port'],
        ];
        if (! empty($connection['unix_socket'])) {
            $options['socket'] = (string) $connection['unix_socket'];
        }

        $lines = ['[client]'];
        foreach ($options as $key => $value) {
            if (preg_match('/[\r\n\0]/', $value)) {
                File::delete($path);

                throw new RuntimeException('Invalid MySQL client configuration.');
            }

            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            $lines[] = $key.'="'.$escaped.'"';
        }

        if (file_put_contents($path, implode("\n", $lines)."\n") === false) {
            File::delete($path);

            throw new RuntimeException('Could not write the temporary MySQL credentials file.');
        }

        chmod($path, 0600);

        return $path;
    }
}
