<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactChannelRequest;
use App\Http\Requests\UpdateContactChannelRequest;
use App\Http\Resources\ContactChannelResource;
use App\Models\ContactChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactChannelController extends Controller
{
    public function index()
    {
        return ContactChannelResource::collection(
            ContactChannel::query()
                ->orderByRaw("CASE kind WHEN 'mobile' THEN 1 WHEN 'whatsapp' THEN 2 WHEN 'social' THEN 3 ELSE 4 END")
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function store(StoreContactChannelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) ContactChannel::query()
            ->where('kind', $data['kind'])
            ->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;

        $channel = ContactChannel::query()->create($data);

        return ContactChannelResource::make($channel)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateContactChannelRequest $request, ContactChannel $contactChannel): ContactChannelResource
    {
        $contactChannel->fill($request->validated())->save();

        return ContactChannelResource::make($contactChannel->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(ContactChannel $contactChannel)
    {
        $contactChannel->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function move(Request $request, ContactChannel $contactChannel)
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $items = ContactChannel::query()
            ->where('kind', $contactChannel->kind)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $index = $items->search(fn (ContactChannel $item) => $item->id === $contactChannel->id);
        if ($index === false) {
            abort(404);
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex >= 0 && $swapIndex < $items->count()) {
            $current = $items[$index];
            $neighbor = $items[$swapIndex];
            $currentOrder = $current->sort_order;
            $current->forceFill(['sort_order' => $neighbor->sort_order])->save();
            $neighbor->forceFill(['sort_order' => $currentOrder])->save();
        }

        return ContactChannelResource::collection(
            ContactChannel::query()
                ->orderByRaw("CASE kind WHEN 'mobile' THEN 1 WHEN 'whatsapp' THEN 2 WHEN 'social' THEN 3 ELSE 4 END")
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        )->additional(['message' => 'Contact order updated.']);
    }
}
