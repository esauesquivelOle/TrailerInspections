<?php
/**
 * Role-based locks for the Transportation module.
 * Each step of the flow belongs to one role; ADMIN and SUPERVISOR can cover any step.
 * Users with no role can only look (view only).
 */

define('TRANSP_ROLES', ['ADMIN', 'SUPERVISOR', 'SECURITY', 'INSPECTOR', 'LOGISTICS', 'WAREHOUSE', 'SHIPPING']);

// Capability → roles that own it (ADMIN and SUPERVISOR are added automatically)
define('TRANSP_CAPS', [
    'canSecurity'    => ['SECURITY'],                          // Phase 1: check-in, advance to inspection
    'canInspect'     => ['INSPECTOR'],                         // Phase 2: inspections + photos
    'canYard'        => ['SECURITY', 'INSPECTOR'],             // Phase 2: waiting / repair zone
    'canLogistica'   => ['LOGISTICS'],                         // Schedules, orders, import, Phase 4 closeout, cancel
    'canWarehouse'   => ['WAREHOUSE'],                         // Gate blocks, gate + order assignment
    'canShipping'    => ['SHIPPING'],                          // Confirm at gate, validate orders, close loading
    'canReports'     => ['LOGISTICS', 'WAREHOUSE', 'SHIPPING'],
    'canManageRoles' => [],                                    // ADMIN only
]);

// Profiles from AppWithProfile (app 'Transportation') still count, mapped to the new roles
define('TRANSP_LEGACY_PROFILES', [
    'Administrator'    => 'ADMIN',      'VIP'             => 'ADMIN',     'Support'         => 'ADMIN',
    'TranspSupervisor' => 'SUPERVISOR', 'TranspSeguridad' => 'SECURITY',  'TranspInspector' => 'INSPECTOR',
    'TranspLogistica'  => 'LOGISTICS',  'TranspWarehouse' => 'WAREHOUSE', 'TranspShipping'  => 'SHIPPING',
]);

function transp_get_roles($cn, string $username): array {
    $roles = [];
    $stmt  = sqlsrv_query($cn, "SELECT [Role] FROM [transp_UserRole] WHERE [Username] = ?", [$username]);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $roles[] = $r['Role'];
        sqlsrv_free_stmt($stmt);
    }
    $stmt = sqlsrv_query($cn, "SELECT [ProfileName] FROM [AppWithProfile] WHERE [app_name] = 'Transportation' AND [username] = ?", [$username]);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset(TRANSP_LEGACY_PROFILES[$r['ProfileName']])) $roles[] = TRANSP_LEGACY_PROFILES[$r['ProfileName']];
        }
        sqlsrv_free_stmt($stmt);
    }
    // Known roles only, in canonical order
    return array_values(array_intersect(TRANSP_ROLES, array_unique($roles)));
}

function transp_build_permissions(array $roles): array {
    $isAdmin = in_array('ADMIN', $roles, true);
    $super   = $isAdmin || in_array('SUPERVISOR', $roles, true);
    $perms   = ['roles' => $roles, 'isAdmin' => $super, 'isViewer' => !$roles];
    foreach (TRANSP_CAPS as $cap => $owners) {
        $perms[$cap] = $cap === 'canManageRoles' ? $isAdmin : ($super || (bool)array_intersect($roles, $owners));
    }
    return $perms;
}

// For lock messages in the UI: which roles own each capability
function transp_cap_owners(): array {
    $out = [];
    foreach (TRANSP_CAPS as $cap => $owners) $out[$cap] = $owners ?: ['ADMIN'];
    return $out;
}
