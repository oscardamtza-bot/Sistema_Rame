<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $connection;
    global $config;

    if (!$connection) {
        $db = $config['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']);
        $connection = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $connection;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Inicia sesión para continuar.'], 401);
    }

    return $user;
}

function require_role(array $roles): array
{
    $user = require_user();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['error' => 'No tienes permisos para realizar esta acción.'], 403);
    }

    return $user;
}

function verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        json_response(['error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'], 419);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function encrypt_secret(string $plainText): string
{
    global $config;
    $key = base64_decode($config['encryption_key'], true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Configura RAME_ENCRYPTION_KEY con una clave base64 válida de 32 bytes.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipherText === false) {
        throw new RuntimeException('No se pudo cifrar la credencial.');
    }

    return base64_encode($nonce . $tag . $cipherText);
}

function decrypt_secret(string $cipherText): string
{
    global $config;
    $key = base64_decode($config['encryption_key'], true);
    $packed = base64_decode($cipherText, true);
    if ($key === false || strlen($key) !== 32 || $packed === false || strlen($packed) < 28) {
        throw new RuntimeException('No se pudo descifrar la credencial. Revisa la clave de cifrado.');
    }

    $nonce = substr($packed, 0, 12);
    $tag = substr($packed, 12, 16);
    $plainText = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plainText === false) {
        throw new RuntimeException('La clave de cifrado no coincide con la usada para guardar esta credencial.');
    }

    return $plainText;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function clean_string(mixed $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}