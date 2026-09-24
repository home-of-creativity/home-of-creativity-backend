<?php

namespace Tests\Feature;

use App\Mail\ContactInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_support_message_goes_to_support_and_copies_info(): void
    {
        Mail::fake();
        config(['mail.mailers.contact.password' => 'secret']);

        $this->postJson('/api/contact/messages', [
            'name' => 'ليلى',
            'email' => 'layla@example.com',
            'phone' => '0999000000',
            'interest' => 'support',
            'interest_label' => 'مشكلة تقنية أو دعم',
            'message' => 'الموقع لا يفتح',
            'locale' => 'ar',
        ])->assertOk()->assertJsonPath('data.to', 'support');

        Mail::assertSent(ContactInquiry::class, function (ContactInquiry $mail): bool {
            return $mail->toAddress === 'support@hoc.agency'
                && $mail->ccAddress === 'info@hoc.agency'
                && $mail->hasReplyTo('layla@example.com');
        });
    }

    public function test_any_other_interest_goes_to_sales(): void
    {
        Mail::fake();
        config(['mail.mailers.contact.password' => 'secret']);

        $this->postJson('/api/contact/messages', [
            'name' => 'Omar',
            'email' => 'omar@example.com',
            'interest' => 'marketing',
            'interest_label' => 'التسويق',
            'message' => 'أريد باقة',
        ])->assertOk()->assertJsonPath('data.to', 'sales');

        Mail::assertSent(ContactInquiry::class, function (ContactInquiry $mail): bool {
            return $mail->toAddress === 'sales@hoc.agency' && $mail->ccAddress === 'info@hoc.agency';
        });
    }

    public function test_send_is_refused_when_the_mailbox_password_is_empty(): void
    {
        config(['mail.mailers.contact.password' => '']);

        $this->postJson('/api/contact/messages', [
            'name' => 'Omar',
            'email' => 'omar@example.com',
            'message' => 'مرحبا',
        ])->assertStatus(503)->assertJsonPath('message', 'mail_not_configured');
    }
}
