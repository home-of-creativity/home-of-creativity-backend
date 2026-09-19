<?php

namespace Tests\Unit;

use App\Support\VideoFaststart;
use Tests\TestCase;

class VideoFaststartTest extends TestCase
{
    public function test_moov_after_mdat_needs_remux(): void
    {
        $path = $this->writeMp4([['ftyp', 'isom'], ['mdat', str_repeat('x', 64)], ['moov', 'index']]);

        $this->assertTrue(VideoFaststart::needsRemux($path));
    }

    public function test_moov_before_mdat_streams_from_first_frame(): void
    {
        $path = $this->writeMp4([['ftyp', 'isom'], ['moov', 'index'], ['mdat', str_repeat('x', 64)]]);

        $this->assertFalse(VideoFaststart::needsRemux($path));
    }

    public function test_non_mp4_payload_is_left_alone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        file_put_contents($path, 'fake video');

        $this->assertFalse(VideoFaststart::needsRemux($path));

        @unlink($path);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $boxes
     */
    private function writeMp4(array $boxes): string
    {
        $payload = '';

        foreach ($boxes as [$type, $body]) {
            $payload .= pack('N', 8 + strlen($body)).$type.$body;
        }

        $path = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        file_put_contents($path, $payload);

        return $path;
    }
}
