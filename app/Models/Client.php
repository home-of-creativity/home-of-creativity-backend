<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Support\ClientProfileValue;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'company_name',
        'company_activity',
        'email',
        'phone',
        'telegram_user_id',
        'locale',
        'odoo_partner_id',
        'odoo_lead_id',
        'odoo_stage_name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public static function findForTelegram(?string $telegramUserId): ?self
    {
        if (! filled($telegramUserId)) {
            return null;
        }

        $client = static::query()->withTrashed()->where('telegram_user_id', $telegramUserId)->first();
        if ($client?->trashed()) {
            $client->restore();
        }

        return $client;
    }

    public function profileComplete(): bool
    {
        return filled($this->name)
            && filled($this->phone)
            && filled($this->company_name);
    }

    public function driveCompanyFolderName(): string
    {
        return ClientProfileValue::usableCompanyName($this->company_name, $this->telegram_user_id)
            ?? 'شركة';
    }

    public function telegramPrivateUrl(): ?string
    {
        $id = trim((string) ($this->telegram_user_id ?? ''));
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return 'tg://user?id='.$id;
    }

    public function telegramContactLine(): ?string
    {
        $url = $this->telegramPrivateUrl();

        return filled($url) ? 'تواصل خاص: '.$url : null;
    }

    public function readyForOdoo(): bool
    {
        return ! filled($this->telegram_user_id) || $this->profileComplete();
    }

    /**
     * Quotation / contract totals on this client's requests for the Odoo CRM
     * expected_revenue field. Won-only is used after full payment.
     */
    public function pipelineRevenue(bool $wonOnly = false): float
    {
        $this->loadMissing('requests');

        $total = 0.0;
        foreach ($this->requests as $request) {
            if (in_array($request->status, [RequestStatus::Cancelled, RequestStatus::QuotationRejected], true)) {
                continue;
            }

            if ($wonOnly && ! $request->isFullyPaid() && $request->odoo_won_at === null) {
                continue;
            }

            $amount = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);
            if ($amount > 0.009) {
                $total += $amount;
            }
        }

        return round($total, 2);
    }

    /**
     * Hide Telegram stubs until name, phone, and company are filled.
     * Staff/factory clients without a Telegram id stay visible.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeVisibleOnDashboard(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->whereNull('telegram_user_id')
                    ->orWhere('telegram_user_id', '');
            })->orWhere(function (Builder $query): void {
                $query->whereNotNull('name')->where('name', '!=', '')
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->whereNotNull('company_name')->where('company_name', '!=', '');
            });
        });
    }

    /**
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        $missing = [];
        if (! filled($this->name)) {
            $missing[] = 'name';
        }
        if (! filled($this->phone)) {
            $missing[] = 'phone';
        }
        if (! filled($this->company_name)) {
            $missing[] = 'company_name';
        }

        return $missing;
    }
}
