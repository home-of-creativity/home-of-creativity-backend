<?php

namespace App\Http\Controllers;

use App\Actions\ApproveQuotation;
use App\Actions\ClientAssistant;
use App\Actions\RejectQuotation;
use App\Actions\UpdateClient;
use App\Enums\RequestStatus;
use App\Http\Middleware\EnsureAppClient;
use App\Http\Resources\App\AppLocale;
use App\Http\Resources\App\AppQuotationResource;
use App\Http\Resources\App\AppReportResource;
use App\Http\Resources\App\AppRequestResource;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client side of the mobile app. Every query is scoped to the client row
 * that {@see EnsureAppClient} attached to the request.
 * Quotation decisions run the same actions as the client bot.
 */
class AppClientController extends Controller
{
    private const ACTIVE_EXCLUDED = [RequestStatus::Completed, RequestStatus::Cancelled];

    public function home(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $requests = $client->requests();

        $counts = [
            'active' => (clone $requests)->whereNotIn('status', self::ACTIVE_EXCLUDED)->count(),
            'awaiting_decision' => (clone $requests)->where('status', RequestStatus::QuotationSent)->count(),
            'awaiting_payment' => (clone $requests)->where('status', RequestStatus::AwaitingPayment)->count(),
            'completed' => (clone $requests)->where('status', RequestStatus::Completed)->count(),
        ];
        $remaining = (float) (clone $requests)->whereNotIn('status', [RequestStatus::Cancelled])->sum('amount_remaining');

        $latest = $this->requestQuery($client)->limit(3)->get();
        $report = ClientReport::query()
            ->where('client_id', $client->id)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->first();

        return response()->json([
            'data' => [
                'client_name' => $client->name,
                'company_name' => $client->company_name,
                'counts' => $counts,
                'amount_remaining' => round($remaining, 2),
                'currency' => 'USD',
                'latest_requests' => AppRequestResource::collection($latest)->resolve($request),
                'latest_report' => $report ? AppReportResource::make($report)->resolve($request) : null,
            ],
            'message' => 'ok',
        ]);
    }

    public function requests(Request $request)
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'active', 'done'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->requestQuery($this->client($request));

        match ($validated['filter'] ?? 'all') {
            'active' => $query->whereNotIn('status', self::ACTIVE_EXCLUDED),
            'done' => $query->whereIn('status', self::ACTIVE_EXCLUDED),
            default => null,
        };

        return AppRequestResource::collection($query->paginate(15))->additional(['message' => 'ok']);
    }

    public function showRequest(Request $request, int $id): JsonResponse
    {
        $serviceRequest = $this->requestQuery($this->client($request))
            ->with(['statusHistory', 'driveDeliveries', 'quotations.decisions'])
            ->findOrFail($id);

        return response()->json([
            'data' => AppRequestResource::make($serviceRequest)->detailed()->resolve($request),
            'message' => 'ok',
        ]);
    }

    public function quotations(Request $request)
    {
        $client = $this->client($request);
        $quotations = Quotation::query()
            ->whereNotNull('sent_at')
            ->whereHas('request', fn (Builder $query) => $query->where('client_id', $client->id))
            ->with([
                'decisions',
                'request' => fn ($query) => $query->withMax('quotations', 'version'),
            ])
            ->latest('sent_at')
            ->latest('id')
            ->paginate(15);

        return AppQuotationResource::collection($quotations)->additional(['message' => 'ok']);
    }

    public function approveQuotation(Request $request, int $id, ApproveQuotation $approve): JsonResponse
    {
        [$quotation, $serviceRequest] = $this->openQuotation($request, $id);
        $approve->handle($serviceRequest, $quotation);

        return $this->quotationResponse($request, $quotation, 'Approved.');
    }

    public function rejectQuotation(Request $request, int $id, RejectQuotation $reject): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        [$quotation, $serviceRequest] = $this->openQuotation($request, $id);
        $reject->handle($serviceRequest, $validated['reason'], $quotation);

        return $this->quotationResponse($request, $quotation, 'Rejected.');
    }

    public function quotationPdf(Request $request, int $id): Response
    {
        $client = $this->client($request);
        $quotation = Quotation::query()
            ->whereHas('request', fn (Builder $query) => $query->where('client_id', $client->id))
            ->findOrFail($id);

        return $this->streamPdf((string) $quotation->pdf_path, 'quotation-'.$quotation->id.'.pdf');
    }

    public function reports(Request $request)
    {
        $reports = ClientReport::query()
            ->where('client_id', $this->client($request)->id)
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->paginate(15);

        return AppReportResource::collection($reports)->additional(['message' => 'ok']);
    }

    public function reportPdf(Request $request, int $id): Response
    {
        $report = ClientReport::query()
            ->where('client_id', $this->client($request)->id)
            ->whereNotNull('published_at')
            ->findOrFail($id);

        return $this->streamPdf((string) $report->pdf_path, 'report-'.$report->id.'.pdf');
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profilePayload($this->client($request)), 'message' => 'ok']);
    }

    public function updateProfile(Request $request, UpdateClient $updateClient): JsonResponse
    {
        $client = $this->client($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'company_activity' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        $client = $updateClient->handle($client, array_filter([
            'name' => trim($validated['name']),
            'company_name' => isset($validated['company_name']) ? trim($validated['company_name']) : null,
            'company_activity' => isset($validated['company_activity']) ? trim($validated['company_activity']) : null,
            'email' => isset($validated['email']) ? strtolower(trim($validated['email'])) : null,
            'locale' => $validated['locale'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $this->syncUser($request->user(), $client);

        return response()->json(['data' => $this->profilePayload($client), 'message' => 'Updated.']);
    }

    public function assistant(Request $request, ClientAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:500'],
        ]);
        $client = $this->client($request);
        $locale = AppLocale::from($request);

        $decision = $assistant->decide(
            $client,
            $validated['message'],
            array_values($validated['history'] ?? []),
            $locale,
            'idle',
            'app',
        );

        $requestId = null;
        $ref = trim(ltrim($decision['ref'], '#'));
        if ($ref !== '') {
            $requestId = $client->requests()
                ->where(fn (Builder $query) => $query->where('number', $ref)->orWhere('number', 'like', '%'.$ref))
                ->value('id');
        }

        $action = match ($decision['action']) {
            'requests' => 'requests',
            'open_request' => $requestId ? 'request' : 'requests',
            'approve', 'reject' => 'quotations',
            'profile' => 'profile',
            default => null,
        };
        $answer = $decision['answer'];
        if ($answer === '' && $action !== null) {
            $answer = $locale === 'en' ? 'Here is what you asked for.' : 'تفضل، هذا ما طلبته.';
        }

        return response()->json([
            'data' => ['answer' => $answer, 'action' => $action, 'request_id' => $requestId],
            'message' => 'ok',
        ]);
    }

    private function client(Request $request): Client
    {
        return $request->attributes->get('appClient');
    }

    /**
     * @return Builder<ServiceRequest>
     */
    private function requestQuery(Client $client): Builder
    {
        return ServiceRequest::query()
            ->where('client_id', $client->id)
            ->with(['pricingPackage', 'quotations'])
            ->latest('id');
    }

    /**
     * @return array{0: Quotation, 1: ServiceRequest}
     */
    private function openQuotation(Request $request, int $id): array
    {
        $client = $this->client($request);
        $quotation = Quotation::query()
            ->whereHas('request', fn (Builder $query) => $query->where('client_id', $client->id))
            ->with('request')
            ->findOrFail($id);
        $serviceRequest = $quotation->request;

        $latest = (int) $serviceRequest->quotations()->max('version');
        abort_if(
            $serviceRequest->status !== RequestStatus::QuotationSent || (int) $quotation->version < $latest,
            422,
            'This quotation no longer waits for a decision.',
        );

        return [$quotation, $serviceRequest];
    }

    private function quotationResponse(Request $request, Quotation $quotation, string $message): JsonResponse
    {
        $fresh = Quotation::query()
            ->with(['decisions', 'request' => fn ($query) => $query->withMax('quotations', 'version')])
            ->findOrFail($quotation->id);

        return response()->json([
            'data' => AppQuotationResource::make($fresh)->resolve($request),
            'message' => $message,
        ]);
    }

    private function streamPdf(string $path, string $name): Response
    {
        abort_if($path === '' || ! Storage::disk('local')->exists($path), 404, 'The PDF is not ready yet.');

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(Client $client): array
    {
        return [
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'company_name' => $client->company_name,
            'company_activity' => $client->company_activity,
            'locale' => $client->locale === 'en' ? 'en' : 'ar',
            'channel' => $client->isWhatsApp() ? 'whatsapp' : (filled($client->telegram_user_id) ? 'telegram' : null),
        ];
    }

    private function syncUser(User $user, Client $client): void
    {
        $updates = ['name' => $client->name, 'locale' => $client->locale ?: $user->locale];
        $email = strtolower((string) $client->email);
        if ($user->hasPlaceholderEmail() && str_contains($email, '@')
            && ! User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
            $updates['email'] = $email;
        }
        $user->forceFill($updates)->save();
    }
}
