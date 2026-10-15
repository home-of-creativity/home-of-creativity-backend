<?php

namespace Tests\Feature;

use App\Actions\WhatsAppSupportAgent;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WhatsAppSupportAgentTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'http://n8n.test/webhook/hoc-support-agent';

    private const MANAGEMENT = '963944000900';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.whatsapp.support_numbers' => ['+963944000999'],
            'services.whatsapp.management_numbers' => ['+963944000900'],
            'services.n8n.support_agent_url' => self::AGENT,
            'services.n8n.webhook_secret' => 'n8n-secret',
            'services.gemini.api_key' => '',
            'services.gemini.e2e_stub' => false,
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
        Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963922222222',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963922222222',
            'locale' => 'ar',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_asking_for_a_person_moves_the_chat_to_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'أهلين نور، شو اللي بقدر ساعدك فيه؟', 'handoff' => false, 'handoff_reason' => '']);

        $this->send('بدي احكي مع موظف', 'm1');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && $sent->hasHeader('X-N8N-Secret', 'n8n-secret')
            && $sent['message'] === 'بدي احكي مع موظف'
            && $sent['reason'] === 'The client asked for a person.'
            && str_contains((string) $sent['records'], 'Nour Co')
            && str_starts_with((string) $sent['session_id'], 'hoc-'));
        $this->assertSentToClient('مساعد الدعم');
        $this->assertSentToClient('أهلين نور، شو اللي بقدر ساعدك فيه؟');
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));

        $this->send('عندي سؤال عن تصميم اللوغو', 'm2');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT && $sent['message'] === 'عندي سؤال عن تصميم اللوغو');
    }

    public function test_two_misses_in_a_row_bring_in_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'خليني فهم عليك أكتر.', 'handoff' => false, 'handoff_reason' => '']);

        $this->send('شو قصة الملف الأزرق تبع امبارح', 'm1');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT);

        $this->send('يعني الشي اللي حكينا عنه', 'm2');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && $sent['message'] === 'يعني الشي اللي حكينا عنه'
            && str_contains((string) $sent['reason'], 'did not understand twice'));
        $this->assertSentToClient('خليني فهم عليك أكتر.');
    }

    public function test_a_handoff_alerts_support_with_the_conversation_and_holds_the_bot(): void
    {
        $this->fakeAgent(['reply' => 'رح يتواصل معك موظف من الفريق بخصوص السعر.', 'handoff' => true, 'handoff_reason' => 'Asks for a discount.']);

        $this->send('بدي موظف، بدي خصم على الباقة', 'm1');

        $this->assertSentToClient('رح يتواصل معك موظف من الفريق بخصوص السعر.');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963944000999'
            && str_contains((string) $sent['text'], 'Nour Co +963922222222')
            && str_contains((string) $sent['text'], 'Asks for a discount.')
            && str_contains((string) $sent['text'], 'العميل: بدي موظف، بدي خصم على الباقة'));
        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $this->assertTrue(Cache::has('hoc:wa-human:963922222222'));
    }

    public function test_an_agent_that_does_not_answer_hands_the_client_to_the_team(): void
    {
        Http::fake([
            self::AGENT => Http::response(['message' => 'Workflow failed'], 500),
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
        ]);

        $this->send('بدي احكي مع موظف', 'm1');

        $this->assertSentToClient('حولتك لموظف من فريق الدعم');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963944000999'
            && str_contains((string) $sent['text'], 'The support agent did not answer.'));
        $this->assertTrue(Cache::has('hoc:wa-human:963922222222'));
    }

    public function test_back_to_bot_leaves_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'أكيد، تفضل.', 'handoff' => false, 'handoff_reason' => '']);
        $this->send('بدي احكي مع موظف', 'm1');

        $this->send('رجوع للبوت', 'm2');

        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $this->assertSentToClient('رجعت معك');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT && $sent['message'] === 'رجوع للبوت');
    }

    public function test_without_the_agent_url_the_bot_keeps_its_old_escalation(): void
    {
        config(['services.n8n.support_agent_url' => '']);
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);

        $this->send('بدي احكي مع موظف', 'm1');

        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'n8n.test'));
        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
    }

    public function test_the_phone_step_explains_then_brings_in_the_agent_and_still_takes_the_number(): void
    {
        $this->fakeAgent(['reply' => 'اكتب رقم موبايلك مع مفتاح الدولة، متل +963944123456.', 'handoff' => false, 'handoff_reason' => '']);
        Client::query()->where('telegram_user_id', 'wa:963922222222')->update(['phone' => null]);
        Cache::put('hoc:wa-session:963922222222', ['step' => 'phone'], now()->addHour());

        $this->send('اهلا فيك', 'p1');
        $this->assertSentToClient('أهلين فيك.');

        $this->send('اي رقم قمت بتسجيل من قبل', 'p2');
        $this->assertSentToClient('ما عنا رقم موبايل واضح مسجل إلك.');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT);

        $this->send('اي رقم؟', 'p3');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && $sent['message'] === 'اي رقم؟'
            && str_contains((string) $sent['reason'], 'mobile number step'));
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));

        $this->send('+963 944 555 666', 'p4');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT && $sent['message'] === '+963 944 555 666');
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $this->assertSentToClient('سجلت رقمك');
        $this->assertSame('+963944555666', Client::query()->where('telegram_user_id', 'wa:963922222222')->value('phone'));
    }

    public function test_the_agent_opens_a_request_it_agreed_with_the_client(): void
    {
        $this->fakeAgent([
            'reply' => 'تمام، لحظة.',
            'handoff' => false,
            'handoff_reason' => '',
            'action' => [
                'type' => 'create_request',
                'title' => 'باقة سوشيال ميديا كبيرة',
                'description' => 'شركة Nour Co بدها باقة كبيرة: 20 بوست بالشهر وإدارة صفحتين على فيسبوك وانستغرام، والهدف زيادة الطلبات.',
                'request' => '',
                'reason' => '',
            ],
        ]);

        $this->send('بدي احكي مع موظف', 'c1');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && str_contains((string) $sent['packages'], 'Published packages'));
        $request = $this->client()->requests()->latest('id')->firstOrFail();
        $this->assertSame('باقة سوشيال ميديا كبيرة', $request->title);
        $this->assertSame(RequestStatus::Submitted, $request->status);
        $this->assertNull($request->pricing_package_id);
        $this->assertSentToClient('فتحتلك الطلب #'.ResolveServiceRequest::displayNumber($request));

        $this->send('تمام شكراً', 'c2');
        $this->assertSame(1, $this->client()->requests()->count());
    }

    public function test_the_agent_opens_a_website_package_and_keeps_asking_for_the_phone(): void
    {
        $package = $this->seedPackage();
        Client::query()->where('telegram_user_id', 'wa:963922222222')->update(['phone' => null]);
        $this->fakeAgent([
            'reply' => 'تمام، هلق بفتحلك الباقة.',
            'handoff' => false,
            'handoff_reason' => '',
            'action' => [
                'type' => 'create_package_request',
                'package' => (string) $package->id,
                'period' => 'monthly',
                'title' => '',
                'description' => 'بدنا نبلش الشهر الجاي',
                'request' => '',
                'reason' => '',
            ],
        ]);

        $this->send('بدي احكي مع موظف', 'pkg1');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && str_contains((string) $sent['packages'], 'id:'.$package->id));
        $request = $this->client()->requests()->latest('id')->firstOrFail();
        $this->assertSame($package->id, $request->pricing_package_id);
        $this->assertSame('monthly', $request->billing_period);
        $this->assertSentToClient('فتحتلك الطلب #'.ResolveServiceRequest::displayNumber($request));
        $this->assertSentToClient('خبرني برقم موبايلك');
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));

        $this->send('+963944111222', 'pkg2');

        $this->assertSentToClient('سجلت رقمك');
        $this->assertSame('+963944111222', $this->client()->fresh()->phone);
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));
    }

    public function test_a_short_manual_brief_is_refused_until_the_notes_are_complete(): void
    {
        $this->fakeAgent([
            'reply' => 'تمام.',
            'handoff' => false,
            'handoff_reason' => '',
            'action' => [
                'type' => 'create_request',
                'title' => 'تصميم',
                'description' => 'بدي لوغو',
                'request' => '',
                'reason' => '',
            ],
        ]);

        $this->send('بدي احكي مع موظف', 'short1');

        $this->assertSame(0, $this->client()->requests()->count());
        $this->assertSentToClient('الملاحظات لسا ناقصة');
    }

    public function test_a_cancel_waits_for_management_then_cancels_and_relays_the_note(): void
    {
        $request = ServiceRequest::factory()->create(['client_id' => $this->client()->id, 'title' => 'تصميم لوغو']);
        $ref = ResolveServiceRequest::displayNumber($request);
        $this->fakeAgent($this->cancelOutput($ref));

        $this->send('بدي احكي مع موظف', 'x1');

        $this->assertSentToClient("بعتت طلب إلغاء الطلب #{$ref} للإدارة");
        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        $token = $this->approvalToken('cxok');

        $this->sendButton(self::MANAGEMENT, 'cxok:'.$token, 'x2');

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSentToClient("الإدارة وافقت على إلغاء الطلب #{$ref}، وانلغى.");
        $this->assertSentTo(self::MANAGEMENT, "انلغى الطلب #{$ref}");

        $this->sendFrom(self::MANAGEMENT, 'العربون رح يرجعلك خلال يومين', 'x3');

        $this->assertSentToClient('ملاحظة من الإدارة بخصوص الطلب #'.$ref);
        $this->assertSentToClient('العربون رح يرجعلك خلال يومين');

        $this->sendButton(self::MANAGEMENT, 'cxok:'.$token, 'x4');
        $this->assertSentTo(self::MANAGEMENT, 'انحسم من قبل');
    }

    public function test_management_can_refuse_a_cancel(): void
    {
        $request = ServiceRequest::factory()->create(['client_id' => $this->client()->id]);
        $ref = ResolveServiceRequest::displayNumber($request);
        $this->fakeAgent($this->cancelOutput($ref));
        $this->send('بدي احكي مع موظف', 'r1');

        $this->sendButton(self::MANAGEMENT, 'cxno:'.$this->approvalToken('cxno'), 'r2');

        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        $this->assertSentToClient("الإدارة ما وافقت على إلغاء الطلب #{$ref}");
    }

    public function test_a_paid_request_goes_to_support_instead_of_management(): void
    {
        $request = ServiceRequest::factory()->create([
            'client_id' => $this->client()->id,
            'status' => RequestStatus::InProgress,
            'paid_at' => now(),
        ]);
        $ref = ResolveServiceRequest::displayNumber($request);
        $this->fakeAgent($this->cancelOutput($ref));

        $this->send('بدي احكي مع موظف', 'p1');

        $this->assertSentToClient("الطلب #{$ref} مدفوع");
        $this->assertSentTo('963944000999', 'عميل بدو يلغي طلب مدفوع');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === self::MANAGEMENT
            && ($sent['kind'] ?? '') === 'list');
    }

    public function test_the_person_phrases(): void
    {
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('بدي احكي مع موظف'));
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('حولني على خدمة العملاء'));
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('I want a real person'));
        $this->assertFalse(WhatsAppSupportAgent::wantsHuman('الموظف بعتلي الملف، شكراً'));
        $this->assertFalse(WhatsAppSupportAgent::wantsHuman('بدي باقة سوشيال ميديا'));
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function fakeAgent(array $output): void
    {
        Http::fake([
            self::AGENT => Http::response(['output' => $output], 200),
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
        ]);
    }

    private function send(string $text, string $id): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'message_id' => $id,
                'text' => $text,
            ])
            ->assertOk();
    }

    private function assertSentToClient(string $fragment): void
    {
        $this->assertSentTo('963922222222', $fragment);
    }

    private function assertSentTo(string $to, string $fragment): void
    {
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === $to
            && str_contains((string) ($sent['text'] ?? ''), $fragment));
    }

    private function client(): Client
    {
        return Client::query()->where('telegram_user_id', 'wa:963922222222')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelOutput(string $ref): array
    {
        return [
            'reply' => 'تمام، رح أبعت طلب الإلغاء للإدارة.',
            'handoff' => false,
            'handoff_reason' => '',
            'action' => ['type' => 'cancel_request', 'title' => '', 'description' => '', 'request' => '#'.$ref, 'reason' => 'ما عاد بدي ياه'],
        ];
    }

    private function approvalToken(string $prefix): string
    {
        $token = '';
        Http::assertSent(function (Request $sent) use ($prefix, &$token): bool {
            if ($sent->url() !== 'http://wa-web.test/send' || $sent['to'] !== self::MANAGEMENT || ($sent['kind'] ?? '') !== 'list') {
                return false;
            }
            foreach ((array) $sent['buttons'] as $button) {
                if (str_starts_with((string) ($button['id'] ?? ''), $prefix.':')) {
                    $token = substr((string) $button['id'], strlen($prefix) + 1);
                }
            }

            return $token !== '' && str_contains((string) $sent['text'], 'طلب إلغاء من عميل');
        });

        return $token;
    }

    private function sendFrom(string $phone, string $text, string $id): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', ['phone' => $phone, 'message_id' => $id, 'text' => $text])
            ->assertOk();
    }

    private function sendButton(string $phone, string $buttonId, string $id): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', ['phone' => $phone, 'message_id' => $id, 'button_id' => $buttonId])
            ->assertOk();
    }

    private function seedPackage(): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'retainers-agent',
            'name_en' => 'Retainers',
            'name_ar' => 'اشتراكات',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'monthly-agent',
            'name_en' => 'Monthly',
            'name_ar' => 'شهري',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'startup-agent',
            'name_en' => 'Startup',
            'name_ar' => 'Startup',
            'subtitle_en' => 'Small',
            'subtitle_ar' => 'صغير',
            'prices' => ['monthly' => 399],
            'features' => [['ar' => '12 بوست', 'en' => '12 posts']],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
