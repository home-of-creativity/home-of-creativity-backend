<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesOdooDocuments
{
    protected function fakeOdooDocuments(): void
    {
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function odooDocumentsHttpFake(): array
    {
        $pdf = '%PDF-1.4 odoo-test-document';

        return [
            'https://odoo.test/jsonrpc' => function ($request) {
                $params = $request->data()['params'] ?? [];
                $service = $params['service'] ?? '';
                $method = $params['method'] ?? '';

                if ($service === 'common' && $method === 'authenticate') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                if ($service !== 'object' || $method !== 'execute_kw') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                return match (true) {
                    $model === 'res.partner' && $action === 'search' => Http::response(['jsonrpc' => '2.0', 'result' => []], 200),
                    $model === 'res.partner' && $action === 'create' => Http::response(['jsonrpc' => '2.0', 'result' => 44], 200),
                    $model === 'res.currency' && $action === 'search_read' => Http::response(['jsonrpc' => '2.0', 'result' => [['id' => 1, 'name' => 'SYP']]], 200),
                    $model === 'sale.order' && $action === 'create' => Http::response(['jsonrpc' => '2.0', 'result' => 88], 200),
                    $model === 'account.move' && $action === 'create' => Http::response(['jsonrpc' => '2.0', 'result' => 501], 200),
                    $action === 'get_portal_url' => Http::response(['jsonrpc' => '2.0', 'result' => '/my/report/pdf?access_token=test-token'], 200),
                    default => Http::response(['jsonrpc' => '2.0', 'result' => null], 200),
                };
            },
            'https://odoo.test/*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
        ];
    }
}
