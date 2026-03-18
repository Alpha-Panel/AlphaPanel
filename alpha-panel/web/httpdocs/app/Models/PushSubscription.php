<?php

namespace App\Models;

use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

class PushSubscription extends BasePushSubscription
{
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'browser_name',
        'browser_version',
        'os_name',
        'device_type',
        'user_agent',
    ];
}
