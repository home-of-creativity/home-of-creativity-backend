<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\OdooLeadNote;
use App\Models\ServiceRequest;
use App\Support\BillingPeriod;
use App\Support\Money;
use App\Support\ResolveServiceRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every line is stored in Laravel first. Posting to the client's crm.lead
 * happens after the response and again from odoo:reconcile, so a closed or
 * slow Odoo never blocks a bot or dashboard action.
 */
class OdooLeadLog
{
    private bool $flushQueued = false;

    public function __construct(private OdooClient $odoo) {}

    public function requestCreated(ServiceRequest $request): void
    {
        $request->loadMissing(['client', 'pricingPackage']);
        $package = $request->pricingPackage?->name_ar ?: $request->pricingPackage?->name_en;
        $period = filled($request->billing_period) ? ' — '.BillingPeriod::labelAr((string) $request->billing_period) : '';

        $lines = [
            'طلب جديد — '.$this->requestLine($request),
            'الباقة: '.($package ?: 'طلب يدوي').$period,
        ];
        $chat = $request->client?->telegramPrivateUrl();
        if (filled($chat)) {
            $lines[] = 'تواصل: '.$chat;
        }

        $this->record($request, $lines, 'request:'.$request->id);
    }

    public function statusChanged(ServiceRequest $request, RequestStatus $to, ?string $actor, ?string $note, int $historyId): void
    {
        $lines = [
            $this->requestLine($request),
            'الحالة: '.$to->labelAr(),
        ];
        $by = $this->actorLabel($actor);
        if ($by !== null) {
            $lines[] = 'بواسطة: '.$by;
        }
        if (in_array($to, [RequestStatus::QuotationRejected, RequestStatus::RevisionRequested, RequestStatus::Cancelled], true)
            && filled($note)
            && preg_match('/\p{Arabic}/u', (string) $note) === 1) {
            $lines[] = trim((string) $note);
        }

        $this->record($request, $lines, 'status:'.$historyId);
    }

    public function quotationSent(ServiceRequest $request, float $amount, int $version): void
    {
        $this->record($request, [
            'عرض سعر — '.$this->requestLine($request),
            'المبلغ: '.Money::format($amount).($version > 1 ? " (نسخة {$version})" : ''),
        ], "quotation:{$request->id}:{$version}");
    }

    public function paymentReceived(ServiceRequest $request, float $applied, PaymentMethod $method): void
    {
        $paid = (float) ($request->amount_paid ?? 0);
        $remaining = (float) ($request->amount_remaining ?? 0);

        $this->record($request, [
            'دفعة — '.$this->requestLine($request),
            'المقبوض الآن: '.Money::format($applied).' — '.($method === PaymentMethod::Cash ? 'نقداً' : 'بوصل تحويل'),
            'مجموع المدفوع: '.Money::format($paid).' — المتبقي: '.Money::format($remaining),
        ]);
    }

    public function receiptUploaded(ServiceRequest $request): void
    {
        $this->record($request, ['رفع الزبون وصل دفع — '.$this->requestLine($request)]);
    }

    public function driveFolderReady(ServiceRequest $request): void
    {
        if (! filled($request->google_drive_folder_id)) {
            return;
        }

        $this->record($request, [
            'مجلد درايف — '.$this->requestLine($request),
            'https://drive.google.com/drive/folders/'.$request->google_drive_folder_id,
        ], "drive-folder:{$request->id}:{$request->google_drive_folder_id}");
    }

    public function fileSent(ServiceRequest $request, DriveDelivery $delivery): void
    {
        $this->record($request, [
            'وصل ملف للزبون — '.$this->requestLine($request),
            (string) ($delivery->name ?: $delivery->drive_file_id),
        ], 'drive-sent:'.$delivery->id.':'.($delivery->content_hash ?: $delivery->drive_modified_at?->timestamp ?: 'x'));
    }

    public function fileApproved(ServiceRequest $request, DriveDelivery $delivery): void
    {
        $this->record($request, [
            'وافق الزبون على ملف — '.$this->requestLine($request),
            (string) ($delivery->name ?: $delivery->drive_file_id),
        ], 'drive-ok:'.$delivery->id.':'.($delivery->client_approved_at?->timestamp ?? 'x'));
    }

    public function renewal(ServiceRequest $request, bool $renewing): void
    {
        $ends = $request->subscription_ends_at?->timezone('Asia/Damascus')->format('Y-m-d');
        $this->record($request, [
            ($renewing ? 'طلب الزبون التجديد — ' : 'لن يجدد الزبون — ').$this->requestLine($request),
            ...($renewing || $ends === null ? [] : ['ينتهي الاشتراك في '.$ends]),
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    public function record(Client|ServiceRequest $subject, array $lines, ?string $dedupeKey = null): void
    {
        try {
            $request = $subject instanceof ServiceRequest ? $subject : null;
            $clientId = $request?->client_id ?? ($subject instanceof Client ? $subject->id : null);
            $body = trim(implode("\n", array_filter($lines, fn (string $line): bool => trim($line) !== '')));
            if (! $clientId || $body === '') {
                return;
            }
            if ($dedupeKey !== null && OdooLeadNote::query()->where('dedupe_key', $dedupeKey)->exists()) {
                return;
            }

            OdooLeadNote::query()->create([
                'client_id' => $clientId,
                'request_id' => $request?->id,
                'dedupe_key' => $dedupeKey,
                'body' => $body,
            ]);
            $this->queueFlush();
        } catch (Throwable $exception) {
            Log::warning('Odoo lead note was not stored.', ['error' => $exception->getMessage()]);
        }
    }

    public function flush(int $limit = 50): int
    {
        if (! $this->odoo->configured()) {
            return 0;
        }

        $notes = OdooLeadNote::query()
            ->whereNull('posted_at')
            ->where('attempts', '<', OdooLeadNote::MAX_ATTEMPTS)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->whereHas('client', function ($query): void {
                $query->whereNotNull('odoo_lead_id')->where('odoo_lead_id', '!=', '');
            })
            ->with('client')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $posted = 0;
        foreach ($notes as $note) {
            try {
                $this->odoo->postLeadNote((int) $note->client->odoo_lead_id, $this->outgoingBody($note));
                $note->forceFill(['posted_at' => now(), 'last_error' => null])->save();
                $posted++;
            } catch (Throwable $exception) {
                $attempts = $note->attempts + 1;
                $note->forceFill([
                    'attempts' => $attempts,
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($attempts, 6))),
                ])->save();

                if ($exception instanceof ConnectionException || $exception instanceof RequestException) {
                    break;
                }
            }
        }

        return $posted;
    }

    public function pendingCount(): int
    {
        return OdooLeadNote::query()
            ->whereNull('posted_at')
            ->where('attempts', '<', OdooLeadNote::MAX_ATTEMPTS)
            ->count();
    }

    private function queueFlush(): void
    {
        if ($this->flushQueued || ! $this->odoo->configured()) {
            return;
        }

        $this->flushQueued = true;
        app()->terminating(function (): void {
            $this->flushQueued = false;
            try {
                $this->flush(20);
            } catch (Throwable $exception) {
                Log::warning('Odoo lead notes flush failed.', ['error' => $exception->getMessage()]);
            }
        });
    }

    private function outgoingBody(OdooLeadNote $note): string
    {
        $created = $note->created_at instanceof Carbon ? $note->created_at : null;
        if ($created !== null && $created->lt(now()->subMinutes(5))) {
            return $note->body."\nسُجّل في: ".$created->copy()->timezone('Asia/Damascus')->format('Y-m-d H:i');
        }

        return $note->body;
    }

    private function requestLine(ServiceRequest $request): string
    {
        return 'الطلب #'.ResolveServiceRequest::displayNumber($request).' — '.$request->title;
    }

    private function actorLabel(?string $actor): ?string
    {
        $actor = trim((string) $actor);

        return match (true) {
            $actor === '' => null,
            $actor === 'client', str_starts_with($actor, 'client:') => 'الزبون',
            str_starts_with($actor, 'manual:') => 'اللوحة نيابة عن الزبون',
            $actor === 'admin', str_starts_with($actor, 'admin:') => 'اللوحة',
            $actor === 'system' => 'النظام',
            $actor === 'drive' => 'ملفات درايف',
            str_starts_with($actor, 'staff:') => 'الموظف '.substr($actor, 6),
            default => $actor,
        };
    }
}
