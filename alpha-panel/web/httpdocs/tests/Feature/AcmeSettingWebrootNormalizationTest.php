<?php

namespace Tests\Feature;

use App\Models\AcmeSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AcmeSettingWebrootNormalizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_webroot_path_strips_well_known_acme_challenge_suffix(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/var/www/html/.well-known/acme-challenge',
        ]);

        $this->assertSame('/var/www/html', $setting->webroot_path);
    }

    public function test_webroot_path_strips_trailing_suffix_with_slash(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/var/www/acme-challenge/.well-known/acme-challenge/',
        ]);

        $this->assertSame('/var/www/acme-challenge', $setting->webroot_path);
    }

    public function test_webroot_path_leaves_clean_path_unchanged(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/var/www/acme-challenge',
        ]);

        $this->assertSame('/var/www/acme-challenge', $setting->webroot_path);
    }

    public function test_webroot_path_trims_trailing_slash(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/var/www/acme-challenge/',
        ]);

        $this->assertSame('/var/www/acme-challenge', $setting->webroot_path);
    }

    public function test_webroot_path_falls_back_to_default_when_empty(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '',
        ]);

        $this->assertSame('/var/www/acme-challenge', $setting->webroot_path);
    }

    public function test_webroot_path_falls_back_to_default_when_only_suffix(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/.well-known/acme-challenge',
        ]);

        $this->assertSame('/var/www/acme-challenge', $setting->webroot_path);
    }

    public function test_webroot_path_persists_normalized_value_to_database(): void
    {
        $setting = AcmeSetting::create([
            'webroot_path' => '/var/www/html/.well-known/acme-challenge',
        ]);

        $reloaded = AcmeSetting::find($setting->id);

        $this->assertSame('/var/www/html', $reloaded->webroot_path);
    }
}
