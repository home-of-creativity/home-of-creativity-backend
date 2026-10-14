<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Submitted = 'submitted';
    case QuotationSent = 'quotation_sent';
    case QuotationRejected = 'quotation_rejected';
    /**
     * The client approved the quotation and already has the payment steps; an admin has not
     * approved it yet. Staff only: the client sees awaiting payment (forClient()).
     */
    case AwaitingAdminApproval = 'awaiting_admin_approval';
    case AwaitingPayment = 'awaiting_payment';
    case PaymentConfirmed = 'payment_confirmed';
    case InProgress = 'in_progress';
    case ReadyForReview = 'ready_for_review';
    case RevisionRequested = 'revision_requested';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // A dashboard invoice skips the quotation: the request waits for payment.
            self::Submitted => [self::QuotationSent, self::AwaitingPayment, self::Cancelled],
            self::QuotationSent => [self::QuotationRejected, self::AwaitingAdminApproval, self::AwaitingPayment, self::Cancelled],
            self::QuotationRejected => [self::QuotationSent, self::Cancelled],
            self::AwaitingAdminApproval => [self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::PaymentConfirmed, self::Cancelled],
            self::PaymentConfirmed => [self::InProgress, self::RevisionRequested, self::Cancelled],
            self::InProgress => [self::ReadyForReview, self::RevisionRequested, self::Cancelled],
            self::ReadyForReview => [self::Completed, self::RevisionRequested, self::Cancelled],
            self::RevisionRequested => [self::InProgress, self::ReadyForReview, self::Cancelled],
            self::Completed => [self::InProgress],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** The statuses where the client is asked to pay (the admin's approval is not the client's business). */
    public static function awaitingPayment(): array
    {
        return [self::AwaitingAdminApproval, self::AwaitingPayment];
    }

    public function isAwaitingPayment(): bool
    {
        return in_array($this, self::awaitingPayment(), true);
    }

    /** The status the client is shown: the admin approval step reads as awaiting payment. */
    public function forClient(): self
    {
        return $this === self::AwaitingAdminApproval ? self::AwaitingPayment : $this;
    }

    public function allowsClientEdit(): bool
    {
        return $this === self::Submitted;
    }

    public function allowsClientRevision(): bool
    {
        return in_array($this, [
            self::InProgress,
            self::ReadyForReview,
            self::RevisionRequested,
        ], true);
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Submitted => 'تم الاستلام',
            self::QuotationSent => 'عرض سعر مرسل',
            self::QuotationRejected => 'عرض مرفوض',
            self::AwaitingAdminApproval => 'بانتظار اعتماد الإدارة',
            self::AwaitingPayment => 'بانتظار الدفع',
            self::PaymentConfirmed => 'تم تأكيد الدفع',
            self::InProgress => 'قيد التنفيذ',
            self::ReadyForReview => 'بانتظار المراجعة',
            self::RevisionRequested => 'مطلوب تعديل',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغى',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Submitted => 'Received',
            self::QuotationSent => 'Quotation sent',
            self::QuotationRejected => 'Quotation rejected',
            self::AwaitingAdminApproval => 'Awaiting admin approval',
            self::AwaitingPayment => 'Awaiting payment',
            self::PaymentConfirmed => 'Payment confirmed',
            self::InProgress => 'In progress',
            self::ReadyForReview => 'Ready for review',
            self::RevisionRequested => 'Revision requested',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
