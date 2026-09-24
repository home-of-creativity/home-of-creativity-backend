<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendContactMessageRequest;
use App\Http\Resources\ContactChannelResource;
use App\Mail\ContactInquiry;
use App\Models\ContactChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    public function send(SendContactMessageRequest $request): JsonResponse
    {
        if ((string) config('mail.mailers.contact.password') === '') {
            return response()->json([
                'data' => null,
                'message' => 'mail_not_configured',
            ], 503);
        }

        $data = $request->validated();
        $support = ($data['interest'] ?? '') === 'support';
        $to = (string) ($support ? config('services.contact.support') : config('services.contact.sales'));
        $cc = (string) config('services.contact.info');
        $locale = ($data['locale'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $subject = $locale === 'en' ? 'Website contact' : 'تواصل من الموقع';

        try {
            Mail::mailer('contact')->send(new ContactInquiry(
                senderName: $data['name'],
                senderEmail: $data['email'],
                phone: (string) ($data['phone'] ?? ''),
                interestLabel: (string) ($data['interest_label'] ?? ''),
                inquiry: $data['message'],
                toAddress: $to,
                ccAddress: $cc,
                subjectLine: $subject,
            ));
        } catch (\Throwable $exception) {
            Log::error('Website contact email failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'data' => null,
                'message' => 'mail_failed',
            ], 502);
        }

        return response()->json([
            'data' => ['to' => $support ? 'support' : 'sales'],
            'message' => 'ok',
        ]);
    }
}
