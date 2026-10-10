<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PhotographyBooking;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPhotographyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_telegram_books_a_paid_package_session_and_charges_only_after_agreement(): void
    {
        $request = $this->photographyRequest();

        $this->say('بدي موعد تصوير')->assertOk()->assertJson(['handled' => true]);
        $this->assertTelegram('اختار اليوم');
        $this->assertTelegram('#'.$request->number);

        $this->say('شو الأخبار')->assertJson(['handled' => true]);
        $this->say('2')->assertJson(['handled' => true]);
        $this->assertTelegram('اختار الوقت');
        $this->say('1')->assertJson(['handled' => true]);
        $this->assertTelegram('وصلنا طلب الموعد');

        $booking = PhotographyBooking::query()->where('request_id', $request->id)->firstOrFail();
        $this->assertSame('pending_staff', $booking->status);
        $this->assertSame(0, $request->fresh()->photography_sessions_used);

        $this->say('كم جلسة ضل إلي')->assertJson(['handled' => true]);
        $this->assertTelegram('من 2');

        $this->say('مرحبا')->assertJson(['handled' => false]);
    }

    public function test_a_request_without_a_count_takes_the_package_count_when_the_client_books(): void
    {
        $request = $this->photographyRequest();
        $request->forceFill(['photography_sessions' => null, 'photography_period_key' => null])->save();

        $this->say('بدي موعد تصوير')->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(2, $request->fresh()->photography_sessions);
        $this->assertTelegram('اختار اليوم');
        $this->assertTelegram('باقي جلستان من 2');
    }

    private function say(string $text)
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/photography', [
                'telegram_user_id' => '9001',
                'text' => $text,
            ]);
    }

    private function assertTelegram(string $needle): void
    {
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'api.telegram.org/bottest-token/sendMessage')
            && str_contains((string) data_get($sent->data(), 'text'), $needle)
            && (string) data_get($sent->data(), 'chat_id') === '9001');
    }

    private function photographyRequest(): ServiceRequest
    {
        $category = PricingCategory::query()->create([
            'slug' => 'tg-photo-cat',
            'name_en' => 'Strategic',
            'name_ar' => 'حلول',
            'is_published' => true,
            'allows_renewal' => true,
        ]);
        $subcategory = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'tg-photo-sub',
            'name_en' => 'Plans',
            'name_ar' => 'باقات',
            'is_published' => true,
        ]);
        $package = PricingPackage::query()->create([
            'subcategory_id' => $subcategory->id,
            'slug' => 'tg-photo-pkg',
            'name_en' => 'Growth',
            'name_ar' => 'نمو',
            'subtitle_en' => 'Mid',
            'subtitle_ar' => 'متوسطة',
            'prices' => ['monthly' => 899],
            'work_lines' => [['department' => 'photography', 'hours' => 6]],
            'photography_sessions' => 2,
            'is_published' => true,
        ]);
        $client = Client::query()->create([
            'name' => 'Telegram Client',
            'phone' => '+963944000001',
            'company_name' => 'Telegram Co',
            'telegram_user_id' => '9001',
            'locale' => 'ar',
        ]);

        return ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'status' => RequestStatus::InProgress,
            'paid_at' => now(),
            'title' => 'Growth',
        ]);
    }
}
