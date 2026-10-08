<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\FinancialVoucher;
use App\Models\FinancialVoucherTemplate;
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
        $this->postJson('/api/admin/voucher-templates', [])->assertForbidden();

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

    public function test_delivery_total_is_the_sum_of_the_lines(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $saved = $this->postJson('/api/admin/vouchers', [
            'kind' => 'delivery',
            'party_name' => 'موظف',
            'amount' => 999,
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'lines' => [
                ['memo' => 'بدل', 'debit' => 40],
                ['memo' => 'سلفة', 'debit' => 10],
            ],
        ])->assertCreated()->json('data');

        $this->assertEquals(50, $saved['amount']);
    }

    public function test_a_background_image_is_stored_on_the_voucher_and_not_the_list(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $image = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';

        $created = $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'محل النور',
            'amount' => 20,
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'background' => $image,
        ])->assertCreated()->json('data');

        $this->assertSame($image, $created['background']);
        $this->assertArrayNotHasKey('background', $this->getJson('/api/admin/vouchers')->json('data.0'));

        $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'محل النور',
            'amount' => 20,
            'currency' => 'USD',
            'issued_on' => '2026-10-08',
            'background' => 'not-an-image',
        ])->assertUnprocessable()->assertJsonValidationErrors('background');
    }

    public function test_a_template_keeps_the_voucher_data(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $image = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';

        $this->postJson('/api/admin/voucher-templates', [
            'name' => 'تسليم رواتب',
            'kind' => 'delivery',
            'party_name' => 'القسم',
            'currency' => 'USD',
            'amount' => 1,
            'lines' => [
                ['memo' => 'راتب', 'debit' => 80],
            ],
            'background' => $image,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'تسليم رواتب')
            ->assertJsonPath('data.data.amount', 80)
            ->assertJsonPath('data.data.background', $image);

        $list = $this->getJson('/api/admin/voucher-templates')->assertOk()->json('data');
        $this->assertSame('تسليم رواتب', $list[0]['name']);
        $this->assertArrayNotHasKey('data', $list[0]);

        $this->postJson('/api/admin/voucher-templates', [
            'name' => 'تسليم رواتب',
            'kind' => 'delivery',
            'party_name' => 'قسم آخر',
            'currency' => 'SYP',
            'lines' => [
                ['memo' => 'راتب', 'debit' => 90],
            ],
        ])->assertOk()->assertJsonPath('data.data.party_name', 'قسم آخر');

        $this->assertSame(1, FinancialVoucherTemplate::query()->count());

        $id = FinancialVoucherTemplate::query()->value('id');
        $this->getJson('/api/admin/voucher-templates/'.$id)
            ->assertOk()
            ->assertJsonPath('data.data.amount', 90);

        $this->deleteJson('/api/admin/voucher-templates/'.$id)->assertOk();
        $this->assertSame(0, FinancialVoucherTemplate::query()->count());
    }
}
