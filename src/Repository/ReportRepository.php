<?php

class ReportRepository
{
    private $cn;
    private string $DB = "[OLEspresso].[dbo]";

    public function __construct($cn) { $this->cn = $cn; }

    public function getHistoricalReport(array $filters): array
    {
        $where  = "WHERE 1=1";
        $params = [];

        if (!empty($filters['dateFrom'])) {
            $where   .= " AND t.[TruckDate] >= ?";
            $params[] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where   .= " AND t.[TruckDate] <= ?";
            $params[] = $filters['dateTo'];
        }
        if (!empty($filters['carrier'])) {
            $where   .= " AND t.[CarrierName] LIKE ?";
            $params[] = '%' . $filters['carrier'] . '%';
        }
        if (!empty($filters['status'])) {
            $where   .= " AND t.[Status] = ?";
            $params[] = $filters['status'];
        }

        $sql = "SELECT t.*,
                       s.[WindowStart], s.[WindowEnd],
                       (SELECT COUNT(*) FROM {$this->DB}.[transp_Inspection] i
                        WHERE i.[TruckID] = t.[TruckID]) AS [TotalInspections],
                       (SELECT COUNT(*) FROM {$this->DB}.[transp_Inspection] i
                        WHERE i.[TruckID] = t.[TruckID] AND i.[Result]='REJECTED') AS [RejectedInspections],
                       (SELECT TOP 1 g.[GateName]
                        FROM {$this->DB}.[transp_GateAssignment] a
                        JOIN {$this->DB}.[transp_Gate] g ON g.[GateID] = a.[GateID]
                        WHERE a.[TruckID] = t.[TruckID]
                        ORDER BY a.[AssignedAt] DESC) AS [LastGate]
                FROM {$this->DB}.[transp_Truck] t
                LEFT JOIN {$this->DB}.[transp_Schedule] s ON s.[ScheduleID] = t.[ScheduleID]
                {$where}
                ORDER BY t.[TruckDate] DESC, t.[CreatedAt] DESC";

        $stmt = sqlsrv_query($this->cn, $sql, $params ?: null);
        return $this->fetchAll($stmt);
    }

    public function getKpis(string $dateFrom, string $dateTo): array
    {
        $params = [$dateFrom, $dateTo];

        // Total trucks
        $stmt = sqlsrv_query($this->cn,
            "SELECT COUNT(*) AS C FROM {$this->DB}.[transp_Truck]
             WHERE [TruckDate] BETWEEN ? AND ?", $params);
        $total = (int)(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['C'] ?? 0);

        // Completed
        $stmt = sqlsrv_query($this->cn,
            "SELECT COUNT(*) AS C FROM {$this->DB}.[transp_Truck]
             WHERE [TruckDate] BETWEEN ? AND ? AND [Status]='Completed'", $params);
        $completed = (int)(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['C'] ?? 0);

        // Inspection rejections
        $stmt = sqlsrv_query($this->cn,
            "SELECT COUNT(*) AS C FROM {$this->DB}.[transp_Inspection] i
             JOIN {$this->DB}.[transp_Truck] t ON t.[TruckID] = i.[TruckID]
             WHERE i.[Result]='REJECTED' AND t.[TruckDate] BETWEEN ? AND ?", $params);
        $rejected = (int)(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['C'] ?? 0);

        // Average inspections per truck
        $stmt = sqlsrv_query($this->cn,
            "SELECT AVG(CAST(cnt AS FLOAT)) AS A FROM (
                SELECT COUNT(*) AS cnt FROM {$this->DB}.[transp_Inspection] i
                JOIN {$this->DB}.[transp_Truck] t ON t.[TruckID] = i.[TruckID]
                WHERE t.[TruckDate] BETWEEN ? AND ?
                GROUP BY i.[TruckID]
             ) sub", $params);
        $avgInspections = round((float)(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['A'] ?? 0), 2);

        return [
            'totalTrucks'      => $total,
            'completed'        => $completed,
            'active'           => $total - $completed,
            'rejectedInspections' => $rejected,
            'avgInspections'   => $avgInspections,
        ];
    }

    public function getInspectionRejectionsByCarrier(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT t.[CarrierName],
                       COUNT(*) AS [TotalRejections],
                       COUNT(DISTINCT t.[TruckID]) AS [TrucksAffected]
                FROM {$this->DB}.[transp_Inspection] i
                JOIN {$this->DB}.[transp_Truck] t ON t.[TruckID] = i.[TruckID]
                WHERE i.[Result]='REJECTED'
                  AND t.[TruckDate] BETWEEN ? AND ?
                GROUP BY t.[CarrierName]
                ORDER BY [TotalRejections] DESC";
        $stmt = sqlsrv_query($this->cn, $sql, [$dateFrom, $dateTo]);
        return $this->fetchAll($stmt);
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
