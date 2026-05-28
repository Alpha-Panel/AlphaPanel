<?php

namespace App\Console\Commands;

use App\Services\Terminal\TerminalProxyServer;
use Illuminate\Console\Command;

class TerminalServeCommand extends Command
{
    protected $signature = 'terminal:serve {--port=2999 : Port to listen on}';

    protected $description = 'Start the terminal WebSocket proxy server (run persistently via supervisor)';

    public function handle(TerminalProxyServer $server): int
    {
        $port = (int) $this->option('port');

        $server->setInfoLogger(function (string $message): void {
            $this->info($message);
        });

        return $server->run($port);
    }
}
