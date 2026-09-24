<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteEmployee;
use App\Actions\GenerateEmployeeCode;
use App\Actions\HydrateEmployeeFromOdoo;
use App\Actions\PushEmployeeToOdoo;
use App\Actions\SyncOdooEmployees;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveEmployeeRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClickUpClient;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeController extends Controller
{
    public function index(
        OdooClient $odoo,
        SyncOdooEmployees $syncOdooEmployees,
    ) {
        if ($odoo->configured() && (app()->runningUnitTests() || PHP_SAPI !== 'cli-server')) {
            try {
                Cache::remember('odoo:hr:index-pull', 60, function () use ($syncOdooEmployees): bool {
                    $syncOdooEmployees->handle(200);

                    return true;
                });
            } catch (Throwable $exception) {
                Log::warning('Odoo HR pull on employees index failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $paginator = Employee::query()
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->latest('id')
            ->paginate(50);

        return EmployeeResource::collection($paginator)->additional(['message' => 'ok']);
    }

    public function store(
        StoreEmployeeRequest $request,
        GenerateEmployeeCode $generateEmployeeCode,
        PushEmployeeToOdoo $pushEmployeeToOdoo,
    ): JsonResponse {
        $data = $this->applyClickUpEmail($request->validated());
        $data['code'] = $data['code'] ?? $generateEmployeeCode->handle();
        $data['is_active'] = $data['is_active'] ?? true;
        $data['status'] = EmployeeStatus::Approved;

        $employee = $pushEmployeeToOdoo->handle(Employee::query()->create($data));

        return EmployeeResource::make($employee)
            ->additional(['message' => 'Employee created and linked to Odoo.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee, HydrateEmployeeFromOdoo $hydrateEmployeeFromOdoo): EmployeeResource
    {
        return EmployeeResource::make($hydrateEmployeeFromOdoo->handle($employee))
            ->additional(['message' => 'ok']);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, PushEmployeeToOdoo $pushEmployeeToOdoo): EmployeeResource
    {
        $employee->fill($this->applyClickUpEmail($request->validated()))->save();
        $this->syncLoginEmail($employee);

        return EmployeeResource::make($pushEmployeeToOdoo->handle($employee->refresh()))
            ->additional(['message' => 'Employee updated in dashboard and Odoo.']);
    }

    public function destroy(Employee $employee, DeleteEmployee $deleteEmployee)
    {
        $deleteEmployee->handle($employee);

        return response()->json([
            'data' => null,
            'message' => 'Employee deleted from dashboard and Odoo.',
        ]);
    }

    public function approve(ApproveEmployeeRequest $request, Employee $employee, PushEmployeeToOdoo $pushEmployeeToOdoo): EmployeeResource
    {
        if ($employee->status === EmployeeStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['This employee is already approved.'],
            ]);
        }

        $employee->fill([
            ...$this->applyClickUpEmail($request->validated()),
            'status' => EmployeeStatus::Approved,
            'is_active' => true,
        ])->save();
        $this->syncLoginEmail($employee);

        $employee = $pushEmployeeToOdoo->handle($employee->refresh());

        $this->notifyDecision($employee, "تمت الموافقة على انضمامك يا {$employee->name}. ستصلك طلبات قسمك هنا.");

        return EmployeeResource::make($employee)
            ->additional(['message' => 'Approved.']);
    }

    public function reject(Employee $employee): EmployeeResource
    {
        if ($employee->status !== EmployeeStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending join requests can be rejected.'],
            ]);
        }

        $employee->fill([
            'status' => EmployeeStatus::Rejected,
            'is_active' => false,
        ])->save();

        $this->notifyDecision($employee, 'لم تتم الموافقة على طلب انضمامك حالياً. يمكنك إعادة المحاولة لاحقاً من البوت عبر /start.');

        return EmployeeResource::make($employee)
            ->additional(['message' => 'Rejected.']);
    }

    public function clickupMembers(ClickUpClient $clickUp): JsonResponse
    {
        $members = app()->runningUnitTests()
            ? $clickUp->members()
            : Cache::remember('clickup:members', 60, fn (): array => $clickUp->members());

        return response()->json([
            'data' => $members,
            'message' => $clickUp->configured() ? 'ok' : 'ClickUp is not configured.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyClickUpEmail(array $data): array
    {
        if (! filled($data['clickup_user_id'] ?? null)) {
            return $data;
        }

        $clickUp = app(ClickUpClient::class);
        if (! $clickUp->configured()) {
            return $data;
        }

        $member = collect($clickUp->members())->firstWhere('id', (string) $data['clickup_user_id']);
        $email = is_array($member) ? ($member['email'] ?? null) : null;
        if (! filled($email)) {
            throw ValidationException::withMessages([
                'clickup_user_id' => ['This ClickUp member has no email.'],
            ]);
        }

        $data['email'] = $email;

        return $data;
    }

    private function syncLoginEmail(Employee $employee): void
    {
        $user = $employee->user;
        if (! $user || ! filled($employee->email) || $user->email === $employee->email) {
            return;
        }

        $taken = User::query()
            ->where('email', $employee->email)
            ->whereKeyNot($user->id)
            ->exists();
        if ($taken) {
            return;
        }

        $user->forceFill(['email' => $employee->email])->save();
    }

    private function notifyDecision(Employee $employee, string $text): void
    {
        $chatId = (string) $employee->telegram_user_id;
        if ($chatId === '') {
            return;
        }

        $telegram = app(TelegramNotifier::class);
        $bot = $telegram->configured('staff') ? 'staff' : 'client';
        if (! $telegram->configured($bot)) {
            return;
        }

        try {
            $telegram->send($chatId, $text, $bot);
        } catch (Throwable $exception) {
            Log::warning('Employee decision notify failed.', [
                'employee_id' => $employee->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
