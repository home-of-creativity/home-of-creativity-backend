<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\OdooInvoice;
use App\Models\OpsSetting;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies Odoo customer invoices into `odoo_invoices`. A normal run reads only the
 * invoices Odoo changed since the last run, so a payment, a cancel, or a reset to
 * draft in Odoo reaches the finance page within a minute. A full run also drops
 * invoices that were deleted in Odoo.
 */
class SyncOdooInvoices
{
    public const CURSOR = 'odoo_invoices.cursor';

    public const SYNCED_AT = 'odoo_invoices.synced_at';

    public const ERROR = 'odoo_invoices.error';

    public const ATTEMPTED_AT = 'odoo_invoices.attempted_at';

    private const PAGE = 200;

    private const MAX_PAGES = 25;

    /** @var array<string, int|null> */
    private array $clientByPartner = [];

    public function __construct(private OdooClient $odoo) {}

    /**
     * @return array{synced: bool, changed: int, removed: int}
     */
    public function handle(bool $full = false): array
    {
        $result = ['synced' => false, 'changed' => 0, 'removed' => 0];
        if (! $this->odoo->configured()) {
            return $result;
        }

        $lock = Cache::lock('odoo-invoices-sync', 120);
        if (! $lock->get()) {
            return $result;
        }

        OpsSetting::setValue(self::ATTEMPTED_AT, now()->toIso8601String());
        try {
            $since = $full ? null : $this->cursor();
            $seen = [];
            $latest = null;
            $complete = false;
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $rows = $this->odoo->invoicesChangedSince($since, self::PAGE, $page * self::PAGE);
                foreach ($rows as $row) {
                    if ($this->store($row)) {
                        $result['changed']++;
                    }
                    $seen[(int) $row['id']] = true;
                    $written = (string) ($row['write_date'] ?? '');
                    if ($written !== '' && ($latest === null || $written > $latest)) {
                        $latest = $written;
                    }
                }
                if (count($rows) < self::PAGE) {
                    $complete = true;
                    break;
                }
            }

            if ($full && $complete) {
                $result['removed'] = $this->dropDeleted(array_keys($seen));
            }
            if ($latest !== null) {
                OpsSetting::setValue(self::CURSOR, $latest);
            }
            OpsSetting::setValue(self::SYNCED_AT, now()->toIso8601String());
            OpsSetting::setValue(self::ERROR, null);
            $result['synced'] = true;
        } catch (Throwable $exception) {
            OpsSetting::setValue(self::ERROR, mb_substr($exception->getMessage(), 0, 300));
            Log::warning('Odoo invoice sync failed.', ['error' => $exception->getMessage()]);
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * Runs an incremental sync when the last one is older than $seconds. A failed
     * attempt also waits $seconds, so a page that polls while Odoo is down stays fast.
     */
    public function refreshIfStale(int $seconds = 30): void
    {
        foreach ([self::SYNCED_AT, self::ATTEMPTED_AT] as $key) {
            $last = OpsSetting::getValue($key);
            if ($last !== null && Carbon::parse($last)->gt(now()->subSeconds($seconds))) {
                return;
            }
        }

        $this->handle();
    }

    /**
     * @return array{synced_at: string|null, error: string|null}
     */
    public static function status(): array
    {
        return [
            'synced_at' => OpsSetting::getValue(self::SYNCED_AT),
            'error' => OpsSetting::getValue(self::ERROR),
        ];
    }

    /**
     * One second back so an invoice written in the same second as the cursor is read again.
     */
    private function cursor(): ?string
    {
        $cursor = OpsSetting::getValue(self::CURSOR);
        if ($cursor === null || OdooInvoice::query()->doesntExist()) {
            return null;
        }

        try {
            return Carbon::parse($cursor, 'UTC')->subSecond()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function store(array $row): bool
    {
        $odooId = (int) ($row['id'] ?? 0);
        if ($odooId <= 0) {
            return false;
        }

        $existing = OdooInvoice::query()->where('odoo_id', $odooId)->first();
        $values = [
            'name' => filled($row['name'] ?? null) ? (string) $row['name'] : null,
            'partner_id' => $row['partner_id'] ?? null,
            'partner_name' => $row['partner_name'] ?? null,
            'client_id' => $this->clientFor($row['partner_id'] ?? null),
            'request_id' => $this->requestFor($odooId, $row),
            'amount_total' => round((float) ($row['amount_total'] ?? 0), 2),
            'amount_residual' => round((float) ($row['amount_residual'] ?? 0), 2),
            'currency' => $row['currency'] ?? null,
            'state' => (string) ($row['state'] ?? 'draft'),
            'payment_state' => filled($row['payment_state'] ?? null) ? (string) $row['payment_state'] : null,
            'invoice_date' => $row['invoice_date'] ?? null,
            'invoice_date_due' => $row['invoice_date_due'] ?? null,
            'invoice_origin' => $row['invoice_origin'] ?? null,
            'ref' => $row['ref'] ?? null,
            'odoo_write_date' => filled($row['write_date'] ?? null) ? Carbon::parse((string) $row['write_date'], 'UTC') : null,
            'synced_at' => now(),
        ];

        $changed = $existing === null
            || $existing->state !== $values['state']
            || $existing->payment_state !== $values['payment_state']
            || abs((float) $existing->amount_residual - $values['amount_residual']) > 0.009
            || abs((float) $existing->amount_total - $values['amount_total']) > 0.009;

        OdooInvoice::query()->updateOrCreate(['odoo_id' => $odooId], $values);
        if ($changed) {
            $this->followLocalInvoices($odooId, $values['state'], (string) $values['payment_state']);
        }

        return $changed;
    }

    /**
     * Local payment records that point at this Odoo invoice follow its state.
     */
    private function followLocalInvoices(int $odooId, string $state, string $payment): void
    {
        $status = match (true) {
            $state === 'cancel' => 'cancelled',
            in_array($payment, ['paid', 'in_payment', 'reversed'], true) => 'paid',
            $payment === 'partial' => 'partial',
            default => 'issued',
        };

        Invoice::query()
            ->where('odoo_invoice_id', (string) $odooId)
            ->where('status', '!=', $status)
            ->update(['status' => $status]);
    }

    private function clientFor(mixed $partnerId): ?int
    {
        if (! is_numeric($partnerId) || (int) $partnerId <= 0) {
            return null;
        }
        $key = (string) (int) $partnerId;
        if (! array_key_exists($key, $this->clientByPartner)) {
            $id = Client::query()->where('odoo_partner_id', $key)->value('id');
            $this->clientByPartner[$key] = $id !== null ? (int) $id : null;
        }

        return $this->clientByPartner[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requestFor(int $odooId, array $row): ?int
    {
        $id = ServiceRequest::query()->where('odoo_invoice_id', (string) $odooId)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        foreach (['ref', 'invoice_origin'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && str_starts_with(strtoupper($value), 'REQ-')) {
                $id = ServiceRequest::query()->where('number', $value)->value('id');
                if ($id !== null) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $seen
     */
    private function dropDeleted(array $seen): int
    {
        $missing = OdooInvoice::query()
            ->when($seen !== [], fn ($query) => $query->whereNotIn('odoo_id', $seen))
            ->pluck('odoo_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($missing === []) {
            return 0;
        }

        // Ask Odoo once more so a page that moved during the walk does not drop a live invoice.
        $still = $this->odoo->existingInvoiceIds($missing);
        $gone = array_values(array_diff($missing, $still));
        if ($gone === []) {
            return 0;
        }

        Invoice::query()->whereIn('odoo_invoice_id', array_map('strval', $gone))->update(['status' => 'cancelled']);

        return OdooInvoice::query()->whereIn('odoo_id', $gone)->delete();
    }
}
