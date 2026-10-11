<?php

namespace App\Services\AppAuth;

use App\Enums\AppRole;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds the account behind a phone number or a verified email, and decides
 * which side of the app it opens. Staff win over client rows that share the
 * same phone, because staff often test the client bot with their own number.
 */
class AppAccountResolver
{
    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * The chat that receives the code, and the user it signs in.
     */
    public function forPhone(string $phone): ?AppOtpTarget
    {
        $digits = self::digits($phone);
        if (strlen($digits) < 8) {
            return null;
        }

        $employee = $this->employeeByPhone($digits);
        if ($employee && filled($employee->telegram_user_id)) {
            return new AppOtpTarget(
                user: $this->userForEmployee($employee),
                chatId: (string) $employee->telegram_user_id,
                bot: 'staff',
                locale: 'ar',
            );
        }

        $client = $this->clientByContact(null, $digits);
        if ($client && filled($client->telegram_user_id)) {
            return new AppOtpTarget(
                user: $this->userForClient($client),
                chatId: (string) $client->telegram_user_id,
                bot: 'client',
                locale: $client->locale === 'en' ? 'en' : 'ar',
            );
        }

        return null;
    }

    /**
     * A verified email from Google or Facebook. Dashboard users first, then
     * employees, then clients.
     */
    public function forEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return null;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user && $this->roleFor($user) !== null) {
            return $user;
        }

        $employee = Employee::query()->approved()->whereRaw('lower(email) = ?', [$email])->first();
        if ($employee) {
            return $this->userForEmployee($employee);
        }

        $client = $this->clientByContact($email, null);
        if ($client) {
            return $this->userForClient($client);
        }

        return null;
    }

    public function roleFor(User $user): ?AppRole
    {
        if ($user->is_admin) {
            return AppRole::Admin;
        }

        if ($user->role_id !== null || $user->employee()->approved()->exists()) {
            return AppRole::Employee;
        }

        if ($user->client()->exists()) {
            return AppRole::Client;
        }

        return null;
    }

    /**
     * The contact index is a static cache meant for bulk imports. Build it
     * fresh for this lookup and drop it after, so neither side sees stale ids.
     */
    private function clientByContact(?string $email, ?string $phone): ?Client
    {
        Client::forgetContactIndex();
        try {
            return Client::findByContact($email, $phone);
        } finally {
            Client::forgetContactIndex();
        }
    }

    private function employeeByPhone(string $digits): ?Employee
    {
        $tail = substr($digits, -8);

        return Employee::query()
            ->approved()
            ->whereNotNull('phone')
            ->get()
            ->first(function (Employee $employee) use ($digits, $tail): bool {
                $own = self::digits((string) $employee->phone);

                return strlen($own) >= 8 && ($own === $digits || substr($own, -8) === $tail);
            });
    }

    private function userForEmployee(Employee $employee): User
    {
        if ($employee->user) {
            return $employee->user;
        }

        return DB::transaction(function () use ($employee): User {
            $user = User::query()->create([
                'name' => $employee->name,
                'email' => $this->freeEmail($employee->email, 'employee-'.$employee->id),
                'phone' => $employee->phone,
                'password' => Str::random(64),
                'locale' => 'ar',
            ]);
            $employee->forceFill(['user_id' => $user->id])->save();

            return $user;
        });
    }

    private function userForClient(Client $client): User
    {
        if ($client->user) {
            return $client->user;
        }

        return DB::transaction(function () use ($client): User {
            $user = User::query()->create([
                'name' => $client->name ?: 'Client',
                'email' => $this->freeEmail($client->email, 'client-'.$client->id),
                'phone' => $client->phone,
                'password' => Str::random(64),
                'locale' => $client->locale ?: 'ar',
            ]);
            $client->forceFill(['user_id' => $user->id])->save();

            return $user;
        });
    }

    private function freeEmail(?string $email, string $fallbackLocal): string
    {
        $email = strtolower(trim((string) $email));
        if (str_contains($email, '@') && ! User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return $email;
        }

        return $fallbackLocal.'@'.User::APP_PLACEHOLDER_EMAIL_DOMAIN;
    }
}
