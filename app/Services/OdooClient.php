<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OdooClient
{
    private ?int $legacyUid = null;

    private ?int $currencyId = null;

    public function configured(): bool
    {
        if (! (bool) config('services.odoo.enabled')
            || ! filled(config('services.odoo.url'))
            || ! filled(config('services.odoo.api_key'))) {
            return false;
        }

        if ($this->useJson2()) {
            return true;
        }

        return filled(config('services.odoo.db'))
            && filled(config('services.odoo.username'));
    }

    public function recordUrl(string $model, int|string $recordId): string
    {
        return rtrim((string) config('services.odoo.url'), '/')
            .'/web#id='.(int) $recordId
            .'&model='.rawurlencode($model)
            .'&view_type=form';
    }

    /**
     * @return list<array{id: int, name: string, email: string|null, phone: string|null, odoo_url: string}>
     */
    public function listPartners(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('res.partner', [
            ['customer_rank', '>', 0],
        ], ['id', 'name', 'email', 'phone'], $limit, $offset, 'name asc');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => filled($row['email'] ?? null) ? (string) $row['email'] : null,
            'phone' => filled($row['phone'] ?? null) ? (string) $row['phone'] : null,
            'odoo_url' => $this->recordUrl('res.partner', (int) $row['id']),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQuotations(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('sale.order', [
            ['state', 'in', ['draft', 'sent']],
        ], [
            'id', 'name', 'partner_id', 'amount_total', 'state', 'client_order_ref', 'origin', 'date_order',
        ], $limit, $offset, 'date_order desc');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'client_order_ref' => filled($row['client_order_ref'] ?? null) ? (string) $row['client_order_ref'] : null,
            'origin' => filled($row['origin'] ?? null) ? (string) $row['origin'] : null,
            'date_order' => filled($row['date_order'] ?? null) ? (string) $row['date_order'] : null,
            'odoo_url' => $this->recordUrl('sale.order', (int) $row['id']),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoices(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('account.move', [
            ['move_type', '=', 'out_invoice'],
        ], [
            'id', 'name', 'partner_id', 'amount_total', 'state', 'payment_state', 'invoice_origin', 'ref', 'invoice_date',
        ], $limit, $offset, 'invoice_date desc');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'payment_state' => (string) ($row['payment_state'] ?? ''),
            'invoice_origin' => filled($row['invoice_origin'] ?? null) ? (string) $row['invoice_origin'] : null,
            'ref' => filled($row['ref'] ?? null) ? (string) $row['ref'] : null,
            'invoice_date' => filled($row['invoice_date'] ?? null) ? (string) $row['invoice_date'] : null,
            'odoo_url' => $this->recordUrl('account.move', (int) $row['id']),
        ], $rows);
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>|null  $lines
     * @return array{odoo_partner_id: string, odoo_quotation_id: string}
     */
    public function createQuotation(
        string $partnerName,
        ?string $email,
        ?string $phone,
        string $requestNumber,
        string $title,
        ?float $amount = null,
        ?string $notes = null,
        ?array $lines = null,
    ): array {
        $partnerId = $this->createOrReusePartner($partnerName, $email, $phone, $requestNumber);
        $lineName = filled($notes) ? "{$title}\n{$notes}" : $title;
        $orderValues = [
            'partner_id' => (int) $partnerId,
            'client_order_ref' => $requestNumber,
            'origin' => $requestNumber,
            'note' => $lineName,
        ];

        if ($currencyId = $this->resolveCurrencyId()) {
            $orderValues['currency_id'] = $currencyId;
        }

        if ($lines !== null && $lines !== []) {
            $orderValues['order_line'] = array_map(
                fn (array $line): array => [0, 0, $this->saleOrderLine([
                    'name' => filled($line['notes'] ?? null)
                        ? "{$line['title']}\n{$line['notes']}"
                        : (string) $line['title'],
                    'product_uom_qty' => (float) ($line['units'] ?? 1),
                    'price_unit' => (float) $line['amount'],
                ])],
                $lines,
            );
        } elseif ($amount !== null && $amount > 0) {
            $orderValues['order_line'] = [[0, 0, $this->saleOrderLine([
                'name' => $lineName,
                'product_uom_qty' => 1,
                'price_unit' => $amount,
            ])]];
        }

        $orderId = $this->call('sale.order', 'create', [$orderValues]);

        return [
            'odoo_partner_id' => (string) $partnerId,
            'odoo_quotation_id' => (string) $orderId,
        ];
    }

    public function downloadSaleOrderPdf(int|string $orderId): ?string
    {
        return $this->downloadPortalReportPdf('sale.order', (int) $orderId);
    }

    public function downloadInvoicePdf(int|string $invoiceId): ?string
    {
        return $this->downloadPortalReportPdf('account.move', (int) $invoiceId);
    }

    public function downloadPortalReportPdf(string $model, int $recordId): ?string
    {
        if (! $this->configured() || $recordId <= 0) {
            return null;
        }

        try {
            $portalPath = $this->call($model, 'get_portal_url', [
                'ids' => [$recordId],
                'context' => [],
            ]);

            if (! is_string($portalPath) || $portalPath === '') {
                Log::warning('Odoo portal URL missing for PDF download.', [
                    'model' => $model,
                    'record_id' => $recordId,
                ]);

                return null;
            }

            $path = str_starts_with($portalPath, '/') ? $portalPath : '/'.$portalPath;
            $separator = str_contains($path, '?') ? '&' : '?';
            $url = rtrim((string) config('services.odoo.url'), '/').$path.$separator.'report_type=pdf';

            $response = Http::timeout(30)
                ->connectTimeout(3)
                ->retry(2, 200)
                ->get($url);

            $body = $response->body();
            if ($response->successful() && str_starts_with($body, '%PDF')) {
                return $body;
            }

            Log::warning('Odoo portal PDF response was not a PDF.', [
                'model' => $model,
                'record_id' => $recordId,
                'status' => $response->status(),
                'start' => substr($body, 0, 40),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Odoo portal PDF download failed.', [
                'model' => $model,
                'record_id' => $recordId,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    public function createOrReusePartner(string $partnerName, ?string $email, ?string $phone, ?string $requestNumber = null): string
    {
        if (filled($email)) {
            $existing = $this->call('res.partner', 'search', [
                'domain' => [['email', '=', $email]],
                'limit' => 1,
            ]);
            if (is_array($existing) && isset($existing[0])) {
                return (string) $existing[0];
            }
        }

        $partnerId = $this->call('res.partner', 'create', [[
            'name' => $partnerName,
            'email' => $email,
            'phone' => $phone,
            'customer_rank' => 1,
            'comment' => filled($requestNumber) ? 'HOC '.$requestNumber : 'HOC dashboard',
        ]]);

        return (string) $partnerId;
    }

    public function createInvoice(string $partnerId, string $requestNumber, ?string $quotationId = null, ?float $amount = null): string
    {
        $values = [
            'move_type' => 'out_invoice',
            'partner_id' => (int) $partnerId,
            'invoice_origin' => $quotationId ?: $requestNumber,
            'ref' => $requestNumber,
        ];

        if ($currencyId = $this->resolveCurrencyId()) {
            $values['currency_id'] = $currencyId;
        }

        if ($amount !== null && $amount > 0) {
            $values['invoice_line_ids'] = [[0, 0, $this->invoiceLine([
                'name' => 'HOC '.$requestNumber,
                'quantity' => 1,
                'price_unit' => $amount,
            ])]];
        }

        return (string) $this->call('account.move', 'create', [$values]);
    }

    /**
     * @param  list<list<mixed>>  $domain
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function searchRead(string $model, array $domain, array $fields, int $limit, int $offset, string $order): array
    {
        $result = $this->call($model, 'search_read', [
            'domain' => $domain,
            'fields' => $fields,
            'offset' => $offset,
            'limit' => $limit,
            'order' => $order,
        ]);

        return is_array($result) ? $result : [];
    }

    private function relationName(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return isset($value[1]) ? (string) $value[1] : null;
    }

    private function resolveCurrencyId(): ?int
    {
        if ($this->currencyId !== null) {
            return $this->currencyId > 0 ? $this->currencyId : null;
        }

        if (! $this->configured()) {
            $this->currencyId = 0;

            return null;
        }

        $code = strtoupper((string) config('services.odoo.currency_code', 'SYP'));
        $rows = $this->searchRead('res.currency', [
            ['name', '=', $code],
        ], ['id', 'name'], 1, 0, 'id asc');

        if ($rows === []) {
            $rows = $this->searchRead('res.currency', [
                ['name', 'ilike', $code],
            ], ['id', 'name'], 1, 0, 'id asc');
        }

        $this->currencyId = isset($rows[0]['id']) ? (int) $rows[0]['id'] : 0;

        return $this->currencyId > 0 ? $this->currencyId : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function saleOrderLine(array $values): array
    {
        return array_merge($values, [
            'tax_id' => [[6, 0, []]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function invoiceLine(array $values): array
    {
        return array_merge($values, [
            'tax_ids' => [[6, 0, []]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $model, string $method, array $payload): mixed
    {
        if ($this->useJson2()) {
            return $this->json2($model, $method, $this->normalizeJson2Payload($model, $method, $payload));
        }

        return $this->executeLegacy($model, $method, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeJson2Payload(string $model, string $method, array $payload): array
    {
        if ($method === 'create') {
            if (isset($payload['vals']) && is_array($payload['vals'])) {
                return ['vals_list' => [$payload['vals']]];
            }

            if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
                return ['vals_list' => [$payload[0]]];
            }
        }

        if ($method === 'search' && isset($payload[0]) && is_array($payload[0])) {
            return [
                'domain' => $payload[0],
                'offset' => $payload[1] ?? 0,
                'limit' => $payload[2] ?? 0,
            ];
        }

        if ($method === 'search_read' && array_is_list($payload)) {
            return [
                'domain' => $payload[0] ?? [],
                'fields' => $payload[1] ?? [],
                'offset' => $payload[2] ?? 0,
                'limit' => $payload[3] ?? 0,
                'order' => $payload[4] ?? '',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json2(string $model, string $method, array $payload): mixed
    {
        $url = rtrim((string) config('services.odoo.url'), '/')."/json/2/{$model}/{$method}";
        $headers = [
            'Authorization' => 'bearer '.config('services.odoo.api_key'),
        ];

        if (filled(config('services.odoo.db'))) {
            $headers['X-Odoo-Database'] = (string) config('services.odoo.db');
        }

        $response = Http::timeout((int) config('services.odoo.timeout', 12))
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->withHeaders($headers)
            ->post($url, $payload);

        if ($response->status() === 401) {
            throw new RuntimeException('Odoo API key is invalid. Regenerate it from Preferences → Account Security → API Keys.');
        }

        $response->throw();

        $body = $response->json();
        if (is_array($body) && isset($body['name'], $body['message']) && str_contains((string) $body['name'], 'Exception')) {
            throw new RuntimeException((string) $body['message']);
        }

        if ($method === 'create' && is_array($body) && array_is_list($body) && count($body) === 1) {
            return $body[0];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeLegacy(string $model, string $method, array $payload): mixed
    {
        $uid = $this->legacyUid();

        if ($method === 'search_read') {
            return $this->execute($uid, $model, $method, [
                $payload['domain'] ?? [],
                $payload['fields'] ?? [],
                $payload['offset'] ?? 0,
                $payload['limit'] ?? 0,
                $payload['order'] ?? '',
            ]);
        }

        if ($method === 'search') {
            return $this->execute($uid, $model, $method, [
                $payload['domain'] ?? ($payload[0] ?? []),
                $payload['offset'] ?? ($payload[1] ?? 0),
                $payload['limit'] ?? ($payload[2] ?? 0),
            ]);
        }

        if ($method === 'create') {
            $values = $payload['vals'] ?? ($payload[0] ?? $payload);

            return $this->execute($uid, $model, $method, [[$values]]);
        }

        return $this->execute($uid, $model, $method, [$payload]);
    }

    private function legacyUid(): int
    {
        if ($this->legacyUid !== null) {
            return $this->legacyUid;
        }

        $this->legacyUid = $this->authenticate();

        return $this->legacyUid;
    }

    private function authenticate(): int
    {
        $uid = $this->jsonrpc('common', 'authenticate', [
            config('services.odoo.db'),
            config('services.odoo.username'),
            config('services.odoo.api_key'),
            [],
        ]);

        if (! is_int($uid) && ! is_numeric($uid)) {
            throw new RuntimeException('Odoo authentication failed. Check ODOO_DB, ODOO_USERNAME, and ODOO_API_KEY.');
        }

        return (int) $uid;
    }

    private function execute(int $uid, string $model, string $method, array $args): mixed
    {
        return $this->jsonrpc('object', 'execute_kw', [
            config('services.odoo.db'),
            $uid,
            config('services.odoo.api_key'),
            $model,
            $method,
            $args,
        ]);
    }

    private function jsonrpc(string $service, string $method, array $args): mixed
    {
        $url = rtrim((string) config('services.odoo.url'), '/').'/jsonrpc';
        $response = Http::timeout((int) config('services.odoo.timeout', 12))
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->post($url, [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => $service,
                    'method' => $method,
                    'args' => $args,
                ],
                'id' => random_int(1, 9999),
            ])
            ->throw();

        $payload = $response->json();
        if (isset($payload['error'])) {
            $message = data_get($payload, 'error.data.message')
                ?? data_get($payload, 'error.message')
                ?? 'Odoo request failed.';
            throw new RuntimeException((string) $message);
        }

        return $payload['result'] ?? null;
    }

    private function useJson2(): bool
    {
        return (bool) config('services.odoo.use_json2', false);
    }
}
