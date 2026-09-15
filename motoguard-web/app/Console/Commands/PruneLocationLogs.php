<?php

namespace App\Console\Commands;

use App\Models\LocationLog;
use Illuminate\Console\Command;

class PruneLocationLogs extends Command
{
    protected $signature = 'location-logs:prune {--days=30 : Keep logs newer than this many days}';

    protected $description = 'Delete old GPS logs to stay within the Supabase free-tier storage limit';

    public function handle(): int
    {
        $deleted = LocationLog::query()
            ->where('recorded_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Deleted {$deleted} location log(s).");

        return self::SUCCESS;
    }
}
