<?php
// Profiles with full (admin) access to the Transportation module
define('TRANSP_ADMIN_PROFILES', ['Administrator', 'VIP', 'Support', 'TranspSupervisor']);

// Profiles per functional role
define('TRANSP_LOGISTICA_PROFILES',  ['TranspLogistica',  'TranspSupervisor', 'Administrator', 'VIP', 'Support']);
define('TRANSP_SHIPPING_PROFILES',   ['TranspShipping',   'TranspSupervisor', 'Administrator', 'VIP', 'Support']);
define('TRANSP_SECURITY_PROFILES',   ['TranspSeguridad',  'TranspInspector',  'TranspSupervisor', 'Administrator', 'VIP', 'Support']);
define('TRANSP_INSPECTOR_PROFILES',  ['TranspInspector',  'TranspSeguridad',  'TranspSupervisor', 'Administrator', 'VIP', 'Support']);

function transp_get_profile($cn, string $username): string {
    $sql  = "SELECT TOP 1 [ProfileName] FROM [AppWithProfile]
             WHERE [app_name] = 'Transportation' AND [username] = ?";
    $stmt = sqlsrv_query($cn, $sql, [$username]);
    if (!$stmt) return '';
    $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row['ProfileName'] ?? '';
}

function transp_is_admin(string $profile): bool {
    // No profile assigned = full access until roles are configured
    return $profile === '' || in_array($profile, TRANSP_ADMIN_PROFILES, true);
}

function transp_can_logistica(string $profile): bool {
    return $profile === '' || in_array($profile, TRANSP_LOGISTICA_PROFILES, true);
}

function transp_can_shipping(string $profile): bool {
    return $profile === '' || in_array($profile, TRANSP_SHIPPING_PROFILES, true);
}

function transp_can_security(string $profile): bool {
    return $profile === '' || in_array($profile, TRANSP_SECURITY_PROFILES, true);
}

function transp_can_inspect(string $profile): bool {
    return $profile === '' || in_array($profile, TRANSP_INSPECTOR_PROFILES, true);
}

function transp_build_permissions(string $profile): array {
    return [
        'profile'       => $profile,
        'isAdmin'       => transp_is_admin($profile),
        'canLogistica'  => transp_can_logistica($profile),
        'canShipping'   => transp_can_shipping($profile),
        'canSecurity'   => transp_can_security($profile),
        'canInspect'    => transp_can_inspect($profile),
    ];
}
