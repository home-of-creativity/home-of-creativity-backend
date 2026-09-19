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
     * @return list<string>
     */
    private function quotationFields(): array
    {
        return ['id', 'name', 'partner_id', 'amount_total', 'state', 'client_order_ref', 'origin', 'date_order'];
    }

    /**
     * @return list<string>
     */
    private function invoiceFields(): array
    {
        return ['id', 'name', 'partner_id', 'amount_total', 'state', 'payment_state', 'invoice_origin', 'ref', 'invoice_date'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapQuotation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'client_order_ref' => $this->optionalString($row['client_order_ref'] ?? null),
            'origin' => $this->optionalString($row['origin'] ?? null),
            'date_order' => $this->optionalString($row['date_order'] ?? null),
            'odoo_url' => $this->recordUrl('sale.order', (int) $row['id']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapInvoice(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'payment_state' => (string) ($row['payment_state'] ?? ''),
            'invoice_origin' => $this->optionalString($row['invoice_origin'] ?? null),
            'ref' => $this->optionalString($row['ref'] ?? null),
            'invoice_date' => $this->optionalString($row['invoice_date'] ?? null),
            'odoo_url' => $this->recordUrl('account.move', (int) $row['id']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQuotations(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('sale.order', [], $this->quotationFields(), $limit, $offset, 'date_order desc');

        return array_map(fn (array $row): array => $this->mapQuotation($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function quotationSnapshot(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->firstRecord($this->searchRead('sale.order', [['id', '=', $id]], $this->quotationFields(), 1, 0, 'id desc'));

        return $row === null ? null : $this->mapQuotation($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findQuotationForRequest(string $requestNumber): ?array
    {
        if ($requestNumber === '') {
            return null;
        }

        $row = $this->firstRecord($this->searchRead('sale.order', [
            '|',
            ['client_order_ref', '=', $requestNumber],
            ['origin', '=', $requestNumber],
        ], $this->quotationFields(), 1, 0, 'id desc'));

        return $row === null ? null : $this->mapQuotation($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoices(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('account.move', [
            ['move_type', '=', 'out_invoice'],
        ], $this->invoiceFields(), $limit, $offset, 'invoice_date desc');

        return array_map(fn (array $row): array => $this->mapInvoice($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invoiceSnapshot(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->firstRecord($this->searchRead('account.move', [['id', '=', $id]], $this->invoiceFields(), 1, 0, 'id desc'));

        return $row === null ? null : $this->mapInvoice($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInvoiceForRequest(string $requestNumber, ?string $quotationName = null): ?array
    {
        $refs = [];
        if ($requestNumber !== '') {
            $refs[] = $requestNumber;
        }
        if (filled($quotationName) && ! in_array($quotationName, $refs, true)) {
            $refs[] = $quotationName;
        }

        foreach ($refs as $ref) {
            foreach (['invoice_origin', 'ref'] as $field) {
                $row = $this->firstRecord($this->searchRead('account.move', [
                    ['move_type', '=', 'out_invoice'],
                    [$field, '=', $ref],
                ], $this->invoiceFields(), 1, 0, 'id desc'));
                if ($row !== null) {
                    return $this->mapInvoice($row);
                }
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     name: string,
     *     contact_name?: string|null,
     *     partner_name?: string|null,
     *     stage_id?: int|null,
     *     team_id?: int|null,
     *     phone?: string|null,
     *     mobile?: string|null,
     *     email_from?: string|null,
     *     description?: string|null,
     *     tag_ids?: list<int>|null,
     *     partner_id?: int|null
     * }  $values
     */
    public function createCrmLead(array $values): int
    {
        $pipeline = $this->resolveTelegramPipeline(
            isset($values['team_id']) ? (int) $values['team_id'] : null,
            isset($values['stage_id']) ? (int) $values['stage_id'] : null,
        );
        $teamId = $pipeline['team_id'];
        $stageId = $pipeline['stage_id'];

        $tagIds = $values['tag_ids'] ?? null;
        if (is_array($tagIds) && $tagIds !== [] && ! is_array($tagIds[0] ?? null)) {
            $tagIds = [[6, 0, array_map('intval', $tagIds)]];
        }

        $payload = array_filter([
            'name' => $values['name'],
            'contact_name' => $values['contact_name'] ?? null,
            'partner_name' => $values['partner_name'] ?? null,
            'stage_id' => $stageId,
            'team_id' => $teamId,
            'user_id' => $values['user_id'] ?? $this->crmOwnerUserId(),
            'phone' => $values['phone'] ?? $values['mobile'] ?? null,
            'mobile' => $values['mobile'] ?? $values['phone'] ?? null,
            'email_from' => $values['email_from'] ?? null,
            'description' => $values['description'] ?? null,
            'tag_ids' => $tagIds,
            'partner_id' => $values['partner_id'] ?? null,
            'type' => 'opportunity',
            'active' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            return (int) $this->call('crm.lead', 'create', [$payload]);
        } catch (Throwable $exception) {
            foreach ($this->crmLeadCreateFallbacks($payload) as $attempt) {
                try {
                    return (int) $this->call('crm.lead', 'create', [$attempt]);
                } catch (Throwable) {
                    continue;
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function crmLeadCreateFallbacks(array $payload): array
    {
        $withoutTags = $payload;
        unset($withoutTags['tag_ids']);

        $withoutTeam = $withoutTags;
        unset($withoutTeam['team_id']);

        $minimal = array_filter([
            'name' => $payload['name'] ?? null,
            'contact_name' => $payload['contact_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'type' => 'opportunity',
            'stage_id' => $payload['stage_id'] ?? null,
            'team_id' => $payload['team_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return [$withoutTags, $withoutTeam, $minimal];
    }

    public function crmOwnerUserId(): ?int
    {
        try {
            if (! $this->useJson2()) {
                $uid = $this->legacyUid();
                if ($uid > 0) {
                    return $uid;
                }
            }

            $login = trim((string) config('services.odoo.username'));
            if ($login === '') {
                return null;
            }

            return $this->firstId('res.users', [['login', '=', $login]]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{stage_id: int|null, team_id: int|null}
     */
    public function resolveTelegramPipeline(?int $teamId = null, ?int $stageId = null): array
    {
        $stage = null;
        if ($stageId === null) {
            try {
                $stage = $this->findCrmStage('تلغرام', $teamId);
            } catch (Throwable) {
                $stage = null;
            }

            $stageId = $stage['id'] ?? null;
            if ($stageId === null) {
                try {
                    $stageId = $this->ensureCrmStage('تلغرام');
                } catch (Throwable) {
                    $stageId = null;
                }
            }
        }

        $teamId ??= $stage['team_id'] ?? $this->findCrmTeamId('تلغرام');

        return [
            'stage_id' => $stageId,
            'team_id' => $teamId,
        ];
    }

    public function ensureCrmStage(string $name): int
    {
        $existing = $this->findCrmStageId($name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createCrmStage($name);
    }

    public function createCrmStage(string $name, ?int $teamId = null): int
    {
        $existing = $this->findCrmStage($name, $teamId);
        if ($existing !== null) {
            return $existing['id'];
        }

        $values = array_filter([
            'name' => trim($name),
            'sequence' => 1,
            'team_id' => $teamId,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return (int) $this->call('crm.stage', 'create', [$values]);
    }

    public function ensureCrmTeamId(string $name): ?int
    {
        try {
            $existing = $this->findCrmTeamId($name);
            if ($existing !== null) {
                return $existing;
            }

            $teamId = $this->call('crm.team', 'create', [[
                'name' => trim($name),
            ]]);

            return (int) $teamId;
        } catch (Throwable $exception) {
            Log::warning('Odoo CRM team ensure failed.', [
                'team' => $name,
                'error' => $exception->getMessage(),
            ]);

            return $this->findCrmTeamId($name);
        }
    }

    public function findCrmTeamId(string $name): ?int
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return null;
        }

        try {
            $rows = $this->searchRead('crm.team', [], ['id', 'name'], 200, 0, 'id asc');
        } catch (Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            if (trim((string) ($row['name'] ?? '')) === $normalized) {
                return (int) $row['id'];
            }
        }

        foreach ($rows as $row) {
            $candidate = trim((string) ($row['name'] ?? ''));
            if ($candidate !== '' && mb_stripos($candidate, $normalized) !== false) {
                return (int) $row['id'];
            }
        }

        foreach (['Telegram', 'telegram'] as $english) {
            if (mb_strtolower($normalized) === 'تلغرام') {
                foreach ($rows as $row) {
                    $candidate = trim((string) ($row['name'] ?? ''));
                    if ($candidate !== '' && mb_stripos($candidate, $english) !== false) {
                        return (int) $row['id'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{leads: int, partners: int}
     */
    public function purgeCrmCustomers(): array
    {
        return [
            'leads' => $this->unlinkMatchingRecords('crm.lead', []),
            'partners' => $this->unlinkMatchingRecords('res.partner', [
                ['customer_rank', '>', 0],
                ['id', '>', 1],
            ]),
        ];
    }

    /**
     * @param  list<list<mixed>>  $domain
     */
    public function unlinkMatchingRecords(string $model, array $domain, int $batch = 80): int
    {
        $deleted = 0;

        foreach ([true, false] as $active) {
            $offset = 0;
            $guard = 0;

            do {
                $rows = $this->searchRead($model, array_merge($domain, [
                    ['active', '=', $active],
                ]), ['id'], $batch, $offset, 'id asc');
                if ($rows === []) {
                    break;
                }

                $ids = [];
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }

                if ($ids === []) {
                    break;
                }

                try {
                    $this->unlinkRecords($model, $ids);
                    $deleted += count($ids);
                } catch (Throwable $exception) {
                    Log::warning('Odoo unlink failed; archiving records instead.', [
                        'model' => $model,
                        'ids' => $ids,
                        'error' => $exception->getMessage(),
                    ]);
                    foreach ($ids as $id) {
                        try {
                            $this->writeRecord($model, $id, ['active' => false]);
                            $deleted++;
                        } catch (Throwable) {
                            $offset += 1;
                        }
                    }
                }

                $guard++;
            } while (count($rows) === $batch && $guard < 200);
        }

        return $deleted;
    }

    /**
     * @param  list<int>  $ids
     */
    public function unlinkRecords(string $model, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $this->call($model, 'unlink', ['ids' => $ids]);
    }

    public function messagePost(string $model, int $recordId, string $body, bool $html = false): void
    {
        if ($recordId <= 0 || trim($body) === '') {
            return;
        }

        $payload = [
            'ids' => [$recordId],
            'body' => $html ? $body : strip_tags($body),
            'message_type' => 'comment',
            'subtype_xmlid' => 'mail.mt_note',
        ];

        if ($html) {
            $payload['body_html'] = $body;
            $payload['body_is_html'] = true;
        }

        try {
            $this->call($model, 'message_post', $payload);
        } catch (Throwable $exception) {
            Log::warning('Odoo message_post failed.', [
                'model' => $model,
                'record_id' => $recordId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function markLeadRejected(int $leadId, string $noteHtml): void
    {
        if ($leadId <= 0) {
            return;
        }

        try {
            $stageId = $this->findCrmStageId('خسارة')
                ?? $this->findCrmStageId('Lost')
                ?? $this->findCrmStageId('lost');

            $values = [
                'color' => 1,
                'probability' => 0,
            ];
            if ($stageId !== null) {
                $values['stage_id'] = $stageId;
            }

            $this->writeRecord('crm.lead', $leadId, $values);
            $this->messagePost('crm.lead', $leadId, $noteHtml, true);
        } catch (Throwable $exception) {
            Log::warning('Odoo markLeadRejected failed.', [
                'lead_id' => $leadId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function markLeadWon(int $leadId): void
    {
        if ($leadId <= 0) {
            return;
        }

        try {
            $stageId = $this->findCrmStageId('تم الفوز بها')
                ?? $this->findCrmStageId('Won')
                ?? $this->findCrmStageId('تم الفوز')
                ?? $this->findCrmStageId('won');

            if ($stageId === null) {
                Log::warning('Odoo Won stage not found for markLeadWon.', ['lead_id' => $leadId]);

                return;
            }

            $this->writeRecord('crm.lead', $leadId, [
                'stage_id' => $stageId,
                'probability' => 100,
                'color' => 10,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Odoo markLeadWon failed.', [
                'lead_id' => $leadId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function ensureCrmTagId(string $tagName): ?int
    {
        $normalized = trim($tagName);
        if ($normalized === '' || ! $this->configured()) {
            return null;
        }

        try {
            $rows = $this->searchRead('crm.tag', [['name', '=', $normalized]], ['id', 'name'], 1, 0, 'id asc');
            if (isset($rows[0]['id'])) {
                return (int) $rows[0]['id'];
            }

            return (int) $this->call('crm.tag', 'create', [['name' => $normalized]]);
        } catch (Throwable $exception) {
            Log::warning('Odoo CRM tag ensure failed.', [
                'tag' => $normalized,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *     stage: string|null,
     *     color: int|null,
     *     probability: float|null,
     *     expected_revenue: float|null,
     *     contact_name: string|null,
     *     partner_name: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     partner_id: string|null,
     *     odoo_url: string
     * }|null
     */
    public function leadSnapshot(int|string $leadId): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $rows = $this->searchRead('crm.lead', [['id', '=', (int) $leadId]], [
                'id', 'stage_id', 'color', 'probability', 'expected_revenue',
                'contact_name', 'partner_name', 'email_from', 'phone', 'partner_id',
            ], 1, 0, 'id desc');
        } catch (Throwable) {
            return null;
        }

        if ($rows === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
            return null;
        }

        $row = $rows[0];
        $partnerId = 0;
        if (is_array($row['partner_id'] ?? null) && isset($row['partner_id'][0])) {
            $partnerId = (int) $row['partner_id'][0];
        } elseif (is_numeric($row['partner_id'] ?? null)) {
            $partnerId = (int) $row['partner_id'];
        }

        return [
            'stage' => $this->relationName($row['stage_id'] ?? null),
            'color' => isset($row['color']) ? (int) $row['color'] : null,
            'probability' => isset($row['probability']) ? (float) $row['probability'] : null,
            'expected_revenue' => isset($row['expected_revenue']) ? (float) $row['expected_revenue'] : null,
            'contact_name' => filled($row['contact_name'] ?? null) ? (string) $row['contact_name'] : null,
            'partner_name' => filled($row['partner_name'] ?? null) ? (string) $row['partner_name'] : null,
            'email' => filled($row['email_from'] ?? null) ? (string) $row['email_from'] : null,
            'phone' => filled($row['phone'] ?? null) ? (string) $row['phone'] : null,
            'partner_id' => $partnerId > 0 ? (string) $partnerId : null,
            'odoo_url' => $this->recordUrl('crm.lead', (int) $leadId),
        ];
    }

    /**
     * @return array{name: string|null, email: string|null, phone: string|null, odoo_url: string}|null
     */
    public function partnerSnapshot(int|string $partnerId): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $rows = $this->searchRead('res.partner', [['id', '=', (int) $partnerId]], [
                'id', 'name', 'email', 'phone',
            ], 1, 0, 'id desc');
        } catch (Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'name' => filled($row['name'] ?? null) ? (string) $row['name'] : null,
            'email' => filled($row['email'] ?? null) ? (string) $row['email'] : null,
            'phone' => filled($row['phone'] ?? null) ? (string) $row['phone'] : null,
            'odoo_url' => $this->recordUrl('res.partner', (int) $partnerId),
        ];
    }

    public function archiveOrUnlink(string $model, int|string $recordId): void
    {
        $id = (int) $recordId;
        if ($id <= 0) {
            return;
        }

        try {
            $this->unlinkRecords($model, [$id]);
        } catch (Throwable $exception) {
            Log::warning('Odoo unlink failed; archiving record instead.', [
                'model' => $model,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);
            $this->writeRecord($model, $id, ['active' => false]);
        }
    }

    public function findCrmLead(string $name, ?string $partnerName = null): ?int
    {
        $domain = [['name', '=', $name]];
        if (filled($partnerName)) {
            $domain[] = ['partner_name', '=', $partnerName];
        }

        return $this->firstId('crm.lead', $domain);
    }

    public function findCrmLeadByContact(?string $phone, ?string $email = null, ?string $contactName = null): ?int
    {
        if (filled($phone)) {
            foreach ([['phone', '=', $phone], ['mobile', '=', $phone]] as $clause) {
                $id = $this->firstId('crm.lead', [$clause]);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        if (filled($email)) {
            $id = $this->firstId('crm.lead', [['email_from', '=', $email]]);
            if ($id !== null) {
                return $id;
            }
        }

        if (filled($contactName)) {
            return $this->firstId('crm.lead', [['contact_name', '=', $contactName]]);
        }

        return null;
    }

    /**
     * @param  list<list<mixed>>  $domain
     */
    private function firstId(string $model, array $domain): ?int
    {
        try {
            $ids = $this->call($model, 'search', [
                'domain' => $domain,
                'limit' => 1,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($ids) || ! isset($ids[0]) || ! is_numeric($ids[0])) {
            return null;
        }

        $id = (int) $ids[0];

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{id: int, team_id: int|null}|null
     */
    public function findCrmStage(string $stageName, ?int $preferTeamId = null): ?array
    {
        $normalized = trim($stageName);
        if ($normalized === '') {
            return null;
        }

        try {
            $rows = $this->searchRead('crm.stage', [], ['id', 'name', 'team_id', 'team_ids'], 200, 0, 'sequence asc');
        } catch (Throwable) {
            try {
                $rows = $this->searchRead('crm.stage', [], ['id', 'name', 'team_id'], 200, 0, 'sequence asc');
            } catch (Throwable) {
                $rows = $this->searchRead('crm.stage', [], ['id', 'name'], 200, 0, 'sequence asc');
            }
        }

        $matches = [];
        foreach ($rows as $row) {
            $candidate = trim((string) ($row['name'] ?? ''));
            if ($candidate === $normalized || ($candidate !== '' && mb_stripos($candidate, $normalized) !== false)) {
                $matches[] = $row;
            }
        }

        if ($matches === []) {
            return null;
        }

        $match = $matches[0];
        if ($preferTeamId !== null) {
            foreach ($matches as $row) {
                if ($this->stageTeamId($row) === $preferTeamId) {
                    $match = $row;
                    break;
                }
            }
        }

        $teamId = $this->stageTeamId($match);

        return [
            'id' => (int) $match['id'],
            'team_id' => $teamId,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stageTeamId(array $row): ?int
    {
        if (is_array($row['team_id'] ?? null) && isset($row['team_id'][0])) {
            $id = (int) $row['team_id'][0];

            return $id > 0 ? $id : null;
        }

        if (is_numeric($row['team_id'] ?? null)) {
            $id = (int) $row['team_id'];

            return $id > 0 ? $id : null;
        }

        if (is_array($row['team_ids'] ?? null) && isset($row['team_ids'][0]) && is_numeric($row['team_ids'][0])) {
            $id = (int) $row['team_ids'][0];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    public function findCrmStageId(string $stageName): ?int
    {
        $stage = $this->findCrmStage($stageName);

        return $stage['id'] ?? null;
    }

    /**
     * @return list<array{lead_id: int, name: string, email: string|null, phone: string|null, company_name: string|null, odoo_partner_id: string|null, odoo_stage_name: string|null, odoo_url: string}>
     */
    public function listCrmClients(int $limit = 200, int $offset = 0): array
    {
        $rows = $this->searchRead('crm.lead', [
            ['active', '=', true],
        ], [
            'id', 'name', 'contact_name', 'partner_name', 'partner_id', 'email_from', 'phone', 'stage_id',
        ], $limit, $offset, 'write_date desc');

        $clients = [];
        $seen = [];

        foreach ($rows as $row) {
            $leadId = (int) ($row['id'] ?? 0);
            if ($leadId <= 0) {
                continue;
            }

            $partnerId = 0;
            if (is_array($row['partner_id'] ?? null) && isset($row['partner_id'][0])) {
                $partnerId = (int) $row['partner_id'][0];
            } elseif (is_numeric($row['partner_id'] ?? null)) {
                $partnerId = (int) $row['partner_id'];
            }

            $name = filled($row['contact_name'] ?? null)
                ? (string) $row['contact_name']
                : (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $email = filled($row['email_from'] ?? null) ? (string) $row['email_from'] : null;
            $phone = filled($row['phone'] ?? null)
                ? (string) $row['phone']
                : (filled($row['mobile'] ?? null) ? (string) $row['mobile'] : null);

            $key = $partnerId > 0
                ? 'partner:'.$partnerId
                : 'lead:'.$leadId.':'.strtolower($email ?? $name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $company = filled($row['partner_name'] ?? null) ? (string) $row['partner_name'] : null;

            $clients[] = [
                'lead_id' => $leadId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'company_name' => $company,
                'odoo_partner_id' => $partnerId > 0 ? (string) $partnerId : null,
                'odoo_stage_name' => $this->relationName($row['stage_id'] ?? null),
                'odoo_url' => $partnerId > 0
                    ? $this->recordUrl('res.partner', $partnerId)
                    : $this->recordUrl('crm.lead', $leadId),
            ];
        }

        return $clients;
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
        ?string $existingPartnerId = null,
    ): array {
        $partnerId = $this->createOrReusePartner($partnerName, $email, $phone, $requestNumber, $existingPartnerId);
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

    public function createOrReusePartner(string $partnerName, ?string $email, ?string $phone, ?string $requestNumber = null, ?string $existingPartnerId = null): string
    {
        if (filled($existingPartnerId)) {
            return (string) $existingPartnerId;
        }

        if (filled($email)) {
            $existing = $this->call('res.partner', 'search', [
                'domain' => [['email', '=', $email]],
                'limit' => 1,
            ]);
            if (is_array($existing) && isset($existing[0])) {
                return (string) $existing[0];
            }
        }

        if (filled($phone)) {
            $existing = $this->call('res.partner', 'search', [
                'domain' => [['phone', '=', $phone]],
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

    /**
     * @return list<array{id: int, name: string, email: string|null, phone: string|null, barcode: string|null, active: bool, odoo_url: string}>
     */
    public function listEmployees(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->searchRead('hr.employee', [
            ['active', 'in', [true, false]],
        ], [
            'id', 'name', 'work_email', 'work_phone', 'mobile_phone', 'barcode', 'active',
        ], $limit, $offset, 'name asc');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => filled($row['work_email'] ?? null) ? (string) $row['work_email'] : null,
            'phone' => filled($row['work_phone'] ?? null)
                ? (string) $row['work_phone']
                : (filled($row['mobile_phone'] ?? null) ? (string) $row['mobile_phone'] : null),
            'barcode' => filled($row['barcode'] ?? null) ? (string) $row['barcode'] : null,
            'active' => (bool) ($row['active'] ?? true),
            'odoo_url' => $this->recordUrl('hr.employee', (int) $row['id']),
        ], $rows);
    }

    public function createOrReuseEmployee(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $code = null,
        bool $active = true,
    ): string {
        if (filled($email)) {
            $existing = $this->call('hr.employee', 'search', [
                'domain' => [['work_email', '=', $email]],
                'limit' => 1,
            ]);
            if (is_array($existing) && isset($existing[0])) {
                return (string) $existing[0];
            }
        }

        if (filled($code)) {
            $existing = $this->call('hr.employee', 'search', [
                'domain' => [['barcode', '=', $code]],
                'limit' => 1,
            ]);
            if (is_array($existing) && isset($existing[0])) {
                return (string) $existing[0];
            }
        }

        $employeeId = $this->call('hr.employee', 'create', [[
            'name' => $name,
            'work_email' => $email,
            'work_phone' => $phone,
            'barcode' => $code,
            'active' => $active,
        ]]);

        return (string) $employeeId;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function writeEmployee(int|string $employeeId, array $values): void
    {
        $this->writeRecord('hr.employee', $employeeId, $values);
    }

    /**
     * @return array{name: string|null, email: string|null, phone: string|null, barcode: string|null, active: bool, odoo_url: string}|null
     */
    public function employeeSnapshot(int|string $employeeId): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $rows = $this->searchRead('hr.employee', [['id', '=', (int) $employeeId]], [
                'id', 'name', 'work_email', 'work_phone', 'mobile_phone', 'barcode', 'active',
            ], 1, 0, 'id desc');
        } catch (Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'name' => filled($row['name'] ?? null) ? (string) $row['name'] : null,
            'email' => filled($row['work_email'] ?? null) ? (string) $row['work_email'] : null,
            'phone' => filled($row['work_phone'] ?? null)
                ? (string) $row['work_phone']
                : (filled($row['mobile_phone'] ?? null) ? (string) $row['mobile_phone'] : null),
            'barcode' => filled($row['barcode'] ?? null) ? (string) $row['barcode'] : null,
            'active' => (bool) ($row['active'] ?? true),
            'odoo_url' => $this->recordUrl('hr.employee', (int) $employeeId),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function writeRecord(string $model, int|string $recordId, array $values): void
    {
        $this->call($model, 'write', [
            'ids' => [(int) $recordId],
            'vals' => $values,
        ]);
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
    private function searchRead(string $model, array $domain, array $fields, int $limit = 80, int $offset = 0, string $order = ''): array
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

    /**
     * @param  array<mixed>  $rows
     * @return array<string, mixed>|null
     */
    private function firstRecord(array $rows): ?array
    {
        if (isset($rows['id']) && is_numeric($rows['id'])) {
            return $rows;
        }

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                return $row;
            }
        }

        return null;
    }

    private function relationName(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return isset($value[1]) ? (string) $value[1] : null;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
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
            'tax_ids' => [[6, 0, []]],
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

        if ($method === 'write') {
            return [
                'ids' => $payload['ids'] ?? [],
                'vals' => $payload['vals'] ?? [],
            ];
        }

        if ($method === 'unlink') {
            return [
                'ids' => $payload['ids'] ?? [],
            ];
        }

        if ($method === 'message_post') {
            return $payload;
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

        if ($method === 'write') {
            return $this->execute($uid, $model, $method, [
                $payload['ids'] ?? [],
                $payload['vals'] ?? [],
            ]);
        }

        if ($method === 'unlink') {
            return $this->execute($uid, $model, $method, [
                $payload['ids'] ?? [],
            ]);
        }

        if ($method === 'message_post') {
            $ids = $payload['ids'] ?? [];
            $kwargs = $payload;
            unset($kwargs['ids']);

            return $this->executeKw($uid, $model, $method, [$ids], $kwargs);
        }

        return $this->execute($uid, $model, $method, [$payload]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    private function executeKw(int $uid, string $model, string $method, array $args, array $kwargs = []): mixed
    {
        return $this->jsonrpc('object', 'execute_kw', [
            config('services.odoo.db'),
            $uid,
            config('services.odoo.api_key'),
            $model,
            $method,
            $args,
            $kwargs,
        ]);
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
