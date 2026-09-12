<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactChannelResource;
use App\Models\ContactChannel;

class ContactController extends Controller
{
    public function index()
    {
        $items = ContactChannel::query()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('kind');

        return response()->json([
            'data' => [
                'mobile' => ContactChannelResource::collection($items->get('mobile', collect())),
                'whatsapp' => ContactChannelResource::collection($items->get('whatsapp', collect())),
                'social' => ContactChannelResource::collection($items->get('social', collect())),
                'location' => ContactChannelResource::collection($items->get('location', collect())),
            ],
            'message' => 'ok',
        ]);
    }
}
