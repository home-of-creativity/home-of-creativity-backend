<?php

namespace App\Http\Controllers;

use App\Actions\RepairWhatsAppBridge;
use Illuminate\Http\JsonResponse;

class WhatsAppBridgeRepairController extends Controller
{
    public function __invoke(RepairWhatsAppBridge $repair): JsonResponse
    {
        return response()->json($repair->repair());
    }
}
