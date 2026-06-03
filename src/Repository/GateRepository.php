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

    public function listGatesWithStatus(string $date): array
    {
        $sql = "SELECT g.*,
                    (SELECT TOP 1 t.[TruckNumber]
                     FROM {$this->T_ASN} a
                     JOIN {$this->T_TRK} t ON t.[TruckID] = a.[TruckID]
                     WHERE a.[GateID] = g.[GateID]
                       AND a.[IsActive] = 1
                       AND t.[TruckDate] = ?
                       AND t.[Status] NOT IN ('Completed','Cancelled')
                    ) AS [OccupiedByTruck],
                    (SELECT TOP 1 t.[TruckID]
                     FROM {$this->T_ASN} a
                     JOIN {$this->T_TRK} t ON t.[TruckID] = a.[TruckID]
                     WHERE a.[GateID] = g.[GateID]
                       AND a.[IsActive] = 1
                       AND t.[TruckDate] = ?
                       AND t.[Status] NOT IN ('Completed','Cancelled')
                    ) AS [OccupiedByTruckID]
                FROM {$this->T_GAT} g
                WHERE g.[Active] = 1
                ORDER BY g.[SortOrder], g.[GateName]";
        $stmt = sqlsrv_query($this->cn, $sql, [$date, $date]);
        return $this->fetchAll($stmt);
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

    public function assignGate(int $truckId, int $gateId, string $by): int
    {
        // Deactivate any existing active assignment
        $this->cancelActiveAssignment($truckId, 'Reasignación', $by);

        $sql = "INSERT INTO {$this->T_ASN} ([TruckID],[GateID],[AssignedBy])
                VALUES (?,?,?);
                SELECT SCOPE_IDENTITY();";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId, $gateId, $by]);
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
