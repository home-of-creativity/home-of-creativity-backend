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
        HydrateEmployeeFromOdoo $hydrateEmployeeFromOdoo,
    ) {
        if ($odoo->configured()) {
            try {
                Cache::remember('odoo:hr:index-pull', 10, function () use ($syncOdooEmployees): bool {
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

        if ($odoo->configured()) {
            $paginator->setCollection(
                $paginator->getCollection()
                    ->map(fn (Employee $employee): Employee => $hydrateEmployeeFromOdoo->handle($employee))
                    ->filter(fn (Employee $employee): bool => $employee->exists)
                    ->values()
            );
        }

        return EmployeeResource::collection($paginator)->additional(['message' => 'ok']);
    }

    public function store(
        StoreEmployeeRequest $request,
        GenerateEmployeeCode $generateEmployeeCode,
        PushEmployeeToOdoo $pushEmployeeToOdoo,
    ): JsonResponse {
        $data = $request->validated();
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
        $employee->fill($request->validated())->save();

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
            ...$request->validated(),
            'status' => EmployeeStatus::Approved,
            'is_active' => true,
        ])->save();

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
        return response()->json([
            'data' => $clickUp->members(),
            'message' => $clickUp->configured() ? 'ok' : 'ClickUp is not configured.',
        ]);
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
