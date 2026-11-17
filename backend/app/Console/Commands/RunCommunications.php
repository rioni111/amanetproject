<?php

namespace App\Console\Commands;

use App\Services\Communications\CommunicationScheduler;
use Illuminate\Console\Command;

/**
 * Reminders and time-based notifications. Scheduled every five minutes (routes/console.php); run it by hand
 * with `php artisan lexora:communications` (or `--dry-run` to only count what is due). Output: counts only —
 * never recipients, links or message content.
 */
class RunCommunications extends Command
{
    protected $signature = 'lexora:communications {--dry-run : Count due reminders without sending or notifying}';

    protected $description = 'Send due client reminders and create overdue / expired-link notifications';

    public function handle(CommunicationScheduler $scheduler): int
    {
        $stats = $scheduler->run((bool) $this->option('dry-run'));
        foreach ($stats as $name => $value) {
            $this->line(str_pad($name, 20).' '.$value);
        }

        return self::SUCCESS;
    }
}
