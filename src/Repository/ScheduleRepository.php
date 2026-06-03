<?php

class ScheduleRepository
{
    private $cn;
    private string $DB = "[OLEspresso].[dbo]";
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
            isset($d['ProgressPct']) ? (float)$d['ProgressPct'] : 0,
            $d['Notes']       ?? null,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
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
        sqlsrv_next_result($stmt);
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
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
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
