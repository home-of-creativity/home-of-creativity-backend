<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStatus = ServiceRequest::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'clients' => Client::query()->visibleOnDashboard()->count(),
                'requests' => ServiceRequest::query()->count(),
                'by_status' => $byStatus,
            ],
            'message' => 'ok',
        ]);
    }
}
