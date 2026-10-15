<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\StagingGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Adds or updates a full-admin dashboard login on the testers' copy only.
 */
class StagingTesterCommand extends Command
{
    protected $signature = 'staging:tester
        {email : Email the tester signs in with}
        {--name= : Name shown in the dashboard}
        {--generate : Make a random password and print it once instead of asking}
        {--remove : Delete this tester instead}';

    protected $description = 'Add a tester login (full admin) to the staging dashboard';

    public function handle(): int
    {
        $database = config('database.connections.'.config('database.default').'.database');
        if (! StagingGuard::isStagingDatabase((string) app()->environment(), $database)) {
            $this->error('Refused: this command runs only with APP_ENV=staging on the '.StagingGuard::DATABASE.' database.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('That is not a valid email.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->warn('No tester with that email.');

                return self::SUCCESS;
            }
            $user->tokens()->delete();
            $user->delete();
            $this->info('Tester removed: '.$email);

            return self::SUCCESS;
        }

        $generated = (bool) $this->option('generate');
        $password = $generated ? Str::password(16, symbols: false) : $this->askPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => trim((string) $this->option('name')) ?: ($user->name ?: Str::before($email, '@')),
            'password' => $password,
            'is_admin' => true,
            'role_id' => null,
            'locale' => $user->locale ?: 'ar',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
        $user->tokens()->delete();

        $this->info('Tester ready: '.$email.' (full admin).');
        if ($generated) {
            $this->line('Password (shown once): '.$password);
        }
        $this->line('Sign in: '.rtrim((string) config('app.url'), '/').'/dashboard/');

        return self::SUCCESS;
    }

    private function askPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run it in a terminal to type the password, or add --generate.');

            return null;
        }

        $password = (string) $this->secret('Password (at least 10 characters)');
        if (mb_strlen($password) < 10) {
            $this->error('The password must be at least 10 characters.');

            return null;
        }
        if ($password !== (string) $this->secret('Repeat the password')) {
            $this->error('The two passwords do not match.');

            return null;
        }

        return $password;
    }
}
