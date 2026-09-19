<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use App\Models\Client;
use App\Models\OpsFollowUp;
use App\Models\RequestFile;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Models\SupportMessage;
use App\Services\TelegramNotifier;
use App\Support\Money;
use App\Support\ResolveServiceRequest;
use App\Support\ShamCashQr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBotSla
{
    public function __construct(
        private TelegramNotifier $telegram,
        private NotifyEmployees $notifyEmployees,
        private NotifyPaymentStage $notifyPaymentStage,
    ) {}

    /**
     * @return array<string, int>
     */
    public function handle(int $limit = 50): array
    {
        return [
            OpsFollowUp::KIND_QUOTE_WAITING => $this->quoteWaiting($limit),
            OpsFollowUp::KIND_RECEIPT_WAITING => $this->receiptWaiting($limit),
            OpsFollowUp::KIND_RECEIPT_WAITING_SALES => $this->receiptWaitingSales($limit),
            OpsFollowUp::KIND_RECEIPT_UNCONFIRMED => $this->receiptUnconfirmed($limit),
            OpsFollowUp::KIND_REVISION_STALE => $this->revisionStale($limit),
            OpsFollowUp::KIND_SUPPORT_STALE => $this->supportStale($limit),
            OpsFollowUp::KIND_PROFILE_INCOMPLETE => $this->profileIncomplete($limit),
            OpsFollowUp::KIND_SALES_DIGEST => $this->salesDigest(),
        ];
    }

    private function quoteWaiting(int $limit): int
    {
        $hours = $this->hours('quote_waiting_hours', 2);
        $sent = 0;

        $requests = ServiceRequest::query()
            ->with('client')
            ->where('status', RequestStatus::Submitted)
            ->where('created_at', '<=', now()->subHours($hours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($requests as $request) {
            if (! $this->claim($request, OpsFollowUp::KIND_QUOTE_WAITING, 'request:'.$request->id)) {
                continue;
            }

            $ref = ResolveServiceRequest::displayNumber($request);
            $who = $this->who($request);
            $contact = $request->client?->telegramContactLine();
            $text = implode("\n", array_values(array_filter([
                'طلب بلا عرض سعر',
                "#{$ref} — {$request->title}",
                $who,
                "منذ {$hours} ساعات على الأقل — الزبون ينتظر العرض.",
                $contact,
            ])));

            $this->notifyEmployees->handle($request, EmployeeProfession::Sales, $text);
            $sent++;
        }

        return $sent;
    }

    private function receiptWaiting(int $limit): int
    {
        $hours = $this->hours('receipt_waiting_hours', 12);
        $sent = 0;

        foreach ($this->awaitingPaymentWithoutReceipt($hours, $limit) as $request) {
            $chatId = $request->client?->telegram_user_id;
            if (! filled($chatId) || ! $this->telegram->configured('client')) {
                continue;
            }
            if (! $this->claim($request, OpsFollowUp::KIND_RECEIPT_WAITING, 'request:'.$request->id)) {
                continue;
            }

            $ref = ResolveServiceRequest::displayNumber($request);
            $due = Money::format($request->expectedDue());
            $caption = "تذكير برفع وصل التحويل للطلب #{$ref}.\nالمطلوب: {$due}\nحوّل عبر شام كاش ثم أرسل صورة أو PDF الوصل من البوت.";

            try {
                $qrPath = ShamCashQr::relativePath();
                if (filled($qrPath)) {
                    $this->telegram->sendPaymentQr((string) $chatId, $caption, $qrPath);
                } else {
                    $this->telegram->send((string) $chatId, $caption);
                }
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Bot SLA receipt reminder failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    private function receiptWaitingSales(int $limit): int
    {
        $hours = $this->hours('receipt_waiting_sales_hours', 24);
        $sent = 0;

        foreach ($this->awaitingPaymentWithoutReceipt($hours, $limit) as $request) {
            if (! $this->claim($request, OpsFollowUp::KIND_RECEIPT_WAITING_SALES, 'request:'.$request->id)) {
                continue;
            }

            $ref = ResolveServiceRequest::displayNumber($request);
            $due = number_format($request->expectedDue(), 2);
            $text = implode("\n", array_values(array_filter([
                'وافق ولم يرفع وصلاً',
                "#{$ref} — {$request->title}",
                $this->who($request),
                "المبلغ المتوقع: {$due} USD",
                "مرّت {$hours} ساعة بلا وصل.",
                $request->client?->telegramContactLine(),
            ])));

            $this->notifyEmployees->handle($request, EmployeeProfession::Sales, $text);
            $sent++;
        }

        return $sent;
    }

    private function receiptUnconfirmed(int $limit): int
    {
        $hours = $this->hours('receipt_unconfirmed_hours', 3);
        $cutoff = now()->subHours($hours);
        $sent = 0;

        $requests = ServiceRequest::query()
            ->with(['client', 'pricingPackage', 'files' => fn ($query) => $query
                ->where('kind', 'payment_receipt')
                ->latest('id')])
            ->where('status', RequestStatus::AwaitingPayment)
            ->whereHas('files', fn ($query) => $query->where('kind', 'payment_receipt'))
            ->orderBy('id')
            ->limit($limit * 3)
            ->get()
            ->filter(function (ServiceRequest $request) use ($cutoff): bool {
                $receipt = $request->files->first();

                return $receipt instanceof RequestFile
                    && $receipt->created_at !== null
                    && $receipt->created_at->lte($cutoff);
            })
            ->take($limit);

        foreach ($requests as $request) {
            if (! $this->claim($request, OpsFollowUp::KIND_RECEIPT_UNCONFIRMED, 'request:'.$request->id)) {
                continue;
            }

            $receipt = $request->files->first();
            $this->notifyPaymentStage->handle(
                $request,
                $receipt instanceof RequestFile ? $receipt : null,
                "وصل مرفوع بلا تأكيد منذ {$hours} ساعات — أعد تأكيد المبلغ المتوقع.",
            );
            $sent++;
        }

        return $sent;
    }

    private function revisionStale(int $limit): int
    {
        $hours = $this->hours('revision_stale_hours', 1);
        $cutoff = now()->subHours($hours);
        $sent = 0;

        $requests = ServiceRequest::query()
            ->with(['client', 'revisions' => fn ($query) => $query->where('status', 'open')->latest('id')])
            ->where('status', RequestStatus::RevisionRequested)
            ->whereHas('revisions', fn ($query) => $query
                ->where('status', 'open')
                ->where('created_at', '<=', $cutoff))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($requests as $request) {
            $revision = $request->revisions->first();
            $revisionId = $revision instanceof Revision ? $revision->id : 0;
            if (! $this->claim($request, OpsFollowUp::KIND_REVISION_STALE, 'request:'.$request->id.':revision:'.$revisionId)) {
                continue;
            }

            $ref = ResolveServiceRequest::displayNumber($request);
            $comment = $revision instanceof Revision ? $revision->comments : '';
            $text = implode("\n", array_values(array_filter([
                'تعديل بلا رد منذ ساعة',
                "#{$ref} — {$request->title}",
                $this->who($request),
                $comment !== '' ? $comment : null,
                $request->client?->telegramContactLine(),
            ])));

            foreach ($this->productionProfessions($request) as $profession) {
                $this->notifyEmployees->handle($request, $profession, $text);
            }
            $this->notifyEmployees->handle($request, EmployeeProfession::Sales, $text);
            $sent++;
        }

        return $sent;
    }

    private function supportStale(int $limit): int
    {
        $hours = $this->hours('support_stale_hours', 1);
        $cutoff = now()->subHours($hours);
        $sent = 0;

        $messages = SupportMessage::query()
            ->with(['client', 'request.client', 'request.statusHistory'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            if ($this->supportWasHandled($message)) {
                continue;
            }
            if (! OpsFollowUp::claim(
                OpsFollowUp::KIND_SUPPORT_STALE,
                'support:'.$message->id,
                $message->request ?? $message->client,
            )) {
                continue;
            }

            $who = trim(($message->client?->name ?? '').($message->client?->company_name ? ' ('.$message->client->company_name.')' : ''));
            $ref = $message->request?->number;
            $text = 'رسالة دعم بلا رد منذ ساعة'."\n".($who !== '' ? $who : 'عميل تيليجرام');
            if (filled($ref)) {
                $text .= "\n#{$ref}";
            }
            $text .= "\n\n".$message->message;
            $contact = $message->client?->telegramContactLine();
            if (filled($contact)) {
                $text .= "\n".$contact;
            }

            if ($message->request) {
                $this->notifyEmployees->handle($message->request, EmployeeProfession::Sales, $text);
            } else {
                $this->notifyEmployees->handlePlain(EmployeeProfession::Sales, $text);
            }
            $sent++;
        }

        return $sent;
    }

    private function profileIncomplete(int $limit): int
    {
        $hours = $this->hours('profile_incomplete_hours', 24);
        $sent = 0;

        $clients = Client::query()
            ->whereNotNull('telegram_user_id')
            ->where('telegram_user_id', '!=', '')
            ->where('created_at', '<=', now()->subHours($hours))
            ->where(function ($query): void {
                $query->whereNull('name')->orWhere('name', '')
                    ->orWhereNull('phone')->orWhere('phone', '')
                    ->orWhereNull('company_name')->orWhere('company_name', '');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($clients as $client) {
            if ($client->profileComplete()) {
                continue;
            }
            $chatId = $client->telegram_user_id;
            if (! filled($chatId) || ! $this->telegram->configured('client')) {
                continue;
            }
            if (! OpsFollowUp::claim(OpsFollowUp::KIND_PROFILE_INCOMPLETE, 'client:'.$client->id, $client)) {
                continue;
            }

            try {
                $this->telegram->send(
                    (string) $chatId,
                    'أكمل ملفك في البوت (الاسم، الهاتف، الشركة) حتى نقدر نبدأ طلبك.',
                );
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Bot SLA profile reminder failed.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    private function salesDigest(): int
    {
        $hour = max(0, min(23, (int) config('services.telegram.sla.sales_digest_hour', 9)));
        $now = now('Asia/Damascus');
        if ($now->hour < $hour) {
            return 0;
        }

        $date = $now->toDateString();
        if (! OpsFollowUp::claim(OpsFollowUp::KIND_SALES_DIGEST, 'date:'.$date)) {
            return 0;
        }

        $quoteWaiting = ServiceRequest::query()->where('status', RequestStatus::Submitted)->count();
        $receiptWaiting = ServiceRequest::query()
            ->where('status', RequestStatus::AwaitingPayment)
            ->whereDoesntHave('files', fn ($query) => $query->where('kind', 'payment_receipt'))
            ->count();
        $receiptsToReview = ServiceRequest::query()
            ->where('status', RequestStatus::AwaitingPayment)
            ->whereHas('files', fn ($query) => $query->where('kind', 'payment_receipt'))
            ->count();
        $openRevisions = ServiceRequest::query()->where('status', RequestStatus::RevisionRequested)->count();

        $text = implode("\n", [
            'هضم المبيعات — '.$now->format('Y-m-d'),
            "بانتظار عرض: {$quoteWaiting}",
            "بانتظار وصل: {$receiptWaiting}",
            "وصولات للمراجعة: {$receiptsToReview}",
            "تعديلات مفتوحة: {$openRevisions}",
        ]);

        $this->notifyEmployees->handlePlain(EmployeeProfession::Sales, $text);

        return 1;
    }

    /**
     * @return list<ServiceRequest>
     */
    private function awaitingPaymentWithoutReceipt(int $hours, int $limit): array
    {
        $cutoff = now()->subHours($hours);

        return ServiceRequest::query()
            ->with(['client', 'statusHistory'])
            ->where('status', RequestStatus::AwaitingPayment)
            ->whereDoesntHave('files', fn ($query) => $query->where('kind', 'payment_receipt'))
            ->orderBy('id')
            ->limit($limit * 3)
            ->get()
            ->filter(fn (ServiceRequest $request): bool => $this->enteredStatusAt($request, RequestStatus::AwaitingPayment)->lte($cutoff))
            ->take($limit)
            ->all();
    }

    private function enteredStatusAt(ServiceRequest $request, RequestStatus $status): Carbon
    {
        $at = $request->statusHistory
            ->where('to_status', $status->value)
            ->sortByDesc('id')
            ->first()
            ?->created_at;

        return $at instanceof Carbon ? $at : ($request->updated_at ?? now());
    }

    private function supportWasHandled(SupportMessage $message): bool
    {
        $request = $message->request;
        if (! $request instanceof ServiceRequest) {
            return false;
        }

        if (in_array($request->status, [RequestStatus::Completed, RequestStatus::Cancelled], true)) {
            return true;
        }

        return $request->statusHistory
            ->contains(fn ($history): bool => $history->created_at !== null
                && $history->created_at->gt($message->created_at));
    }

    /**
     * @return list<EmployeeProfession>
     */
    private function productionProfessions(ServiceRequest $request): array
    {
        $map = [
            'design' => EmployeeProfession::Design,
            'content' => EmployeeProfession::Content,
            'programming' => EmployeeProfession::Web,
            'photography' => EmployeeProfession::Media,
        ];
        $found = [];
        foreach ($request->plannedDepartments() as $department) {
            if (isset($map[$department]) && ! in_array($map[$department], $found, true)) {
                $found[] = $map[$department];
            }
        }

        if ($found === [] && $request->work_type instanceof WorkType) {
            foreach ($request->work_type->requiredBriefTypes() as $department) {
                if (isset($map[$department]) && ! in_array($map[$department], $found, true)) {
                    $found[] = $map[$department];
                }
            }
        }

        return $found;
    }

    private function claim(ServiceRequest $request, string $kind, string $dedupeKey): bool
    {
        return OpsFollowUp::claim($kind, $dedupeKey, $request);
    }

    private function who(ServiceRequest $request): string
    {
        $who = trim(($request->client?->name ?? '').($request->client?->company_name ? ' — '.$request->client->company_name : ''));

        return $who !== '' ? $who : 'عميل تيليجرام';
    }

    private function hours(string $key, int $default): int
    {
        $value = (int) config('services.telegram.sla.'.$key, $default);

        return max(1, $value);
    }
}
