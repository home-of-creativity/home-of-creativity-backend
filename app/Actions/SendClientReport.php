<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportSend;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends the saved report as a PDF to the client by email, WhatsApp, or both. Every attempt is
 * logged; a delivery marks the client's next report date done and can keep the new contact.
 */
class SendClientReport
{
    public function __construct(
        private ConvertDocxToPdf $convert,
        private DeliverClientDocument $deliver,
        private ReportDeadlines $deadlines,
        private PushClientToOdoo $pushClientToOdoo,
        private PushClientLeadToOdoo $pushClientLeadToOdoo,
    ) {}

    /**
     * @return array{sends: list<ClientReportSend>, delivered: array<string, bool>}
     */
    public function handle(ClientReport $report, ?User $user, ?string $email, ?string $phone, ?string $message, bool $saveContact): array
    {
        $email = filled($email) ? trim((string) $email) : null;
        $phone = filled($phone) ? trim((string) $phone) : null;
        if ($email === null && $phone === null) {
            throw ValidationException::withMessages(['email' => 'اكتب البريد أو رقم واتساب.']);
        }
        if ($phone !== null && ClientPhone::whatsappDigits($phone) === '') {
            throw ValidationException::withMessages(['phone' => 'رقم واتساب غير صالح. اكتبه مع رمز الدولة.']);
        }
        if (! filled($report->document_path) || ! Storage::disk('local')->exists($report->document_path)) {
            throw ValidationException::withMessages(['report' => 'احفظ التقرير أولاً.']);
        }

        $report->loadMissing('client');
        $pdf = $this->convert->handle((string) Storage::disk('local')->get($report->document_path));
        $path = tempnam(sys_get_temp_dir(), 'hoc-report-');
        file_put_contents($path, $pdf);

        try {
            $text = $this->text($report, $message);
            $filename = $this->filename($report);
            $delivered = [];
            $sends = [];
            foreach (array_filter(['email' => $email, 'whatsapp' => $phone]) as $channel => $recipient) {
                $delivered[$channel] = $this->deliver->deliver(
                    $channel === 'email' ? $recipient : null,
                    $channel === 'whatsapp' ? $recipient : null,
                    $channel,
                    $text,
                    $path,
                    $filename,
                    "report {$report->id}",
                );
                $sends[] = $report->sends()->create([
                    'user_id' => $user?->id,
                    'channel' => $channel,
                    'recipient' => mb_substr($recipient, 0, 190),
                    'message' => filled($message) ? trim((string) $message) : null,
                    'delivered' => $delivered[$channel],
                ]);
            }
        } finally {
            @unlink($path);
        }

        if (in_array(true, $delivered, true)) {
            $this->deadlines->completeFor($report, $user);
            if ($saveContact && $report->client !== null) {
                $this->keepContact(
                    $report->client,
                    ($delivered['email'] ?? false) ? $email : null,
                    ($delivered['whatsapp'] ?? false) ? $phone : null,
                );
            }
        }

        return ['sends' => $sends, 'delivered' => $delivered];
    }

    private function text(ClientReport $report, ?string $message): string
    {
        $client = $report->client;
        $name = trim((string) ($client?->name ?? ''));

        return trim(implode("\n\n", array_filter([
            $name !== '' ? "مرحباً {$name}،" : 'مرحباً،',
            filled($message) ? trim((string) $message) : null,
            "مرفق تقرير «{$report->title}».",
            'Home of Creativity، بيت الإبداع',
        ])));
    }

    private function filename(ClientReport $report): string
    {
        $name = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string) $report->title));

        return ($name !== '' ? mb_substr($name, 0, 80) : 'report').'.pdf';
    }

    private function keepContact(Client $client, ?string $email, ?string $phone): void
    {
        $changes = [];
        if ($email !== null && mb_strtolower($email) !== mb_strtolower((string) $client->email)) {
            $changes['email'] = $email;
        }
        if ($phone !== null && ClientPhone::whatsappDigits($phone) !== ClientPhone::whatsappDigits((string) $client->phone)) {
            $changes['phone'] = $phone;
        }
        if ($changes === []) {
            return;
        }
        $client->forceFill($changes)->save();

        if (! $client->readyForOdoo() && ! filled($client->odoo_partner_id) && ! filled($client->odoo_lead_id)) {
            return;
        }
        try {
            $fresh = $this->pushClientToOdoo->handle($client->fresh() ?? $client, writeExisting: true);
            $this->pushClientLeadToOdoo->handle($fresh, writeExisting: filled($fresh->odoo_lead_id), classifyIndustry: false);
        } catch (Throwable $exception) {
            Log::warning('Report contact did not reach Odoo.', ['client' => $client->id, 'error' => $exception->getMessage()]);
        }
    }
}
