<?php
/**
 * Runs a .sql migration (GO-separated batches) with the CLI account (db_owner).
 *   php run_migration.php ../sql/003_warehouse_gates.sql
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once(__DIR__ . "/../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../includes/clases/conexion.php");

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Usage: php run_migration.php <file.sql>\n"); exit(1); }

sqlsrv_configure("WarningsReturnAsErrors", 0);
$cn = sqlsrv_connect($serverName, $connectionInfo + ['CharacterSet' => 'UTF-8']);
if (!$cn) { fwrite(STDERR, print_r(sqlsrv_errors(), true)); exit(1); }

$batches = preg_split('/^\s*GO\s*$/mi', file_get_contents($file));
foreach ($batches as $i => $sql) {
    $sql = trim($sql);
    if ($sql === '' || preg_match('/^USE\s/i', $sql)) continue;
    $stmt = sqlsrv_query($cn, $sql);
    if (!$stmt) {
        fwrite(STDERR, "Batch $i failed:\n$sql\n" . print_r(sqlsrv_errors(), true));
        exit(1);
    }
    do {
        // PRINT messages come back as warnings
        foreach ((array)sqlsrv_errors(SQLSRV_ERR_WARNINGS) as $w) {
            if (!empty($w['message'])) echo preg_replace('/^\[.*\]/', '', $w['message']), "\n";
        }
    } while (sqlsrv_next_result($stmt));
}
echo "OK\n";
