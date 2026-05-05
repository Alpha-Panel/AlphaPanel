<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMysqlConfigRawRequest;
use App\Http\Requests\UpdateMysqlConfigRequest;
use App\Services\MysqlConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MysqlConfigController extends Controller
{
    public function __construct(private MysqlConfigService $service) {}

    public function index(): Response
    {
        $fileContents = $this->service->loadAllFiles();
        $parsedValues = [];
        foreach ($fileContents as $file => $content) {
            $parsedValues[$file] = $this->service->parseFile($content);
        }

        return Inertia::render('Settings/MysqlConfig/Index', [
            'schema' => $this->service->schemaByFile(),
            'fileContents' => $fileContents,
            'parsedValues' => $parsedValues,
            'binlogDisabled' => $this->service->isBinlogDisabled(),
            'restartRequired' => session('mysql_restart_required', false),
        ]);
    }

    public function update(UpdateMysqlConfigRequest $request, string $file): RedirectResponse
    {
        $allowedFiles = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        if (! in_array($file, $allowedFiles, true)) {
            abort(400, 'Invalid config file.');
        }

        try {
            $result = $this->service->saveStructured($file, $request->validated('values', []));
        } catch (\Throwable $e) {
            return back()->with('error', __('mysql_config.save_failed').': '.$e->getMessage());
        }

        if ($result->restartRequired) {
            session(['mysql_restart_required' => true]);
        }

        $message = $result->setGlobalErrors
            ? __('mysql_config.saved_with_warnings', ['vars' => implode(', ', $result->setGlobalErrors)])
            : __('mysql_config.saved');

        return back()->with('success', $message);
    }

    public function updateRaw(UpdateMysqlConfigRawRequest $request, string $file): RedirectResponse
    {
        $allowedFiles = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        if (! in_array($file, $allowedFiles, true)) {
            abort(400, 'Invalid config file.');
        }

        try {
            $this->service->saveRaw($file, $request->validated('content'));
        } catch (\Throwable $e) {
            return back()->with('error', __('mysql_config.save_failed').': '.$e->getMessage());
        }

        session(['mysql_restart_required' => true]);

        return back()->with('success', __('mysql_config.saved'));
    }

    public function restart(): RedirectResponse
    {
        try {
            $this->service->restart();
            session()->forget('mysql_restart_required');
        } catch (\Throwable $e) {
            return back()->with('error', __('mysql_config.restart_failed').': '.$e->getMessage());
        }

        return back()->with('success', __('mysql_config.restart_initiated'));
    }

    public function purgeBinlogs(Request $request): RedirectResponse
    {
        $days = (int) $request->input('days', 7);
        $days = max(1, min(365, $days));

        try {
            $this->service->purgeBinaryLogs($days);
        } catch (\Throwable $e) {
            return back()->with('error', __('mysql_config.purge_failed').': '.$e->getMessage());
        }

        return back()->with('success', __('mysql_config.purge_success', ['days' => $days]));
    }
}
