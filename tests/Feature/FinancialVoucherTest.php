<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Mail\ClientDocumentMail;
use App\Models\FinancialVoucher;
use App\Models\FinancialVoucherSignature;
use App\Models\FinancialVoucherTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        $this->assertSame([...StaffAbility::crudActions(), 'send'], $row['actions']);
    }

    public function test_a_notes_record_keeps_the_numbered_notes_of_a_conversation(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $long = str_repeat('نقطة تفصيلية ', 60);

        $this->postJson('/api/admin/vouchers', [
            'kind' => 'notes',
            'party_name' => 'شركة النور',
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
            'lines' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        $saved = $this->postJson('/api/admin/vouchers', [
            'kind' => 'notes',
            'party_name' => 'شركة النور',
            'signer_name' => 'فايز',
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
            'purpose' => 'مراجعة هوية الحملة',
            'reference' => 'اجتماع في مكتب دمشق',
            'lines' => array_map(fn (int $index): array => ['memo' => $index === 0 ? $long : "ملاحظة {$index}"], range(0, 24)),
        ])->assertCreated()->json('data');

        $this->assertSame('notes', $saved['kind']);
        $this->assertEquals(0, $saved['amount']);
        $this->assertCount(25, $saved['lines']);
        $this->assertSame(trim($long), $saved['lines'][0]['memo']);
    }

    public function test_a_voucher_is_sent_as_a_pdf_by_email_and_whatsapp(): void
    {
        Mail::fake();
        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
        ]);
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'])]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $voucher = $this->postJson('/api/admin/vouchers', [
            'kind' => 'notes',
            'party_name' => 'شركة النور',
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
            'lines' => [['memo' => 'تسليم الشعار يوم الأحد']],
        ])->assertCreated()->json('data');
        $pdf = UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%fake\n");

        $this->post("/api/admin/vouchers/{$voucher['id']}/send", [
            'pdf' => $pdf,
            'channel' => 'both',
            'name' => 'سامر',
            'email' => 'samer@example.com',
            'phone' => '0944 000 123',
            'message' => 'هذا محضر اجتماع اليوم.',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.sent.email', true)
            ->assertJsonPath('data.sent.whatsapp', true);

        Mail::assertSent(ClientDocumentMail::class, fn ($mail): bool => $mail->hasTo('samer@example.com')
            && str_contains($mail->body, 'محضر الملاحظات') && str_contains($mail->body, 'هذا محضر اجتماع اليوم.'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'wa-web.test') && ($request['to'] ?? null) === '963944000123');
        $this->assertNotNull(FinancialVoucher::query()->find($voucher['id'])->sent_at);

        // Email needs an address.
        $this->post("/api/admin/vouchers/{$voucher['id']}/send", ['pdf' => $pdf, 'channel' => 'email', 'name' => 'سامر'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_sending_needs_its_own_permission(): void
    {
        $voucher = FinancialVoucher::query()->create([
            'serial' => 'HOC-V-2026-0099', 'kind' => 'notes', 'party_name' => 'x', 'amount' => 0,
            'currency' => 'USD', 'issued_on' => '2026-10-13', 'lines' => [['memo' => 'x']],
        ]);
        $editor = User::factory()->create(['role_id' => Role::query()->create([
            'name' => 'محرر مستندات',
            'abilities' => ['ops.vouchers.view', 'ops.vouchers.update'],
        ])->id]);
        Sanctum::actingAs($editor);

        $this->post("/api/admin/vouchers/{$voucher->id}/send", ['channel' => 'email'], ['Accept' => 'application/json'])->assertForbidden();
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

    public function test_a_voucher_takes_its_own_title_signature_words_and_negative_amounts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $saved = $this->postJson('/api/admin/vouchers', [
            'kind' => 'payment',
            'title' => 'سند تسوية حساب',
            'party_name' => 'مورّد',
            'amount' => -150.5,
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
            'signer_title' => 'المحاسب',
            'counter_signer_title' => 'HOC',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'سند تسوية حساب')
            ->assertJsonPath('data.signer_title', 'المحاسب')
            ->assertJsonPath('data.counter_signer_title', 'HOC')
            ->json('data');
        $this->assertEquals(-150.5, $saved['amount']);
        $this->assertSame('سند تسوية حساب', $this->getJson('/api/admin/vouchers')->json('data.0.title'));

        // A deduction line counts in the delivery total; zero is still refused.
        $delivery = $this->postJson('/api/admin/vouchers', [
            'kind' => 'delivery',
            'party_name' => 'موظف',
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
            'lines' => [['memo' => 'راتب', 'debit' => 100], ['memo' => 'خصم سلفة', 'debit' => -30]],
        ])->assertCreated()->json('data');
        $this->assertEquals(70, $delivery['amount']);
        $this->assertCount(2, $delivery['lines']);

        $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'عميل',
            'amount' => 0,
            'currency' => 'USD',
            'issued_on' => '2026-10-13',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson('/api/admin/voucher-templates', [
            'name' => 'تسوية',
            'kind' => 'payment',
            'title' => 'سند تسوية حساب',
            'currency' => 'USD',
            'amount' => -20,
            'counter_signer_title' => 'HOC',
        ])->assertCreated()
            ->assertJsonPath('data.data.title', 'سند تسوية حساب')
            ->assertJsonPath('data.data.counter_signer_title', 'HOC')
            ->assertJsonPath('data.data.amount', -20);
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

    public function test_remaining_balance_is_optional_on_the_sheet(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $hidden = $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'عميل',
            'amount' => 500,
            'remaining' => 150,
            'show_remaining' => false,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
        ])->assertCreated()->json('data');

        $this->assertEquals(150, $hidden['remaining']);
        $this->assertFalse($hidden['show_remaining']);

        $shown = $this->putJson('/api/admin/vouchers/'.$hidden['id'], [
            'kind' => 'receipt',
            'party_name' => 'عميل',
            'amount' => 500,
            'remaining' => 120.5,
            'show_remaining' => true,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
        ])->assertOk()->json('data');

        $this->assertEquals(120.5, $shown['remaining']);
        $this->assertTrue($shown['show_remaining']);

        $this->postJson('/api/admin/voucher-templates', [
            'name' => 'قبض مع باقي',
            'kind' => 'receipt',
            'party_name' => 'عميل',
            'amount' => 100,
            'remaining' => 40,
            'show_remaining' => true,
            'currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('data.data.remaining', 40)
            ->assertJsonPath('data.data.show_remaining', true);
    }

    public function test_document_numbers_increment_and_can_be_edited(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $first = $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'أ',
            'amount' => 10,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
        ])->assertCreated()->assertJsonPath('data.serial', 'HOC-V-2026-0001')->json('data');

        $this->getJson('/api/admin/vouchers/next-serial')
            ->assertOk()
            ->assertJsonPath('data.serial', 'HOC-V-2026-0002');

        $second = $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'ب',
            'amount' => 20,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
            'serial' => '7',
        ])->assertCreated()->assertJsonPath('data.serial', 'HOC-V-2026-0007')->json('data');

        $this->putJson('/api/admin/vouchers/'.$second['id'], [
            'kind' => 'receipt',
            'party_name' => 'ب',
            'amount' => 20,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
            'serial' => 'N0009',
        ])->assertOk()->assertJsonPath('data.serial', 'HOC-V-2026-0009');

        $this->postJson('/api/admin/vouchers', [
            'kind' => 'receipt',
            'party_name' => 'ج',
            'amount' => 5,
            'currency' => 'USD',
            'issued_on' => '2026-10-15',
            'serial' => '9',
        ])->assertUnprocessable()->assertJsonValidationErrors('serial');

        $this->deleteJson('/api/admin/vouchers/'.$first['id'])->assertOk();
        // After a delete, the next number follows the highest remaining serial, not the row count.
        $this->getJson('/api/admin/vouchers/next-serial')
            ->assertOk()
            ->assertJsonPath('data.serial', 'HOC-V-2026-0010');
    }

    public function test_staff_can_save_and_reuse_signature_images(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $created = $this->postJson('/api/admin/voucher-signatures', [
            'name' => 'توقيع المدير',
            'image' => self::SIGNATURE,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'توقيع المدير')
            ->assertJsonPath('data.image', self::SIGNATURE)
            ->json('data');

        $this->getJson('/api/admin/voucher-signatures')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id'])
            ->assertJsonPath('data.0.name', 'توقيع المدير');

        $this->postJson('/api/admin/voucher-signatures', [
            'name' => 'سيء',
            'image' => 'not-an-image',
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->deleteJson('/api/admin/voucher-signatures/'.$created['id'])->assertOk();
        $this->assertSame(0, FinancialVoucherSignature::query()->count());
    }
}
