<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate_or_fail(): void {
    $token        = $_POST['csrf_token'] ?? (getallheaders()['X-Csrf-Token'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        json_out(false, "Token de seguridad inválido.", null, 400);
    }
}
