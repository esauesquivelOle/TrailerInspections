<?php
/**
 * Loads simulated schedules + orders to test the full Transportation flow.
 * Goes through ScheduleRepository::importRows(), the same path as the Excel import.
 * All data is marked: carriers start with "TEST - " and order IDs with "TEST-".
 *
 *   php seed_test_schedules.php               → today + tomorrow
 *   php seed_test_schedules.php --days=3      → today + next 2 days
 *   php seed_test_schedules.php --date=2026-10-20
 *   php seed_test_schedules.php --clean       → remove TEST schedules/orders
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

date_default_timezone_set('America/New_York');
require_once(__DIR__ . "/../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../includes/clases/conexion.php");
require_once(__DIR__ . "/../src/Repository/ScheduleRepository.php");

$opts = getopt('', ['days::', 'date::', 'clean']);
$cn   = sqlsrv_connect($serverName, $connectionInfo + ['CharacterSet' => 'UTF-8']);
if (!$cn) { fwrite(STDERR, print_r(sqlsrv_errors(), true)); exit(1); }

function q($cn, string $sql, array $p = []) {
    $s = sqlsrv_query($cn, $sql, $p);
    if (!$s) throw new Exception(print_r(sqlsrv_errors(), true));
    return $s;
}

// ─── Cleanup ─────────────────────────────────────────────────────────
if (isset($opts['clean'])) {
    sqlsrv_begin_transaction($cn);
    try {
        $test = "SELECT ScheduleID FROM transp_Schedule WHERE CarrierName LIKE 'TEST - %'";
        q($cn, "DELETE FROM transp_ScheduleOrder WHERE ScheduleID IN ($test)");
        q($cn, "DELETE b FROM transp_BiOrder b WHERE b.ExternalID LIKE 'TEST-%'
                AND NOT EXISTS (SELECT 1 FROM transp_ScheduleOrder so WHERE so.BiOrderID = b.BiOrderID)");
        // Schedules used by test trucks can't be deleted (FK) — cancel them instead
        $s = q($cn, "UPDATE transp_Schedule SET Status = 'Cancelled', UpdatedBy = 'seed', UpdatedAt = GETDATE()
                     WHERE CarrierName LIKE 'TEST - %' AND ScheduleID IN (SELECT ScheduleID FROM transp_Truck WHERE ScheduleID IS NOT NULL)");
        $cancelled = sqlsrv_rows_affected($s);
        $s = q($cn, "DELETE FROM transp_Schedule WHERE CarrierName LIKE 'TEST - %'
                     AND ScheduleID NOT IN (SELECT ScheduleID FROM transp_Truck WHERE ScheduleID IS NOT NULL)");
        $deleted = sqlsrv_rows_affected($s);
        sqlsrv_commit($cn);
        echo "Deleted $deleted TEST schedules; cancelled $cancelled still linked to trucks.\n";
    } catch (Throwable $e) {
        sqlsrv_rollback($cn);
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
    exit(0);
}

// ─── Simulated data ──────────────────────────────────────────────────
// [carrier, truck type, start, end, [[progress, hasPio, note], ...]]
$plan = [
    ['Swift Transportation', '53ft Dry Van', '06:00', '08:00', [[100, true, ''], [100, true, ''], [80, true, '']]],
    ['J.B. Hunt',            '53ft Reefer',  '07:30', '09:30', [[60, true, 'Keep at 34°F'], [40, true, '']]],
    ['Werner Enterprises',   '53ft Dry Van', '09:00', '11:00', [[20, true, ''], [0, false, 'PIO pending from customer'], [0, true, '']]],
    ['Schneider National',   '48ft Flatbed', '11:00', '13:00', [[0, true, 'Pallets on floor'], [0, true, '']]],
    ['Old Dominion',         '28ft Pup',     '13:30', '15:00', [[0, false, ''], [0, false, 'Customer pickup']]],
    ['Landstar',             '53ft Reefer',  '15:00', '17:00', [[0, true, ''], [0, true, ''], [0, true, ''], [0, true, '']]],
];

$dates = [];
if (!empty($opts['date'])) {
    $dates[] = $opts['date'];
} else {
    $days = max(1, (int)($opts['days'] ?? 2));
    for ($i = 0; $i < $days; $i++) $dates[] = date('Y-m-d', strtotime("+$i day"));
}

$rows = [];
$pio  = 400000 + (int)date('z') * 100;
foreach ($dates as $di => $date) {
    $ymd = str_replace('-', '', $date);
    $n   = 0;
    // Later days get fewer schedules so the board is not identical every day
    foreach (array_slice($plan, 0, $di === 0 ? count($plan) : 4) as [$carrier, $type, $start, $end, $orders]) {
        foreach ($orders as [$progress, $hasPio, $note]) {
            $n++;
            $rows[] = [
                'row'       => count($rows) + 2,
                'date'      => $date,
                'carrier'   => "TEST - $carrier",
                'truckType' => $type,
                'start'     => $start,
                'end'       => $end,
                'orderId'   => sprintf('TEST-%s-%03d', $ymd, $n),
                'pio'       => $hasPio ? 'PIO-' . (++$pio) : '',
                'progress'  => (string)$progress,
                'notes'     => $note,
            ];
        }
    }
}

$repo = new ScheduleRepository($cn);
$res  = $repo->importRows($rows, 'seed-test');
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
