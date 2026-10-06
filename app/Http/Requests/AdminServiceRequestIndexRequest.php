<?php

namespace App\Http\Requests;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use Illuminate\Validation\Rule;

class AdminServiceRequestIndexRequest extends PaginatedIndexRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(RequestStatus::class)],
            'source' => ['sometimes', 'nullable', 'string', Rule::enum(RequestSource::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'quotation' => ['sometimes', 'nullable', 'string', Rule::in(['yes', 'no'])],
            'invoice' => ['sometimes', 'nullable', 'string', Rule::in(['yes', 'no'])],
            'drive' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'sent', 'pending', 'failed'])],
        ];
    }
}
