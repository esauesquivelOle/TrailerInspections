<?php
require_once(__DIR__ . "/../../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../../includes/clases/conexion.php");

session_save_path($CONFIG->path);
session_start();

function require_login_or_redirect(): void {
    if (!isset($_SESSION['user_login_status']) || $_SESSION['user_login_status'] != 1) {
        echo "<script>window.top.location.href='../../../../login.php';</script>";
        exit();
    }
}

function current_username(): string {
    return $_SESSION['user_name'] ?? 'system';
}

function current_user_fullname(): string {
    return trim(($_SESSION['user_firstname'] ?? '') . ' ' . ($_SESSION['user_lastname'] ?? ''));
}
