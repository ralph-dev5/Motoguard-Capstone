<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-off copy of the live data into a second Supabase project, used when moving the database
 * to a closer region. Exists instead of pg_dump/pg_restore because no PostgreSQL client tools
 * are installed here and the dataset is small enough that a chunked copy is simpler and safer.
 */
class CopyDatabaseToNew extends Command
{
    protected $signature = 'db:copy-to-new {--pretend : Show what would be copied without writing}';

    protected $description = 'Copy application data from the current database into the pgsql_new connection';

    /**
     * Parents before children so foreign keys resolve as each table lands.
     *
     * Deliberately excludes jobs, failed_jobs, job_batches, cache, cache_locks, sessions and
     * password_reset_tokens: all transient, and copying stale queued jobs to a fresh project
     * would replay them.
     *
     * @var list<string>
     */
    private const TABLES = [
        'users',
        'devices',
        'personal_access_tokens',
        'passkeys',
        'alerts',
        'location_logs',
    ];

    public function handle(): int
    {
        $source = DB::connection();
        $target = DB::connection('pgsql_new');

        try {
            $target->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot reach pgsql_new: '.$e->getMessage());
            $this->line('Set NEW_DB_HOST, NEW_DB_PORT, NEW_DB_DATABASE, NEW_DB_USERNAME and NEW_DB_PASSWORD in .env.');

            return self::FAILURE;
        }

        $this->info('Source: '.$source->getConfig('host'));
        $this->info('Target: '.$target->getConfig('host'));
        $this->newLine();

        $pretend = (bool) $this->option('pretend');
        $failed = false;

        foreach (self::TABLES as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                $this->line(str_pad($table, 26).'skipped (not in source)');

                continue;
            }

            if (! $target->getSchemaBuilder()->hasTable($table)) {
                $this->error(str_pad($table, 26).'MISSING IN TARGET — run migrations there first');
                $failed = true;

                continue;
            }

            $rows = $source->table($table)->count();

            if ($pretend) {
                $this->line(str_pad($table, 26).$rows.' row(s) would be copied');

                continue;
            }

            $existing = $target->table($table)->count();

            if ($existing > 0) {
                $this->warn(str_pad($table, 26)."target already has {$existing} row(s) — skipped");

                continue;
            }

            // Chunked by primary key so a large table cannot exhaust memory, though in practice
            // these are tiny. Values are passed through untouched, which keeps PostGIS geography
            // columns as the EWKB the source returned.
            $copied = 0;
            $source->table($table)->orderBy('id')->chunk(500, function ($chunk) use ($target, $table, &$copied) {
                $target->table($table)->insert(
                    $chunk->map(fn ($row) => (array) $row)->all()
                );
                $copied += $chunk->count();
            });

            $verified = $target->table($table)->count();
            $ok = $verified === $rows;

            $this->line(str_pad($table, 26)."{$copied} copied, target now {$verified} / {$rows} ".($ok ? 'OK' : 'MISMATCH'));

            if (! $ok) {
                $failed = true;
            }
        }

        if (! $pretend && ! $failed) {
            $this->resetSequences($target);
        }

        $this->newLine();

        if ($failed) {
            $this->error('Finished with problems — do not delete the old project yet.');

            return self::FAILURE;
        }

        $this->info($pretend ? 'Dry run complete.' : 'Copy complete. Verify the app against the new database before deleting the old project.');

        return self::SUCCESS;
    }

    /**
     * Rows are copied with their original ids, which leaves every sequence still pointing at 1,
     * so the next insert would collide on a primary key. Nudge each one past the highest id.
     */
    private function resetSequences(Connection $target): void
    {
        $this->newLine();
        $this->line('Resetting sequences:');

        foreach (self::TABLES as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $sequence = $target->selectOne('select pg_get_serial_sequence(?, ?) as name', [$table, 'id'])->name ?? null;

            if ($sequence === null) {
                continue;
            }

            $max = (int) $target->selectOne("select coalesce(max(id), 0) as max from {$table}")->max;

            // is_called stays false on an empty table so its first row still gets id 1.
            $target->statement('select setval(?, ?, ?)', [$sequence, max($max, 1), $max > 0]);

            $this->line('  '.str_pad($table, 24).'next id '.($max + 1));
        }
    }
}
