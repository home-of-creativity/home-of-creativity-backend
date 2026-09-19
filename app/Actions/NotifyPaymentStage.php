<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Storage;

class NotifyPaymentStage
{
    public function __construct(
        private ResolveWorkPlan $resolveWorkPlan,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function handle(ServiceRequest $request, ?RequestFile $receipt = null, ?string $prefix = null): array
    {
        $request->loadMissing(['client', 'pricingPackage']);
        $plan = $this->resolveWorkPlan->handle($request);
        $ref = ResolveServiceRequest::displayNumber($request);
        $due = number_format($request->expectedDue(), 2);
        $text = $this->message($request, $plan, $ref, $due, $prefix);

        $buttons = [
            ['text' => "✅ تأكيد الدفع {$due} USD", 'callback_data' => 'payok:'.$ref],
        ];

        $attachment = null;
        if ($receipt && filled($receipt->path) && Storage::disk('local')->exists($receipt->path)) {
            $absolute = Storage::disk('local')->path($receipt->path);
            $attachment = [
                'path' => $absolute,
                'mime' => $this->receiptMime($receipt, $absolute),
                'name' => $receipt->original_name,
            ];
        }

        $this->notifyEmployees->handle(
            $request,
            EmployeeProfession::Sales,
            $text,
            $buttons,
            $attachment,
        );

        return $plan;
    }

    /**
     * @param  array{operations?: list<array<string, mixed>>}  $plan
     */
    private function message(ServiceRequest $request, array $plan, string $ref, string $due, ?string $prefix = null): string
    {
        $who = trim(($request->client?->name ?? '').($request->client?->company_name ? ' — '.$request->client->company_name : ''));
        $kind = $request->pricing_package_id ? 'باقة' : 'طلب يدوي';
        $lines = [];
        if (filled($prefix)) {
            $lines[] = $prefix;
            $lines[] = '';
        }
        $lines = array_merge($lines, [
            'مرحلة الدفع',
            "#{$ref} — {$request->title}",
            $who !== '' ? $who : 'عميل تيليجرام',
            "النوع: {$kind}",
            "المبلغ المتوقع: {$due} USD",
            '',
            'خطة العمل (ClickUp):',
        ]);

        $operations = $plan['operations'] ?? [];
        if ($operations === []) {
            $lines[] = 'لم يُحدد إسناد بعد.';
        }

        foreach ($operations as $operation) {
            $dept = self::departmentLabel((string) ($operation['department'] ?? ''));
            $assignee = $operation['employee_name'] ?? 'غير مسند';
            $priority = $operation['priority_label'] ?? 'عادية';
            $hours = (int) ($operation['hours'] ?? 0);
            $lines[] = "• {$dept} — {$assignee} — أولوية {$priority} — {$hours} ساعة";
            if (filled($operation['brief'] ?? null)) {
                $lines[] = '  '.mb_substr((string) $operation['brief'], 0, 180);
            }
        }

        $contact = $request->client?->telegramContactLine();
        if (filled($contact) && ! str_contains($prefix ?? '', (string) $contact)) {
            $lines[] = '';
            $lines[] = $contact;
        }

        return implode("\n", $lines);
    }

    private function receiptMime(RequestFile $receipt, string $absolute): string
    {
        $detected = is_file($absolute) ? (mime_content_type($absolute) ?: '') : '';
        if (str_starts_with($detected, 'image/') || $detected === 'application/pdf') {
            return $detected;
        }

        $extension = strtolower((string) pathinfo((string) $receipt->original_name, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower((string) pathinfo((string) $receipt->path, PATHINFO_EXTENSION));
        }

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => $detected !== '' ? $detected : 'application/octet-stream',
        };
    }

    public static function departmentLabel(string $department): string
    {
        return match ($department) {
            'design' => 'تصميم',
            'content' => 'محتوى',
            'programming' => 'برمجة',
            'photography' => 'تصوير',
            default => $department !== '' ? $department : 'قسم',
        };
    }
}
