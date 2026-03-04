<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Models\PhpMyAdminSsoToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PmaSsoController extends Controller
{
    public function domain(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageDb', $domain);

        // Domaine bağlı en güncel DB kullanıcısını al.
        $dbUser = ManagedDatabaseUser::query()
            ->select(['managed_database_users.db_user', 'managed_database_users.db_password_encrypted'])
            ->join('managed_databases', 'managed_databases.id', '=', 'managed_database_users.managed_database_id')
            ->where('managed_databases.domain_id', $domain->id)
            ->latest('managed_database_users.id')
            ->first();

        abort_unless(
            $dbUser && filled($dbUser->db_user) && filled($dbUser->db_password_encrypted),
            422,
            'Domain icin kullanilabilir veritabani kullanicisi bulunamadi.'
        );

        $token = $this->createTokenFor(
            $request,
            (string) $dbUser->db_user,
            (string) $dbUser->db_password_encrypted
        );

        return $this->redirectToSignon($token);
    }

    public function admin(Request $request): RedirectResponse
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }
        $mysqlUser = config('services.phpmyadmin.admin_user', 'pma_admin');
        $mysqlPass = config('services.phpmyadmin.admin_pass', '');

        $token = $this->createTokenFor($request, (string) $mysqlUser, (string) $mysqlPass);

        return $this->redirectToSignon($token);
    }

    public function database(Request $request, Domain $domain, ManagedDatabase $database): RedirectResponse
    {
        $this->authorize('manageDb', $domain);

        abort_unless($database->domain_id === $domain->id, 404);

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

        $token = $this->createTokenFor(
            $request,
            (string) $dbUser->db_user,
            (string) $dbUser->db_password_encrypted
        );

        return $this->redirectToSignon($token, (string) $database->db_name);
    }

    private function createTokenFor(Request $request, string $mysqlUser, string $mysqlPass): string
    {
        $token = hash('sha256', random_bytes(32));
        $clientIp = $request->header('CF-Connecting-IP', $request->ip());

        PhpMyAdminSsoToken::query()->create([
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

    private function redirectToSignon(string $token, ?string $database = null): RedirectResponse
    {
        $pmaBase = rtrim((string) config('services.phpmyadmin.base_url', ''), '/');
        abort_unless($pmaBase !== '', 500, 'PHPMYADMIN_URL ayari bulunamadi.');

        $query = ['token' => $token];

        if (filled($database)) {
            $query['db'] = $database;
        }

        return redirect()->away($pmaBase.'/signon.php?'.http_build_query($query));
    }
}
