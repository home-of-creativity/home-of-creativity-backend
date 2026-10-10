<?php

namespace App\Support;

use App\Services\GeminiService;

class ResolveCompanyName
{
    public function __construct(private GeminiService $gemini) {}

    public function resolve(?string $value, ?string $telegramUserId = null): ?string
    {
        $judged = ClientProfileValue::judgeCompanyName($value, $telegramUserId);
        if (is_string($judged['name']) && $judged['name'] !== '') {
            return $judged['name'];
        }
        if ($judged['review'] !== true) {
            return null;
        }

        $extracted = $this->gemini->extractCompanyName((string) $value);
        if ($extracted === null) {
            return null;
        }

        $again = ClientProfileValue::judgeCompanyName($extracted, $telegramUserId);

        return is_string($again['name']) && $again['name'] !== '' ? $again['name'] : null;
    }
}
