<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PhotographyBooking;
use App\Models\PricingPackage;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The photography balance of one request: the cap copied from the package, the
 * agreed sessions already charged, and the holds still waiting for an answer.
 */
class PhotographySessions
{
    /** Paid and still open. A completed request joins only when it was paid and has sessions left. */
    public const OPEN_STATUSES = [
        RequestStatus::PaymentConfirmed,
        RequestStatus::InProgress,
        RequestStatus::ReadyForReview,
        RequestStatus::RevisionRequested,
    ];

    public function cap(ServiceRequest $request): ?int
    {
        if ($request->photography_sessions === null) {
            $this->copyMissingCount($request);
        }

        return $request->photography_sessions === null ? null : (int) $request->photography_sessions;
    }

    /**
     * A request opened before the package had a session count stays empty. The
     * package number is copied once, the first time the balance is read.
     */
    private function copyMissingCount(ServiceRequest $request): void
    {
        $request->loadMissing('pricingPackage');
        $packageCap = $request->pricingPackage?->photography_sessions;
        if ($packageCap === null || (int) $packageCap < 1) {
            return;
        }
        $request->forceFill([
            'photography_sessions' => (int) $packageCap,
            'photography_period_key' => $request->photography_period_key ?: 'initial',
        ])->save();
    }

    public function used(ServiceRequest $request): int
    {
        return max(0, (int) $request->photography_sessions_used);
    }

    public function holds(ServiceRequest $request): int
    {
        return PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->whereIn('status', PhotographyBooking::HOLDS)
            ->count();
    }

    /** Sessions still free for a new time: cap − charged − holds. */
    public function remaining(ServiceRequest $request): int
    {
        $cap = $this->cap($request);
        if ($cap === null) {
            return 0;
        }

        return max(0, $cap - $this->used($request) - $this->holds($request));
    }

    public function isPaidOpen(ServiceRequest $request): bool
    {
        if (in_array($request->status, self::OPEN_STATUSES, true)) {
            return true;
        }

        return $request->status === RequestStatus::Completed && $request->paid_at !== null;
    }

    /**
     * Why a new time cannot be opened on this request, or null when it can.
     * unpaid · no_count · no_sessions · held · used_up
     */
    public function blocker(ServiceRequest $request): ?string
    {
        if (! $this->isPaidOpen($request) || $request->hiddenFromClient()) {
            return 'unpaid';
        }
        $cap = $this->cap($request);
        if ($cap === null) {
            return 'no_count';
        }
        if ($cap < 1) {
            return 'no_sessions';
        }
        if ($this->remaining($request) >= 1) {
            return null;
        }

        return $this->holds($request) > 0 ? 'held' : 'used_up';
    }

    public function eligible(ServiceRequest $request): bool
    {
        return $this->blocker($request) === null;
    }

    /**
     * The client's paid requests that carry photography, newest first. A request
     * without sessions is listed only when it was asked for by number.
     *
     * @return Collection<int, ServiceRequest>
     */
    public function forClient(Client $client): Collection
    {
        return $client->requests()
            ->with('pricingPackage')
            ->latest('id')
            ->get()
            ->filter(fn (ServiceRequest $request): bool => $this->isPaidOpen($request)
                && ! $request->hiddenFromClient()
                && (int) $this->cap($request) > 0)
            ->values();
    }

    /**
     * @return Collection<int, ServiceRequest>
     */
    public function eligibleFor(Client $client): Collection
    {
        return $this->forClient($client)->filter(fn (ServiceRequest $request): bool => $this->eligible($request))->values();
    }

    /** The open hold that keeps the balance at zero, so the reply can name its day. */
    public function blockingHold(ServiceRequest $request): ?PhotographyBooking
    {
        return PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->whereIn('status', PhotographyBooking::HOLDS)
            ->orderBy('starts_at')
            ->first();
    }

    public function periodKey(ServiceRequest $request): string
    {
        return (string) ($request->photography_period_key ?: 'initial');
    }

    /**
     * A staff count. It cannot drop below what is already charged or held.
     */
    public function setCap(ServiceRequest $request, int $sessions): ServiceRequest
    {
        $floor = $this->used($request) + $this->holds($request);
        if ($sessions < $floor) {
            throw ValidationException::withMessages([
                'photography_sessions' => "لا يمكن أن يكون العدد أقل من {$floor}: جلسات متفق عليها أو بانتظار الرد.",
            ]);
        }
        $request->forceFill([
            'photography_sessions' => $sessions,
            'photography_period_key' => $request->photography_period_key ?: 'initial',
        ])->save();

        return $request->fresh() ?? $request;
    }

    /**
     * A paid renewal starts a new period: the charged count returns to zero and
     * the package cap is copied again. Rows still waiting for an answer close
     * first, so the new period waits for them.
     */
    public function startNewPeriod(ServiceRequest $request): void
    {
        $key = 'period:'.($request->subscription_ends_at?->timestamp ?? now()->timestamp);
        if ($this->periodKey($request) === $key) {
            return;
        }
        $request->forceFill(['photography_next_period_key' => $key])->save();
        $this->settle($request);
    }

    /** Applies a waiting period once nothing on the request is open. */
    public function settle(ServiceRequest $request): void
    {
        $request = $request->fresh(['pricingPackage']) ?? $request;
        $next = (string) ($request->photography_next_period_key ?? '');
        if ($next === '') {
            return;
        }
        $open = PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->whereIn('status', PhotographyBooking::OPEN)
            ->exists();
        if ($open) {
            return;
        }
        $packageCap = $request->pricingPackage?->photography_sessions;
        $request->forceFill([
            'photography_sessions' => $packageCap !== null ? (int) $packageCap : $request->photography_sessions,
            'photography_sessions_used' => 0,
            'photography_period_key' => $next,
            'photography_next_period_key' => null,
        ])->save();
    }

    /**
     * Writes the package count on paid open requests of that package that never had
     * a count. It never writes over a count, and never writes a zero.
     */
    public function copyPackageCount(PricingPackage $package): int
    {
        $sessions = (int) $package->photography_sessions;
        if ($sessions < 1) {
            return 0;
        }

        return ServiceRequest::query()
            ->where('pricing_package_id', $package->id)
            ->whereNull('photography_sessions')
            ->whereIn('status', array_map(
                fn (RequestStatus $status): string => $status->value,
                [...self::OPEN_STATUSES, RequestStatus::Completed],
            ))
            ->update([
                'photography_sessions' => $sessions,
                'photography_period_key' => 'initial',
            ]);
    }

    /**
     * Paid open requests that look like they include photography but carry no
     * usable count yet. Staff set the count before the chat can book.
     *
     * @return Collection<int, ServiceRequest>
     */
    public function needingCount(int $limit = 50): Collection
    {
        return ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->whereIn('status', array_map(fn (RequestStatus $status): string => $status->value, self::OPEN_STATUSES))
            ->where(function ($query): void {
                $query->whereNull('photography_sessions')->orWhere('photography_sessions', 0);
            })
            ->latest('id')
            ->limit(400)
            ->get()
            ->filter(fn (ServiceRequest $request): bool => $request->photography_sessions === null
                ? $request->pricing_package_id !== null || $this->mentionsPhotography($request)
                : $this->mentionsPhotography($request))
            ->take($limit)
            ->values();
    }

    public function mentionsPhotography(ServiceRequest $request): bool
    {
        $lines = $request->pricingPackage?->work_lines;
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (is_array($line) && ($line['department'] ?? '') === 'photography') {
                    return true;
                }
            }
        }
        $features = json_encode($request->pricingPackage?->features ?? '', JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($features, 'تصوير') || stripos($features, 'photo') !== false;
    }
}
