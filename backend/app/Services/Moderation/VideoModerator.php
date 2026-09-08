<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Prepares a video for moderation by sampling frames across its length.
 *
 * The moderation model does not accept video, so the honest approach is to
 * turn the video into images it *can* judge. Frames are taken at evenly
 * spaced points rather than from the thumbnail alone, specifically so a
 * clean opening shot cannot be used to smuggle prohibited content into the
 * middle or end of a clip.
 *
 * Requires ffmpeg. When it is missing, `extractFrames` reports that clearly
 * so the caller can hold the upload for human review rather than assume the
 * video is fine.
 */
class VideoModerator
{
    /**
     * @return array{available: bool, frames: list<string>, reason: ?string}
     *                                                                       `frames` are data URIs ready for the moderation endpoint.
     */
    public function extractFrames(string $absolutePath, ?int $frameCount = null): array
    {
        $count = $frameCount ?? (int) config('services.moderation.video_frames', 5);
        $count = max(1, min($count, 12));

        if (! $this->ffmpegAvailable()) {
            return ['available' => false, 'frames' => [], 'reason' => 'ffmpeg_missing'];
        }

        $duration = $this->durationSeconds($absolutePath);
        if ($duration === null || $duration <= 0) {
            return ['available' => false, 'frames' => [], 'reason' => 'duration_unknown'];
        }

        $frames = [];
        $workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arucad-mod-'.Str::uuid();
        @mkdir($workDir, 0700, true);

        try {
            foreach ($this->sampleOffsets($duration, $count) as $index => $offset) {
                $target = $workDir.DIRECTORY_SEPARATOR.'frame-'.$index.'.jpg';
                $process = new Process([
                    $this->ffmpegPath(),
                    '-ss', (string) $offset,
                    '-i', $absolutePath,
                    '-frames:v', '1',
                    // Downscale: moderation needs the subject, not 4K detail,
                    // and smaller frames keep the request well inside limits.
                    '-vf', 'scale=640:-1',
                    '-q:v', '4',
                    '-y', $target,
                ]);
                $process->setTimeout(20);
                $process->run();

                if (! $process->isSuccessful() || ! is_file($target)) {
                    continue;
                }
                $bytes = @file_get_contents($target);
                if ($bytes !== false && $bytes !== '') {
                    $frames[] = 'data:image/jpeg;base64,'.base64_encode($bytes);
                }
            }
        } catch (ProcessFailedException|\Throwable $e) {
            Log::warning('moderation.video_frames_failed', ['message' => $e->getMessage()]);
        } finally {
            $this->cleanup($workDir);
        }

        if ($frames === []) {
            return ['available' => false, 'frames' => [], 'reason' => 'no_frames'];
        }

        return ['available' => true, 'frames' => $frames, 'reason' => null];
    }

    /**
     * Evenly spaced sample points, nudged inside the clip so the very first
     * and last frames (often black or a title card) are not what gets judged.
     *
     * @return list<float>
     */
    private function sampleOffsets(float $duration, int $count): array
    {
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $fraction = ($i + 0.5) / $count;
            $offsets[] = round($duration * $fraction, 2);
        }

        return $offsets;
    }

    public function ffmpegAvailable(): bool
    {
        try {
            $process = new Process([$this->ffmpegPath(), '-version']);
            $process->setTimeout(8);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clip length in seconds, or null when it cannot be determined.
     *
     * Public because the upload path needs it before moderation: an
     * over-long video should be refused with a clear reason rather than
     * spending frame extraction on it first. Null is not treated as "fine"
     * anywhere — an unreadable duration means frame extraction will fail
     * too, and that path holds the upload.
     */
    public function durationSeconds(string $path): ?float
    {
        try {
            // ffprobe ships with ffmpeg; fall back to parsing ffmpeg output
            // when only the encoder binary is on PATH.
            $probe = new Process([
                $this->ffprobePath(),
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ]);
            $probe->setTimeout(10);
            $probe->run();
            if ($probe->isSuccessful()) {
                $value = (float) trim($probe->getOutput());
                if ($value > 0) {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    private function ffmpegPath(): string
    {
        return (string) config('services.moderation.ffmpeg_path', 'ffmpeg');
    }

    private function ffprobePath(): string
    {
        $ffmpeg = $this->ffmpegPath();

        return str_ends_with($ffmpeg, 'ffmpeg')
            ? substr($ffmpeg, 0, -6).'ffprobe'
            : 'ffprobe';
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
