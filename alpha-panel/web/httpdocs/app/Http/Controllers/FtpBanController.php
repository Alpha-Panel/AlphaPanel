<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FtpBanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FtpBanController extends Controller
{
    public function index(Request $request, FtpBanService $ftpBan): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return Inertia::render('Security/FtpBans', [
            'bans' => ['hosts' => $this->mapBansForFrontend($ftpBan->getActiveBans())],
            'whitelist' => $ftpBan->getWhitelist(),
        ]);
    }

    public function data(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json([
            'bans' => ['hosts' => $this->mapBansForFrontend($ftpBan->getActiveBans())],
            'whitelist' => $ftpBan->getWhitelist(),
        ]);
    }

    public function store(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip' => ['required', 'string', 'ip'],
        ]);

        try {
            $result = $ftpBan->banHost($validated['ip']);

            return response()->json([
                'success' => $result->isSuccessful(),
                'output' => $result->output,
                'error' => $result->errorOutput,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip' => ['required', 'string', 'ip'],
        ]);

        $result = $ftpBan->permitHost($validated['ip']);

        return response()->json([
            'success' => $result->isSuccessful(),
            'output' => $result->output,
            'error' => $result->errorOutput,
        ]);
    }

    public function log(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $lines = min(500, max(10, (int) $request->query('lines', 100)));

        $entries = $ftpBan->getBanLog($lines);
        $content = collect($entries)
            ->map(fn (array $e): string => ($e['timestamp'] ? "[{$e['timestamp']}] " : '').$e['message'])
            ->implode("\n");

        return response()->json([
            'content' => $content,
        ]);
    }

    public function whitelistStore(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip' => ['required', 'string', 'ip', 'unique:ftp_ban_whitelist,ip_address'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $ftpBan->addToWhitelist($validated['ip'], $validated['note'] ?? null, $user->id);

        return response()->json([
            'success' => true,
            'entry' => $entry->load('creator'),
        ]);
    }

    public function whitelistDestroy(Request $request, FtpBanService $ftpBan): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'ip' => ['required', 'string', 'ip'],
        ]);

        $ftpBan->removeFromWhitelist($validated['ip']);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Map raw ban data from FtpBanService to the format expected by the Vue frontend.
     *
     * @param  array<int, array{ip: string, since: string|null, rule: string|null}>  $bans
     * @return array<int, array{ip: string, reason: string, added: string|null, expires: string|null}>
     */
    private function mapBansForFrontend(array $bans): array
    {
        return collect($bans)->map(fn (array $ban): array => [
            'ip' => $ban['ip'],
            'reason' => $ban['rule'] ?? 'Manual ban',
            'added' => $ban['since'],
            'expires' => null,
        ])->values()->all();
    }
}
