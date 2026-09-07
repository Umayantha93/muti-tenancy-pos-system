<?php

namespace App\Console\Commands;

use App\Models\BillVideo;
use App\Services\JobVideoConverter;
use Illuminate\Console\Command;

class PruneJobVideosCommand extends Command
{
    protected $signature = 'job-videos:prune';

    protected $description = 'Delete garage job videos older than 6 months';

    public function handle(JobVideoConverter $converter): int
    {
        $cutoff = now()->subDays(BillVideo::RETAIN_DAYS);
        $videos = BillVideo::withoutGlobalScopes()->where('created_at', '<', $cutoff)->get();
        foreach ($videos as $video) {
            $converter->delete($video->path);
            $video->delete();
        }
        $this->info('Removed '.$videos->count().' expired job videos.');

        return self::SUCCESS;
    }
}
