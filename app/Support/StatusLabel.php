<?php

namespace App\Support;

class StatusLabel
{
    public static function requestAr(string $status): string
    {
        return match ($status) {
            'submitted' => 'قيد المراجعة',
            'quotation_sent' => 'عرض سعر مرسل',
            'quotation_rejected' => 'عرض السعر مرفوض',
            'awaiting_payment' => 'بانتظار الدفع',
            'payment_confirmed' => 'تم تأكيد الدفع',
            'in_progress' => 'قيد التجهيز',
            'ready_for_review' => 'بانتظار المراجعة',
            'revision_requested' => 'مطلوب تعديل',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
            default => $status,
        };
    }
}
