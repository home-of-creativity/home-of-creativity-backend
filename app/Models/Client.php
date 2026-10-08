<?php

namespace App\Models;

use App\Enums\RequestSource;
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

    public const WHATSAPP_PREFIX = 'wa:';

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
        'google_drive_folder_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_skipped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ClientReport::class);
    }

    public function googleDriveFolderUrl(): ?string
    {
        return filled($this->google_drive_folder_id)
            ? 'https://drive.google.com/drive/folders/'.$this->google_drive_folder_id
            : null;
    }

    /** @var array{email: array<string, int>, phone: array<string, int>}|null */
    private static ?array $contactIndex = null;

    public static function forgetContactIndex(): void
    {
        self::$contactIndex = null;
    }

    public static function rememberContact(self $client): void
    {
        if (self::$contactIndex === null) {
            return;
        }

        $email = strtolower(trim((string) $client->email));
        if (str_contains($email, '@') && ! isset(self::$contactIndex['email'][$email])) {
            self::$contactIndex['email'][$email] = $client->id;
        }

        $digits = preg_replace('/\D+/', '', (string) $client->phone) ?? '';
        if (strlen($digits) >= 8) {
            self::$contactIndex['phone'][$digits] = self::$contactIndex['phone'][$digits] ?? $client->id;
            self::$contactIndex['phone'][substr($digits, -8)] = self::$contactIndex['phone'][substr($digits, -8)] ?? $client->id;
        }
    }

    public static function findByContact(?string $email, ?string $phone): ?self
    {
        $index = self::contactIndex();
        $email = strtolower(trim((string) $email));
        if (str_contains($email, '@') && isset($index['email'][$email])) {
            $found = static::query()->find($index['email'][$email]);
            if ($found) {
                return $found;
            }
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        $id = $index['phone'][$digits] ?? $index['phone'][substr($digits, -8)] ?? null;

        return $id ? static::query()->find($id) : null;
    }

    /**
     * @return array{email: array<string, int>, phone: array<string, int>}
     */
    private static function contactIndex(): array
    {
        if (self::$contactIndex !== null) {
            return self::$contactIndex;
        }

        $email = [];
        $phone = [];

        static::query()->orderBy('id')->get(['id', 'email', 'phone'])->each(function (self $client) use (&$email, &$phone): void {
            $address = strtolower(trim((string) $client->email));
            if (str_contains($address, '@')) {
                $email[$address] ??= $client->id;
            }

            $digits = preg_replace('/\D+/', '', (string) $client->phone) ?? '';
            if (strlen($digits) >= 8) {
                $phone[$digits] ??= $client->id;
                $phone[substr($digits, -8)] ??= $client->id;
            }
        });

        return self::$contactIndex = ['email' => $email, 'phone' => $phone];
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

    public static function isWhatsAppKey(mixed $key): bool
    {
        return is_string($key) && str_starts_with($key, self::WHATSAPP_PREFIX);
    }

    public static function normalizeWhatsAppPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function whatsappKey(string $phone): string
    {
        return self::WHATSAPP_PREFIX.self::normalizeWhatsAppPhone($phone);
    }

    public static function whatsappPhoneFromKey(mixed $key): string
    {
        if (! self::isWhatsAppKey($key)) {
            return '';
        }

        return substr((string) $key, strlen(self::WHATSAPP_PREFIX));
    }

    public function isWhatsApp(): bool
    {
        return self::isWhatsAppKey($this->telegram_user_id);
    }

    public function requestSource(): RequestSource
    {
        return $this->isWhatsApp() ? RequestSource::WhatsApp : RequestSource::Telegram;
    }

    public function telegramPrivateUrl(): ?string
    {
        $id = trim((string) ($this->telegram_user_id ?? ''));
        if ($id === '') {
            return null;
        }
        if (self::isWhatsAppKey($id)) {
            $phone = self::whatsappPhoneFromKey($id);

            return $phone !== '' && ctype_digit($phone) ? 'https://wa.me/'.$phone : null;
        }
        if (! ctype_digit($id)) {
            return null;
        }

        return 'tg://user?id='.$id;
    }

    public function telegramContactLine(): ?string
    {
        $url = $this->telegramPrivateUrl();

        return filled($url) ? 'تواصل خاص: '.$url : null;
    }

    /**
     * A bot client gets its Odoo partner and opportunity only after every
     * profile question is answered, so Odoo never holds a half-filled lead.
     */
    public function readyForOdoo(): bool
    {
        return ! filled($this->telegram_user_id) || $this->profileFinished();
    }

    public function profileFinished(): bool
    {
        return $this->pendingProfileFields() === [];
    }

    /**
     * Questions the Telegram bot still asks, in order. WhatsApp keeps the first three.
     *
     * @return list<string>
     */
    public function pendingProfileFields(): array
    {
        $missing = $this->missingProfileFields();
        if ($this->isWhatsApp()) {
            return $missing;
        }

        if (! filled($this->email) && $this->email_skipped_at === null) {
            $missing[] = 'email';
        }
        if (! filled($this->company_activity)) {
            $missing[] = 'company_activity';
        }

        return $missing;
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
