<?php

namespace App\Http\Requests;

use App\Enums\SocialInboxKind;
use App\Enums\StaffAbility;
use App\Models\SocialInboxItem;
use Illuminate\Foundation\Http\FormRequest;

class ReplySocialInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('social_inbox_item');
        $user = $this->user();
        if (! $user || ! $item instanceof SocialInboxItem) {
            return false;
        }

        $ability = $item->kind === SocialInboxKind::Message
            ? StaffAbility::SocialMessages
            : StaffAbility::SocialEngage;

        return $user->canAbility($ability) && $user->canAccessSocialAccount((int) $item->social_account_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
