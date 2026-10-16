<?php
// Module settings: config.php (local, not committed) over config.sample.php (defaults)
function transp_config(string $key) {
    static $cfg = null;
    if ($cfg === null) {
        $root = __DIR__ . '/../..';
        $cfg  = require($root . '/config.sample.php');
        if (is_file($root . '/config.php')) $cfg = array_merge($cfg, require($root . '/config.php'));
    }
    return $cfg[$key] ?? null;
}

function transp_photo_dir(): string {
    return rtrim(str_replace('\\', '/', (string)transp_config('photo_dir')), '/') . '/';
}
