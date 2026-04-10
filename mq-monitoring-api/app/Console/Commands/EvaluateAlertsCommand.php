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
 * Run in dev loop: php artisan schedule:work  (fires every minute via scheduler)
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature   = 'alerts:evaluate';
    protected $description = 'Evaluate behavior thresholds and broadcast realtime alerts';

    public function handle(AlertEvaluationService $service): int
    {
        $count = $service->evaluate();

        $this->info("Evaluation complete. Fired {$count} alert(s).");

        return Command::SUCCESS;
    }
}
