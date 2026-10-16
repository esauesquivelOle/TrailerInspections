<?php

class ScheduleRepository
{
    private $cn;
    private string $DB = "[dbo]";   // tables live in the database of the connection
    private string $T_SCH;
    private string $T_ORD;
    private string $T_BIO;

    public function __construct($cn)
    {
        $this->cn    = $cn;
        $this->T_SCH = "{$this->DB}.[transp_Schedule]";
        $this->T_ORD = "{$this->DB}.[transp_ScheduleOrder]";
        $this->T_BIO = "{$this->DB}.[transp_BiOrder]";
    }

    // ─── Orders ──────────────────────────────────────────────────────────────

    public function listOrders(string $date): array
    {
        $sql  = "SELECT * FROM {$this->T_BIO}
                 WHERE [OrderDate] = ? AND [IsActive] = 1
                 ORDER BY [ExternalID]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date]);
        return $this->fetchAll($stmt);
    }

    public function createOrder(array $d, string $by): int
    {
        $sql = "INSERT INTO {$this->T_BIO}
                ([OrderDate],[ExternalID],[OrderType],[TruckType],[PioNumber],[ProgressPct],[Notes],[ImportedBy])
                VALUES (?,?,?,?,?,?,?,?);
                SELECT SCOPE_IDENTITY();";
        $params = [
            $d['OrderDate']   ?? date('Y-m-d'),
            $d['ExternalID']  ?? '',
            $d['OrderType']   ?? null,
            $d['TruckType']   ?? null,
            $d['PioNumber']   ?? null,
            isset($d['ProgressPct']) && $d['ProgressPct'] !== '' ? (float)$d['ProgressPct'] : null,
            $d['Notes']       ?? null,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        if (!$stmt) throw new Exception("Order insert failed.");
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function updateOrderPio(int $biOrderId, string $pioNumber, string $by): void
    {
        $sql = "UPDATE {$this->T_BIO} SET [PioNumber]=? WHERE [BiOrderID]=?";
        sqlsrv_query($this->cn, $sql, [$pioNumber, $biOrderId]);
    }

    // ─── Schedules ───────────────────────────────────────────────────────────

    public function listSchedules(string $date): array
    {
        $sql = "SELECT s.*,
                    (SELECT COUNT(*) FROM {$this->T_ORD} o WHERE o.[ScheduleID] = s.[ScheduleID]) AS [OrderCount],
                    (SELECT COUNT(*) FROM {$this->T_ORD} o WHERE o.[ScheduleID] = s.[ScheduleID] AND o.[HasPio] = 1) AS [OrdersWithPio]
                FROM {$this->T_SCH} s
                WHERE s.[ScheduleDate] = ?
                ORDER BY s.[WindowStart], s.[ScheduleID]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date]);
        return $this->fetchAll($stmt);
    }

    public function getSchedule(int $scheduleId): ?array
    {
        $sql  = "SELECT * FROM {$this->T_SCH} WHERE [ScheduleID] = ?";
        $stmt = sqlsrv_query($this->cn, $sql, [$scheduleId]);
        if (!$stmt) return null;
        return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: null;
    }

    public function createSchedule(array $d, string $by): int
    {
        $sql = "INSERT INTO {$this->T_SCH}
                ([ScheduleDate],[CarrierName],[TruckType],[WindowStart],[WindowEnd],[Notes],[CreatedBy])
                VALUES (?,?,?,?,?,?,?);
                SELECT SCOPE_IDENTITY();";
        $params = [
            $d['ScheduleDate'] ?? date('Y-m-d'),
            $d['CarrierName']  ?? '',
            $d['TruckType']    ?? null,
            $d['WindowStart']  ?? null,
            $d['WindowEnd']    ?? null,
            $d['Notes']        ?? null,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        if (!$stmt) throw new Exception("Schedule insert failed.");
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function findActiveSchedule(string $date, string $carrier, ?string $start, ?string $end): int
    {
        $sql  = "SELECT TOP 1 [ScheduleID] FROM {$this->T_SCH}
                 WHERE [ScheduleDate] = ? AND [CarrierName] = ? AND [Status] = 'Active'
                   AND ISNULL([WindowStart],'') = ? AND ISNULL([WindowEnd],'') = ?
                 ORDER BY [ScheduleID]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date, $carrier, (string)$start, (string)$end]);
        if (!$stmt) return 0;
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function updateSchedule(int $scheduleId, array $d, string $by): void
    {
        $sql = "UPDATE {$this->T_SCH}
                SET [CarrierName]=?, [TruckType]=?, [WindowStart]=?, [WindowEnd]=?, [Notes]=?,
                    [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [ScheduleID]=? AND [Status]='Active'";
        sqlsrv_query($this->cn, $sql, [
            $d['CarrierName']  ?? '',
            $d['TruckType']    ?? null,
            $d['WindowStart']  ?? null,
            $d['WindowEnd']    ?? null,
            $d['Notes']        ?? null,
            $by,
            $scheduleId,
        ]);
    }

    public function cancelSchedule(int $scheduleId, string $by): void
    {
        $sql = "UPDATE {$this->T_SCH}
                SET [Status]='Cancelled', [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [ScheduleID]=? AND [Status]='Active'";
        sqlsrv_query($this->cn, $sql, [$by, $scheduleId]);
    }

    // ─── Schedule Orders ─────────────────────────────────────────────────────

    public function listScheduleOrders(int $scheduleId): array
    {
        $sql = "SELECT so.*, b.[ExternalID] AS [BiExternalID], b.[OrderType], b.[TruckType],
                       b.[ProgressPct], b.[Notes] AS [BiNotes]
                FROM {$this->T_ORD} so
                LEFT JOIN {$this->T_BIO} b ON b.[BiOrderID] = so.[BiOrderID]
                WHERE so.[ScheduleID] = ?
                ORDER BY so.[ScheduleOrderID]";
        $stmt = sqlsrv_query($this->cn, $sql, [$scheduleId]);
        return $this->fetchAll($stmt);
    }

    public function addOrderToSchedule(int $scheduleId, array $d, string $by): int
    {
        $hasPio = !empty($d['PioNumber']) ? 1 : 0;
        $sql = "INSERT INTO {$this->T_ORD}
                ([ScheduleID],[BiOrderID],[ExternalOrderID],[PioNumber],[HasPio],[AddedBy])
                VALUES (?,?,?,?,?,?);
                SELECT SCOPE_IDENTITY();";
        $params = [
            $scheduleId,
            !empty($d['BiOrderID']) ? (int)$d['BiOrderID'] : null,
            $d['ExternalOrderID'] ?? null,
            $d['PioNumber']       ?? null,
            $hasPio,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        if (!$stmt) throw new Exception("Schedule order insert failed.");
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    // True when the order ID is already on this schedule (manual add or a re-imported file)
    public function scheduleHasOrder(int $scheduleId, string $externalOrderId): bool
    {
        $sql  = "SELECT TOP 1 1 FROM {$this->T_ORD} so
                 LEFT JOIN {$this->T_BIO} b ON b.[BiOrderID] = so.[BiOrderID]
                 WHERE so.[ScheduleID] = ? AND (so.[ExternalOrderID] = ? OR b.[ExternalID] = ?)";
        $stmt = sqlsrv_query($this->cn, $sql, [$scheduleId, $externalOrderId, $externalOrderId]);
        return $stmt && sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC) !== null;
    }

    // Adds an order to a schedule, keeping its progress/notes in transp_BiOrder
    public function addOrderWithDetails(array $sched, array $d, string $by): int
    {
        $extId = trim($d['ExternalOrderID'] ?? '');
        $biId  = null;
        if ($extId !== '') {
            $biId = $this->createOrder([
                'OrderDate'   => $sched['ScheduleDate'] instanceof DateTime ? $sched['ScheduleDate']->format('Y-m-d') : $sched['ScheduleDate'],
                'ExternalID'  => $extId,
                'TruckType'   => $sched['TruckType'] ?? null,
                'PioNumber'   => ($d['PioNumber'] ?? '') !== '' ? $d['PioNumber'] : null,
                'ProgressPct' => $d['ProgressPct'] ?? null,
                'Notes'       => ($d['Notes'] ?? '') !== '' ? $d['Notes'] : null,
            ], $by);
        }
        return $this->addOrderToSchedule((int)$sched['ScheduleID'], [
            'BiOrderID'       => $biId,
            'ExternalOrderID' => $extId !== '' ? $extId : null,
            'PioNumber'       => ($d['PioNumber'] ?? '') !== '' ? $d['PioNumber'] : null,
        ], $by);
    }

    // ─── Orders loaded on a truck (Warehouse) ───────────────────────────────

    private function orderSelect(): string
    {
        return "SELECT so.[ScheduleOrderID], so.[ScheduleID], so.[TruckID], so.[HasPio], so.[PioNumber],
                       ISNULL(so.[ExternalOrderID], b.[ExternalID]) AS [OrderID],
                       b.[ProgressPct], b.[Notes], so.[LoadAssignedBy], so.[LoadAssignedAt],
                       s.[CarrierName], s.[WindowStart], s.[WindowEnd]
                FROM {$this->T_ORD} so
                JOIN {$this->T_SCH} s ON s.[ScheduleID] = so.[ScheduleID]
                LEFT JOIN {$this->T_BIO} b ON b.[BiOrderID] = so.[BiOrderID]";
    }

    public function listTruckOrders(int $truckId): array
    {
        $stmt = sqlsrv_query($this->cn, $this->orderSelect() . " WHERE so.[TruckID] = ? ORDER BY so.[ScheduleOrderID]", [$truckId]);
        return $this->fetchAll($stmt);
    }

    /**
     * Orders the warehouse can load on this truck: its own schedule's orders first,
     * then orders of other active schedules that day not yet on a truck.
     */
    public function listAssignableOrders(int $truckId, ?int $scheduleId, string $date): array
    {
        $sql = $this->orderSelect() . "
                WHERE (so.[TruckID] IS NULL OR so.[TruckID] = ?)
                  AND s.[Status] = 'Active'
                  AND (so.[ScheduleID] = ? OR s.[ScheduleDate] = ?)
                ORDER BY CASE WHEN so.[ScheduleID] = ? THEN 0 ELSE 1 END, s.[WindowStart], s.[CarrierName], so.[ScheduleOrderID]";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId, (int)$scheduleId, $date, (int)$scheduleId]);
        return $this->fetchAll($stmt);
    }

    /**
     * Sets exactly which orders are loaded on the truck. Only orders with a PIO that are
     * free (or already on this truck) are taken. Returns how many orders ended up on it.
     */
    public function assignOrdersToTruck(int $truckId, array $orderIds, string $by): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($i) => $i > 0)));

        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ok = sqlsrv_query($this->cn, "UPDATE {$this->T_ORD} SET [TruckID]=NULL, [LoadAssignedBy]=NULL, [LoadAssignedAt]=NULL
                                           WHERE [TruckID]=? AND [ScheduleOrderID] NOT IN ($in)", array_merge([$truckId], $ids));
            if (!$ok) throw new Exception("Order release failed.");
            $ok = sqlsrv_query($this->cn, "UPDATE {$this->T_ORD} SET [TruckID]=?, [LoadAssignedBy]=?, [LoadAssignedAt]=GETDATE()
                                           WHERE [ScheduleOrderID] IN ($in) AND [HasPio]=1 AND [TruckID] IS NULL",
                                           array_merge([$truckId, $by], $ids));
            if (!$ok) throw new Exception("Order assignment failed.");
        } else {
            $ok = sqlsrv_query($this->cn, "UPDATE {$this->T_ORD} SET [TruckID]=NULL, [LoadAssignedBy]=NULL, [LoadAssignedAt]=NULL
                                           WHERE [TruckID]=?", [$truckId]);
            if (!$ok) throw new Exception("Order release failed.");
        }

        $stmt = sqlsrv_query($this->cn, "SELECT COUNT(*) FROM {$this->T_ORD} WHERE [TruckID]=?", [$truckId]);
        $row  = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC) : [0];
        return (int)$row[0];
    }

    // ─── Excel / CSV import ──────────────────────────────────────────────────

    // Returns an i18n error key, or null when the row is valid. Normalizes $r in place.
    public static function validateImportRow(array &$r): ?string
    {
        foreach (['date','carrier','truckType','start','end','orderId','pio','progress','notes'] as $k) {
            $r[$k] = trim((string)($r[$k] ?? ''));
        }
        $p = explode('-', $r['date']);
        if (count($p) !== 3 || !checkdate((int)$p[1], (int)$p[2], (int)$p[0])) return 'imp.err.date';
        if ($r['carrier'] === '')                                       return 'imp.err.carrier';
        foreach (['start','end'] as $k) {
            if ($r[$k] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $r[$k])) return 'imp.err.time';
        }
        if ($r['start'] !== '' && $r['end'] !== '' && $r['end'] <= $r['start']) return 'imp.err.window';
        if ($r['progress'] !== '' && (!is_numeric($r['progress']) || $r['progress'] < 0 || $r['progress'] > 100)) return 'imp.err.progress';
        if (mb_strlen($r['carrier']) > 200 || mb_strlen($r['truckType']) > 100 || mb_strlen($r['orderId']) > 100
            || mb_strlen($r['pio']) > 100 || mb_strlen($r['notes']) > 500)  return 'imp.err.length';
        return null;
    }

    /**
     * Imports rows (one per order) grouping them into schedules by date + carrier + window.
     * An active schedule with the same key is reused; an order already on it is skipped.
     * Everything runs in one transaction. Invalid rows are reported and not imported.
     */
    public function importRows(array $rows, string $by): array
    {
        $res = ['schedulesCreated' => 0, 'schedulesReused' => 0, 'ordersAdded' => 0,
                'ordersSkipped' => 0, 'withoutPio' => 0, 'dates' => [], 'errors' => []];

        $valid = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) continue;
            $err = self::validateImportRow($r);
            if ($err) $res['errors'][] = ['row' => (int)($r['row'] ?? $i + 2), 'key' => $err];
            else      $valid[] = $r;
        }
        if (!$valid) return $res;

        if (!sqlsrv_begin_transaction($this->cn)) throw new Exception("Could not start transaction.");
        try {
            $schedules = [];
            foreach ($valid as $r) {
                $key = implode('|', [$r['date'], mb_strtolower($r['carrier']), $r['start'], $r['end']]);
                if (!isset($schedules[$key])) {
                    $id = $this->findActiveSchedule($r['date'], $r['carrier'], $r['start'], $r['end']);
                    if ($id) {
                        $res['schedulesReused']++;
                    } else {
                        $id = $this->createSchedule([
                            'ScheduleDate' => $r['date'],
                            'CarrierName'  => $r['carrier'],
                            'TruckType'    => $r['truckType'] !== '' ? $r['truckType'] : null,
                            'WindowStart'  => $r['start'] !== '' ? $r['start'] : null,
                            'WindowEnd'    => $r['end']   !== '' ? $r['end']   : null,
                        ], $by);
                        $res['schedulesCreated']++;
                    }
                    $schedules[$key] = ['ScheduleID' => $id, 'ScheduleDate' => $r['date'],
                                        'TruckType' => $r['truckType'] !== '' ? $r['truckType'] : null];
                    $res['dates'][$r['date']] = true;
                }
                if ($r['orderId'] === '') continue;   // schedule-only row

                $sched = $schedules[$key];
                if ($this->scheduleHasOrder((int)$sched['ScheduleID'], $r['orderId'])) {
                    $res['ordersSkipped']++;
                    continue;
                }
                $this->addOrderWithDetails($sched, [
                    'ExternalOrderID' => $r['orderId'],
                    'PioNumber'       => $r['pio'],
                    'ProgressPct'     => $r['progress'],
                    'Notes'           => $r['notes'],
                ], $by);
                $res['ordersAdded']++;
                if ($r['pio'] === '') $res['withoutPio']++;
            }
            sqlsrv_commit($this->cn);
        } catch (Throwable $e) {
            sqlsrv_rollback($this->cn);
            throw $e;
        }

        $res['dates'] = array_keys($res['dates']);
        sort($res['dates']);
        return $res;
    }

    public function removeOrderFromSchedule(int $scheduleOrderId): void
    {
        $sql = "DELETE FROM {$this->T_ORD} WHERE [ScheduleOrderID]=?";
        sqlsrv_query($this->cn, $sql, [$scheduleOrderId]);
    }

    public function updateOrderPioInSchedule(int $scheduleOrderId, string $pioNumber, string $by): void
    {
        $hasPio = $pioNumber !== '' ? 1 : 0;
        $sql = "UPDATE {$this->T_ORD} SET [PioNumber]=?, [HasPio]=? WHERE [ScheduleOrderID]=?";
        sqlsrv_query($this->cn, $sql, [$pioNumber ?: null, $hasPio, $scheduleOrderId]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function fetchAll($stmt): array
    {
        $rows = [];
        if (!$stmt) return $rows;
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($row as $k => $v) {
                if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d H:i:s');
            }
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }
}
