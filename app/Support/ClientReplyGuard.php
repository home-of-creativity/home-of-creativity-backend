<?php

namespace App\Support;

use App\Models\Client;

/**
 * Stops a model reply from leaving this client's own account.
 * The client message is untrusted and can ask the model to ignore its rules.
 */
class ClientReplyGuard
{
    public function allows(Client $client, string $answer): bool
    {
        $answer = trim($answer);
        if ($answer === '') {
            return true;
        }
        if (preg_match('/BEGIN [A-Z ]*PRIVATE KEY|x-webhook-secret|APP_KEY\s*=|whsec_|sk-[A-Za-z0-9]{10,}|CLIENT RECORDS:|OPERATIONS:/i', $answer) === 1) {
            return false;
        }

        $ownEmail = mb_strtolower(trim((string) $client->email));
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $answer, $emails) > 0) {
            foreach ($emails[0] as $email) {
                $email = mb_strtolower($email);
                if ($email !== $ownEmail && ! str_ends_with($email, '@hoc.agency')) {
                    return false;
                }
            }
        }

        $allowedPhones = array_values(array_filter([
            preg_replace('/\D+/', '', (string) $client->phone) ?? '',
            preg_replace('/\D+/', '', ClientChannelGate::SUPPORT_PHONE) ?? '',
        ]));
        $withoutRefs = preg_replace('/REQ-\d{4}-\d+/', ' ', $answer) ?? $answer;
        if (preg_match_all('/\+?\d[\d\s\-()]{8,}\d/', $withoutRefs, $phones) > 0) {
            foreach ($phones[0] as $phone) {
                $digits = preg_replace('/\D+/', '', $phone) ?? '';
                if (strlen($digits) < 9) {
                    continue;
                }
                if (! $this->samePhone($digits, $allowedPhones)) {
                    return false;
                }
            }
        }

        if (preg_match_all('/REQ-\d{4}-\d+/', $answer, $refs) > 0) {
            $owned = $client->requests()->pluck('number')->map(fn ($number): string => (string) $number)->all();
            foreach ($refs[0] as $ref) {
                if (! in_array($ref, $owned, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function samePhone(string $digits, array $allowed): bool
    {
        $tail = substr($digits, -9);
        foreach ($allowed as $phone) {
            if ($phone !== '' && (str_ends_with($phone, $tail) || str_ends_with($digits, substr($phone, -9)))) {
                return true;
            }
        }

        return false;
    }
}
