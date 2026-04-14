<?php

namespace App\Console\Commands;

use App\Models\UserIdentityPhoto;
use App\Services\Identity\FaceEmbeddingApiClient;
use Illuminate\Console\Command;

/**
 * One-shot backfill: detect and store head pose for all 'ready' enrollment
 * photos that were embedded before pose detection was introduced.
 *
 * Usage:
 *   php artisan face:backfill-poses            # dry-run by default
 *   php artisan face:backfill-poses --write     # actually update the DB
 *   php artisan face:backfill-poses --write --limit=50
 *
 * The command calls the lightweight Python /classify-pose endpoint, which
 * runs only the InsightFace face detector — no embeddings are recomputed.
 */
class BackfillFacePosesCommand extends Command
{
    protected $signature = 'face:backfill-poses
        {--write   : Persist detected_pose to the database (default is dry-run)}
        {--limit=0 : Max photos to process (0 = no limit)}';

    protected $description = 'Backfill detected_pose for ready photos that are missing it';

    public function handle(FaceEmbeddingApiClient $client): int
    {
        $write = (bool) $this->option('write');
        $limit = (int)  $this->option('limit');

        $query = UserIdentityPhoto::where('processing_status', 'ready')
            ->whereNull('detected_pose')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $photos = $query->get();

        if ($photos->isEmpty()) {
            $this->info('Nothing to backfill — all ready photos already have detected_pose.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d ready photo(s) with detected_pose = NULL%s.',
            $photos->count(),
            $write ? '' : ' (dry-run — pass --write to persist)',
        ));

        $counts = ['ok' => 0, 'no_face' => 0, 'error' => 0];

        $bar = $this->output->createProgressBar($photos->count());
        $bar->start();

        foreach ($photos as $photo) {
            $bar->advance();

            try {
                $result = $client->classifyPose($photo->id, $photo->absolutePath());
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn(sprintf('  photo #%d — service error: %s', $photo->id, $e->getMessage()));
                $counts['error']++;
                continue;
            }

            if (! ($result['success'] ?? false)) {
                $this->newLine();
                $this->line(sprintf(
                    '  photo #%d — skipped (%s)',
                    $photo->id,
                    $result['failure_reason'] ?? 'unknown',
                ));
                $counts['no_face']++;
                continue;
            }

            $pose = $result['detected_pose'] ?? null;

            if ($write && $pose) {
                $photo->forceFill(['detected_pose' => $pose])->save();
            }

            $this->newLine();
            $this->line(sprintf(
                '  photo #%d → <info>%s</info>%s',
                $photo->id,
                $pose ?? 'null',
                $write ? '' : ' (dry-run)',
            ));
            $counts['ok']++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Result', 'Count'],
            [
                ['Pose detected' . ($write ? ' & saved' : ' (dry-run)'), $counts['ok']],
                ['No face / multi-face',                                  $counts['no_face']],
                ['Service error',                                         $counts['error']],
            ],
        );

        if (! $write && $counts['ok'] > 0) {
            $this->comment('Re-run with --write to persist the results.');
        }

        return self::SUCCESS;
    }
}
