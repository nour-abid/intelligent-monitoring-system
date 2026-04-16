<?php

namespace App\Console\Commands;

use App\Services\Monitoring\AlertEvaluationService;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan alerts:evaluate
 *
 * Evaluates all behavior thresholds against the surveillance database
 * and broadcasts any triggered alerts via Laravel Reverb.
 *
 * Run manually:   php artisan alerts:evaluate
 * Force early-leave check regardless of time: php artisan alerts:evaluate --force-early-leave
 * Run in dev loop: php artisan schedule:work  (fires every minute via scheduler)
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature   = 'alerts:evaluate {--force-early-leave : Bypass workday_end time guard for testing}';
    protected $description = 'Evaluate behavior thresholds and broadcast realtime alerts';

    public function handle(AlertEvaluationService $service): int
    {
        $force = (bool) $this->option('force-early-leave');
        $count = $service->evaluate($force);

        $this->info("Evaluation complete. Fired {$count} alert(s).");

        return Command::SUCCESS;
    }
}
