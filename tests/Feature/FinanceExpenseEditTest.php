<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\OpsExpense;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceExpenseEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_staff_edit_and_delete_an_expense(): void
    {
        Http::fake();
        $role = Role::query()->create(['name' => 'المالية', 'abilities' => [StaffAbility::OpsFinance->value]]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
        $expense = OpsExpense::query()->create(['amount' => 50, 'category' => 'مكتب', 'note' => 'ورق', 'spent_at' => '2026-10-01 14:30:00']);

        $this->putJson("/api/admin/finance/expenses/{$expense->id}", [
            'amount' => 75.5,
            'category' => 'برامج',
            'note' => 'اشتراك أدوبي',
            'spent_at' => '2026-10-05',
        ])->assertOk()
            ->assertJsonPath('data.expense_rows.0.amount', 75.5)
            ->assertJsonPath('data.expense_rows.0.category', 'برامج')
            ->assertJsonPath('data.expense_rows.0.spent_at', '2026-10-05')
            ->assertJsonPath('data.expenses', 75.5);
        $this->assertSame('14:30', $expense->fresh()->spent_at->format('H:i'));

        $this->putJson("/api/admin/finance/expenses/{$expense->id}", ['amount' => 0, 'category' => 'غير موجود', 'spent_at' => '2026-10-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'category']);

        $this->deleteJson("/api/admin/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.expenses', 0);
        $this->assertSame(0, OpsExpense::query()->count());

        $other = Role::query()->create(['name' => 'تصميم', 'abilities' => []]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $other->id]));
        $kept = OpsExpense::query()->create(['amount' => 10, 'category' => 'أخرى', 'spent_at' => now()]);
        $this->deleteJson("/api/admin/finance/expenses/{$kept->id}")->assertForbidden();
        $this->assertSame(1, OpsExpense::query()->count());
    }
}
