<?php

class GateRepository
{
    private $cn;
    private string $DB = "[OLEspresso].[dbo]";
    private string $T_GAT;
    private string $T_ASN;
    private string $T_TRK;

    public function __construct($cn)
    {
        $this->cn    = $cn;
        $this->T_GAT = "{$this->DB}.[transp_Gate]";
        $this->T_ASN = "{$this->DB}.[transp_GateAssignment]";
        $this->T_TRK = "{$this->DB}.[transp_Truck]";
    }

    // ─── Gates ───────────────────────────────────────────────────────────────

    public function listGates(): array
    {
        $sql  = "SELECT * FROM {$this->T_GAT} WHERE [Active]=1 ORDER BY [SortOrder],[GateName]";
        $stmt = sqlsrv_query($this->cn, $sql);
        return $this->fetchAll($stmt);
    }

    // A gate is occupied while a truck has an active assignment and loading is not closed yet
    private function occupantApply(): string
    {
        return "OUTER APPLY (
                    SELECT TOP 1 a.[AssignmentID], a.[AssignedAt], a.[AssignedBy],
                           a.[ShippingConfirmedAt], a.[OrdersValidatedAt],
                           t.[TruckID], t.[TruckNumber], t.[TrailerNumber], t.[CarrierName], t.[DriverName], t.[Status],
                           (SELECT COUNT(*) FROM {$this->DB}.[transp_ScheduleOrder] so WHERE so.[TruckID] = t.[TruckID]) AS [OrderCount]
                    FROM {$this->T_ASN} a
                    JOIN {$this->T_TRK} t ON t.[TruckID] = a.[TruckID]
                    WHERE a.[GateID] = g.[GateID] AND a.[IsActive] = 1 AND a.[LoadingClosedAt] IS NULL
                      AND t.[Status] NOT IN ('Completed','Cancelled')
                    ORDER BY a.[AssignedAt] DESC
                ) occ";
    }

    public function listGatesWithStatus(): array
    {
        $sql = "SELECT g.[GateID], g.[GateName], g.[Description], g.[SortOrder],
                       g.[IsBlocked], g.[BlockReason], g.[BlockNotes], g.[BlockedBy], g.[BlockedAt],
                       occ.[TruckID] AS [OccupiedByTruckID], occ.[TruckNumber] AS [OccupiedByTruck],
                       occ.[TrailerNumber], occ.[CarrierName], occ.[DriverName], occ.[Status] AS [TruckStatus],
                       occ.[AssignedAt], occ.[AssignedBy], occ.[ShippingConfirmedAt], occ.[OrdersValidatedAt],
                       occ.[OrderCount]
                FROM {$this->T_GAT} g
                {$this->occupantApply()}
                WHERE g.[Active] = 1
                ORDER BY g.[SortOrder], g.[GateName]";
        $stmt = sqlsrv_query($this->cn, $sql);
        return $this->fetchAll($stmt);
    }

    // Locks the gate row for the rest of the transaction so two people can't take the same gate
    public function getGateForUpdate(int $gateId): ?array
    {
        $sql = "SELECT g.[GateID], g.[GateName], g.[IsBlocked], g.[BlockReason],
                       occ.[TruckID] AS [OccupiedByTruckID], occ.[TruckNumber] AS [OccupiedByTruck]
                FROM {$this->T_GAT} g WITH (UPDLOCK, HOLDLOCK)
                {$this->occupantApply()}
                WHERE g.[GateID] = ? AND g.[Active] = 1";
        $stmt = sqlsrv_query($this->cn, $sql, [$gateId]);
        $rows = $this->fetchAll($stmt);
        return $rows[0] ?? null;
    }

    // ─── Safety blocking ─────────────────────────────────────────────────────

    public function blockGate(int $gateId, string $reason, string $notes, string $by): void
    {
        $this->exec("UPDATE {$this->T_GAT}
                     SET [IsBlocked]=1, [BlockReason]=?, [BlockNotes]=?, [BlockedBy]=?, [BlockedAt]=GETDATE()
                     WHERE [GateID]=?", [$reason, $notes ?: null, $by, $gateId]);
        $this->logBlock($gateId, 'BLOCK', $reason, $notes, $by);
    }

    public function unblockGate(int $gateId, string $notes, string $by): void
    {
        $this->exec("UPDATE {$this->T_GAT}
                     SET [IsBlocked]=0, [BlockReason]=NULL, [BlockNotes]=NULL, [BlockedBy]=NULL, [BlockedAt]=NULL
                     WHERE [GateID]=?", [$gateId]);
        $this->logBlock($gateId, 'UNBLOCK', null, $notes, $by);
    }

    private function logBlock(int $gateId, string $action, ?string $reason, string $notes, string $by): void
    {
        $this->exec("INSERT INTO {$this->DB}.[transp_GateBlockLog] ([GateID],[Action],[Reason],[Notes],[ActionBy])
                     VALUES (?,?,?,?,?)", [$gateId, $action, $reason, $notes ?: null, $by]);
    }

    public function getGateHistory(int $gateId, int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        $blocks = $this->fetchAll(sqlsrv_query($this->cn,
            "SELECT TOP {$limit} 'BLOCK_LOG' AS [Kind], [Action], [Reason], [Notes], [ActionBy] AS [By], [ActionAt] AS [At],
                    NULL AS [TruckNumber], NULL AS [CarrierName]
             FROM {$this->DB}.[transp_GateBlockLog] WHERE [GateID]=? ORDER BY [ActionAt] DESC", [$gateId]));
        $loads = $this->fetchAll(sqlsrv_query($this->cn,
            "SELECT TOP {$limit} 'ASSIGNMENT' AS [Kind],
                    CASE WHEN a.[IsActive]=0 THEN 'MOVED' WHEN a.[LoadingClosedAt] IS NOT NULL THEN 'LOADED' ELSE 'ASSIGNED' END AS [Action],
                    a.[CancelReason] AS [Reason], NULL AS [Notes], a.[AssignedBy] AS [By], a.[AssignedAt] AS [At],
                    t.[TruckNumber], t.[CarrierName]
             FROM {$this->T_ASN} a JOIN {$this->T_TRK} t ON t.[TruckID]=a.[TruckID]
             WHERE a.[GateID]=? ORDER BY a.[AssignedAt] DESC", [$gateId]));
        $all = array_merge($blocks, $loads);
        usort($all, fn($x, $y) => strcmp($y['At'], $x['At']));
        return array_slice($all, 0, $limit);
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function getActiveAssignment(int $truckId): ?array
    {
        $sql = "SELECT a.*, g.[GateName]
                FROM {$this->T_ASN} a
                JOIN {$this->T_GAT} g ON g.[GateID] = a.[GateID]
                WHERE a.[TruckID]=? AND a.[IsActive]=1";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId]);
        if (!$stmt) return null;
        $rows = $this->fetchAll($stmt);
        return $rows[0] ?? null;
    }

    public function getAssignmentHistory(int $truckId): array
    {
        $sql = "SELECT a.*, g.[GateName]
                FROM {$this->T_ASN} a
                JOIN {$this->T_GAT} g ON g.[GateID] = a.[GateID]
                WHERE a.[TruckID]=?
                ORDER BY a.[AssignedAt]";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId]);
        return $this->fetchAll($stmt);
    }

    public function assignGate(int $truckId, int $gateId, string $by, string $reason = 'Reassigned'): int
    {
        // Deactivate any existing active assignment
        $this->cancelActiveAssignment($truckId, $reason, $by);

        $sql = "INSERT INTO {$this->T_ASN} ([TruckID],[GateID],[AssignedBy])
                VALUES (?,?,?);
                SELECT SCOPE_IDENTITY();";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId, $gateId, $by]);
        if (!$stmt) throw new Exception("Gate assignment failed.");
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function cancelActiveAssignment(int $truckId, string $reason, string $by): void
    {
        $sql = "UPDATE {$this->T_ASN}
                SET [IsActive]=0, [CancelledBy]=?, [CancelledAt]=GETDATE(), [CancelReason]=?
                WHERE [TruckID]=? AND [IsActive]=1";
        sqlsrv_query($this->cn, $sql, [$by, $reason, $truckId]);
    }

    public function confirmGate(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_ASN}
                SET [ShippingConfirmedBy]=?, [ShippingConfirmedAt]=GETDATE()
                WHERE [TruckID]=? AND [IsActive]=1 AND [ShippingConfirmedAt] IS NULL";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    public function validateOrders(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_ASN}
                SET [OrdersValidatedBy]=?, [OrdersValidatedAt]=GETDATE()
                WHERE [TruckID]=? AND [IsActive]=1 AND [OrdersValidatedAt] IS NULL";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    public function closeLoading(int $truckId, string $by): void
    {
        $sql = "UPDATE {$this->T_ASN}
                SET [LoadingClosedBy]=?, [LoadingClosedAt]=GETDATE()
                WHERE [TruckID]=? AND [IsActive]=1 AND [LoadingClosedAt] IS NULL";
        sqlsrv_query($this->cn, $sql, [$by, $truckId]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function exec(string $sql, array $params): void
    {
        if (!sqlsrv_query($this->cn, $sql, $params)) {
            throw new Exception("Database error: " . (sqlsrv_errors()[0]['message'] ?? ''));
        }
    }

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
