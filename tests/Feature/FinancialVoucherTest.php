<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\FinancialVoucher;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialVoucherTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_admin_saves_signs_and_deletes_a_receipt(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'محل النور',
            'amount' => 250,
            'currency' => 'USD',
            'amount_words' => 'مئتان وخمسون دولاراً',
            'issued_on' => '2026-10-08',
            'purpose' => 'دفعة تصميم',
            'signer_name' => 'أمين الصندوق',
            'signature' => self::SIGNATURE,
        ])->assertCreated()->json('data');

        $this->assertSame('HOC-V-2026-0001', $created['serial']);
        $this->assertTrue($created['signed']);
        $this->assertSame(self::SIGNATURE, $created['signature']);

        $list = $this->getJson('/api/admin/vouchers')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('signature', $list[0]);
        $this->assertTrue($list[0]['signed']);

        $this->putJson('/api/admin/vouchers/'.$created['id'], [
            'kind' => 'receipt',
            'party_name' => 'محل النور',
            'amount' => 300,
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'signature' => null,
        ])->assertOk()->assertJsonPath('data.amount', 300)->assertJsonPath('data.signed', false);

        $this->deleteJson('/api/admin/vouchers/'.$created['id'])->assertOk();
        $this->assertSame(0, FinancialVoucher::query()->count());
    }

    public function test_journal_lines_must_balance(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/vouchers', [
            'kind' => 'journal',
            'party_name' => 'قيد افتتاح',
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'lines' => [
                ['memo' => 'صندوق', 'debit' => 100, 'credit' => 0],
                ['memo' => 'رأس المال', 'debit' => 0, 'credit' => 40],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $saved = $this->postJson('/api/admin/vouchers', [
            'kind' => 'journal',
            'party_name' => 'قيد افتتاح',
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'lines' => [
                ['memo' => 'صندوق', 'debit' => 100, 'credit' => 0],
                ['memo' => 'رأس المال', 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated()->json('data');

        $this->assertEquals(100, $saved['amount']);
    }

    public function test_voucher_routes_follow_the_crud_ability(): void
    {
        $role = Role::query()->create([
            'name' => 'محاسبة',
            'abilities' => [StaffAbility::OpsVouchers->value.'.view'],
        ]);
        $cashier = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/admin/vouchers')->assertOk();
        $this->postJson('/api/admin/vouchers', [])->assertForbidden();

        $locked = User::factory()->create([
            'role_id' => Role::query()->create([
                'name' => 'بلا مسندات',
                'abilities' => [StaffAbility::OpsFinance->value],
            ])->id,
        ]);
        Sanctum::actingAs($locked);
        $this->getJson('/api/admin/vouchers')->assertForbidden();
    }

    public function test_permissions_catalog_lists_voucher_actions(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $row = collect($this->getJson('/api/admin/staff-access')->assertOk()->json('abilities'))
            ->firstWhere('key', StaffAbility::OpsVouchers->value);

        $this->assertNotNull($row);
        $this->assertSame('المسندات المالية', $row['label_ar']);
        $this->assertSame(StaffAbility::crudActions(), $row['actions']);
    }
}
