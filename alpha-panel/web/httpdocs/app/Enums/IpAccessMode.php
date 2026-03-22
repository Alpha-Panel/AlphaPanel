<?php

namespace App\Enums;

enum IpAccessMode: string
{
    case None = 'none';
    case Whitelist = 'whitelist';
    case Blacklist = 'blacklist';
}
