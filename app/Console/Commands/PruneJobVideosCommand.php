<?php

namespace App\Console\Commands;

use App\Models\BillPhoto;
use App\Models\BillVideo;
use App\Services\JobPhotoStore;
use App\Services\JobVideoConverter;
use Illuminate\Console\Command;

class PruneJobVideosCommand extends Command
{
    protected $signature = 'job-videos:prune';

    protected $description = 'Delete garage job videos and photos older than 6 months';

    public function handle(JobVideoConverter $converter, JobPhotoStore $photos): int
    {
        $videoCutoff = now()->subDays(BillVideo::RETAIN_DAYS);
        $videos = BillVideo::withoutGlobalScopes()->where('created_at', '<', $videoCutoff)->get();
        foreach ($videos as $video) {
            $converter->delete($video->path);
            $video->delete();
        }

        $photoCutoff = now()->subDays(BillPhoto::RETAIN_DAYS);
        $expiredPhotos = BillPhoto::withoutGlobalScopes()->where('created_at', '<', $photoCutoff)->get();
        foreach ($expiredPhotos as $photo) {
            $photos->delete($photo->path);
            $photo->delete();
        }

        $this->info('Removed '.$videos->count().' expired job videos and '.$expiredPhotos->count().' expired job photos.');

        return self::SUCCESS;
    }
}
