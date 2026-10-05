<?php

namespace Tests\Feature;

use App\Actions\IssueInvoice;
use App\Actions\SendQuotation;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Support\CorrespondenceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CorrespondenceDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_and_invoice_files_use_the_letter_when_odoo_is_down(): void
    {
        config(['services.odoo.enabled' => false]);
        Storage::fake('local');

        $client = Client::factory()->create([
            'name' => 'ليان',
            'company_name' => 'مطعم الشام',
            'phone' => '0947000000',
            'email' => 'layan@example.com',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
            'title' => 'هوية المطعم',
        ]);

        $html = view('correspondence.letter', [
            'kicker' => 'عرض سعر',
            'number' => $request->number,
            'date' => '2026-10-03',
            'clientName' => 'ليان',
            'company' => 'مطعم الشام',
            'phone' => '0947000000',
            'email' => 'layan@example.com',
            'subject' => 'هوية المطعم',
            'rows' => [['label' => 'شعار', 'value' => '400.00 USD']],
            'notes' => 'يشمل دليل الاستخدام',
            'total' => '400.00 USD',
            'aside' => null,
        ])->render();
        $this->assertStringContainsString('دار الإبداع', $html);
        $this->assertStringContainsString('مطعم الشام', $html);
        $this->assertStringContainsString('هوية المطعم', $html);
        $this->assertStringContainsString('400.00 USD', $html);

        app(SendQuotation::class)->handle($request, 400, 'يشمل دليل الاستخدام');

        $quotation = Quotation::query()->first();
        $this->assertNotNull($quotation);
        $this->assertSame("quotations/{$request->number}-v1-local.pdf", $quotation->pdf_path);
        $quotationPdf = Storage::disk('local')->get($quotation->pdf_path);
        $this->assertStringStartsWith('%PDF', $quotationPdf);
        $this->assertStringContainsString('IBMPlexSansArabic', $quotationPdf);

        $request->forceFill([
            'status' => RequestStatus::PaymentConfirmed,
            'quotation_amount' => 400,
            'amount_total' => 400,
            'amount_paid' => 400,
            'amount_remaining' => 0,
        ])->save();

        $invoice = app(IssueInvoice::class)->handle($request->fresh() ?? $request, 400, 'full', false);
        $this->assertNotNull($invoice->pdf_path);
        $this->assertStringEndsWith('-local.pdf', (string) $invoice->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get((string) $invoice->pdf_path));
        $this->assertNull($invoice->odoo_invoice_id);
    }

    public function test_letter_writer_stores_the_quotation_on_the_template(): void
    {
        Storage::fake('local');
        $request = ServiceRequest::factory()->create([
            'title' => 'حملة رمضان',
            'status' => RequestStatus::Submitted,
        ]);

        $path = app(CorrespondenceDocument::class)->quotation(
            $request,
            250,
            null,
            [['title' => 'تصميم', 'amount' => 250, 'units' => 1]],
            'quotations/sample-local.pdf',
        );

        $this->assertSame('quotations/sample-local.pdf', $path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($path));
    }
}
