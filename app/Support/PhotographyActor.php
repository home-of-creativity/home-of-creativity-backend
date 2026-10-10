<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;

/**
 * Who moved a photography booking: the client in the chat, a photographer on the
 * staff bot, a person on the dashboard, or the scheduler.
 */
final class PhotographyActor
{
    private function __construct(
        public readonly string $kind,
        public readonly ?Employee $employee = null,
        public readonly ?User $user = null,
    ) {}

    public static function client(): self
    {
        return new self('client');
    }

    public static function system(): self
    {
        return new self('system');
    }

    public static function employee(Employee $employee): self
    {
        return new self('staff', $employee, $employee->user);
    }

    /** A dashboard user; the employee row linked to that login, if any, is the photographer. */
    public static function user(User $user): self
    {
        $user->loadMissing('employee');

        return new self('staff', $user->employee, $user);
    }

    public function isClient(): bool
    {
        return $this->kind === 'client';
    }

    public function isStaff(): bool
    {
        return $this->kind === 'staff';
    }

    public function label(): string
    {
        return match ($this->kind) {
            'client' => 'العميل',
            'system' => 'النظام',
            default => $this->employee?->name ?: ($this->user?->name ?: 'موظف'),
        };
    }
}
