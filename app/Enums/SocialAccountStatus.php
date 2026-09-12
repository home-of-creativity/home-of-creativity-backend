<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Error = 'error';
    case Disconnected = 'disconnected';
}
