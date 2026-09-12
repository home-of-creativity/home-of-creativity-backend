<?php

namespace App\Enums;

enum SocialActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Replied = 'replied';
    case AccountConnected = 'account_connected';
    case AccountUpdated = 'account_updated';
    case AccountToggled = 'account_toggled';
}
