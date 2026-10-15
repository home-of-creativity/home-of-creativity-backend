<?php

namespace App\Services;

use App\Support\OdooNoteHtml;
use App\Support\OdooPaperLimits;
use App\Support\OdooPaperPdf;
use App\Support\PaymentTermLabel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OdooClient
{
    /** Quotations keep Odoo's «QN» numbers; a confirmed order reads «S», a proforma «PF», an invoice «INV». */
    public const QUOTATION_PREFIX = 'QN';

    public const ORDER_PREFIX = 'S';

    public const PROFORMA_PREFIX = 'PF';

    public const INVOICE_CODE = 'INV';

    private ?int $legacyUid = null;

    private ?int $currencyId = null;

    private ?int $paymentJournalId = null;

    /** @var array<string, int|null> */
    private array $tagIdCache = [];

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
        return ['id', 'name', 'partner_id', 'amount_total', 'amount_untaxed', 'currency_id', 'state', 'client_order_ref', 'origin', 'date_order', 'payment_term_id'];
    }

    /**
     * @return list<string>
     */
    private function invoiceFields(): array
    {
        return ['id', 'name', 'partner_id', 'amount_total', 'amount_untaxed', 'amount_residual', 'currency_id', 'state', 'payment_state', 'invoice_origin', 'ref', 'invoice_date', 'invoice_date_due', 'write_date', 'invoice_payment_term_id', 'commercial_partner_id'];
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
            'partner_id' => $this->relationId($row['partner_id'] ?? null),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'currency' => $this->relationName($row['currency_id'] ?? null),
            'state' => (string) ($row['state'] ?? ''),
            'client_order_ref' => $this->optionalString($row['client_order_ref'] ?? null),
            'origin' => $this->optionalString($row['origin'] ?? null),
            'date_order' => $this->optionalString($row['date_order'] ?? null),
            'payment_term_id' => $this->relationId($row['payment_term_id'] ?? null),
            'payment_term_name' => $this->relationName($row['payment_term_id'] ?? null),
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
            'partner_id' => $this->relationId($row['partner_id'] ?? null),
            'partner_name' => $this->relationName($row['partner_id'] ?? null),
            'commercial_partner_id' => $this->relationId($row['commercial_partner_id'] ?? null),
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'amount_residual' => $this->invoiceResidual($row),
            'currency' => $this->relationName($row['currency_id'] ?? null),
            'state' => (string) ($row['state'] ?? ''),
            'payment_state' => (string) ($row['payment_state'] ?? ''),
            'invoice_origin' => $this->optionalString($row['invoice_origin'] ?? null),
            'ref' => $this->optionalString($row['ref'] ?? null),
            'invoice_date' => $this->optionalString($row['invoice_date'] ?? null),
            'invoice_date_due' => $this->optionalString($row['invoice_date_due'] ?? null),
            'write_date' => $this->optionalString($row['write_date'] ?? null),
            'payment_term_id' => $this->relationId($row['invoice_payment_term_id'] ?? null),
            'payment_term_name' => $this->relationName($row['invoice_payment_term_id'] ?? null),
            'odoo_url' => $this->recordUrl('account.move', (int) $row['id']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQuotations(int $limit = 100, int $offset = 0, ?int $partnerId = null): array
    {
        $rows = $this->searchRead('sale.order', $this->partnerDomain($partnerId), $this->quotationFields(), $limit, $offset, 'date_order desc');

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

    /** The terms block (`note`, HTML) of a quotation, or null when it has none. */
    public function quotationNote(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstRecord($this->searchRead('sale.order', [['id', '=', $id]], ['note'], 1));
        $note = $row['note'] ?? null;

        return is_string($note) && trim(strip_tags($note)) !== '' ? $note : null;
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
    public function listInvoices(int $limit = 100, int $offset = 0, ?int $partnerId = null): array
    {
        $domain = [['move_type', '=', 'out_invoice']];
        if ($partnerId !== null && $partnerId > 0) {
            array_unshift($domain, '&');
            $domain = array_merge($domain, $this->partnerDomain($partnerId));
        }

        $rows = $this->searchRead('account.move', $domain, $this->invoiceFields(), $limit, $offset, 'invoice_date desc');

        return array_map(fn (array $row): array => $this->mapInvoice($row), $rows);
    }

    /**
     * Customer invoices changed in Odoo after $since (UTC "Y-m-d H:i:s"), oldest change first.
     * A null $since walks every customer invoice.
     *
     * @return list<array<string, mixed>>
     */
    public function invoicesChangedSince(?string $since, int $limit = 200, int $offset = 0): array
    {
        $domain = [['move_type', '=', 'out_invoice']];
        if (filled($since)) {
            $domain[] = ['write_date', '>', $since];
        }

        $rows = $this->searchRead('account.move', $domain, $this->invoiceFields(), $limit, $offset, 'write_date asc, id asc');

        return array_map(fn (array $row): array => $this->mapInvoice($row), $rows);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function existingInvoiceIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->searchRead('account.move', [['id', 'in', $ids]], ['id'], count($ids));

        return array_map(fn (array $row): int => (int) $row['id'], $rows);
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

        $extraTagIds = is_array($values['tag_ids'] ?? null) ? $values['tag_ids'] : [];
        $tagIds = $this->withTelegramTagIds($extraTagIds);

        $payload = array_filter([
            'name' => $values['name'],
            'contact_name' => $values['contact_name'] ?? null,
            'partner_name' => $values['partner_name'] ?? null,
            'stage_id' => $stageId,
            'team_id' => $teamId,
            'user_id' => $values['user_id'] ?? $this->crmOwnerUserId($teamId),
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

    public function crmOwnerUserId(?int $teamId = null): ?int
    {
        try {
            if ($teamId !== null) {
                $leaderId = $this->crmTeamLeaderId($teamId);
                if ($leaderId !== null) {
                    return $leaderId;
                }
            }

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

    public function crmTeamLeaderId(int $teamId): ?int
    {
        if ($teamId <= 0) {
            return null;
        }

        try {
            $rows = $this->searchRead('crm.team', [['id', '=', $teamId]], ['id', 'user_id'], 1, 0, 'id asc');
        } catch (Throwable) {
            return null;
        }

        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        if (is_array($row['user_id'] ?? null) && isset($row['user_id'][0])) {
            $id = (int) $row['user_id'][0];

            return $id > 0 ? $id : null;
        }

        if (is_numeric($row['user_id'] ?? null)) {
            $id = (int) $row['user_id'];

            return $id > 0 ? $id : null;
        }

        return null;
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

    /**
     * Every Telegram-originated lead always carries the "تلغرام" tag so the
     * source is identifiable in Odoo regardless of the company's industry
     * tag. Returns an Odoo o2m command list, or null if the tag is
     * unavailable (Odoo down) and there is nothing else to set.
     *
     * @param  list<int>  $extraTagIds
     * @return list<mixed>|null
     */
    private function withTelegramTagIds(array $extraTagIds = []): ?array
    {
        $telegramTagId = $this->ensureCrmTagId('تلغرام');
        $ids = array_values(array_unique(array_filter(
            [...$extraTagIds, $telegramTagId],
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        )));

        if ($ids === []) {
            return null;
        }

        return [[6, 0, array_map('intval', $ids)]];
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

    /** An internal note in the record's chatter. Unlike messagePost, a failure is thrown. */
    public function postNote(string $model, int $recordId, string $html): void
    {
        $this->call($model, 'message_post', [
            'ids' => [$recordId],
            'body' => $html,
            'body_html' => $html,
            'body_is_html' => true,
            'message_type' => 'comment',
            'subtype_xmlid' => 'mail.mt_note',
        ]);
    }

    /**
     * The chatter of a quotation or invoice, newest first: messages, internal notes, and
     * the field changes Odoo tracked on each.
     *
     * @return list<array{id: int, date: string|null, author: string|null, body: string, kind: string, subtype: string|null, changes: list<array{field: string, old: string, new: string}>}>
     */
    public function paperMessages(string $model, int $recordId, int $limit = 80): array
    {
        if ($recordId <= 0 || ! $this->configured()) {
            return [];
        }
        $domain = [['model', '=', $model], ['res_id', '=', $recordId]];
        $fields = ['id', 'date', 'body', 'author_id', 'message_type', 'subtype_id', 'tracking_value_ids'];
        try {
            $rows = $this->searchRead('mail.message', $domain, [...$fields, 'is_internal'], $limit, 0, 'date desc, id desc');
        } catch (Throwable) {
            $rows = $this->searchRead('mail.message', $domain, $fields, $limit, 0, 'date desc, id desc');
        }

        $trackingIds = [];
        foreach ($rows as $row) {
            foreach ($this->idList($row['tracking_value_ids'] ?? []) as $id) {
                $trackingIds[] = $id;
            }
        }
        $tracking = $this->trackingValues($trackingIds);

        $messages = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $subtype = $this->relationName($row['subtype_id'] ?? null);
            $type = (string) ($row['message_type'] ?? '');
            $internal = (bool) ($row['is_internal'] ?? false) || in_array($subtype, ['Note', 'ملاحظة'], true);
            $changes = [];
            foreach ($this->idList($row['tracking_value_ids'] ?? []) as $id) {
                if (isset($tracking[$id])) {
                    $changes[] = $tracking[$id];
                }
            }
            $body = is_string($row['body'] ?? null) ? OdooNoteHtml::safe((string) $row['body']) : '';
            if ($body === '' && $changes === [] && $subtype === null) {
                continue;
            }
            $messages[] = [
                'id' => (int) $row['id'],
                'date' => $this->optionalString($row['date'] ?? null),
                'author' => $this->relationName($row['author_id'] ?? null),
                'body' => $body,
                'kind' => $internal ? 'note' : (($type === 'notification' || ($changes !== [] && $body === '')) ? 'change' : 'message'),
                'subtype' => $subtype,
                'changes' => $changes,
            ];
        }

        return $messages;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{field: string, old: string, new: string}>
     */
    private function trackingValues(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            // Every field: the names moved between Odoo versions (field_desc, field_id, *_value_*).
            $rows = $this->searchRead('mail.tracking.value', [['id', 'in', $ids]], [], count($ids));
        } catch (Throwable) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $field = $this->optionalString($row['field_desc'] ?? null) ?? $this->relationName($row['field_id'] ?? null) ?? $this->optionalString($row['field'] ?? null) ?? '';
            $values[(int) $row['id']] = [
                'field' => $field,
                'old' => $this->trackedValue($row, 'old'),
                'new' => $this->trackedValue($row, 'new'),
            ];
        }

        return $values;
    }

    /** @param  array<string, mixed>  $row */
    private function trackedValue(array $row, string $side): string
    {
        foreach (['char', 'text', 'datetime', 'integer', 'float', 'monetary'] as $type) {
            $value = $row["{$side}_value_{$type}"] ?? null;
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            if (in_array($type, ['integer', 'float', 'monetary'], true) && (float) $value === 0.0 && ($row["{$side}_value_char"] ?? false)) {
                continue;
            }

            return is_float($value) ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : (string) $value;
        }

        return '';
    }

    public function postLeadNote(int $leadId, string $body): void
    {
        if ($leadId <= 0 || trim($body) === '') {
            return;
        }

        $html = implode('<br/>', array_map(
            fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            preg_split('/\R/u', trim($body)) ?: [],
        ));

        $this->call('crm.lead', 'message_post', [
            'ids' => [$leadId],
            'body' => $html,
            'body_html' => $html,
            'body_is_html' => true,
            'message_type' => 'comment',
            'subtype_xmlid' => 'mail.mt_note',
        ]);
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

    public function markLeadWon(int $leadId, ?float $expectedRevenue = null): void
    {
        if ($leadId <= 0) {
            return;
        }

        try {
            $teamId = $this->findCrmTeamId('تلغرام');
            $stage = $this->findCrmStage('تم الفوز بها', $teamId)
                ?? $this->findCrmStage('Won', $teamId)
                ?? $this->findCrmStage('تم الفوز', $teamId);

            $stageId = $stage['id'] ?? null;
            if ($stageId === null) {
                Log::error('Odoo Won stage not found for markLeadWon.', ['lead_id' => $leadId]);

                return;
            }

            $values = [
                'stage_id' => $stageId,
                'probability' => 100,
                'color' => 10,
                'date_closed' => now()->toDateTimeString(),
            ];
            if ($expectedRevenue !== null && $expectedRevenue > 0.009) {
                $values['expected_revenue'] = round($expectedRevenue, 2);
            }

            $this->writeRecord('crm.lead', $leadId, array_filter(
                $values,
                fn (mixed $value): bool => $value !== null && $value !== '',
            ));
        } catch (Throwable $exception) {
            Log::error('Odoo markLeadWon failed.', [
                'lead_id' => $leadId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function writeLeadExpectedRevenue(int $leadId, float $expectedRevenue): void
    {
        if ($leadId <= 0 || $expectedRevenue <= 0.009) {
            return;
        }

        try {
            $this->writeRecord('crm.lead', $leadId, [
                'expected_revenue' => round($expectedRevenue, 2),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Odoo expected_revenue write failed.', [
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

        if (array_key_exists($normalized, $this->tagIdCache)) {
            return $this->tagIdCache[$normalized];
        }

        try {
            $rows = $this->searchRead('crm.tag', [['name', '=', $normalized]], ['id', 'name'], 1, 0, 'id asc');
            if (isset($rows[0]['id'])) {
                return $this->tagIdCache[$normalized] = (int) $rows[0]['id'];
            }

            return $this->tagIdCache[$normalized] = (int) $this->call('crm.tag', 'create', [['name' => $normalized]]);
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
     * The company a contact belongs to: its commercial partner, then its parent.
     *
     * @return list<int>
     */
    public function partnerParents(int $partnerId): array
    {
        if ($partnerId <= 0 || ! $this->configured()) {
            return [];
        }
        try {
            $row = $this->firstRecord($this->searchRead('res.partner', [['id', '=', $partnerId]], ['commercial_partner_id', 'parent_id'], 1));
        } catch (Throwable) {
            return [];
        }
        if ($row === null) {
            return [];
        }

        return array_values(array_unique(array_filter([
            $this->relationId($row['commercial_partner_id'] ?? null),
            $this->relationId($row['parent_id'] ?? null),
        ], fn (?int $id): bool => $id !== null && $id !== $partnerId)));
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
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function createStaffQuotation(
        string $partnerId,
        string $partnerName,
        ?string $email,
        ?string $phone,
        array $lines,
        ?string $notes,
        ?string $reference,
        int|string|null $opportunityId,
        ?int $paymentTermId = null,
    ): array {
        $created = $this->createQuotation(
            $partnerName,
            $email,
            $phone,
            filled($reference) ? $reference : 'DASH',
            'عرض سعر',
            null,
            $notes,
            $lines,
            $partnerId,
            $opportunityId,
        );
        $id = (int) $created['odoo_quotation_id'];
        if ($paymentTermId !== null && $paymentTermId > 0) {
            $this->call('sale.order', 'write', ['ids' => [$id], 'vals' => ['payment_term_id' => $paymentTermId]]);
        }
        $snapshot = $this->quotationSnapshot($id);

        return $snapshot ?? [
            'id' => $id,
            'name' => '',
            'odoo_url' => $this->recordUrl('sale.order', $id),
        ];
    }

    /**
     * Partner, name, and total of a sale order, used to attach a customer invoice.
     *
     * @return array{id: int, name: string, partner_id: int, amount_total: float, client_order_ref: string|null, state: string}|null
     */
    public function quotationLink(int $id): ?array
    {
        if ($id <= 0 || ! $this->configured()) {
            return null;
        }

        $row = $this->firstRecord($this->searchRead('sale.order', [['id', '=', $id]], [
            'id', 'name', 'partner_id', 'amount_total', 'client_order_ref', 'state', 'payment_term_id',
        ], 1, 0, 'id desc'));

        if ($row === null) {
            return null;
        }

        $partnerId = $this->relationId($row['partner_id'] ?? null);
        if ($partnerId === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'partner_id' => $partnerId,
            'amount_total' => (float) ($row['amount_total'] ?? 0),
            'client_order_ref' => $this->optionalString($row['client_order_ref'] ?? null),
            'state' => (string) ($row['state'] ?? ''),
            'payment_term_id' => $this->relationId($row['payment_term_id'] ?? null),
        ];
    }

    /**
     * The invoice of what is left on the order, through Odoo's wizard so its lines stay
     * linked to the order and its "invoiced" amounts stay right. When the wizard fails on
     * an order with nothing invoiced yet, the lines are copied instead.
     *
     * @param  array{id: int, name: string, partner_id: int, client_order_ref?: string|null, payment_term_id?: int|null}  $order
     * @return array<string, mixed>
     */
    public function invoiceFromSaleOrder(array $order): array
    {
        try {
            $invoiceId = $this->invoiceSaleOrderWizard((int) $order['id'], 'delivered', null);
            if (array_key_exists('payment_term_id', $order)) {
                $this->call('account.move', 'write', ['ids' => [$invoiceId], 'vals' => ['invoice_payment_term_id' => $order['payment_term_id'] ?: false]]);
            }

            return $this->invoiceSnapshot($invoiceId) ?? ['id' => $invoiceId];
        } catch (Throwable $exception) {
            try {
                $invoiced = $this->saleOrderInvoiced((int) $order['id']);
            } catch (Throwable) {
                $invoiced = 0.0;
            }
            if ($invoiced > 0.009) {
                throw $exception;
            }
            Log::warning('Odoo invoice wizard failed; copying the order lines.', ['order' => $order['id'], 'error' => $exception->getMessage()]);
        }

        return $this->createStaffInvoice(
            (string) $order['partner_id'],
            $this->quotationLines((int) $order['id']),
            $order['client_order_ref'] ?? null,
            $order['name'],
            $order['payment_term_id'] ?? null,
        );
    }

    /**
     * @return list<array{id: int, name: string, price: float, description: string|null}>
     */
    public function listSaleProducts(int $limit = 80): array
    {
        $rows = $this->searchRead('product.product', [
            ['sale_ok', '=', true],
        ], ['id', 'name', 'list_price', 'description_sale'], $limit, 0, 'name asc');

        $products = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $name = $row['name'] ?? '';
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $products[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'price' => (float) ($row['list_price'] ?? 0),
                'description' => ($text = $this->plainProductText($row['description_sale'] ?? null)) !== '' ? $text : null,
            ];
        }

        return $products;
    }

    /**
     * @return list<array{title: string, amount?: float, units?: float, discount?: float, display_type?: string}>
     */
    public function quotationLines(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $rows = $this->searchRead('sale.order.line', [
            ['order_id', '=', $orderId],
        ], ['name', 'price_unit', 'product_uom_qty', 'discount', 'display_type', 'tax_ids'], 40, 0, 'id asc');

        $lines = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $display = $row['display_type'] ?? null;
            if ($display === 'line_section' || $display === 'line_note') {
                $lines[] = [
                    'title' => (string) ($row['name'] ?? ''),
                    'display_type' => $display,
                ];

                continue;
            }
            $lines[] = [
                'title' => (string) ($row['name'] ?? ''),
                'amount' => (float) ($row['price_unit'] ?? 0),
                'units' => (float) ($row['product_uom_qty'] ?? 1),
                'discount' => (float) ($row['discount'] ?? 0),
                'tax_ids' => $this->idList($row['tax_ids'] ?? []),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function createStaffInvoice(
        string $partnerId,
        array $lines,
        ?string $reference = null,
        ?string $origin = null,
        ?int $paymentTermId = null,
    ): array {
        $values = [
            'move_type' => 'out_invoice',
            'partner_id' => (int) $partnerId,
            'invoice_origin' => filled($origin) ? $origin : null,
            'ref' => filled($reference) ? $reference : null,
            'invoice_date' => now()->toDateString(),
        ];
        if ($paymentTermId !== null && $paymentTermId > 0) {
            $values['invoice_payment_term_id'] = $paymentTermId;
        }

        if ($currencyId = $this->resolveCurrencyId()) {
            $values['currency_id'] = $currencyId;
        }

        $values['invoice_line_ids'] = array_map(
            fn (array $line): array => [0, 0, $this->staffInvoiceLine($line)],
            $lines,
        );

        $id = (int) $this->call('account.move', 'create', [$values]);
        $snapshot = $this->invoiceSnapshot($id);

        return $snapshot ?? [
            'id' => $id,
            'name' => '',
            'odoo_url' => $this->recordUrl('account.move', $id),
        ];
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
        int|string|null $opportunityId = null,
    ): array {
        $partnerId = $this->createOrReusePartner($partnerName, $email, $phone, $requestNumber, $existingPartnerId);
        $notes = $notes !== null ? OdooNoteHtml::safe($notes) : null;
        $lineName = filled($notes) ? "{$title}\n{$notes}" : $title;
        $orderValues = [
            'partner_id' => (int) $partnerId,
            'client_order_ref' => $requestNumber,
            'origin' => $requestNumber,
        ];
        // The note is the terms block of the printed quotation. With lines it carries only the
        // staff terms; a single-amount quotation keeps its text on the line. Without terms the
        // key is left out so Odoo's default terms stay.
        $withLines = $lines !== null && $lines !== [];
        if ($withLines && filled($notes)) {
            $orderValues['note'] = $notes;
        }

        if (filled($opportunityId) && (int) $opportunityId > 0) {
            $orderValues['opportunity_id'] = (int) $opportunityId;
        }

        if ($currencyId = $this->resolveCurrencyId()) {
            $orderValues['currency_id'] = $currencyId;
        }

        if ($withLines) {
            $lines = $this->withProductDetails($lines);
            $orderValues['order_line'] = array_map(
                fn (array $line): array => [0, 0, $this->staffSaleLine($line)],
                $lines,
            );
        } elseif ($amount !== null && $amount > 0) {
            $orderValues['order_line'] = [[0, 0, $this->saleOrderLine([
                'name' => $lineName,
                'product_uom_qty' => 1,
                'price_unit' => $amount,
            ])]];
        }

        try {
            $orderId = $this->call('sale.order', 'create', [$orderValues]);
        } catch (Throwable $exception) {
            if (! isset($orderValues['opportunity_id'])) {
                throw $exception;
            }

            // Older/custom Odoo CRM setups may not expose opportunity_id on
            // sale.order; retry without it rather than losing the quotation.
            unset($orderValues['opportunity_id']);
            $orderId = $this->call('sale.order', 'create', [$orderValues]);
        }

        return [
            'odoo_partner_id' => (string) $partnerId,
            'odoo_quotation_id' => (string) $orderId,
        ];
    }

    public function writeSaleDates(int $id, ?string $dateOrder, ?string $validity): void
    {
        if ($id <= 0 || ! $this->configured()) {
            return;
        }

        $vals = [];
        if (filled($dateOrder)) {
            $vals['date_order'] = $dateOrder.' 00:00:00';
        }
        if (filled($validity)) {
            $vals['validity_date'] = $validity;
        }
        if ($vals === []) {
            return;
        }

        $this->call('sale.order', 'write', ['ids' => [$id], 'vals' => $vals]);
    }

    public function writeInvoiceDates(int $id, ?string $invoiceDate, ?string $dueDate): void
    {
        if ($id <= 0 || ! $this->configured()) {
            return;
        }

        $vals = [];
        if (filled($invoiceDate)) {
            $vals['invoice_date'] = $invoiceDate;
        }
        if (filled($dueDate)) {
            $vals['invoice_date_due'] = $dueDate;
        }
        if ($vals === []) {
            return;
        }

        $this->call('account.move', 'write', ['ids' => [$id], 'vals' => $vals]);
    }

    public function markQuotationSent(int $id): void
    {
        if ($id <= 0 || ! $this->configured()) {
            return;
        }

        $this->call('sale.order', 'action_quotation_sent', ['ids' => [$id]]);
    }

    /**
     * An approved quotation becomes a sales order. A quotation already confirmed or
     * cancelled is left as it is.
     */
    public function confirmSaleOrder(int $id): bool
    {
        if ($id <= 0 || ! $this->configured()) {
            return false;
        }
        $state = (string) ($this->quotationSnapshot($id)['state'] ?? '');
        if (! in_array($state, ['draft', 'sent'], true)) {
            return $state === 'sale';
        }
        $this->call('sale.order', 'action_confirm', ['ids' => [$id]]);
        try {
            $this->renameConfirmedOrders($id);
        } catch (Throwable $exception) {
            Log::warning('Odoo order kept its quotation number.', ['order' => $id, 'error' => $exception->getMessage()]);
        }

        return true;
    }

    /**
     * New lines (and notes or reference) on a quotation that is not a sales order yet.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function updateStaffQuotation(int $id, array $lines, ?string $notes, ?string $reference, ?int $paymentTermId = null): array
    {
        $vals = [
            // 5 clears the old lines, 0 adds each new one.
            'order_line' => array_merge([[5, 0, 0]], array_map(fn (array $line): array => [0, 0, $this->staffSaleLine($line)], $lines)),
        ];
        if ($notes !== null) {
            $vals['note'] = OdooNoteHtml::safe($notes);
        }
        if ($reference !== null && $reference !== '') {
            $vals['client_order_ref'] = $reference;
        }
        if ($paymentTermId !== null) {
            $vals['payment_term_id'] = $paymentTermId > 0 ? $paymentTermId : false;
        }
        $this->call('sale.order', 'write', ['ids' => [$id], 'vals' => $vals]);

        return $this->quotationSnapshot($id) ?? ['id' => $id];
    }

    /**
     * A draft that never left goes away; a sent quotation is cancelled so its history
     * stays in Odoo. A confirmed sales order is not touched here.
     */
    public function removeQuotation(int $id): string
    {
        $state = (string) ($this->quotationSnapshot($id)['state'] ?? '');
        if ($state === 'draft') {
            $this->unlinkRecords('sale.order', [$id]);

            return 'deleted';
        }
        if ($state === 'sent') {
            // Without this context Odoo answers a sent quotation with a confirmation wizard.
            $this->callWithContext('sale.order', 'action_cancel', [$id], ['disable_cancel_warning' => true]);

            return 'cancelled';
        }

        return $state;
    }

    /**
     * New lines on an unpaid invoice: a posted one goes back to draft, takes the lines,
     * and is posted again.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function updateStaffInvoice(int $id, array $lines, ?string $reference, ?int $paymentTermId = null): array
    {
        $row = $this->invoiceSnapshot($id);
        $posted = ($row['state'] ?? '') === 'posted';
        if ($posted) {
            $this->call('account.move', 'button_draft', ['ids' => [$id]]);
        }
        $vals = [
            'invoice_line_ids' => array_merge([[5, 0, 0]], array_map(fn (array $line): array => [0, 0, $this->staffInvoiceLine($line)], $lines)),
        ];
        if ($reference !== null && $reference !== '') {
            $vals['ref'] = $reference;
        }
        if ($paymentTermId !== null) {
            $vals['invoice_payment_term_id'] = $paymentTermId > 0 ? $paymentTermId : false;
        }
        $this->call('account.move', 'write', ['ids' => [$id], 'vals' => $vals]);
        if ($posted) {
            $this->postMove($id);
        }

        return $this->invoiceSnapshot($id) ?? ['id' => $id];
    }

    /** The customer of a draft invoice; its lines stay linked to the order. */
    public function setInvoicePartner(int $id, int $partnerId): void
    {
        $this->call('account.move', 'write', ['ids' => [$id], 'vals' => ['partner_id' => $partnerId]]);
    }

    /** An unpaid invoice is cancelled; a posted one goes through draft first, as in Odoo. */
    public function cancelInvoice(int $id): void
    {
        $state = (string) ($this->invoiceSnapshot($id)['state'] ?? '');
        if ($state === 'posted') {
            $this->call('account.move', 'button_draft', ['ids' => [$id]]);
        }
        if ($state !== 'cancel') {
            $this->call('account.move', 'button_cancel', ['ids' => [$id]]);
        }
    }

    /**
     * Cancels a confirmed sales order. Its unpaid invoices are cancelled first. Null,
     * and nothing touched, when one of its invoices has a payment.
     *
     * @return list<int>|null the invoices that were cancelled
     */
    public function cancelSalesOrder(int $id): ?array
    {
        $order = $this->firstRecord($this->searchRead('sale.order', [['id', '=', $id]], ['state', 'invoice_ids'], 1));
        $invoiceIds = array_values(array_filter(array_map('intval', (array) ($order['invoice_ids'] ?? []))));
        $invoices = $invoiceIds === [] ? [] : $this->searchRead('account.move', [['id', 'in', $invoiceIds]], ['id', 'state', 'payment_state'], 50);
        foreach ($invoices as $invoice) {
            if (($invoice['state'] ?? '') === 'posted' && ($invoice['payment_state'] ?? 'not_paid') !== 'not_paid') {
                return null;
            }
        }

        $cancelled = [];
        foreach ($invoices as $invoice) {
            if (($invoice['state'] ?? '') !== 'cancel') {
                $this->cancelInvoice((int) $invoice['id']);
                $cancelled[] = (int) $invoice['id'];
            }
        }
        $this->callWithContext('sale.order', 'action_cancel', [$id], ['disable_cancel_warning' => true]);

        return $cancelled;
    }

    /**
     * The lines of a quotation (`sale.order`) or an invoice (`account.move`), as the
     * dashboard paper form edits them. Tax and payment-term lines are left out.
     *
     * @return list<array{title: string, notes: string|null, amount: float, units: float, discount: float, display_type: string|null, product_id: int|null, tax_ids: list<int>}>
     */
    public function paperLines(string $model, int $id): array
    {
        if ($id <= 0 || ! $this->configured()) {
            return [];
        }
        $sale = $model === 'sale.order';
        $rows = $sale
            ? $this->searchRead('sale.order.line', [['order_id', '=', $id]], ['name', 'product_uom_qty', 'price_unit', 'discount', 'display_type', 'product_id', 'tax_ids'], 200, 0, 'sequence asc, id asc')
            : $this->searchRead('account.move.line', [['move_id', '=', $id], ['display_type', 'in', ['product', 'line_section', 'line_note']]], ['name', 'quantity', 'price_unit', 'discount', 'display_type', 'product_id', 'tax_ids'], 200, 0, 'sequence asc, id asc');

        $lines = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = is_string($row['display_type'] ?? null) && in_array($row['display_type'], ['line_section', 'line_note'], true) ? $row['display_type'] : null;
            [$title, $notes] = array_pad(explode("\n", (string) ($row['name'] ?? ''), 2), 2, null);
            $lines[] = [
                'title' => trim((string) $title),
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'amount' => (float) ($row['price_unit'] ?? 0),
                'units' => (float) ($row[$sale ? 'product_uom_qty' : 'quantity'] ?? 1),
                'discount' => (float) ($row['discount'] ?? 0),
                'display_type' => $type,
                'product_id' => $this->relationId($row['product_id'] ?? null),
                'tax_ids' => $type === null ? $this->idList($row['tax_ids'] ?? []) : [],
            ];
        }

        return $lines;
    }

    /**
     * Sales taxes the dashboard offers on each paper line.
     *
     * @return list<array{id: int, name: string, amount: float, amount_type: string, price_include: bool}>
     */
    public function listSaleTaxes(): array
    {
        $domain = [['type_tax_use', '=', 'sale'], ['active', '=', true]];
        try {
            $rows = $this->searchRead('account.tax', $domain, ['id', 'name', 'amount', 'amount_type', 'price_include'], 100, 0, 'sequence asc, id asc');
        } catch (Throwable) {
            // Odoo versions without a readable price_include: taxes count as excluded.
            $rows = $this->searchRead('account.tax', $domain, ['id', 'name', 'amount', 'amount_type'], 100, 0, 'sequence asc, id asc');
        }

        return array_values(array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'amount_type' => (string) ($row['amount_type'] ?? 'percent'),
            'price_include' => (bool) ($row['price_include'] ?? false),
        ], array_filter($rows, fn (mixed $row): bool => is_array($row) && isset($row['id']))));
    }

    /**
     * Payment terms (immediate, 15 days, 30 days, …) for quotations and invoices.
     *
     * @return list<array{id: int, name: string}>
     */
    public function listPaymentTerms(): array
    {
        $domain = [['active', '=', true]];
        $fields = ['id', 'name'];
        $order = 'sequence asc, id asc';
        // Odoo's own Arabic translation first; terms without one are translated by PaymentTermLabel.
        try {
            $context = ['lang' => 'ar_001'];
            $rows = $this->useJson2()
                ? $this->json2('account.payment.term', 'search_read', ['domain' => $domain, 'fields' => $fields, 'limit' => 100, 'order' => $order, 'context' => $context])
                : $this->executeKw($this->legacyUid(), 'account.payment.term', 'search_read', [$domain], ['fields' => $fields, 'limit' => 100, 'order' => $order, 'context' => $context]);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('Odoo did not return a record list.');
            }
        } catch (Throwable) {
            $rows = $this->searchRead('account.payment.term', $domain, $fields, 100, 0, $order);
        }

        return array_values(array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => PaymentTermLabel::arabic((string) ($row['name'] ?? '')),
        ], array_filter($rows, fn (mixed $row): bool => is_array($row) && isset($row['id']))));
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $value): array
    {
        return array_values(array_filter(array_map('intval', is_array($value) ? $value : []), fn (int $id): bool => $id > 0));
    }

    /**
     * What a sales order has invoiced so far: the total of its invoices that are not
     * cancelled, credit notes taken off. The "already invoiced" line of Odoo's wizard.
     */
    public function saleOrderInvoiced(int $orderId): float
    {
        $order = $this->firstRecord($this->searchRead('sale.order', [['id', '=', $orderId]], ['invoice_ids'], 1));
        $ids = $this->idList($order['invoice_ids'] ?? []);
        if ($ids === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($this->searchRead('account.move', [['id', 'in', $ids], ['state', '!=', 'cancel']], ['amount_total', 'move_type'], 100) as $row) {
            $total += ($row['move_type'] ?? '') === 'out_refund' ? -(float) $row['amount_total'] : (float) ($row['amount_total'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Odoo's "Create invoice" wizard on a sales order: `delivered` is the regular invoice of
     * what is left to invoice, `percentage` and `fixed` are a down payment. A draft quotation
     * is confirmed first, as only a sales order is invoiced. A down payment line is named after
     * the ordered products instead of Odoo's "Down payment of 50%".
     *
     * @return int the new draft invoice
     */
    public function invoiceSaleOrderWizard(int $orderId, string $method, ?float $value): int
    {
        $this->confirmSaleOrder($orderId);
        $before = $this->idList($this->firstRecord($this->searchRead('sale.order', [['id', '=', $orderId]], ['invoice_ids'], 1))['invoice_ids'] ?? []);

        $context = ['active_model' => 'sale.order', 'active_ids' => [$orderId], 'active_id' => $orderId];
        $values = ['advance_payment_method' => $method, 'sale_order_ids' => [[6, 0, [$orderId]]]];
        if ($method === 'percentage') {
            $values['amount'] = (float) $value;
        } elseif ($method === 'fixed') {
            $values['fixed_amount'] = (float) $value;
        }
        $created = $this->createWithContext('sale.advance.payment.inv', $values, $context);
        $wizard = (int) (is_array($created) ? ($created[0] ?? 0) : $created);
        $this->callWithContext('sale.advance.payment.inv', 'create_invoices', [$wizard], $context);

        $after = $this->idList($this->firstRecord($this->searchRead('sale.order', [['id', '=', $orderId]], ['invoice_ids'], 1))['invoice_ids'] ?? []);
        $new = array_values(array_diff($after, $before));
        if ($new === []) {
            throw new RuntimeException('Odoo created no invoice: nothing is left to invoice on this order.');
        }
        $invoiceId = max($new);

        if ($method !== 'delivered') {
            $this->nameDownPayment($orderId, $invoiceId, $method, (float) $value);
        }

        return $invoiceId;
    }

    /** The sales order an invoice came from, by the name in its origin («S00028»). */
    public function saleOrderIdByName(?string $origin): ?int
    {
        $name = trim(explode(',', (string) $origin)[0]);
        if ($name === '' || ! $this->configured()) {
            return null;
        }
        $names = array_values(array_unique([$name, self::orderName($name)]));
        $row = $this->firstRecord($this->searchRead('sale.order', [['name', 'in', $names]], ['id'], 1));

        return $row === null ? null : (int) $row['id'];
    }

    /** «QN00041» → «S00041»: the number of a confirmed order. Any other name stays. */
    public static function orderName(string $name): string
    {
        return str_starts_with($name, self::QUOTATION_PREFIX)
            ? self::ORDER_PREFIX.substr($name, strlen(self::QUOTATION_PREFIX))
            : $name;
    }

    /**
     * Odoo keeps the quotation number after confirmation; a confirmed order gets its order
     * number instead (QN00041 → S00041). One order, or every confirmed one still named QN.
     *
     * @return list<array{id: int, from: string, to: string}>
     */
    public function renameConfirmedOrders(?int $orderId = null, bool $dryRun = false): array
    {
        if (! $this->configured()) {
            return [];
        }
        $domain = [['state', 'in', ['sale', 'done']], ['name', '=like', self::QUOTATION_PREFIX.'%']];
        if ($orderId !== null) {
            $domain[] = ['id', '=', $orderId];
        }

        $renamed = [];
        foreach ($this->searchRead('sale.order', $domain, ['id', 'name'], 500, 0, 'id asc') as $row) {
            $from = (string) ($row['name'] ?? '');
            $to = self::orderName($from);
            if ($to === $from) {
                continue;
            }
            if ($this->firstRecord($this->searchRead('sale.order', [['name', '=', $to]], ['id'], 1)) !== null) {
                Log::warning('Odoo order number already taken; the order keeps its quotation number.', ['from' => $from, 'to' => $to]);

                continue;
            }
            if (! $dryRun) {
                $this->writeRecord('sale.order', (int) $row['id'], ['name' => $to]);
            }
            $renamed[] = ['id' => (int) $row['id'], 'from' => $from, 'to' => $to];
        }

        return $renamed;
    }

    /**
     * The sales journal code is the invoice prefix (INV/2026/00012). Odoo numbers an invoice
     * after the previous one in its journal, so the first invoice posted after the code changes
     * is named here, continuing the year's count; Odoo continues from it.
     *
     * @return array{journal: string|null, from: string|null, to: string}
     */
    public function useInvoicePrefix(bool $dryRun = false): array
    {
        $journal = $this->firstRecord($this->searchRead('account.journal', [['type', '=', 'sale']], ['id', 'code'], 1, 0, 'id asc'));
        if ($journal === null) {
            throw new RuntimeException('Odoo has no sales journal.');
        }
        $from = (string) ($journal['code'] ?? '');
        if ($from !== self::INVOICE_CODE && ! $dryRun) {
            $this->writeRecord('account.journal', (int) $journal['id'], ['code' => self::INVOICE_CODE]);
        }

        return ['journal' => (string) $journal['id'], 'from' => $from, 'to' => self::INVOICE_CODE];
    }

    /**
     * Invoices and refunds numbered with the journal's old code take the INV prefix and keep
     * their numbers (الفات/2026/00013 → INV/2026/00013, Rالفات/… → RINV/…), so Odoo continues
     * from INV. The payment reference follows the new name.
     *
     * @return list<array{id: int, from: string, to: string}>
     */
    public function renameInvoicesFromCode(string $fromCode, bool $dryRun = false): array
    {
        $fromCode = trim($fromCode);
        if ($fromCode === '' || $fromCode === self::INVOICE_CODE) {
            return [];
        }
        $rows = $this->searchRead('account.move', [
            ['move_type', 'in', ['out_invoice', 'out_refund']],
            '|', ['name', '=like', $fromCode.'/%'], ['name', '=like', 'R'.$fromCode.'/%'],
        ], ['id', 'name', 'move_type'], 1000, 0, 'id asc');

        $renamed = [];
        foreach ($rows as $row) {
            $from = (string) ($row['name'] ?? '');
            $refund = str_starts_with($from, 'R'.$fromCode.'/');
            $to = ($refund ? 'R' : '').self::INVOICE_CODE.substr($from, strlen(($refund ? 'R' : '').$fromCode));
            if (! $dryRun) {
                $vals = ['name' => $to];
                if (($row['move_type'] ?? '') === 'out_invoice') {
                    $vals['payment_reference'] = $to;
                }
                $this->writeRecord('account.move', (int) $row['id'], $vals);
            }
            $renamed[] = ['id' => (int) $row['id'], 'from' => $from, 'to' => $to];
        }

        return $renamed;
    }

    /**
     * «دفع فوري» means due on the invoice date: its line is due after 0 days, and open invoices
     * that carry it fall due on their invoice date.
     *
     * @return array{terms: list<int>, invoices: list<string>}
     */
    public function makeImmediateTermsImmediate(bool $dryRun = false): array
    {
        $terms = $this->searchRead('account.payment.term', [['name', 'in', ['دفع فوري', 'Immediate Payment']]], ['id', 'name'], 10);
        $termIds = array_values(array_map(fn (array $term): int => (int) $term['id'], $terms));
        if ($termIds === []) {
            return ['terms' => [], 'invoices' => []];
        }
        if (! $dryRun) {
            foreach ($this->searchRead('account.payment.term.line', [['payment_id', 'in', $termIds], ['nb_days', '!=', 0]], ['id'], 50) as $line) {
                $this->writeRecord('account.payment.term.line', (int) $line['id'], ['nb_days' => 0]);
            }
            foreach ($terms as $term) {
                $this->writeRecord('account.payment.term', (int) $term['id'], ['note' => '<p>شروط الدفع: دفع فوري</p>']);
            }
        }

        $fixed = [];
        $invoices = $this->searchRead('account.move', [
            ['move_type', '=', 'out_invoice'],
            ['invoice_payment_term_id', 'in', $termIds],
            ['state', '=', 'posted'],
            ['payment_state', 'in', ['not_paid', 'partial']],
        ], ['id', 'name', 'invoice_date', 'invoice_date_due'], 500);
        foreach ($invoices as $invoice) {
            $date = (string) ($invoice['invoice_date'] ?? '');
            if ($date === '' || $date === (string) ($invoice['invoice_date_due'] ?? '')) {
                continue;
            }
            if (! $dryRun) {
                $lines = $this->searchRead('account.move.line', [
                    ['move_id', '=', (int) $invoice['id']],
                    ['display_type', '=', 'payment_term'],
                ], ['id'], 20);
                foreach ($lines as $line) {
                    $this->writeRecord('account.move.line', (int) $line['id'], ['date_maturity' => $date]);
                }
                $this->writeRecord('account.move', (int) $invoice['id'], ['invoice_date_due' => $date]);
            }
            $fixed[] = (string) ($invoice['name'] ?? $invoice['id']);
        }

        return ['terms' => $termIds, 'invoices' => $fixed];
    }

    /** The invoice print without the «التواصل بشأن الدفع» line. */
    public function installInvoicePrintView(): void
    {
        $arch = <<<'XML'
<data>
    <xpath expr="//p[@name='payment_communication']/.." position="attributes">
        <attribute name="t-if">False</attribute>
    </xpath>
</data>
XML;
        $this->upsertInheritedView('HOC invoice without payment communication', 'account.report_invoice_document', $arch);
    }

    /**
     * The proforma invoice prints «PF00041» (its order number with PF), and a confirmed order
     * is titled «رقم أمر البيع» instead of the quotation title.
     */
    public function installDocumentNumberView(): void
    {
        $quote = self::QUOTATION_PREFIX;
        $order = self::ORDER_PREFIX;
        $proforma = self::PROFORMA_PREFIX;
        $arch = <<<XML
<data>
    <xpath expr="//t[@t-set='layout_document_title']/span[@t-else='']" position="replace">
        <span t-else="">رقم أمر البيع </span>
    </xpath>
    <xpath expr="//t[@t-set='layout_document_title']/span[@t-field='doc.name']" position="replace">
        <span t-if="is_proforma" t-out="'{$proforma}' + (doc.name[len('{$quote}'):] if doc.name.startswith('{$quote}') else (doc.name[len('{$order}'):] if doc.name.startswith('{$order}') else doc.name))"/>
        <span t-else="" t-field="doc.name"/>
    </xpath>
</data>
XML;
        $this->upsertInheritedView('HOC document numbers', 'sale.report_saleorder_document', $arch);
    }

    /** Posts a draft invoice, naming it first when it is the first one with the INV prefix. */
    private function postMove(int $id): void
    {
        try {
            $this->numberFirstInvoice($id);
        } catch (Throwable $exception) {
            Log::warning('Odoo invoice keeps the number Odoo gives it.', ['invoice' => $id, 'error' => $exception->getMessage()]);
        }
        $this->call('account.move', 'action_post', ['ids' => [$id]]);
        try {
            $this->issueInvoiceDocument($id);
        } catch (Throwable $exception) {
            Log::warning('Odoo invoice document not issued.', ['invoice' => $id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Odoo prints a posted invoice that has no issued PDF as «Proforma Invoice». The «Send &
     * Print» wizard with no sending method only issues that PDF and marks the invoice sent;
     * the client gets the invoice from us, not from Odoo.
     */
    public function issueInvoiceDocument(int $id): void
    {
        $context = ['active_model' => 'account.move', 'active_ids' => [$id], 'active_id' => $id];
        $created = $this->createWithContext('account.move.send.wizard', ['sending_methods' => []], $context);
        $wizardId = (int) (is_array($created) ? ($created[0] ?? 0) : $created);
        $this->call('account.move.send.wizard', 'action_send_and_print', ['ids' => [$wizardId], 'context' => $context]);
    }

    /**
     * Posted customer invoices and refunds without an issued PDF, which Odoo prints as proforma.
     *
     * @return list<string>
     */
    public function issueMissingInvoiceDocuments(bool $dryRun = false): array
    {
        $rows = $this->searchRead('account.move', [
            ['move_type', 'in', ['out_invoice', 'out_refund']],
            ['state', '=', 'posted'],
        ], ['id', 'name', 'invoice_pdf_report_id'], 1000, 0, 'id asc');
        $issued = [];
        foreach ($rows as $row) {
            if (! empty($row['invoice_pdf_report_id'])) {
                continue;
            }
            if (! $dryRun) {
                try {
                    $this->issueInvoiceDocument((int) $row['id']);
                } catch (Throwable $exception) {
                    Log::warning('Odoo invoice document not issued.', ['invoice' => $row['id'], 'error' => $exception->getMessage()]);

                    continue;
                }
            }
            $issued[] = (string) ($row['name'] ?? $row['id']);
        }

        return $issued;
    }

    private function numberFirstInvoice(int $id): void
    {
        $move = $this->firstRecord($this->searchRead('account.move', [['id', '=', $id]], ['name', 'move_type', 'journal_id', 'invoice_date', 'state'], 1));
        $name = is_string($move['name'] ?? null) ? trim($move['name']) : '';
        if ($move === null || ($move['move_type'] ?? '') !== 'out_invoice' || ($move['state'] ?? '') !== 'draft' || ($name !== '' && $name !== '/')) {
            return;
        }
        $journalId = $this->relationId($move['journal_id'] ?? null);
        $journal = $journalId === null ? null : $this->firstRecord($this->searchRead('account.journal', [['id', '=', $journalId]], ['code'], 1));
        if (($journal['code'] ?? '') !== self::INVOICE_CODE) {
            return;
        }
        $posted = [['journal_id', '=', $journalId], ['move_type', '=', 'out_invoice'], ['state', '=', 'posted']];
        $last = $this->firstRecord($this->searchRead('account.move', $posted, ['name'], 1, 0, 'id desc'));
        // No earlier invoice: Odoo starts from the journal code. An INV invoice: Odoo continues it.
        if ($last === null || str_starts_with((string) ($last['name'] ?? ''), self::INVOICE_CODE.'/')) {
            return;
        }
        $year = substr(is_string($move['invoice_date'] ?? null) && $move['invoice_date'] !== '' ? $move['invoice_date'] : now()->toDateString(), 0, 4);
        $highest = $this->firstRecord($this->searchRead('account.move', [
            ...$posted,
            ['invoice_date', '>=', "{$year}-01-01"],
            ['invoice_date', '<=', "{$year}-12-31"],
        ], ['sequence_number'], 1, 0, 'sequence_number desc'));
        $next = (int) ($highest['sequence_number'] ?? 0) + 1;
        $this->writeRecord('account.move', $id, ['name' => sprintf('%s/%s/%05d', self::INVOICE_CODE, $year, $next)]);
    }

    /** «باقة إنستغرام — دفعة مقدّمة 50%» on the down payment line of a draft invoice. */
    private function nameDownPayment(int $orderId, int $invoiceId, string $method, float $value): void
    {
        // The ordered lines only: earlier down payments are lines of the order too.
        $ordered = $this->searchRead('sale.order.line', [['order_id', '=', $orderId], ['display_type', '=', false], ['is_downpayment', '=', false]], ['name'], 40, 0, 'sequence asc, id asc');
        $products = collect($ordered)
            ->map(fn (array $line): string => trim(explode("\n", (string) ($line['name'] ?? ''))[0]))
            ->filter()
            ->unique()
            ->take(3)
            ->implode('، ');
        if ($products === '') {
            return;
        }
        $share = $method === 'percentage'
            ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%'
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'$';
        $lines = $this->searchRead('account.move.line', [['move_id', '=', $invoiceId], ['display_type', '=', 'product']], ['id'], 20);
        $updates = array_map(fn (array $row): array => [1, (int) $row['id'], ['name' => "{$products} — دفعة مقدّمة {$share}"]], $lines);
        if ($updates !== []) {
            $this->call('account.move', 'write', ['ids' => [$invoiceId], 'vals' => ['invoice_line_ids' => $updates]]);
        }
    }

    /** An invoice that is posted and not yet fully paid, so a payment can go on it. */
    public function invoiceAwaitsPayment(int $id): bool
    {
        if ($id <= 0 || ! $this->configured()) {
            return false;
        }
        $row = $this->invoiceSnapshot($id);

        return $row !== null
            && ($row['state'] ?? '') === 'posted'
            && in_array((string) ($row['payment_state'] ?? ''), ['not_paid', 'partial'], true);
    }

    /** A draft or posted invoice with something left to pay: a payment posts it if needed, then goes on it. */
    public function invoiceOpenForPayment(int $id): bool
    {
        if ($id <= 0 || ! $this->configured()) {
            return false;
        }
        $row = $this->invoiceSnapshot($id);

        return $row !== null
            && in_array($row['state'] ?? '', ['draft', 'posted'], true)
            && ! in_array((string) ($row['payment_state'] ?? ''), ['paid', 'in_payment', 'reversed'], true)
            && (($row['state'] ?? '') === 'draft' || (float) ($row['amount_residual'] ?? 0) > 0.009);
    }

    public function postInvoice(int $id): void
    {
        if ($id <= 0 || ! $this->configured()) {
            return;
        }

        $this->postMove($id);
    }

    public function downloadSaleOrderPdf(int|string $orderId): ?string
    {
        return $this->downloadPortalReportPdf('sale.order', (int) $orderId);
    }

    public function downloadInvoicePdf(int|string $invoiceId): ?string
    {
        return $this->downloadPortalReportPdf('account.move', (int) $invoiceId);
    }

    /**
     * The customer portal link of a quotation or invoice, with its access token, where
     * the client can read, accept, or decline it in Odoo.
     */
    public function portalUrl(string $model, int $recordId): ?string
    {
        if (! $this->configured() || $recordId <= 0) {
            return null;
        }

        try {
            $path = $this->call($model, 'get_portal_url', ['ids' => [$recordId], 'context' => []]);
        } catch (Throwable $exception) {
            Log::warning('Odoo portal URL failed.', ['model' => $model, 'record_id' => $recordId, 'error' => $exception->getMessage()]);

            return null;
        }
        if (! is_string($path) || $path === '') {
            return null;
        }

        return rtrim((string) config('services.odoo.url'), '/').(str_starts_with($path, '/') ? $path : '/'.$path);
    }

    /**
     * Current states of several sales orders, by id.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function saleOrderStates(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === [] || ! $this->configured()) {
            return [];
        }

        $states = [];
        foreach ($this->searchRead('sale.order', [['id', 'in', $ids]], ['id', 'state'], count($ids)) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $states[(int) $row['id']] = (string) ($row['state'] ?? '');
            }
        }

        return $states;
    }

    /**
     * Writes the quotation and invoice print layout into Odoo: A4 paper with the cover
     * margins, the cover bands, lines that flow across pages, and the opening letter.
     * Run on purpose with `php artisan odoo:report-layout`; nothing else writes it, so the
     * layout stays as set until the command runs again.
     *
     * @return array<string, string|null> each step and its error, null when it worked
     */
    public function installReportLayout(): array
    {
        $steps = [
            'paper' => fn () => $this->lockSaleInvoicePaperToA4(),
            'cover' => fn () => $this->installPagedLetterheadCss(),
            'lines' => fn () => $this->installSplitLineViews(),
            'letter' => fn () => $this->installQuotationLetterView(),
        ];
        $result = [];
        foreach ($steps as $name => $step) {
            try {
                $step();
                $result[$name] = null;
            } catch (Throwable $exception) {
                $result[$name] = $exception->getMessage();
            }
        }

        return $result;
    }

    /** «إلى: الشركة، تحية طيبة وبعد.» and the opening sentence above the quotation details. */
    private function installQuotationLetterView(): void
    {
        $arch = <<<'XML'
<data>
    <xpath expr="//div[@class='page']" position="attributes">
        <attribute name="dir">rtl</attribute>
    </xpath>
    <xpath expr="//t[@t-set='address']" position="replace">
        <t t-set="address" t-value="False"/>
    </xpath>
    <xpath expr="//div[@id='informations']" position="before">
        <div dir="rtl" style="text-align: right; width: 100%; margin-bottom: 6mm;">
            <p style="margin: 0 0 4mm;">إلى: <t t-out="doc.partner_id.commercial_company_name or doc.partner_id.name"/>، تحية طيبة وبعد.</p>
            <p style="margin: 0;">يسعدنا في <a href="https://hoc.agency" target="_blank" style="color: #241752; text-decoration: underline;">hoc.agency</a> أن نضع بين أيديكم هذا العرض، ونأمل أن ينال رضاكم.</p>
        </div>
    </xpath>
</data>
XML;
        $this->upsertInheritedView('HOC quotation letter', 'sale.report_saleorder_document', $arch);
    }

    private function upsertInheritedView(string $name, string $parentKey, string $arch): void
    {
        $parent = $this->firstRecord($this->searchRead('ir.ui.view', [
            ['key', '=', $parentKey],
            ['inherit_id', '=', false],
        ], ['id'], 1));
        $parentId = (int) ($parent['id'] ?? 0);
        if ($parentId <= 0) {
            throw new RuntimeException("Odoo view {$parentKey} was not found.");
        }
        $vals = ['name' => $name, 'type' => 'qweb', 'inherit_id' => $parentId, 'arch' => $arch, 'active' => true];
        $existing = $this->firstRecord($this->searchRead('ir.ui.view', [['name', '=', $name]], ['id'], 1));
        if ($existing !== null) {
            $this->writeRecord('ir.ui.view', (int) $existing['id'], $vals);

            return;
        }
        $this->call('ir.ui.view', 'create', ['vals' => $vals]);
    }

    /**
     * wkhtmltopdf never splits a table row shorter than a page: a line with a long description
     * jumps whole to the next page and leaves the first page empty under the column titles.
     * Each description line after the first becomes its own row, so a long line flows across
     * pages. Rows of one line share its stripe so it still reads as one line.
     */
    private function installSplitLineViews(): void
    {
        // \\n stays a two-character escape for Odoo's expression parser.
        $more = <<<'XML'
    <t t-set="hoc_more" t-value="(line.name or '').split('\n')[1:]"/>
    <tr t-foreach="hoc_more" t-as="hoc_text" t-attf-class="hoc-line-more hoc-stripe-{{ line_index % 2 }}{{ ' hoc-line-end' if hoc_text_last else '' }}">
        <td colspan="99" t-att-class="padding_class"><span dir="auto" t-out="hoc_text"/></td>
    </tr>
XML;
        $views = [
            'sale.report_saleorder_document' => [
                'name' => 'HOC split long lines (quotation)',
                'arch' => <<<XML
<data>
    <xpath expr="//tr[@name='tr_product']" position="attributes">
        <attribute name="t-attf-class">hoc-stripe-{{ line_index % 2 }}{{ ' hoc-has-more' if '\\n' in (line.name or '') else '' }}</attribute>
    </xpath>
    <xpath expr="//tr[@name='tr_product']/td[@name='td_product_name']/span[@t-field='line.name']" position="replace">
        <span dir="auto" t-out="(line.name or '').split('\\n')[0]"/>
    </xpath>
    <xpath expr="//tr[@name='tr_product']" position="after">
        <t t-if="not (is_section or is_subsection or is_note)">
{$more}
        </t>
    </xpath>
</data>
XML,
            ],
            'account.report_invoice_document' => [
                'name' => 'HOC split long lines (invoice)',
                'arch' => <<<XML
<data>
    <xpath expr="//t[@name='account_invoice_line_accountable']/.." position="attributes">
        <attribute name="t-att-class">('fw-bolder o_line_section' if is_section else 'fw-bold o_line_subsection' if is_subsection else 'fst-italic o_line_note' if is_note else '') + ' hoc-stripe-%s' % (line_index % 2) + (' hoc-has-more' if is_product and '\\n' in (line.name or '') else '')</attribute>
    </xpath>
    <xpath expr="//td[@name='account_invoice_line_name']/span[@t-field='line.name']" position="replace">
        <span t-if="line.name" dir="auto" t-out="line.name.split('\\n')[0]"/>
    </xpath>
    <xpath expr="//t[@name='account_invoice_line_accountable']/.." position="after">
        <t t-if="is_product">
{$more}
        </t>
    </xpath>
</data>
XML,
            ],
        ];

        foreach ($views as $key => $spec) {
            $this->upsertInheritedView($spec['name'], $key, $spec['arch']);
        }
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

            $response = Http::timeout(90)
                ->connectTimeout(10)
                ->retry(2, 200)
                ->get($url);

            $body = $response->body();
            if ($response->successful() && str_starts_with($body, '%PDF')) {
                return $this->decoratePaperPdf($body);
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

    /** Page numbers and the letterhead on continuation pages, as on every paper we send. */
    private function decoratePaperPdf(string $body): string
    {
        $pages = app(OdooPaperPdf::class);
        $paged = $pages->paginate($body);
        $cover = $this->companyLetterheadPng();

        return $cover === null ? $paged : $pages->stampContinuationCovers($paged, $cover);
    }

    /**
     * The order printed as Odoo's pro-forma invoice. The portal only prints the quotation,
     * so the PDF comes from the «Send PRO-FORMA» mail composer, which renders it as an
     * attachment; nothing is sent and the attachment is deleted after reading.
     */
    public function downloadProformaPdf(int $orderId): ?string
    {
        if (! $this->configured() || $orderId <= 0) {
            return null;
        }

        $attachmentIds = [];
        try {
            $action = $this->call('sale.order', 'action_quotation_send', ['ids' => [$orderId], 'context' => ['proforma' => true]]);
            $templateId = (int) (is_array($action) ? ($action['context']['default_template_id'] ?? 0) : 0);
            $context = [
                'default_model' => 'sale.order',
                'default_res_ids' => [$orderId],
                'default_composition_mode' => 'comment',
                'proforma' => true,
            ];
            if ($templateId > 0) {
                $context['default_template_id'] = $templateId;
            }
            $created = $this->createWithContext('mail.compose.message', [], $context);
            $composerId = (int) (is_array($created) ? ($created[0] ?? 0) : $created);
            $rows = $this->call('mail.compose.message', 'read', ['ids' => [$composerId], 'fields' => ['attachment_ids'], 'context' => $context]);
            $attachmentIds = array_values(array_map('intval', (array) ($rows[0]['attachment_ids'] ?? [])));
            if ($attachmentIds === []) {
                Log::warning('Odoo pro-forma composer had no attachment.', ['order' => $orderId]);

                return null;
            }
            $files = $this->call('ir.attachment', 'read', ['ids' => $attachmentIds, 'fields' => ['mimetype', 'datas']]);
            foreach ((array) $files as $file) {
                if (($file['mimetype'] ?? '') !== 'application/pdf' || ! is_string($file['datas'] ?? null)) {
                    continue;
                }
                $body = base64_decode($file['datas'], true);
                if (is_string($body) && str_starts_with($body, '%PDF')) {
                    return $this->decoratePaperPdf($body);
                }
            }
            Log::warning('Odoo pro-forma attachment was not a PDF.', ['order' => $orderId]);
        } catch (Throwable $exception) {
            Log::warning('Odoo pro-forma PDF failed.', ['order' => $orderId, 'error' => $exception->getMessage()]);
        } finally {
            if ($attachmentIds !== []) {
                try {
                    $this->call('ir.attachment', 'unlink', ['ids' => $attachmentIds]);
                } catch (Throwable) {
                }
            }
        }

        return null;
    }

    private function lockSaleInvoicePaperToA4(): void
    {
        $vals = [
            'format' => 'A4',
            'orientation' => 'Portrait',
            'page_height' => 0,
            'page_width' => 0,
            'margin_top' => OdooPaperLimits::LETTERHEAD_MARGIN_TOP,
            'margin_bottom' => OdooPaperLimits::LETTERHEAD_MARGIN_BOTTOM,
            'margin_left' => 0,
            'margin_right' => 0,
            'header_line' => false,
            // wkhtmltopdf measures the cover header as 0mm tall and puts its top at
            // margin_top - spacing; spacing equal to the margin puts the cover at the page top.
            'header_spacing' => OdooPaperLimits::LETTERHEAD_MARGIN_TOP,
            'dpi' => OdooPaperLimits::LETTERHEAD_DPI,
            'disable_shrinking' => true,
        ];

        $reports = $this->searchRead('ir.actions.report', [
            ['model', 'in', ['sale.order', 'account.move']],
            ['report_type', '=', 'qweb-pdf'],
        ], ['id', 'paperformat_id'], 20);
        if ($reports === []) {
            return;
        }

        $named = $this->firstRecord($this->searchRead('report.paperformat', [
            ['name', '=', 'HOC A4 paged letterhead'],
        ], ['id'], 1));
        $formatId = is_array($named) ? (int) $named['id'] : (int) $this->call('report.paperformat', 'create', ['vals' => array_merge(['name' => 'HOC A4 paged letterhead'], $vals)]);
        if ($formatId <= 0) {
            return;
        }

        $core = $vals;
        unset($core['disable_shrinking']);
        try {
            $this->writeRecord('report.paperformat', $formatId, $core);
        } catch (Throwable) {
            unset($core['page_height'], $core['page_width']);
            $this->writeRecord('report.paperformat', $formatId, $core);
        }
        try {
            $this->writeRecord('report.paperformat', $formatId, [
                'disable_shrinking' => true,
            ]);
        } catch (Throwable) {
        }

        foreach ($reports as $report) {
            if ($this->relationId($report['paperformat_id'] ?? null) === $formatId) {
                continue;
            }
            $this->writeRecord('ir.actions.report', (int) $report['id'], ['paperformat_id' => $formatId]);
        }
    }

    private function companyLetterheadPng(): ?string
    {
        try {
            $row = $this->firstRecord($this->searchRead('res.company', [], ['id', 'layout_background_image'], 1));
            $raw = $row['layout_background_image'] ?? null;
            if (! is_string($raw) || $raw === '') {
                return null;
            }
            $bytes = base64_decode($raw, true);
            if (! is_string($bytes) || strlen($bytes) < 32) {
                return null;
            }
            $path = storage_path('framework/cache/mpdf/hoc-letterhead.png');
            file_put_contents($path, $bytes);

            return is_file($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function installPagedLetterheadCss(): void
    {
        $parent = $this->firstRecord($this->searchRead('ir.model.data', [
            ['module', '=', 'web'],
            ['name', '=', 'external_layout_bubble'],
        ], ['res_id'], 1));
        $parentId = (int) ($parent['res_id'] ?? 0);
        if ($parentId <= 0) {
            $view = $this->firstRecord($this->searchRead('ir.ui.view', [
                ['key', '=', 'web.external_layout_bubble'],
            ], ['id'], 1));
            $parentId = (int) ($view['id'] ?? 0);
        }
        if ($parentId <= 0) {
            return;
        }

        $side = OdooPaperLimits::LETTERHEAD_MARGIN_SIDE;
        $top = OdooPaperLimits::LETTERHEAD_MARGIN_TOP;
        $bottom = OdooPaperLimits::LETTERHEAD_MARGIN_BOTTOM;
        $cover = $this->companyLetterheadPng();
        $bands = is_string($cover)
            ? app(OdooPaperPdf::class)->letterheadBandDataUris((string) file_get_contents($cover))
            : null;
        // Bands are 100% wide (210mm would overflow at dpi 90) and sized to print exactly at the margins.
        $band = fn (string $uri, int $mm): string => '<div class="hoc-cover-band" dir="ltr" style="direction: ltr; margin: 0; padding: 0; line-height: 0; height: '.OdooPaperLimits::printedMm($mm).'mm; overflow: hidden;"><img src="'.$uri.'" alt="" style="width: 100%; height: '.OdooPaperLimits::printedMm($mm).'mm; display: block; margin: 0; padding: 0; border: 0;"/></div>';
        // The header and footer are their own pages on a Bootstrap .container body, whose side padding
        // left white strips beside the cover; the cover runs edge to edge on both sides.
        $edgeCss = '<style>html,body,body.container,body.container-fluid,.o_body_pdf{margin-left:0 !important;margin-right:0 !important;padding-left:0 !important;padding-right:0 !important;max-width:none !important;width:100% !important;}.header,.footer{margin-left:0 !important;margin-right:0 !important;padding-left:0 !important;padding-right:0 !important;}</style>';
        // Odoo's HTML preview pads the page and the header by 11mm; the cover runs edge to edge there too.
        $previewCss = '<style>.o_body_html{padding:0 !important;}.o_body_html .header{padding-top:0 !important;}.o_body_html .hoc-cover-band,.o_body_html .hoc-cover-band img{height:auto !important;}</style>';
        // A line with a long description continues on the next page instead of leaving the first
        // page empty, and the column titles repeat above it there.
        // Rows of one line (installSplitLineViews) share its stripe and read as one block.
        $contentCss = '<style>'
            .'.o_main_table thead{display:table-header-group !important;}.o_main_table td{vertical-align:top;}'
            .'.o_table_striped table.o_main_table tbody tr td{background-color:transparent !important;}'
            .'.o_table_striped table.o_main_table tbody tr.hoc-stripe-0 td{background-color:rgba(231,233,237,0.5) !important;}'
            .'.o_main_table tbody tr.hoc-line-more{border-top:none !important;}'
            .'.o_main_table tbody tr.hoc-has-more td,.o_main_table tbody tr.hoc-line-more td{padding-bottom:0 !important;}'
            .'.o_main_table tbody tr.hoc-line-more td{padding-top:0 !important;}'
            .'.o_main_table tbody tr.hoc-line-end td{padding-bottom:0.5rem !important;}'
            .'</style>';
        $headerImg = is_array($bands) ? $edgeCss.$previewCss.$band($bands['header'], $top) : '';
        $footerImg = is_array($bands) ? $edgeCss.$band($bands['footer'], $bottom) : '';
        $arch = <<<XML
<data>
    <xpath expr="//div[@t-if='layout_background_url'][1]" position="replace">
        <t/>
    </xpath>
    <xpath expr="//img[contains(@style, '206mm')]" position="replace">
        <span/>
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'article')]" position="attributes">
        <attribute name="t-attf-style">background: transparent !important; padding: 0 {$side}mm 0 {$side}mm !important;</attribute>
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'article')]" position="inside">
        {$contentCss}
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'header')]" position="attributes">
        <attribute name="t-attf-style">background: transparent !important; padding: 0; margin: 0; direction: ltr; line-height: 0;</attribute>
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'header')]" position="inside">
        {$headerImg}
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'footer')]" position="attributes">
        <attribute name="t-attf-style">background: transparent !important; padding: 0; margin: 0; direction: ltr; line-height: 0;</attribute>
    </xpath>
    <xpath expr="//div[contains(@t-attf-class, 'footer')]" position="inside">
        {$footerImg}
    </xpath>
</data>
XML;

        $existing = $this->firstRecord($this->searchRead('ir.ui.view', [
            ['name', '=', 'HOC paged letterhead'],
        ], ['id'], 1));
        $vals = [
            'name' => 'HOC paged letterhead',
            'type' => 'qweb',
            'inherit_id' => $parentId,
            'arch' => $arch,
            'active' => true,
        ];
        if ($existing !== null) {
            $this->writeRecord('ir.ui.view', (int) $existing['id'], $vals);

            return;
        }

        $this->call('ir.ui.view', 'create', ['vals' => $vals]);
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

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) >= 8) {
            $existing = $this->call('res.partner', 'search', [
                'domain' => [['phone', 'ilike', substr($digits, -8)]],
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

        if (filled($name)) {
            $existing = $this->call('hr.employee', 'search', [
                'domain' => [['name', '=ilike', $name]],
                'limit' => 2,
            ]);
            if (is_array($existing) && count($existing) === 1 && isset($existing[0])) {
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

        $rows = $this->searchRead('hr.employee', [['id', '=', (int) $employeeId]], [
            'id', 'name', 'work_email', 'work_phone', 'mobile_phone', 'barcode', 'active',
        ], 1, 0, 'id desc');

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
            'invoice_date' => now()->toDateString(),
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

    public function postAndPayInvoice(int|string $invoiceId, ?float $amount = null): void
    {
        if (! $this->configured()) {
            return;
        }

        $id = (int) $invoiceId;
        if ($id <= 0) {
            return;
        }

        $row = $this->invoiceAccountingRow($id);
        if ($row === null) {
            return;
        }

        $state = (string) ($row['state'] ?? '');
        $paymentState = (string) ($row['payment_state'] ?? '');

        if ($state === 'cancel' || in_array($paymentState, ['paid', 'in_payment', 'reversed'], true)) {
            return;
        }

        if ($state === 'draft') {
            $this->postMove($id);
            $row = $this->invoiceAccountingRow($id) ?? $row;
            $paymentState = (string) ($row['payment_state'] ?? $paymentState);
            if (in_array($paymentState, ['paid', 'in_payment', 'reversed'], true)) {
                return;
            }
        }

        $residual = $this->invoiceResidual($row);
        $payAmount = $amount !== null && $amount > 0
            ? min(round($amount, 2), round($residual, 2))
            : round($residual, 2);

        if ($payAmount <= 0.009) {
            return;
        }

        $this->registerInvoicePayment($id, $payAmount);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function unpaidAmountForLocalPayment(array $invoice, float $localPaid): float
    {
        $residual = $this->invoiceResidual($invoice);
        $total = (float) ($invoice['amount_total'] ?? 0);
        $paidOnOdoo = max(round($total - $residual, 2), 0);
        $needed = max(round($localPaid, 2) - $paidOnOdoo, 0);

        return min($needed, max($residual, 0));
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

        if (! is_array($result) || ! array_is_list($result)) {
            throw new RuntimeException('Odoo did not return a record list.');
        }

        return $result;
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

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function partnerDomain(?int $partnerId): array
    {
        if ($partnerId === null || $partnerId <= 0) {
            return [];
        }

        $scope = $partnerId;
        try {
            $row = $this->firstRecord($this->searchRead('res.partner', [['id', '=', $partnerId]], ['commercial_partner_id'], 1));
            $commercial = is_array($row) ? ($row['commercial_partner_id'] ?? null) : null;
            if (is_array($commercial) && isset($commercial[0])) {
                $scope = (int) $commercial[0];
            }
        } catch (Throwable) {
            $scope = $partnerId;
        }

        return [['partner_id', 'child_of', $scope > 0 ? $scope : $partnerId]];
    }

    private function relationId(mixed $value): ?int
    {
        if (is_array($value) && isset($value[0]) && (int) $value[0] > 0) {
            return (int) $value[0];
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
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

    /**
     * @return array<string, mixed>|null
     */
    private function invoiceAccountingRow(int $invoiceId): ?array
    {
        return $this->firstRecord($this->searchRead('account.move', [['id', '=', $invoiceId]], [
            'id',
            'state',
            'payment_state',
            'amount_total',
            'amount_residual',
        ], 1, 0, 'id desc'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function invoiceResidual(array $row): float
    {
        if (array_key_exists('amount_residual', $row) && is_numeric($row['amount_residual'])) {
            return (float) $row['amount_residual'];
        }

        $paymentState = (string) ($row['payment_state'] ?? '');
        if (in_array($paymentState, ['paid', 'in_payment', 'reversed'], true)) {
            return 0.0;
        }

        return (float) ($row['amount_total'] ?? 0);
    }

    private function registerInvoicePayment(int $invoiceId, float $amount): void
    {
        $values = [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ];

        $journalId = $this->resolvePaymentJournalId();
        if ($journalId !== null) {
            $values['journal_id'] = $journalId;
        }

        $wizardId = $this->createWithContext('account.payment.register', $values, [
            'active_model' => 'account.move',
            'active_ids' => [$invoiceId],
            'active_id' => $invoiceId,
        ]);

        $id = is_array($wizardId) ? (int) ($wizardId[0] ?? 0) : (int) $wizardId;
        if ($id <= 0) {
            throw new RuntimeException('Odoo payment register did not return a wizard id.');
        }

        $this->call('account.payment.register', 'action_create_payments', ['ids' => [$id]]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     */
    private function createWithContext(string $model, array $values, array $context): mixed
    {
        if ($this->useJson2()) {
            return $this->json2($model, 'create', [
                'vals_list' => [$values],
                'context' => $context,
            ]);
        }

        return $this->executeKw($this->legacyUid(), $model, 'create', [[$values]], [
            'context' => $context,
        ]);
    }

    private function resolvePaymentJournalId(): ?int
    {
        if ($this->paymentJournalId !== null) {
            return $this->paymentJournalId > 0 ? $this->paymentJournalId : null;
        }

        if (! $this->configured()) {
            $this->paymentJournalId = 0;

            return null;
        }

        $rows = $this->searchRead('account.journal', [
            ['type', 'in', ['bank', 'cash']],
            ['active', '=', true],
        ], ['id', 'type', 'name'], 8, 0, 'id asc');

        $chosen = null;
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'cash' && isset($row['id'])) {
                $chosen = (int) $row['id'];
                break;
            }
        }

        if ($chosen === null && isset($rows[0]['id'])) {
            $chosen = (int) $rows[0]['id'];
        }

        $this->paymentJournalId = $chosen ?? 0;

        return $this->paymentJournalId > 0 ? $this->paymentJournalId : null;
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
    /**
     * @param  array{title?: string, amount?: float|int|string|null, units?: float|int|string|null, notes?: string|null, discount?: float|int|string|null, display_type?: string|null}  $line
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function withProductDetails(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            if ($id > 0 && ! filled($line['notes'] ?? null)) {
                $ids[] = $id;
            }
        }
        $details = $this->productSaleDetails($ids);
        if ($details === []) {
            return $lines;
        }

        foreach ($lines as $index => $line) {
            $id = (int) ($line['product_id'] ?? 0);
            if ($id > 0 && ! filled($line['notes'] ?? null) && filled($details[$id] ?? null)) {
                $lines[$index]['notes'] = $details[$id];
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function productSaleDetails(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
        if ($ids === [] || ! $this->configured()) {
            return [];
        }

        try {
            $rows = $this->searchRead('product.product', [
                ['id', 'in', $ids],
            ], ['id', 'description_sale'], count($ids), 0, 'id asc');
        } catch (Throwable) {
            return [];
        }

        $details = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $text = $this->plainProductText($row['description_sale'] ?? null);
            if ($text !== '') {
                $details[(int) $row['id']] = $text;
            }
        }

        return $details;
    }

    private function plainProductText(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr)>#i', "\n", $value) ?? $value;
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]*\n[ \t]*/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function staffSaleLine(array $line): array
    {
        $type = $line['display_type'] ?? null;
        if ($type === 'line_section' || $type === 'line_note') {
            return [
                'display_type' => $type,
                'name' => (string) ($line['title'] ?? ''),
            ];
        }

        $values = [
            'name' => filled($line['notes'] ?? null)
                ? "{$line['title']}\n{$line['notes']}"
                : (string) ($line['title'] ?? ''),
            'product_uom_qty' => (float) ($line['units'] ?? 1),
            'price_unit' => (float) ($line['amount'] ?? 0),
        ];
        if (isset($line['product_id']) && (int) $line['product_id'] > 0) {
            $values['product_id'] = (int) $line['product_id'];
        }
        $discount = (float) ($line['discount'] ?? 0);
        if ($discount > 0) {
            $values['discount'] = $discount;
        }

        return $this->saleOrderLine($values, $this->idList($line['tax_ids'] ?? []));
    }

    /**
     * @param  array{title?: string, amount?: float|int|string|null, units?: float|int|string|null, notes?: string|null, discount?: float|int|string|null, display_type?: string|null}  $line
     * @return array<string, mixed>
     */
    private function staffInvoiceLine(array $line): array
    {
        $type = $line['display_type'] ?? null;
        if ($type === 'line_section' || $type === 'line_note') {
            return [
                'display_type' => $type,
                'name' => (string) ($line['title'] ?? ''),
            ];
        }

        $values = [
            'name' => filled($line['notes'] ?? null)
                ? "{$line['title']}\n{$line['notes']}"
                : (string) ($line['title'] ?? ''),
            'quantity' => (float) ($line['units'] ?? 1),
            'price_unit' => (float) ($line['amount'] ?? 0),
        ];
        if (isset($line['product_id']) && (int) $line['product_id'] > 0) {
            $values['product_id'] = (int) $line['product_id'];
        }
        $discount = (float) ($line['discount'] ?? 0);
        if ($discount > 0) {
            $values['discount'] = $discount;
        }

        return $this->invoiceLine($values, $this->idList($line['tax_ids'] ?? []));
    }

    /** @param  list<int>  $taxIds */
    private function saleOrderLine(array $values, array $taxIds = []): array
    {
        return array_merge($values, [
            'tax_ids' => [[6, 0, $taxIds]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<int>  $taxIds
     * @return array<string, mixed>
     */
    private function invoiceLine(array $values, array $taxIds = []): array
    {
        return array_merge($values, [
            'tax_ids' => [[6, 0, $taxIds]],
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

        if (in_array($method, ['action_post', 'action_create_payments', 'action_confirm', 'action_quotation_sent', 'create_invoices', 'button_draft', 'button_cancel'], true)) {
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

        if (str_contains($response->body(), 'Database currently unavailable')) {
            throw new RuntimeException('Odoo database is currently unavailable.');
        }

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

        if (in_array($method, ['action_post', 'action_create_payments', 'action_confirm', 'action_quotation_sent', 'create_invoices', 'button_draft', 'button_cancel'], true)) {
            return $this->execute($uid, $model, $method, [
                $payload['ids'] ?? [],
            ]);
        }

        if (array_key_exists('ids', $payload)) {
            $kwargs = $payload;
            unset($kwargs['ids']);

            return $this->executeKw($uid, $model, $method, [$payload['ids']], $kwargs);
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

    /**
     * A button method that needs an Odoo context, such as cancelling a sent quotation
     * without its wizard.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $context
     */
    private function callWithContext(string $model, string $method, array $ids, array $context): mixed
    {
        if ($this->useJson2()) {
            return $this->json2($model, $method, ['ids' => $ids, 'context' => $context]);
        }

        return $this->executeKw($this->legacyUid(), $model, $method, [$ids], ['context' => $context]);
    }

    private function useJson2(): bool
    {
        return (bool) config('services.odoo.use_json2', false);
    }
}
