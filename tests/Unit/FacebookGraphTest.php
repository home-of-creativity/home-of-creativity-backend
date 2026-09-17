<?php

namespace Tests\Unit;

use App\Services\FacebookGraph;
use PHPUnit\Framework\TestCase;

class FacebookGraphTest extends TestCase
{
    public function test_error_message_prefers_graph_error_message(): void
    {
        $this->assertSame(
            'Page token expired',
            FacebookGraph::errorMessage(['error' => ['message' => 'Page token expired']], 400),
        );
    }

    public function test_error_message_reads_rupload_debug_info(): void
    {
        $this->assertSame(
            'File size does not match',
            FacebookGraph::errorMessage([
                'debug_info' => [
                    'retriable' => false,
                    'type' => 'ProcessingFailedError',
                    'message' => 'File size does not match',
                ],
            ], 400),
        );
    }

    public function test_error_message_falls_back_to_status(): void
    {
        $this->assertSame('Facebook Graph HTTP 400', FacebookGraph::errorMessage(null, 400));
    }
}
