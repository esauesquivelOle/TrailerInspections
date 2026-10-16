<?php
/**
 * Module settings. Copy this file to config.php and adjust it for the server.
 * config.php is not committed (see .gitignore).
 */
return [
    // Folder where inspection photos are stored. Must be writable by the web server identity.
    // Prefer a folder outside the web root; photos are served through api.php, never directly.
    'photo_dir' => __DIR__ . '/uploads/inspections/',
];
