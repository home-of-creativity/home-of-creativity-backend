<?php

namespace App\Enums;

enum IntegrationEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
