<?php

namespace App\Console\Commands;

use App\Models\LandingReel;
use App\Support\VideoFaststart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FaststartReelsCommand extends Command
{
    protected $signature = 'reels:faststart';

    protected $description = 'Move the MP4 index to the front of every stored reel so clips play while they download';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $fixed = 0;
        $skipped = 0;

        foreach (LandingReel::query()->orderBy('id')->get() as $reel) {
            $path = $reel->video_path;

            if (! $path || ! $disk->exists($path)) {
                $this->warn('missing: '.($path ?? '—'));

                continue;
            }

            if (! VideoFaststart::needsRemux($disk->path($path))) {
                $skipped++;

                continue;
            }

            VideoFaststart::apply($path);

            if (VideoFaststart::needsRemux($disk->path($path))) {
                $this->error('failed: '.$path);

                continue;
            }

            $this->line('faststart: '.$path);
            $fixed++;
        }

        $this->info("Remuxed {$fixed} reel(s); {$skipped} already streamed from the first frame.");

        return self::SUCCESS;
    }
}
