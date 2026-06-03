<?php

class InspectionRepository
{
    private $cn;
    private string $DB = "[OLEspresso].[dbo]";
    private string $T_INS;
    private string $T_PHO;

    public function __construct($cn)
    {
        $this->cn    = $cn;
        $this->T_INS = "{$this->DB}.[transp_Inspection]";
        $this->T_PHO = "{$this->DB}.[transp_InspectionPhoto]";
    }

    public function getAttemptCount(int $truckId): int
    {
        $sql  = "SELECT COUNT(*) AS C FROM {$this->T_INS} WHERE [TruckID]=?";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId]);
        $row  = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        return (int)($row['C'] ?? 0);
    }

    public function hasApproved(int $truckId): bool
    {
        $sql  = "SELECT TOP 1 1 FROM {$this->T_INS}
                 WHERE [TruckID]=? AND [Result]='APPROVED'";
        $stmt = sqlsrv_query($this->cn, $sql, [$truckId]);
        return $stmt && sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) !== null;
    }

    public function submitInspection(int $truckId, array $d, string $by): int
    {
        $attempt = $this->getAttemptCount($truckId) + 1;

        // Determine result: all 5 checks must pass for APPROVED
        $pass = (int)($d['CheckOdors']    ?? 0) &&
                (int)($d['CheckHoles']    ?? 0) &&
                (int)($d['CheckForeign']  ?? 0) &&
                (int)($d['CheckChemicals']?? 0) &&
                (int)($d['CheckFloor']    ?? 0);

        $result = $pass ? 'APPROVED' : 'REJECTED';

        $sql = "INSERT INTO {$this->T_INS}
                ([TruckID],[AttemptNumber],[CheckOdors],[CheckHoles],[CheckForeign],
                 [CheckChemicals],[CheckFloor],[Result],[Comments],[InspectedBy])
                VALUES (?,?,?,?,?,?,?,?,?,?);
                SELECT SCOPE_IDENTITY();";
        $params = [
            $truckId,
            $attempt,
            (int)($d['CheckOdors']     ?? 0),
            (int)($d['CheckHoles']     ?? 0),
            (int)($d['CheckForeign']   ?? 0),
            (int)($d['CheckChemicals'] ?? 0),
            (int)($d['CheckFloor']     ?? 0),
            $result,
            $d['Comments']    ?? null,
            $by,
        ];
        $stmt = sqlsrv_query($this->cn, $sql, $params);
        sqlsrv_next_result($stmt);
        $row  = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function getInspections(int $truckId): array
    {
        $rows = $this->fetchAll(
            sqlsrv_query($this->cn,
                "SELECT * FROM {$this->T_INS} WHERE [TruckID]=? ORDER BY [AttemptNumber]",
                [$truckId])
        );
        foreach ($rows as &$row) {
            $row['photos'] = $this->getPhotosByInspection((int)$row['InspectionID']);
        }
        return $rows;
    }

    // ─── Photos ──────────────────────────────────────────────────────────────

    public function savePhoto(int $inspectionId, int $truckId, array $file, string $by): int
    {
        $sql = "INSERT INTO {$this->T_PHO}
                ([InspectionID],[TruckID],[FileName],[StoredFileName],[FileSize],[Notes],[UploadedBy])
                VALUES (?,?,?,?,?,?,?);
                SELECT SCOPE_IDENTITY();";
        $stmt = sqlsrv_query($this->cn, $sql, [
            $inspectionId,
            $truckId,
            $file['FileName'],
            $file['StoredFileName'],
            $file['FileSize'] ?? null,
            $file['Notes']    ?? null,
            $by,
        ]);
        sqlsrv_next_result($stmt);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        return (int)($row[0] ?? 0);
    }

    public function getPhotosByInspection(int $inspectionId): array
    {
        $stmt = sqlsrv_query($this->cn,
            "SELECT * FROM {$this->T_PHO} WHERE [InspectionID]=? ORDER BY [UploadedAt]",
            [$inspectionId]);
        return $this->fetchAll($stmt);
    }

    public function getPhotoById(int $photoId): ?array
    {
        $stmt = sqlsrv_query($this->cn,
            "SELECT * FROM {$this->T_PHO} WHERE [PhotoID]=?", [$photoId]);
        if (!$stmt) return null;
        $rows = $this->fetchAll($stmt);
        return $rows[0] ?? null;
    }

    // ─── Reports ─────────────────────────────────────────────────────────────

    public function getRejectionReport(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT i.*, t.[TruckNumber], t.[TrailerNumber], t.[CarrierName], t.[TruckDate]
                FROM {$this->T_INS} i
                JOIN {$this->DB}.[transp_Truck] t ON t.[TruckID] = i.[TruckID]
                WHERE i.[Result]='REJECTED'
                  AND t.[TruckDate] BETWEEN ? AND ?
                ORDER BY t.[TruckDate] DESC, i.[InspectedAt] DESC";
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
