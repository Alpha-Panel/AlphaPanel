<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Acme\AcmeService;
use Illuminate\Console\Command;

class EnsureDefaultCertCommand extends Command
{
    protected $signature = 'panel:ensure-default-cert';

    protected $description = 'Generate the panel default self-signed certificate if missing or near expiry';

    public function handle(AcmeService $acmeService): int
    {
        try {
            $paths = $acmeService->ensurePanelDefaultSelfSigned();

            $this->info('Panel default certificate is ready.');
            $this->line("  cert: {$paths['cert_path']}");
            $this->line("  key:  {$paths['key_path']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to ensure panel default cert: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
