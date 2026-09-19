<?php

namespace App\Enums;

enum SocialPostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed, self::Published], true);
    }

    public function canRetryPublish(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed, self::Publishing], true);
    }

    public function isDeletable(): bool
    {
        return true;
    }
}
