<?php
// UI language for the Transportation module: English by default, Spanish optional.
// Selected with ?lang=en|es and remembered in a cookie scoped to this module.

define('TRANSP_LANGS', ['en', 'es']);
define('TRANSP_LANG_COOKIE', 'transp_lang');

function transp_lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;

    $req = strtolower(trim($_GET['lang'] ?? ''));
    if (in_array($req, TRANSP_LANGS, true)) {
        $lang = $req;
        if (!headers_sent()) {
            $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
            setcookie(TRANSP_LANG_COOKIE, $lang, [
                'expires'  => time() + 365 * 86400,
                'path'     => $path,
                'samesite' => 'Lax',
            ]);
        }
        return $lang;
    }

    $cookie = $_COOKIE[TRANSP_LANG_COOKIE] ?? '';
    $lang   = in_array($cookie, TRANSP_LANGS, true) ? $cookie : 'en';
    return $lang;
}

function transp_dict(?string $lang = null): array {
    static $cache = [];
    $lang = $lang ?? transp_lang();
    if (!isset($cache[$lang])) {
        $cache[$lang] = require(__DIR__ . "/../Lang/{$lang}.php");
    }
    return $cache[$lang];
}

// Translate a key; {name} placeholders are replaced from $vars. Falls back to English, then to the key.
function transp_t(string $key, array $vars = []): string {
    $text = transp_dict()[$key] ?? transp_dict('en')[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

// Echo a translation escaped for HTML.
function transp_e(string $key, array $vars = []): void {
    echo htmlspecialchars(transp_t($key, $vars), ENT_QUOTES, 'UTF-8');
}
