<?php

namespace App\Enums;

enum SslMethod: string
{
    case CloudflareDns = 'cloudflare_dns';
    case WebrootHttp = 'webroot_http';
    case SelfSigned = 'self_signed';
    case None = 'none';
}
