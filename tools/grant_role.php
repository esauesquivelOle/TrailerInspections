<?php
/**
 * Gives a role to a user from the command line (e.g. the first administrator after migration 004).
 *   php grant_role.php <username> ADMIN
 * Roles: ADMIN, SUPERVISOR, SECURITY, INSPECTOR, LOGISTICS, WAREHOUSE, SHIPPING
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once(__DIR__ . "/../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../includes/clases/conexion.php");
require_once(__DIR__ . "/../src/Support/permissions.php");

[$_, $user, $role] = $argv + [null, '', ''];
$role = strtoupper(trim($role));
if ($user === '' || !in_array($role, TRANSP_ROLES, true)) {
    fwrite(STDERR, "Usage: php grant_role.php <username> <" . implode('|', TRANSP_ROLES) . ">\n");
    exit(1);
}

$cn = sqlsrv_connect($serverName, $connectionInfo + ['CharacterSet' => 'UTF-8']);
if (!$cn) { fwrite(STDERR, print_r(sqlsrv_errors(), true)); exit(1); }

$sql = "IF NOT EXISTS (SELECT 1 FROM [dbo].[transp_UserRole] WHERE [Username] = ? AND [Role] = ?)
            INSERT INTO [dbo].[transp_UserRole] ([Username], [Role], [AssignedBy]) VALUES (?, ?, 'cli')";
if (!sqlsrv_query($cn, $sql, [$user, $role, $user, $role])) { fwrite(STDERR, print_r(sqlsrv_errors(), true)); exit(1); }
echo "$user has role $role\n";
