<?php

namespace App\Http\Controllers;

use App\Actions\ApplyClickUpMapping;
use App\Http\Requests\ClickUpMappingRequest;
use App\Http\Requests\ClickUpTasksRequest;
use App\Http\Requests\DrivePollRequest;
use App\Http\Requests\OdooInvoiceRequest;
use App\Http\Requests\OdooQuotationRequest;
use App\Http\Requests\TelegramNotifyRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\OpsSetting;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{
    public function quotation(OdooQuotationRequest $request, OdooClient $odoo): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()
            ->with('client')
            ->where('number', $request->validated('request_number'))
            ->firstOrFail();

        if (! $odoo->configured()) {
            return $this->placeholder($serviceRequest->number, 'Odoo');
        }

        $client = $request->validated('client') ?? [];
        try {
            $created = $odoo->createQuotation(
                (string) ($client['name'] ?? $serviceRequest->client?->name ?? $serviceRequest->number),
                $client['email'] ?? $serviceRequest->client?->email,
                $client['phone'] ?? $serviceRequest->client?->phone,
                $serviceRequest->number,
                (string) $request->validated('title'),
                existingPartnerId: $serviceRequest->client?->odoo_partner_id,
                opportunityId: $serviceRequest->client?->odoo_lead_id,
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo quotation failed.', [
                'request' => $serviceRequest->number,
                'error' => $exception->getMessage(),
            ]);

            return $this->placeholder($serviceRequest->number, 'Odoo');
        }

        $serviceRequest->forceFill(['odoo_quotation_id' => $created['odoo_quotation_id']])->save();
        $serviceRequest->client?->forceFill(['odoo_partner_id' => $created['odoo_partner_id']])->save();

        return response()->json([
            'data' => $created,
            'message' => 'Odoo quotation created.',
        ]);
    }

    public function invoice(OdooInvoiceRequest $request, OdooClient $odoo): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()
            ->with('client')
            ->where('number', $request->validated('request_number'))
            ->firstOrFail();

        if (! $odoo->configured()) {
            return response()->json([
                'data' => ['odoo_invoice_id' => 'INV-'.$serviceRequest->number],
                'message' => 'Odoo is not configured; placeholder IDs returned.',
            ]);
        }

        $partnerId = $request->validated('odoo_partner_id')
            ?? $serviceRequest->client?->odoo_partner_id;
        if (! is_string($partnerId) || $partnerId === '') {
            return response()->json([
                'data' => ['odoo_invoice_id' => 'INV-'.$serviceRequest->number],
                'message' => 'Odoo partner is missing; placeholder invoice returned.',
            ]);
        }

        try {
            $invoiceId = $odoo->createInvoice(
                $partnerId,
                $serviceRequest->number,
                $request->validated('odoo_quotation_id') ?? $serviceRequest->odoo_quotation_id,
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo invoice failed.', [
                'request' => $serviceRequest->number,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'data' => ['odoo_invoice_id' => 'INV-'.$serviceRequest->number],
                'message' => 'Odoo invoice failed; placeholder ID returned.',
            ]);
        }

        $serviceRequest->forceFill(['odoo_invoice_id' => $invoiceId])->save();

        return response()->json([
            'data' => ['odoo_invoice_id' => $invoiceId],
            'message' => 'Odoo invoice created.',
        ]);
    }

    public function tasks(ClickUpTasksRequest $request, ClickUpClient $clickUp): JsonResponse
    {
        $briefs = array_map(fn (array $brief): array => [
            'department' => $brief['department'],
            'brief' => (string) ($brief['brief'] ?? ''),
        ], $request->validated('briefs'));

        if (! $clickUp->configured()) {
            return response()->json([
                'data' => [
                    'briefs' => array_map(fn (array $brief): array => [
                        ...$brief,
                        'clickup_task_id' => 'CU-'.$request->validated('request_number').'-'.$brief['department'],
                    ], $briefs),
                ],
                'message' => 'ClickUp is not configured; placeholder IDs returned.',
            ]);
        }

        try {
            $created = $clickUp->createTasks($request->validated('request_number'), $briefs);
        } catch (\Throwable $exception) {
            Log::warning('ClickUp tasks failed.', [
                'request' => $request->validated('request_number'),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'data' => [
                    'briefs' => array_map(fn (array $brief): array => [
                        ...$brief,
                        'clickup_task_id' => 'CU-'.$request->validated('request_number').'-'.$brief['department'],
                    ], $briefs),
                ],
                'message' => 'ClickUp tasks failed; placeholder IDs returned.',
            ]);
        }

        return response()->json([
            'data' => [
                'briefs' => $created,
            ],
            'message' => 'ClickUp tasks created.',
        ]);
    }

    public function mapping(ClickUpMappingRequest $request, ApplyClickUpMapping $applyClickUpMapping): ServiceRequestResource
    {
        $serviceRequest = ServiceRequest::query()
            ->where('number', $request->validated('request_number'))
            ->firstOrFail();

        $updated = $applyClickUpMapping->handle($serviceRequest, $request->validated());

        return ServiceRequestResource::make($updated)
            ->additional(['message' => 'ClickUp mapping saved.']);
    }

    public function notify(TelegramNotifyRequest $request, TelegramNotifier $telegram): JsonResponse
    {
        $serviceRequest = ServiceRequest::query()
            ->with('client')
            ->where('number', $request->validated('request_number'))
            ->firstOrFail();

        $chatId = $request->validated('chat_id')
            ?: $serviceRequest->client?->telegram_user_id
            ?: (string) config('services.telegram.staff_chat_id');

        if (! $telegram->configured() || $chatId === '') {
            Log::warning('Telegram notify skipped.', [
                'request' => $serviceRequest->number,
            ]);

            return response()->json([
                'data' => ['chat_id' => $chatId, 'sent' => false],
                'message' => 'Telegram is not configured.',
            ]);
        }

        try {
            $telegram->send($chatId, $request->validated('text'));
        } catch (\Throwable $exception) {
            Log::warning('Telegram notify failed.', [
                'request' => $serviceRequest->number,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'data' => ['chat_id' => $chatId, 'sent' => false],
                'message' => 'Telegram notify failed.',
            ]);
        }

        return response()->json([
            'data' => ['chat_id' => $chatId, 'sent' => true],
            'message' => 'Telegram message sent.',
        ]);
    }

    public function pollDrive(DrivePollRequest $request, ResolveServiceRequest $resolveServiceRequest): JsonResponse
    {
        $number = trim((string) ($request->validated('request_number') ?? ''));
        $folderId = trim((string) ($request->validated('drive_folder_id') ?? ''));

        $serviceRequest = null;
        if ($number !== '') {
            try {
                $serviceRequest = $resolveServiceRequest->byReference($number);
            } catch (\Throwable) {
                $serviceRequest = ServiceRequest::query()->where('number', $number)->first();
            }
        }

        if ($serviceRequest === null && $folderId !== '') {
            $serviceRequest = ServiceRequest::query()
                ->where('google_drive_folder_id', $folderId)
                ->orderByDesc('id')
                ->first();
        }

        $fileId = trim((string) ($request->validated('drive_file_id') ?? ''));
        $arguments = ['--limit' => 200];
        if ($serviceRequest !== null) {
            $arguments['--request'] = (string) $serviceRequest->id;
        }
        if ($fileId !== '') {
            $arguments['--file'] = $fileId;
        }

        Artisan::call('ops:poll-drive', $arguments);

        Log::info('n8n Drive poll ran.', [
            'request' => $serviceRequest?->number,
            'drive_folder_id' => $folderId !== '' ? $folderId : $serviceRequest?->google_drive_folder_id,
            'drive_file_id' => $fileId !== '' ? $fileId : null,
        ]);

        return response()->json([
            'data' => [
                'polled' => true,
                'scoped' => $serviceRequest !== null || $fileId !== '',
                'request_number' => $serviceRequest?->number,
                'drive_folder_id' => $serviceRequest?->google_drive_folder_id ?? ($folderId !== '' ? $folderId : null),
                'drive_file_id' => $fileId !== '' ? $fileId : null,
            ],
            'message' => $serviceRequest !== null || $fileId !== ''
                ? 'Drive file sent to the client bot.'
                : 'Live Drive folders polled for the client bot.',
        ]);
    }

    public function driveChanged(Request $request): JsonResponse
    {
        $expected = (string) (OpsSetting::getValue('drive_watch_token') ?: config('services.google.drive_watch_token'));
        $provided = (string) $request->header('X-Goog-Channel-Token', '');
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid Drive watch token.');
        }

        $state = strtolower((string) $request->header('X-Goog-Resource-State', 'change'));
        if ($state === 'sync') {
            return response()->json([
                'data' => ['ignored' => true],
                'message' => 'Drive watch sync acknowledged.',
            ]);
        }

        $fileId = trim((string) ($request->input('drive_file_id') ?? $request->input('id') ?? ''));
        $arguments = ['--limit' => 200];
        if ($fileId !== '') {
            $arguments['--file'] = $fileId;
        }

        Artisan::call('ops:poll-drive', $arguments);

        return response()->json([
            'data' => ['polled' => true, 'drive_file_id' => $fileId !== '' ? $fileId : null],
            'message' => 'Drive change polled for the client bot.',
        ]);
    }

    private function placeholder(string $number, string $service): JsonResponse
    {
        Log::warning($service.' is not configured; returning placeholder IDs.', [
            'request' => $number,
        ]);

        return response()->json([
            'data' => [
                'odoo_partner_id' => 'P-'.$number,
                'odoo_quotation_id' => 'Q-'.$number,
            ],
            'message' => $service.' is not configured; placeholder IDs returned.',
        ]);
    }
}
