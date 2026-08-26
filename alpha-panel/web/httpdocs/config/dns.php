<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authoritative DNS defaults
    |--------------------------------------------------------------------------
    |
    | Seed values for the `dns_settings` singleton row, which drives the SOA and
    | NS records LocalDnsService writes into PowerDNS for every new zone. They
    | are only applied when that row does not exist yet; afterwards Settings →
    | DNS in the panel is the source of truth.
    |
    | They also act as the last-resort fallback when an administrator clears the
    | nameserver fields, so a zone is never created without NS records.
    |
    | The installer fills these in from the install form. Left at the example.com
    | placeholders, every zone the panel publishes delegates to nameservers you
    | do not control.
    |
    */

    'ns1' => env('DNS_NS1', 'ns1.example.com'),
    'ns2' => env('DNS_NS2', 'ns2.example.com'),

    // SOA RNAME. Written as an email here; LocalDnsService converts the "@" to
    // a "." when it builds the SOA record.
    'soa_admin_email' => env('DNS_SOA_ADMIN', 'admin@example.com'),

    // Default A-record target offered when a template uses the {ip} placeholder.
    'default_ip' => env('DNS_DEFAULT_IP'),

];
