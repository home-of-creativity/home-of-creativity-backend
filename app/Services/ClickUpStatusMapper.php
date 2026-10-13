<?php

namespace App\Services;

use App\Enums\ClickUpSyncEvent;
use App\Enums\ExecutionStatus;
use App\Enums\RequestStatus;

class ClickUpStatusMapper
{
    public function toClientLabel(ExecutionStatus|string|null $status): string
    {
        $value = $status instanceof ExecutionStatus ? $status : ExecutionStatus::tryFrom((string) $status);

        return match ($value) {
            ExecutionStatus::NotStarted => 'لم يبدأ',
            ExecutionStatus::InProgress => 'قيد المعالجة',
            ExecutionStatus::AlmostDone => 'قاربت على الانتهاء',
            ExecutionStatus::Completed => 'مكتملة',
            default => 'لم يبدأ',
        };
    }

    public function fromClickUpStatus(?string $clickUpStatus): ExecutionStatus
    {
        $normalized = strtolower(trim((string) $clickUpStatus));

        return match (true) {
            str_contains($normalized, 'complete') || str_contains($normalized, 'done') || str_contains($normalized, 'closed') => ExecutionStatus::Completed,
            str_contains($normalized, 'review') || str_contains($normalized, 'almost') => ExecutionStatus::AlmostDone,
            str_contains($normalized, 'progress') || str_contains($normalized, 'active') || str_contains($normalized, 'working') => ExecutionStatus::InProgress,
            default => ExecutionStatus::NotStarted,
        };
    }

    public function clickUpStatusFor(ClickUpSyncEvent $event): string
    {
        $key = match ($event) {
            ClickUpSyncEvent::Contacted,
            ClickUpSyncEvent::Quotation,
            ClickUpSyncEvent::Revision => 'progress',
            ClickUpSyncEvent::Delivery => 'review',
            ClickUpSyncEvent::Completed => 'complete',
            ClickUpSyncEvent::Cancelled => 'cancelled',
        };

        return (string) config("services.clickup.statuses.{$key}");
    }

    public function fromRequestStatus(RequestStatus $status): ExecutionStatus
    {
        return match ($status) {
            RequestStatus::Submitted,
            RequestStatus::QuotationSent,
            RequestStatus::QuotationRejected,
            RequestStatus::AwaitingAdminApproval,
            RequestStatus::AwaitingPayment,
            RequestStatus::PaymentConfirmed => ExecutionStatus::NotStarted,
            RequestStatus::InProgress,
            RequestStatus::RevisionRequested => ExecutionStatus::InProgress,
            RequestStatus::ReadyForReview => ExecutionStatus::AlmostDone,
            RequestStatus::Completed => ExecutionStatus::Completed,
            RequestStatus::Cancelled => ExecutionStatus::NotStarted,
        };
    }
}
