<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class ImportLegacyDatabase extends Command
{
    protected $signature = 'legacy:import-database
        {--source=webaccv3 : Tên database nguồn}
        {--target= : Tên database đích; mặc định lấy từ DB_DATABASE}
        {--execute : Thực sự ghi dữ liệu; bỏ option này để chỉ kiểm tra}';

    protected $description = 'Chuyển dữ liệu legacy sang database mới, giữ nguyên user ID và đổi username bị trùng';

    /**
     * Các bảng runtime/rác đã thống nhất không chuyển dữ liệu.
     * Passport client ở database đích cũng được giữ nguyên.
     *
     * @var list<string>
     */
    private const EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'cards',
        'carot_recharges',
        'carot_recharge_statistics',
        'failed_jobs',
        'inventory_movements',
        'jobs',
        'login_attempts',
        'migrations',
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_device_codes',
        'oauth_refresh_tokens',
        'password_reset_tokens',
        'sessions',
        'user_devices',
        'user_oauth_consents',
        'user_security_logs',
        'user_sessions',
    ];

    private PDO $pdo;

    private string $source;

    private string $target;

    /** @var array<string, int> */
    private array $sourceCounts = [];

    public function handle(): int
    {
        $this->pdo = DB::connection()->getPdo();
        $this->source = (string) $this->option('source');
        $this->target = (string) ($this->option('target') ?: DB::connection()->getDatabaseName());

        try {
            $this->assertSafeEnvironment();
            $copyTables = $this->preflight();

            if (! $this->option('execute')) {
                $this->newLine();
                $this->warn('Đây chỉ là kiểm tra. Chưa có dữ liệu nào được ghi.');
                $this->line('Chạy lại với --execute để bắt đầu chuyển dữ liệu.');

                return self::SUCCESS;
            }

            $renames = $this->createUsernameRenameMap();
            $excludedBefore = $this->captureExcludedTableState();
            $oauthClientsBefore = $this->fetchAllRows($this->target, 'oauth_clients');

            $insertedCounts = $this->copyWithinTransaction($copyTables, $renames, $excludedBefore, $oauthClientsBefore);
            [$renameReport, $transferReport] = $this->writeReports($renames, $insertedCounts);

            $this->newLine();
            $this->info('Chuyển dữ liệu hoàn tất.');
            $this->line('User giữ nguyên ID: '.number_format($this->sourceCounts['users']));
            $this->line('Username đã thêm hậu tố 4 chữ: '.number_format(count($renames)));
            $this->line('Danh sách username đổi: '.$renameReport);
            $this->line('Báo cáo chuyển dữ liệu: '.$transferReport);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($this->pdo)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                try {
                    $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_legacy_username_renames`');
                } catch (Throwable) {
                    // Kết nối có thể đã đóng sau lỗi SQL; không che lỗi gốc.
                }
            }
        }
    }

    private function assertSafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Lệnh chuyển dữ liệu chỉ được phép chạy khi APP_ENV=local.');
        }

        foreach ([$this->source, $this->target] as $database) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                throw new RuntimeException("Tên database không hợp lệ: {$database}");
            }
        }

        $configuredTarget = (string) DB::connection()->getDatabaseName();

        if ($this->target !== $configuredTarget) {
            throw new RuntimeException("Database đích phải đúng DB_DATABASE hiện tại ({$configuredTarget}).");
        }

        if ($this->source === $this->target) {
            throw new RuntimeException('Database nguồn và đích không được trùng nhau.');
        }

        if ($this->source !== 'webaccv3' || $this->target !== 'newdb') {
            throw new RuntimeException('Lần test này chỉ cho phép chuyển chính xác từ webaccv3 sang newdb.');
        }
    }

    /** @return list<string> */
    private function preflight(): array
    {
        $sourceTables = $this->baseTables($this->source);
        $targetTables = $this->baseTables($this->target);
        $copyTables = array_values(array_diff(array_intersect($sourceTables, $targetTables), self::EXCLUDED_TABLES));
        sort($copyTables);

        if (! in_array('users', $copyTables, true) || ! in_array('user_auth_providers', $copyTables, true)) {
            throw new RuntimeException('Thiếu bảng users hoặc user_auth_providers trong danh sách chuyển.');
        }

        $nonEmptyTargets = [];
        $columnMismatches = [];

        foreach ($copyTables as $table) {
            $sourceColumns = $this->columns($this->source, $table);
            $targetColumns = $this->columns($this->target, $table);

            if ($sourceColumns !== $targetColumns) {
                $columnMismatches[] = $table;
            }

            $this->sourceCounts[$table] = $this->rowCount($this->source, $table);
            $targetCount = $this->rowCount($this->target, $table);

            if ($targetCount !== 0) {
                $nonEmptyTargets[$table] = $targetCount;
            }
        }

        if ($columnMismatches !== []) {
            throw new RuntimeException('Cột nguồn/đích không giống nhau ở: '.implode(', ', $columnMismatches));
        }

        if ($nonEmptyTargets !== []) {
            $details = collect($nonEmptyTargets)
                ->map(fn (int $count, string $table): string => "{$table}={$count}")
                ->implode(', ');

            throw new RuntimeException('Dừng để tránh ghi chồng: bảng đích đã có dữ liệu: '.$details);
        }

        $duplicateGroups = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM (SELECT username FROM '.$this->qualifiedTable($this->source, 'users')
            .' GROUP BY username HAVING COUNT(*) > 1) AS duplicate_names'
        )->fetchColumn();
        $duplicateUsers = (int) $this->pdo->query(
            'SELECT COALESCE(SUM(total), 0) FROM (SELECT COUNT(*) AS total FROM '.$this->qualifiedTable($this->source, 'users')
            .' GROUP BY username HAVING COUNT(*) > 1) AS duplicate_users'
        )->fetchColumn();
        $renameCount = $duplicateUsers - $duplicateGroups;

        $this->info("Nguồn: {$this->source}; đích: {$this->target}");
        $this->line('Bảng chung sẽ chuyển: '.count($copyTables));
        $this->line('Bảng không chuyển dữ liệu: '.implode(', ', self::EXCLUDED_TABLES));
        $this->line('Tổng user: '.number_format($this->sourceCounts['users']));
        $this->line("Username trùng: {$duplicateGroups} nhóm; {$renameCount} user sẽ được thêm hậu tố 4 chữ.");

        return $copyTables;
    }

    /**
     * @return array<int, array{id: int, old_username: string, new_username: string}>
     */
    private function createUsernameRenameMap(): array
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `tmp_legacy_username_renames`');
        $this->pdo->exec(
            'CREATE TEMPORARY TABLE `tmp_legacy_username_renames` ('
            .'`user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY, '
            .'`old_username` VARCHAR(191) NOT NULL, '
            .'`new_username` VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE'
            .')'
        );

        $duplicateRows = $this->pdo->query(
            'SELECT u.id, u.username FROM '.$this->qualifiedTable($this->source, 'users').' AS u '
            .'INNER JOIN ('
            .'SELECT username, MIN(id) AS kept_id FROM '.$this->qualifiedTable($this->source, 'users').' '
            .'GROUP BY username HAVING COUNT(*) > 1'
            .') AS duplicate_names ON duplicate_names.username = u.username '
            .'WHERE u.id <> duplicate_names.kept_id ORDER BY u.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $usernameLimit = $this->usernameLengthLimit();
        $sourceNameExists = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM '.$this->qualifiedTable($this->source, 'users').' WHERE username = ? LIMIT 1)'
        );
        $insertRename = $this->pdo->prepare(
            'INSERT INTO `tmp_legacy_username_renames` (`user_id`, `old_username`, `new_username`) VALUES (?, ?, ?)'
        );
        $renames = [];

        foreach ($duplicateRows as $row) {
            $oldUsername = (string) $row['username'];

            do {
                $newUsername = mb_substr($oldUsername, 0, $usernameLimit - 5, 'UTF-8').'_'.$this->randomLetters(4);
                $sourceNameExists->execute([$newUsername]);

                if ((bool) $sourceNameExists->fetchColumn()) {
                    continue;
                }

                try {
                    $insertRename->execute([(int) $row['id'], $oldUsername, $newUsername]);
                    break;
                } catch (PDOException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }
                }
            } while (true);

            $renames[] = [
                'id' => (int) $row['id'],
                'old_username' => $oldUsername,
                'new_username' => $newUsername,
            ];
        }

        return $renames;
    }

    /**
     * @param  list<string>  $copyTables
     * @param  array<int, array{id: int, old_username: string, new_username: string}>  $renames
     * @param  array<string, int>  $excludedBefore
     * @param  array<int, array<string, mixed>>  $oauthClientsBefore
     * @return array<string, int>
     */
    private function copyWithinTransaction(
        array $copyTables,
        array $renames,
        array $excludedBefore,
        array $oauthClientsBefore,
    ): array {
        $orderedTables = array_values(array_unique(array_merge(
            ['users', 'user_auth_providers'],
            array_diff($copyTables, ['users', 'user_auth_providers']),
        )));
        $insertedCounts = [];

        $this->pdo->beginTransaction();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($orderedTables as $table) {
                $insertedCounts[$table] = $this->copyTable($table);
                $this->line("[{$table}] ".number_format($insertedCounts[$table]).' dòng');
            }

            $this->validateTransfer($copyTables, $renames, $excludedBefore, $oauthClientsBefore);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        return $insertedCounts;
    }

    private function copyTable(string $table): int
    {
        $columns = $this->columns($this->target, $table);
        $insertColumns = implode(', ', array_map($this->quote(...), $columns));
        $selectColumns = [];
        $join = '';

        foreach ($columns as $column) {
            $sourceColumn = 'source_row.'.$this->quote($column);

            if ($table === 'users' && $column === 'username') {
                $selectColumns[] = 'COALESCE(rename_row.new_username, '.$sourceColumn.') AS `username`';
                $join = ' LEFT JOIN `tmp_legacy_username_renames` AS rename_row ON rename_row.user_id = source_row.id';

                continue;
            }

            if ($table === 'user_auth_providers' && in_array($column, ['provider_id', 'provider_username'], true)) {
                $selectColumns[] = "CASE WHEN source_row.provider = 'password' AND rename_row.user_id IS NOT NULL "
                    .'THEN rename_row.new_username ELSE '.$sourceColumn.' END AS '.$this->quote($column);
                $join = ' LEFT JOIN `tmp_legacy_username_renames` AS rename_row ON rename_row.user_id = source_row.user_id';

                continue;
            }

            $selectColumns[] = $sourceColumn;
        }

        $sql = 'INSERT INTO '.$this->qualifiedTable($this->target, $table).' ('.$insertColumns.') '
            .'SELECT '.implode(', ', $selectColumns).' FROM '.$this->qualifiedTable($this->source, $table).' AS source_row'.$join;

        return (int) $this->pdo->exec($sql);
    }

    /**
     * @param  list<string>  $copyTables
     * @param  array<int, array{id: int, old_username: string, new_username: string}>  $renames
     * @param  array<string, int>  $excludedBefore
     * @param  array<int, array<string, mixed>>  $oauthClientsBefore
     */
    private function validateTransfer(
        array $copyTables,
        array $renames,
        array $excludedBefore,
        array $oauthClientsBefore,
    ): void {
        foreach ($copyTables as $table) {
            $targetCount = $this->rowCount($this->target, $table);

            if ($targetCount !== $this->sourceCounts[$table]) {
                throw new RuntimeException(
                    "Sai row count ở {$table}: nguồn={$this->sourceCounts[$table]}, đích={$targetCount}"
                );
            }
        }

        $duplicateTargetNames = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM (SELECT username FROM '.$this->qualifiedTable($this->target, 'users')
            .' GROUP BY username HAVING COUNT(*) > 1) AS duplicate_names'
        )->fetchColumn();

        if ($duplicateTargetNames !== 0) {
            throw new RuntimeException("Database đích vẫn còn {$duplicateTargetNames} nhóm username trùng.");
        }

        $missingIds = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '.$this->qualifiedTable($this->source, 'users').' AS source_user '
            .'LEFT JOIN '.$this->qualifiedTable($this->target, 'users').' AS target_user ON target_user.id = source_user.id '
            .'WHERE target_user.id IS NULL'
        )->fetchColumn();
        $extraIds = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '.$this->qualifiedTable($this->target, 'users').' AS target_user '
            .'LEFT JOIN '.$this->qualifiedTable($this->source, 'users').' AS source_user ON source_user.id = target_user.id '
            .'WHERE source_user.id IS NULL'
        )->fetchColumn();

        if ($missingIds !== 0 || $extraIds !== 0) {
            throw new RuntimeException("User ID không khớp: thiếu={$missingIds}, thừa={$extraIds}.");
        }

        $changedNames = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '.$this->qualifiedTable($this->source, 'users').' AS source_user '
            .'INNER JOIN '.$this->qualifiedTable($this->target, 'users').' AS target_user ON target_user.id = source_user.id '
            .'WHERE NOT (source_user.username <=> target_user.username)'
        )->fetchColumn();

        if ($changedNames !== count($renames)) {
            throw new RuntimeException('Số username thực tế đã đổi không khớp bản đồ đổi tên.');
        }

        $unchangedUserColumns = array_values(array_diff($this->columns($this->source, 'users'), ['username']));
        $sameExpressions = array_map(
            fn (string $column): string => '(source_user.'.$this->quote($column).' <=> target_user.'.$this->quote($column).')',
            $unchangedUserColumns,
        );
        $changedUserData = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '.$this->qualifiedTable($this->source, 'users').' AS source_user '
            .'INNER JOIN '.$this->qualifiedTable($this->target, 'users').' AS target_user ON target_user.id = source_user.id '
            .'WHERE NOT ('.implode(' AND ', $sameExpressions).')'
        )->fetchColumn();

        if ($changedUserData !== 0) {
            throw new RuntimeException("Có {$changedUserData} user bị thay đổi dữ liệu ngoài username.");
        }

        $unsyncedPasswordProviders = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM '.$this->qualifiedTable($this->target, 'user_auth_providers').' AS provider_row '
            .'INNER JOIN `tmp_legacy_username_renames` AS rename_row ON rename_row.user_id = provider_row.user_id '
            ."WHERE provider_row.provider = 'password' AND ("
            .'NOT (provider_row.provider_id <=> rename_row.new_username) '
            .'OR NOT (provider_row.provider_username <=> rename_row.new_username))'
        )->fetchColumn();

        if ($unsyncedPasswordProviders !== 0) {
            throw new RuntimeException("Có {$unsyncedPasswordProviders} hồ sơ password chưa đồng bộ username mới.");
        }

        foreach ($excludedBefore as $table => $count) {
            if ($this->rowCount($this->target, $table) !== $count) {
                throw new RuntimeException("Bảng loại trừ {$table} đã bị thay đổi ngoài ý muốn.");
            }
        }

        if ($this->fetchAllRows($this->target, 'oauth_clients') !== $oauthClientsBefore) {
            throw new RuntimeException('Passport client ở database đích đã bị thay đổi ngoài ý muốn.');
        }
    }

    /** @return array<string, int> */
    private function captureExcludedTableState(): array
    {
        $targetTables = $this->baseTables($this->target);
        $state = [];

        foreach (self::EXCLUDED_TABLES as $table) {
            if (in_array($table, $targetTables, true)) {
                $state[$table] = $this->rowCount($this->target, $table);
            }
        }

        return $state;
    }

    /**
     * @param  array<int, array{id: int, old_username: string, new_username: string}>  $renames
     * @param  array<string, int>  $insertedCounts
     * @return array{string, string}
     */
    private function writeReports(array $renames, array $insertedCounts): array
    {
        $directory = storage_path('app/legacy-transfer');
        File::ensureDirectoryExists($directory);
        $stamp = now()->format('Ymd-His');
        $renamePath = $directory.DIRECTORY_SEPARATOR."username-renames-{$stamp}.csv";
        $reportPath = $directory.DIRECTORY_SEPARATOR."transfer-report-{$stamp}.json";

        $stream = fopen($renamePath, 'wb');

        if ($stream === false) {
            throw new RuntimeException('Không thể tạo file báo cáo đổi username.');
        }

        fputcsv($stream, ['user_id', 'old_username', 'new_username']);
        foreach ($renames as $rename) {
            fputcsv($stream, [$rename['id'], $rename['old_username'], $rename['new_username']]);
        }
        fclose($stream);

        $report = [
            'source_database' => $this->source,
            'target_database' => $this->target,
            'completed_at' => now()->toIso8601String(),
            'renamed_user_count' => count($renames),
            'inserted_rows' => $insertedCounts,
            'excluded_tables' => self::EXCLUDED_TABLES,
        ];
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [$renamePath, $reportPath];
    }

    /** @return list<string> */
    private function baseTables(string $database): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME'
        );
        $statement->execute([$database]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function columns(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$database, $table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function usernameLengthLimit(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'users\' AND COLUMN_NAME = \'username\''
        );
        $statement->execute([$this->target]);

        return (int) $statement->fetchColumn();
    }

    private function rowCount(string $database, string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->qualifiedTable($database, $table))->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAllRows(string $database, string $table): array
    {
        return $this->pdo->query(
            'SELECT * FROM '.$this->qualifiedTable($database, $table).' ORDER BY 1'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function qualifiedTable(string $database, string $table): string
    {
        return $this->quote($database).'.'.$this->quote($table);
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function randomLetters(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz';
        $result = '';

        for ($index = 0; $index < $length; $index++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $result;
    }
}
