<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Models\PhpMyAdminSsoToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PmaSsoApiController extends ApiController
{
    public function admin(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403);
        abort_unless($user->tokenCan('pma:admin') || $user->tokenCan('*'), 403);

        $pmaBase = rtrim((string) config('services.phpmyadmin.base_url', ''), '/');
        abort_unless($pmaBase !== '', 500, 'PHPMYADMIN_URL ayari bulunamadi.');

        $mysqlUser = config('services.phpmyadmin.admin_user', 'pma_admin');
        $mysqlPass = config('services.phpmyadmin.admin_pass', '');

        $token = $this->mintToken($request, (string) $mysqlUser, (string) $mysqlPass);
        $expiresAt = now()->addSeconds((int) config('services.phpmyadmin.token_ttl_seconds', 120));

        return response()->json([
            'data' => [
                'redirect_url' => $pmaBase.'/signon.php?'.http_build_query(['token' => $token]),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    public function database(Request $request, Domain $domain, ManagedDatabase $database): JsonResponse
    {
        $this->authorize('manageDb', $domain);

        abort_unless($database->domain_id === $domain->id, 404);

        $pmaBase = rtrim((string) config('services.phpmyadmin.base_url', ''), '/');
        abort_unless($pmaBase !== '', 500, 'PHPMYADMIN_URL ayari bulunamadi.');

        $dbUser = ManagedDatabaseUser::query()
            ->select(['db_user', 'db_password_encrypted'])
            ->where('managed_database_id', $database->id)
            ->latest('id')
            ->first();

        abort_unless(
            $dbUser && filled($dbUser->db_user) && filled($dbUser->db_password_encrypted),
            422,
            'Veritabani icin kullanilabilir kullanici bulunamadi.'
        );

        $token = $this->mintToken($request, (string) $dbUser->db_user, (string) $dbUser->db_password_encrypted);
        $expiresAt = now()->addSeconds((int) config('services.phpmyadmin.token_ttl_seconds', 120));
        $query = ['token' => $token, 'db' => $database->db_name];

        return response()->json([
            'data' => [
                'redirect_url' => $pmaBase.'/signon.php?'.http_build_query($query),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function mintToken(Request $request, string $mysqlUser, string $mysqlPass): string
    {
        $token = hash('sha256', random_bytes(32));
        $clientIp = $request->header('CF-Connecting-IP', $request->ip());

        PhpMyAdminSsoToken::create([
            'token' => $token,
            'mysql_user' => $mysqlUser,
            'mysql_pass' => $mysqlPass,
            'mysql_host' => config('services.phpmyadmin.mysql_host', 'mysql'),
            'mysql_port' => (int) config('services.phpmyadmin.mysql_port', 3306),
            'client_ip' => $clientIp,
            'expires_at' => now()->addSeconds((int) config('services.phpmyadmin.token_ttl_seconds', 120)),
        ]);

        return $token;
    }
}
