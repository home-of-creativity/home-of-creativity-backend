<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OdooClient
{
    public function configured(): bool
    {
        return (bool) config('services.odoo.enabled')
            && filled(config('services.odoo.url'))
            && filled(config('services.odoo.db'))
            && filled(config('services.odoo.username'))
            && filled(config('services.odoo.api_key'));
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
        ], ['id', 'name', 'email', 'phone', 'mobile'], $limit, $offset, 'name asc');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => filled($row['email'] ?? null) ? (string) $row['email'] : null,
            'phone' => filled($row['phone'] ?? null)
                ? (string) $row['phone']
                : (filled($row['mobile'] ?? null) ? (string) $row['mobile'] : null),
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
     * @return array{odoo_partner_id: string, odoo_quotation_id: string}
     */
    public function createQuotation(string $partnerName, ?string $email, ?string $phone, string $requestNumber, string $title, ?float $amount = null): array
    {
        $partnerId = $this->createOrReusePartner($partnerName, $email, $phone, $requestNumber);
        $uid = $this->authenticate();
        $orderValues = [
            'partner_id' => (int) $partnerId,
            'client_order_ref' => $requestNumber,
            'origin' => $requestNumber,
            'note' => $title,
        ];

        if ($amount !== null && $amount > 0) {
            $orderValues['order_line'] = [[0, 0, [
                'name' => $title,
                'product_uom_qty' => 1,
                'price_unit' => $amount,
            ]]];
        }

        $orderId = $this->execute($uid, 'sale.order', 'create', [$orderValues]);

        return [
            'odoo_partner_id' => (string) $partnerId,
            'odoo_quotation_id' => (string) $orderId,
        ];
    }

    public function createOrReusePartner(string $partnerName, ?string $email, ?string $phone, ?string $requestNumber = null): string
    {
        $uid = $this->authenticate();

        if (filled($email)) {
            $existing = $this->execute($uid, 'res.partner', 'search', [[['email', '=', $email]], 0, 1]);
            if (is_array($existing) && isset($existing[0])) {
                return (string) $existing[0];
            }
        }

        $partnerId = $this->execute($uid, 'res.partner', 'create', [[
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
        $uid = $this->authenticate();

        $values = [
            'move_type' => 'out_invoice',
            'partner_id' => (int) $partnerId,
            'invoice_origin' => $quotationId ?: $requestNumber,
            'ref' => $requestNumber,
        ];

        if ($amount !== null && $amount > 0) {
            $values['invoice_line_ids'] = [[0, 0, [
                'name' => 'HOC '.$requestNumber,
                'quantity' => 1,
                'price_unit' => $amount,
            ]]];
        }

        return (string) $this->execute($uid, 'account.move', 'create', [$values]);
    }

    /**
     * @param  list<list<mixed>>  $domain
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function searchRead(string $model, array $domain, array $fields, int $limit, int $offset, string $order): array
    {
        $uid = $this->authenticate();
        $result = $this->execute($uid, $model, 'search_read', [
            $domain,
            $fields,
            $offset,
            $limit,
            $order,
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

    private function authenticate(): int
    {
        $uid = $this->jsonrpc('common', 'authenticate', [
            config('services.odoo.db'),
            config('services.odoo.username'),
            config('services.odoo.api_key'),
            [],
        ]);

        if (! is_int($uid) && ! is_numeric($uid)) {
            throw new RuntimeException('Odoo authentication failed.');
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
}
