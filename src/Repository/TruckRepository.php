<?php

class TruckRepository
{
    private $cn;
    private string $DB = "[dbo]";   // tables live in the database of the connection
    private string $T_TRK;
    private string $T_SCH;
    private string $T_LOG;

    public function __construct($cn)
    {
        $this->cn    = $cn;
        $this->T_TRK = "{$this->DB}.[transp_Truck]";
        $this->T_SCH = "{$this->DB}.[transp_Schedule]";
        $this->T_LOG = "{$this->DB}.[transp_AuditLog]";
    }

    // ─── Dashboard ───────────────────────────────────────────────────────────

    public function listActiveTrucks(string $date): array
    {
        $sql = "SELECT t.*,
                       s.[CarrierName] AS [SchedCarrier],
                       s.[WindowStart], s.[WindowEnd],
                       (SELECT TOP 1 [Result] FROM {$this->DB}.[transp_Inspection]
                        WHERE [TruckID] = t.[TruckID] AND [Result]='APPROVED'
                        ORDER BY [InspectionID] DESC) AS [LastApprovedInspection],
                       (SELECT COUNT(*) FROM {$this->DB}.[transp_Inspection]
                        WHERE [TruckID] = t.[TruckID]) AS [InspectionAttempts],
                       (SELECT TOP 1 g.[GateName] FROM {$this->DB}.[transp_GateAssignment] ga
                        JOIN {$this->DB}.[transp_Gate] g ON g.[GateID] = ga.[GateID]
                        WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1) AS [AssignedGate],
                       (SELECT TOP 1 ga.[ShippingConfirmedAt] FROM {$this->DB}.[transp_GateAssignment] ga
                        WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1) AS [GateConfirmedAt],
                       (SELECT TOP 1 ga.[LoadingClosedAt] FROM {$this->DB}.[transp_GateAssignment] ga
                        WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1) AS [LoadingClosedAt]
                FROM {$this->T_TRK} t
                LEFT JOIN {$this->T_SCH} s ON s.[ScheduleID] = t.[ScheduleID]
                WHERE t.[TruckDate] = ? AND t.[Status] NOT IN ('Completed','Cancelled')
                ORDER BY t.[CreatedAt]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date]);
        return $this->fetchAll($stmt);
    }

    // ─── Warehouse ───────────────────────────────────────────────────────────

    /**
     * Trucks the warehouse works with:
     *  - ready:    Phase 3 (inspection approved) with no active gate — includes earlier days still waiting
     *  - incoming: today's trucks still in check-in / inspection / waiting zone (next in line)
     */
    public function listWarehouseQueue(string $date): array
    {
        $sql = "SELECT t.[TruckID], t.[TruckNumber], t.[TrailerNumber], t.[CarrierName], t.[DriverName],
                       t.[Status], t.[TruckDate], t.[CreatedAt], t.[ScheduleID], t.[WaitingZone],
                       s.[WindowStart], s.[WindowEnd], s.[TruckType],
                       (SELECT MAX([InspectedAt]) FROM {$this->DB}.[transp_Inspection]
                        WHERE [TruckID] = t.[TruckID] AND [Result]='APPROVED') AS [ApprovedAt],
                       (SELECT COUNT(*) FROM {$this->DB}.[transp_ScheduleOrder] so
                        WHERE so.[ScheduleID] = t.[ScheduleID]) AS [ScheduleOrders],
                       (SELECT COUNT(*) FROM {$this->DB}.[transp_ScheduleOrder] so
                        WHERE so.[ScheduleID] = t.[ScheduleID] AND so.[HasPio] = 1 AND so.[TruckID] IS NULL) AS [OrdersReady],
                       (SELECT TOP 1 ga.[LoadingClosedAt] FROM {$this->DB}.[transp_GateAssignment] ga
                        WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1) AS [LoadingClosedAt]
                FROM {$this->T_TRK} t
                LEFT JOIN {$this->T_SCH} s ON s.[ScheduleID] = t.[ScheduleID]
                WHERE (t.[Status] = 'Phase3' AND t.[TruckDate] <= ?
                       AND NOT EXISTS (SELECT 1 FROM {$this->DB}.[transp_GateAssignment] ga
                                       WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1))
                   OR (t.[Status] IN ('Phase1','Phase2','Phase2_Waiting') AND t.[TruckDate] = ?)
                ORDER BY CASE WHEN t.[Status]='Phase3' THEN 0 ELSE 1 END, t.[CreatedAt]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date, $date]);
        return $this->fetchAll($stmt);
    }

    public function listTrucksByDateRange(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $where  = "WHERE t.[TruckDate] BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];

        if (!empty($filters['carrier'])) {
            $where   .= " AND t.[CarrierName] LIKE ?";
            $params[] = '%' . $filters['carrier'] . '%';
        }
        if (!empty($filters['status'])) {
            $where   .= " AND t.[Status] = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['gate'])) {
            $where .= " AND EXISTS (SELECT 1 FROM {$this->DB}.[transp_GateAssignment] ga
                        JOIN {$this->DB}.[transp_Gate] g ON g.[GateID] = ga.[GateID]
                        WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive]=1 AND g.[GateName] = ?)";
            $params[] = $filters['gate'];
        }

        $sql  = "SELECT t.*, s.[WindowStart], s.[WindowEnd],
                        (SELECT TOP 1 [Result] FROM {$this->DB}.[transp_Inspection]
                         WHERE [TruckID] = t.[TruckID] AND [Result]='APPROVED'
                         ORDER BY [InspectionID] DESC) AS [LastApprovedInspection],
                        (SELECT TOP 1 g.[GateName] FROM {$this->DB}.[transp_GateAssignment] ga
                         JOIN {$this->DB}.[transp_Gate] g ON g.[GateID] = ga.[GateID]
                         WHERE ga.[TruckID] = t.[TruckID] AND ga.[IsActive] = 1) AS [AssignedGate]
                 FROM {$this->T_TRK} t
                 LEFT JOIN {$this->T_SCH} s ON s.[ScheduleID] = t.[ScheduleID]
                 {$where}
                 ORDER BY t.[TruckDate] DESC, t.[CreatedAt] DESC";
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        return $this->fetchAll($stmt);
    }

    // ─── Single Truck ─────────────────────────────────────────────────────────

    public function getTruck(int $truckId): ?array
    {
        $sql = "SELECT t.*, s.[CarrierName] AS [SchedCarrier],
                       s.[WindowStart], s.[WindowEnd], s.[TruckType] AS [SchedTruckType]
                FROM {$this->T_TRK} t
                LEFT JOIN {$this->T_SCH} s ON s.[ScheduleID] = t.[ScheduleID]
                WHERE t.[TruckID] = ?";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId]);
        if (!$stmt) return null;
        $rows = $this->fetchAll($stmt);
        return $rows[0] ?? null;
    }

    // ─── Phase 1 — Register truck / driver check-in ──────────────────────────

    public function registerTruck(array $d, string $by): int
    {
        $sql = "INSERT INTO {$this->T_TRK}
                ([TruckDate],[ScheduleID],[TruckNumber],[TrailerNumber],[LicensePlate],[CarrierName],
                 [DriverName],[DriverLicense],[DriverPhone],[ArrivalTime],
                 [Phase1By],[Phase1At],[CurrentPhase],[Status],[Notes],[CreatedBy])
                VALUES (?,?,?,?,?,?,?,?,?,GETDATE(),?,GETDATE(),1,'Phase1',?,?);
                SELECT SCOPE_IDENTITY();";
        $params = [
            $d['TruckDate']     ?? date('Y-m-d'),
            !empty($d['ScheduleID']) ? (int)$d['ScheduleID'] : null,
            $d['TruckNumber']   ?? '',
            $d['TrailerNumber'] ?? null,
            $d['LicensePlate']  ?? null,
            $d['CarrierName']   ?? '',
            $d['DriverName']    ?? null,
            $d['DriverLicense'] ?? null,
            $d['DriverPhone']   ?? null,
            $by,
            $d['Notes']         ?? null,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    // ─── Phase transitions ───────────────────────────────────────────────────

    public function advanceToPhase2(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [CurrentPhase]=2, [Status]='Phase2', [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase1'";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    public function advanceToPhase3(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [CurrentPhase]=3, [Status]='Phase3', [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase2'";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    public function advanceToPhase4(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [CurrentPhase]=4, [Status]='Phase4', [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase3'";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    public function closeTruck(int $truckId, string $notes, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [Status]='Completed', [Phase4Notes]=?, [Phase4By]=?, [Phase4At]=GETDATE(),
                    [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase4'";
        sqlsrv_query($this->cn, $sql, [$notes ?: null, $by, $by, $truckId]);
    }

    // ─── Zona de espera/reparación ───────────────────────────────────────────

    public function sendToWaitingZone(int $truckId, string $zone, string $notes, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [Status]='Phase2_Waiting', [WaitingZone]=?, [WaitingNotes]=?,
                    [SentToWaitingBy]=?, [SentToWaitingAt]=GETDATE(),
                    [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase2'";
        sqlsrv_query($this->cn, $sql, [$zone ?: null, $notes ?: null, $by, $by, $truckId]);
    }

    public function recallFromWaiting(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [Status]='Phase2', [RecalledBy]=?, [RecalledAt]=GETDATE(),
                    [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status]='Phase2_Waiting'";
        sqlsrv_query($this->cn, $sql, [$by, $by, $truckId]);
    }

    public function cancelTruck(int $truckId, string $reason, string $by): void
    {
        $sql = "UPDATE {$this->T_TRK}
                SET [Status]='Cancelled', [Notes]=ISNULL([Notes],'')+CHAR(13)+?+' - '+?,
                    [UpdatedBy]=?, [UpdatedAt]=GETDATE()
                WHERE [TruckID]=? AND [Status] NOT IN ('Completed','Cancelled')";
        sqlsrv_query($this->cn, $sql, ['CANCELADO', $reason, $by, $truckId]);
    }

    // ─── Audit Log ───────────────────────────────────────────────────────────

    public function logAudit(int $truckId, string $action, string $table, int $recordId,
                             ?string $old, ?string $new, string $by): void
    {
        $sql = "INSERT INTO {$this->T_LOG}
                ([TruckID],[Action],[TableName],[RecordID],[OldData],[NewData],[ActionBy])
                VALUES (?,?,?,?,?,?,?)";
        sqlsrv_query($this->cn, $sql, [$truckId, $action, $table, $recordId, $old, $new, $by]);
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
