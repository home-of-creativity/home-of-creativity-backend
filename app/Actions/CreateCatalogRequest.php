<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PricingPackage;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use App\Support\BillingPeriod;
use App\Support\PaymentPlanResolver;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCatalogRequest
{
    public bool $quotationDelivered = false;

    public function __construct(
        private GenerateRequestNumber $generateRequestNumber,
        private SendQuotation $sendQuotation,
        private NotifyEmployees $notifyEmployees,
        private ProvisionSalesClickUpTask $provisionSalesClickUpTask,
    ) {}

    public function handle(Client $client, PricingPackage $package, ?string $billingPeriod = null): ServiceRequest
    {
        $package->loadMissing('subcategory.category');

        if (! $package->is_published) {
            throw ValidationException::withMessages(['package_id' => 'Package is not available.']);
        }

        $prices = is_array($package->prices) ? $package->prices : [];
        $period = $billingPeriod;
        if ($period === null || $period === '') {
            $period = array_key_exists('one_time', $prices) ? 'one_time' : (array_key_first($prices) ?: 'one_time');
        }

        $amount = null;
        if (isset($prices[$period]) && is_numeric($prices[$period])) {
            $amount = (float) $prices[$period];
        } elseif ($package->price_usd !== null) {
            $amount = (float) $package->price_usd;
        }

        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['billing_period' => 'No price available for this package/period.']);
        }

        $recent = ServiceRequest::query()
            ->where('client_id', $client->id)
            ->where('pricing_package_id', $package->id)
            ->where('billing_period', $period)
            ->whereIn('status', [RequestStatus::Submitted, RequestStatus::QuotationSent])
            ->where('created_at', '>=', now()->subMinutes(3))
            ->latest('id')
            ->first();
        if ($recent) {
            $this->quotationDelivered = filled($recent->quotations()->latest('id')->value('telegram_file_id'));

            return $recent;
        }

        $plan = PaymentPlanResolver::forPackage($package);
        $title = $package->name_ar ?: $package->name_en ?: $package->slug;
        $periodLabel = BillingPeriod::labelAr($period);
        $description = "طلب من الكتالوج: {$title}\nالفترة: {$periodLabel}";

        $serviceRequest = DB::transaction(function () use ($client, $package, $period, $plan, $title, $description): ServiceRequest {
            $request = $client->requests()->create([
                'number' => $this->generateRequestNumber->handle(),
                'pricing_package_id' => $package->id,
                'billing_period' => $period,
                'payment_plan' => $plan['payment_plan'],
                'requires_full_payment' => $plan['requires_full_payment'],
                'allows_renewal' => $plan['allows_renewal'],
                'title' => $title,
                'description' => $description,
                'status' => RequestStatus::Submitted,
                'source' => RequestSource::Telegram,
            ]);

            RequestStatusHistory::query()->create([
                'request_id' => $request->id,
                'from_status' => null,
                'to_status' => RequestStatus::Submitted->value,
                'actor' => 'client',
                'note' => 'Catalog request submitted.',
            ]);

            return $request->fresh(['client', 'pricingPackage']) ?? $request;
        });

        $displayNumber = ResolveServiceRequest::displayNumber($serviceRequest);
        $this->notifyEmployees->handle(
            $serviceRequest,
            EmployeeProfession::Sales,
            "طلب كتالوج جديد\n#{$displayNumber} — {$serviceRequest->title}\n{$client->name}".($client->company_name ? " ({$client->company_name})" : ''),
        );

        $serviceRequest = $this->provisionSalesClickUpTask->handle($serviceRequest)->fresh(['client', 'pricingPackage']) ?? $serviceRequest;

        $this->sendQuotation->handle(
            $serviceRequest,
            $amount,
            "الفترة: {$periodLabel}",
            'client:catalog',
        );
        $this->quotationDelivered = $this->sendQuotation->deliveredToClient;

        return $serviceRequest->fresh(['client', 'pricingPackage', 'quotations']) ?? $serviceRequest;
    }
}
