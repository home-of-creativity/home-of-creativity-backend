<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReRequestReceipt
{
    public function __construct(
        private TelegramNotifier $telegram,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function handle(ServiceRequest $request, string $reason = 'الوصل غير واضح'): ServiceRequest
    {
        if (! $request->acceptsReceiptUpload() && $request->files()->where('kind', 'payment_receipt')->doesntExist()) {
            throw ValidationException::withMessages([
                'receipt' => 'No receipt to replace on this request.',
            ]);
        }

        $files = $request->files()->where('kind', 'payment_receipt')->get();
        foreach ($files as $file) {
            if (filled($file->path) && Storage::disk('local')->exists($file->path)) {
                Storage::disk('local')->delete($file->path);
            }
            $file->delete();
        }

        $request->forceFill([
            'receipt_reupload_required' => true,
            'receipt_reupload_reason' => $reason,
        ])->save();

        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $chatId = $request->client?->telegram_user_id;
        if ($this->telegram->canReachClient($chatId)) {
            $this->telegram->send(
                (string) $chatId,
                "نحتاج إعادة إرسال وصل الدفع للطلب #{$displayNumber}.\nالسبب: {$reason}\nأرسل صورة أو PDF للوصل.",
            );
        }

        $this->notifyEmployees->handle(
            $request,
            EmployeeProfession::Sales,
            "طُلب إعادة إرسال الوصل للطلب #{$displayNumber}\n{$reason}",
        );

        return $request->fresh(['client', 'files']) ?? $request;
    }
}
