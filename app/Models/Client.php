<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

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

    public function profileComplete(): bool
    {
        return filled($this->name)
            && filled($this->phone)
            && filled($this->company_name);
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
