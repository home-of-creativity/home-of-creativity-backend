<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Services\GeminiService;
use App\Services\SiteGuide;
use App\Support\BillingPeriod;
use App\Support\ClientChannelGate;
use App\Support\ClientReplyGuard;
use App\Support\ResolveServiceRequest;
use App\Support\WorkCalendar;
use App\Support\WorkLines;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The client bot's free-text desk. Gemini reads this client's own records, the
 * list of bot operations, and the published company brief, then answers, picks
 * one operation, or hands the message to the developer by email.
 */
class ClientAssistant
{
    public function __construct(
        private GeminiService $gemini,
        private SiteGuide $siteGuide,
        private EscalateClientMessage $escalate,
        private BookPhotographySlot $photography,
        private PhotographySessions $sessions,
        private WorkCalendar $calendar,
        private ClientReplyGuard $replies,
    ) {}

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{action: string, ref: string, answer: string, reason: string}
     */
    public function decide(Client $client, string $message, array $history, string $locale, string $step, string $channel): array
    {
        $locale = $locale === 'en' ? 'en' : 'ar';
        try {
            $brief = $this->siteGuide->brief();
        } catch (Throwable) {
            $brief = '';
        }

        $decision = $this->gemini->assistClient(
            $message,
            $this->records($client),
            $this->operations(),
            $brief,
            $history,
            $locale,
            $step === '' ? 'idle' : $step,
        );
        if ($decision === null) {
            $decision = [
                'action' => 'escalate',
                'ref' => '',
                'answer' => '',
                'reason' => 'The assistant could not read this message because Gemini did not answer.',
            ];
        }

        if ($decision['answer'] !== '' && ! $this->replies->allows($client, $decision['answer'])) {
            $decision['action'] = 'escalate';
            $decision['answer'] = '';
            $decision['reason'] = 'The reply was blocked because it included data outside this client.';
        }

        if ($decision['action'] === 'escalate') {
            $this->escalate->handle($client, $message, $decision['reason'], $history, $channel, $step);
            if ($decision['answer'] === '') {
                $phone = ClientChannelGate::SUPPORT_PHONE;
                $decision['answer'] = $locale === 'en'
                    ? "We have your message. The team will follow up with you shortly.
If it is urgent, call {$phone}."
                    : "وصلتنا رسالتك، والفريق رح يتابعها معك ويرجعلك بأقرب وقت.
إذا مستعجل احكي معنا عالرقم {$phone}.";
            }
        }

        return $decision;
    }

    /**
     * Everything this client may know about their own account, as plain lines.
     */
    public function records(Client $client): string
    {
        $lines = [
            'Client: '.$client->name
                .' | company: '.($client->company_name ?: 'not given')
                .' | phone: '.($client->phone ?: 'not given')
                .' | email: '.($client->email ?: 'not given')
                .' | business: '.($client->company_activity ?: 'not given'),
        ];

        $requests = $client->requests()
            ->with(['pricingPackage', 'clickupTasks', 'subscriptions'])
            ->latest('id')
            ->limit(12)
            ->get()
            ->reject(fn (ServiceRequest $request): bool => $request->hiddenFromClient());
        if ($requests->isEmpty()) {
            $lines[] = 'Requests: none yet.';

            return implode("\n", $lines);
        }

        $bookings = $this->photography->clientBookings($client)->groupBy('request_id');
        $deliveries = DriveDelivery::query()
            ->whereIn('request_id', $requests->pluck('id'))
            ->whereNotNull('sent_at')
            ->get()
            ->groupBy('request_id');

        $waiting = [];
        $lines[] = 'Requests, newest first:';
        foreach ($requests as $request) {
            $ref = '#'.ResolveServiceRequest::displayNumber($request);
            $lines[] = $ref.' ('.$request->number.') "'.$request->title.'"';
            $lines[] = '  status: '.$request->status->labelAr().' / '.$request->status->labelEn()
                .' | created '.$request->created_at?->timezone('Asia/Damascus')->toDateString();

            $package = $request->pricingPackage;
            $period = (string) $request->billing_period;
            $lines[] = '  package: '.($package
                ? trim(($package->name_ar ?: $package->name_en).' / '.$package->name_en, ' /').($period !== '' ? ' | period '.BillingPeriod::labelAr($period).' ('.$period.')' : '')
                : 'manual request (no catalog package)');

            $money = [];
            if ($request->quotation_amount !== null) {
                $money[] = 'quotation '.$this->usd($request->quotation_amount);
            }
            if ((float) $request->amount_paid > 0) {
                $money[] = 'paid '.$this->usd($request->amount_paid);
            }
            if ((float) $request->amount_remaining > 0) {
                $money[] = 'remaining '.$this->usd($request->amount_remaining);
            }
            if ($request->paid_at !== null) {
                $money[] = 'payment confirmed '.$request->paid_at->timezone('Asia/Damascus')->toDateString();
            }
            if ($money !== []) {
                $lines[] = '  money: '.implode(' | ', $money);
            }

            if ($request->subscription_ends_at !== null) {
                $ends = $request->subscription_ends_at->copy()->timezone('Asia/Damascus');
                $renewal = match (true) {
                    $request->renewalDeclined() => 'the client said they will not renew',
                    $request->canRenew() => 'renewal is open now',
                    default => 'renewal opens on '.$ends->copy()->subDays(8)->toDateString(),
                };
                $lines[] = '  subscription ends '.$ends->toDateString().' | '.$renewal;
            }

            $hours = [];
            foreach (WorkLines::fromRequest($request) as $line) {
                $hours[] = $line['department'].' '.$line['hours'].'h';
            }
            if ($hours !== []) {
                $lines[] = '  package hours: '.implode(', ', $hours);
            }
            $tasks = [];
            foreach ($request->clickupTasks as $task) {
                $tasks[] = $task->task_type->value.' '.((int) $task->planned_hours).'h '.trim((string) $task->status);
            }
            if ($tasks !== []) {
                $lines[] = '  work in progress: '.implode(', ', $tasks);
            }

            $lines = array_merge($lines, $this->photographyLines($request, $bookings->get($request->id, collect()), $ref, $waiting));

            $sent = $deliveries->get($request->id, collect());
            if ($sent->isNotEmpty()) {
                $approved = $sent->whereNotNull('client_approved_at')->count();
                $lines[] = '  delivered files: '.$sent->count().' sent, '.$approved.' approved by the client, '.($sent->count() - $approved).' waiting for approval or a change';
            }

            $next = match ($request->status) {
                RequestStatus::QuotationSent => 'approve or reject the quotation for '.$ref,
                RequestStatus::AwaitingPayment => 'pay and send the receipt for '.$ref,
                RequestStatus::ReadyForReview => 'review the delivery of '.$ref,
                default => null,
            };
            if ($next !== null) {
                $waiting[] = $next;
            }
        }

        $lines[] = 'Waiting for the client: '.($waiting === [] ? 'nothing' : implode('; ', $waiting)).'.';

        return implode("\n", $lines);
    }

    /**
     * What the bot can do for a client and the rules the team works by.
     */
    public function operations(): string
    {
        $lead = $this->calendar->photographyLeadDays();
        [$open, $close] = $this->calendar->teamWindowMinutes();
        $team = sprintf('%02d:%02d-%02d:%02d', intdiv($open, 60), $open % 60, intdiv($close, 60), $close % 60);
        $support = ClientChannelGate::SUPPORT_PHONE;
        $shoot = BookPhotographySlot::SHOOT_HOURS;
        $gap = BookPhotographySlot::GAP_HOURS;

        return <<<TEXT
requests: show the client's list of requests with their status. Each request opens to its status, payment, and the actions still allowed.
open_request: open one request (needs ref).
new: start a new request. The client picks a category, a package, and a billing period from the catalog, or writes a manual request (title, description, files). The request is created and the quotation arrives in the chat.
approve / reject: decide on a quotation waiting for the client. Approving sends the Sham Cash payment code and the amounts. Packages that need full payment are paid in full first; others start with 50% and the rest later. Rejecting asks why (too expensive, delay, or a written reason). Asking for a bigger package sends a new quotation for the next package instead.
receipt: the client sends the payment transfer photo or PDF here. The team checks it, confirms the payment, starts the work, and sends the invoice.
hours: show what work is inside a request (design, content, programming, photography hours).
edit: change a request's title or description. Only while the request is still "received" and before the quotation.
photo: book, check, or move a photography shoot on a paid request whose package includes shoot sessions. The client picks the request, a day, then a time. The earliest day is {$lead} days ahead. Friday and the saved days off are closed. Shoots run inside {$team} Damascus time and last {$shoot} hours. Two shoots on the same day start at least {$gap} hours apart. Each package period has a set number of shoot sessions; a session is used only when the time is agreed. The photographer confirms the time; if it does not work they offer another time and the client answers works / does not work. An unanswered request or offer closes after 48 hours. To move an agreed shoot the client asks in the chat before the shoot day; cancelling an agreed shoot is done by the team.
renew: renew a subscription. Renewal opens in the last 8 days of the period. An invoice is sent, and the period is extended after the payment is confirmed.
norenew: the client will not renew; the current period stays until its end date.
profile: show or change the client's name, phone, or company.
help: the support phone {$support}.
Delivery: finished files arrive in this chat one by one. Each file has approve and change buttons. A change request asks what to change. When every file is approved, the client accepts the delivery and the request is completed.
Payments are in USD through Sham Cash. Remaining balances get a reminder.
The client cannot pick an employee, change prices, or get a discount in the chat. Those go to the team (escalate).
Never tell the client a shoot time is booked, moved, or that a session was used. Only the photography replies do that, after Laravel saves the row.
TEXT;
    }

    /**
     * @param  iterable<\App\Models\PhotographyBooking>  $bookings
     * @param  list<string>  $waiting
     * @return list<string>
     */
    private function photographyLines(ServiceRequest $request, iterable $bookings, string $ref, array &$waiting): array
    {
        $cap = $this->sessions->cap($request);
        $shoots = [];
        foreach ($bookings as $booking) {
            $time = $booking->status === 'needs_client' || $booking->status === 'rescheduling'
                ? $booking->proposed_starts_at
                : $booking->starts_at;
            $shoots[] = $this->photography->statusLabel($booking).' ('.$booking->status.') '.$this->when($time);
            if ($booking->status === 'needs_client') {
                $waiting[] = 'answer the shoot time the team offered for '.$ref.' (works / does not work)';
            }
        }
        if ($cap === null && $shoots === []) {
            return [];
        }
        if ($cap === null) {
            $line = '  photography: the team has not set the number of shoot sessions yet';
        } else {
            $line = '  photography: '.$cap.' shoot session(s) this period, '.$this->sessions->used($request).' used, '
                .$this->sessions->remaining($request).' left to book';
            $blocker = $this->sessions->blocker($request);
            if ($blocker !== null) {
                $line .= ' | cannot book now: '.$blocker;
            }
        }

        return [$line.($shoots !== [] ? ' | '.implode('; ', $shoots) : '')];
    }

    private function usd(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.').' USD';
    }

    private function when(?Carbon $time): string
    {
        if ($time === null) {
            return 'an open time';
        }
        $local = Carbon::parse($time->format('Y-m-d H:i:s'), 'Asia/Damascus');

        return $local->format('l Y-m-d H:i');
    }
}
