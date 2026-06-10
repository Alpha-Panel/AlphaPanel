<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

// Cookie'nin / altında görünmesi, reverse proxy senaryolarında daha güvenli.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// phpMyAdmin signon session adı config ile aynı olmalı
session_name('PMA_SSO');
session_start();

$token = trim((string)($_GET['token'] ?? ''));
$pmaUrl = getenv('PMA_URL') ?: '/index.php?server=2';
$panelDomain = getenv('PANEL_DOMAIN');
$bootstrapUrl = getenv('PMA_SIGNON_BOOTSTRAP_URL') ?: "https://{$panelDomain}:8443/pma/admin/sso";

if ($token === '') {
    // Token yoksa her zaman panel bootstrap endpoint'inden yeni token al.
    $bootstrapAttempts = (int)($_SESSION['PMA_SSO_BOOTSTRAP_ATTEMPTS'] ?? 0) + 1;
    $_SESSION['PMA_SSO_BOOTSTRAP_ATTEMPTS'] = $bootstrapAttempts;
    unset(
        $_SESSION['PMA_single_signon_user'],
        $_SESSION['PMA_single_signon_password'],
        $_SESSION['PMA_single_signon_host'],
        $_SESSION['PMA_single_signon_port']
    );

    if ($bootstrapAttempts > 5) {
        http_response_code(500);
        exit('SSO bootstrap loop detected. Check PMA_SIGNON_BOOTSTRAP_URL and panel auth state.');
    }

    error_log('[phpmyadmin-sso] missing token, redirect bootstrap attempt='.$bootstrapAttempts);
    if ($bootstrapUrl !== '') {
        session_write_close();
        header('Location: '.$bootstrapUrl);
        exit;
    }

    http_response_code(403);
    exit('Missing token');
}

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(403);
    exit('Invalid token format');
}

$panelDbHost = getenv('PANEL_DB_HOST') ?: '';
$panelDbFallbackHost = getenv('PANEL_DB_FALLBACK_HOST') ?: '';
$panelDbName = getenv('PANEL_DB_NAME') ?: 'alphapanel';
$panelDbUser = getenv('PANEL_DB_USER') ?: 'alphapanel';
$panelDbPass = getenv('PANEL_DB_PASS') ?: '';
$panelDbPort = (int)(getenv('PANEL_DB_PORT') ?: 3306);
$enforceIp = (getenv('PMA_SSO_ENFORCE_IP') ?: '0') === '1';
$requestIp = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

$hosts = array_values(array_filter(array_unique([
    $panelDbHost,
    $panelDbFallbackHost,
    'mysql',
])));

// Token lookup uses ONLY the panel DB user. That account owns the panel database
// and therefore can read phpmyadmin_sso_tokens. The previous MySQL root fallback
// is removed: handing root credentials to the phpMyAdmin container is a privilege
// escalation path and is unnecessary for this query. Fail closed if it cannot connect.
$row = null;
$mysqli = null;

foreach ($hosts as $host) {
    $conn = @mysqli_connect($host, $panelDbUser, $panelDbPass, $panelDbName, $panelDbPort);
    if (!$conn) {
        continue;
    }

    mysqli_set_charset($conn, 'utf8mb4');

    $stmt = mysqli_prepare(
        $conn,
        "SELECT mysql_user, mysql_pass, mysql_host, mysql_port, client_ip, expires_at
         FROM phpmyadmin_sso_tokens
         WHERE token = ? LIMIT 1"
    );
    if (!$stmt) {
        mysqli_close($conn);
        continue;
    }

    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $lookup = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$lookup) {
        mysqli_close($conn);
        continue;
    }

    $row = $lookup;
    $mysqli = $conn;
    break;
}


if (!$row || !$mysqli) {
    error_log('[phpmyadmin-sso] token lookup failed');
    http_response_code(403);
    exit('Token not found');
}

// Decrypt mysql_pass — Laravel 'encrypted' cast uses AES-256-CBC with APP_KEY.
//
// Residual risk / follow-up: PANEL_APP_KEY is the panel's APP_KEY shared into this
// container so it can decrypt mysql_pass. Sharing the panel's master key here means
// a compromise of the phpMyAdmin container exposes the key used to decrypt all
// panel-encrypted data. This should move to a dedicated key used only for SSO token
// payloads. Not changed now because it would break the existing SSO flow.
$panelAppKey = getenv('PANEL_APP_KEY') ?: '';
if ($panelAppKey !== '' && ($row['mysql_pass'] ?? '') !== '') {
    $appKeyRaw = $panelAppKey;
    if (str_starts_with($appKeyRaw, 'base64:')) {
        $appKeyRaw = base64_decode(substr($appKeyRaw, 7), true);
    }
    if ($appKeyRaw !== false && strlen($appKeyRaw) >= 16) {
        $payload = json_decode(base64_decode($row['mysql_pass'], true) ?: '', true);
        if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
            $iv = base64_decode($payload['iv'], true);
            // Verify HMAC-SHA256 MAC — Laravel stores mac as hex string (not raw binary)
            $calcMac = hash_hmac('sha256', $payload['iv'].$payload['value'], $appKeyRaw);
            if ($iv !== false && hash_equals($calcMac, $payload['mac'])) {
                // Laravel encrypts with flag 0 (base64 output), so pass value as-is with flag 0
                $decrypted = openssl_decrypt($payload['value'], 'aes-256-cbc', $appKeyRaw, 0, $iv);
                if ($decrypted !== false) {
                    // Laravel wraps the value in serialize(); strip it
                    $unserialized = @unserialize($decrypted);
                    $row['mysql_pass'] = $unserialized !== false ? $unserialized : $decrypted;
                }
            }
        }
    }
}

$expiresAt = strtotime((string)$row['expires_at']);
if ($expiresAt !== false && $expiresAt < time()) {
    $del = mysqli_prepare($mysqli, "DELETE FROM phpmyadmin_sso_tokens WHERE token = ? LIMIT 1");
    if ($del) {
        mysqli_stmt_bind_param($del, 's', $token);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }
    mysqli_close($mysqli);
    http_response_code(403);
    exit('Token expired');
}

if (
    $enforceIp
    && ($row['client_ip'] ?? '') !== ''
    && $requestIp !== ''
    && !hash_equals((string)$row['client_ip'], $requestIp)
) {
    $del = mysqli_prepare($mysqli, "DELETE FROM phpmyadmin_sso_tokens WHERE token = ? LIMIT 1");
    if ($del) {
        mysqli_stmt_bind_param($del, 's', $token);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }

    mysqli_close($mysqli);
    error_log('[phpmyadmin-sso] token rejected: client IP mismatch');
    http_response_code(403);
    exit('Token IP mismatch');
}

// Token kullanıldıktan sonra sil — tek kullanımlık
$del = mysqli_prepare($mysqli, "DELETE FROM phpmyadmin_sso_tokens WHERE token = ? LIMIT 1");
if ($del) {
    mysqli_stmt_bind_param($del, 's', $token);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
}
mysqli_close($mysqli);

$targetHost = ($row['mysql_host'] ?? '') !== '' ? (string)$row['mysql_host'] : 'mysql';
$targetPort = (int)($row['mysql_port'] ?? 3306);
$pmaHost = getenv('PMA_HOST') ?: '';
$authHosts = array_values(array_filter(array_unique([
    $targetHost,
    $pmaHost,
    'mysql',
    $panelDbHost,
])));
$authConn = null;
$authHost = '';

foreach ($authHosts as $host) {
    try {
        $tmpConn = @mysqli_connect(
            $host,
            (string)$row['mysql_user'],
            (string)$row['mysql_pass'],
            '',
            $targetPort
        );
    } catch (Throwable $e) {
        continue;
    }

    if (!$tmpConn) {
        continue;
    }

    $authConn = $tmpConn;
    $authHost = $host;
    break;
}

if (!$authConn) {
    error_log('[phpmyadmin-sso] SSO MySQL auth failed');
    http_response_code(403);
    exit('MySQL auth failed for SSO user');
}
mysqli_close($authConn);

// phpMyAdmin'in beklediği session değişkenleri
$_SESSION['PMA_single_signon_user']     = (string)$row['mysql_user'];
$_SESSION['PMA_single_signon_password'] = (string)$row['mysql_pass'];
$_SESSION['PMA_single_signon_host']     = $authHost !== '' ? $authHost : $targetHost;
$_SESSION['PMA_single_signon_port']     = $targetPort;
$_SESSION['PMA_SSO_BOOTSTRAP_ATTEMPTS'] = 0;
error_log('[phpmyadmin-sso] token accepted');

// Session mutlaka yazılsın
session_write_close();

// phpMyAdmin'e signon server ile git
header('Location: ' . $pmaUrl);
exit;
