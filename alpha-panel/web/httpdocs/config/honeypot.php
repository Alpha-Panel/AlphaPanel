<?php

return [

    /*
     * Enable/disable the honeypot protection.
     * This value is dynamically overridden in AppServiceProvider
     * based on SecuritySetting::instance()->honeypot_enabled.
     */
    'enabled' => true,

    /*
     * Name of the hidden form field used as the honeypot trap.
     * This should be a field name that looks like a real field
     * to trick bots into filling it out.
     */
    'name_field_name' => 'my_name',

    /*
     * Name of the form field that stores an encrypted timestamp
     * used for the speed check.
     */
    'valid_from_field_name' => 'my_time',

    /*
     * Minimum time in seconds after the page was loaded before
     * a form submission is considered valid. Bots typically
     * submit forms faster than humans.
     */
    'amount_of_seconds' => 2,

    /*
     * The response the package sends when it detects spam.
     * By default it sends a blank 200 response.
     */
    'respond_to_spam_with' => null,

    /*
     * When this is activated the package will log spam attempts.
     */
    'log_spam_to_log' => true,

];
