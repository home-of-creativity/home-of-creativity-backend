<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Client;
use App\Models\Employee;
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

        $recent = ServiceRequest::query()
            ->with('client')
            ->latest('id')
            ->limit(6)
            ->get();

        return response()->json([
            'data' => [
                'clients' => Client::query()->visibleOnDashboard()->count(),
                'requests' => ServiceRequest::query()->count(),
                'pending_employees' => Employee::query()->where('status', EmployeeStatus::Pending)->count(),
                'by_status' => $byStatus,
                'recent' => ServiceRequestResource::collection($recent)->resolve(),
            ],
            'message' => 'ok',
        ]);
    }
}
