<?php
date_default_timezone_set('America/New_York');

require_once(__DIR__ . "/src/Support/json.php");
require_once(__DIR__ . "/src/Support/auth.php");
require_once(__DIR__ . "/src/Support/i18n.php");
require_once(__DIR__ . "/src/Support/csrf.php");
require_once(__DIR__ . "/src/Support/config.php");
require_once(__DIR__ . "/src/Support/db.php");
require_once(__DIR__ . "/src/Support/permissions.php");
require_once(__DIR__ . "/src/Repository/ScheduleRepository.php");
require_once(__DIR__ . "/src/Repository/TruckRepository.php");
require_once(__DIR__ . "/src/Repository/InspectionRepository.php");
require_once(__DIR__ . "/src/Repository/GateRepository.php");
require_once(__DIR__ . "/src/Repository/ReportRepository.php");

require_login_or_redirect();

// 403 with a message that says who owns the step ("Locked: only Shipping can do this.")
function transp_require(array $perms, string $cap): void {
    if (!empty($perms[$cap])) return;
    $owners = transp_cap_owners()[$cap] ?? ['ADMIN'];
    $names  = implode(' / ', array_map(fn($r) => transp_t('role.' . $r), $owners));
    json_out(false, transp_t('lock.only', ['roles' => $names]), ['locked' => $cap], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $cn       = db_connect_sqlsrv();
    $schedRepo = new ScheduleRepository($cn);
    $truckRepo = new TruckRepository($cn);
    $insRepo   = new InspectionRepository($cn);
    $gateRepo  = new GateRepository($cn);
    $rptRepo   = new ReportRepository($cn);

    $username = current_username();
    $roles    = transp_get_roles($cn, $username);
    $perms    = transp_build_permissions($roles);
} catch (Throwable $e) {
    json_out(false, $e->getMessage(), null, 500);
}

try {

    // ─── Permissions ─────────────────────────────────────────────────────────
    if ($action === 'get_permissions') {
        json_out(true, "ok", array_merge(['username' => $username], $perms));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MÓDULO 1 — Órdenes y Schedules
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'list_orders') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        json_out(true, "ok", $schedRepo->listOrders($date));
    }

    if ($action === 'create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $d = $_POST;
        if (empty($d['ExternalID'])) json_out(false, transp_t('api.orderIdRequired'), null, 422);

        $id = $schedRepo->createOrder($d, $username);
        json_out(true, transp_t('api.orderCreated'), ["BiOrderID" => $id]);
    }

    if ($action === 'list_schedules') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        json_out(true, "ok", $schedRepo->listSchedules($date));
    }

    if ($action === 'create_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $d = $_POST;
        if (empty($d['CarrierName']))   json_out(false, transp_t('api.carrierRequired'), null, 422);
        if (empty($d['ScheduleDate'])) json_out(false, transp_t('api.scheduleDateRequired'), null, 422);

        $id = $schedRepo->createSchedule($d, $username);
        json_out(true, transp_t('api.scheduleCreated'), ["ScheduleID" => $id]);
    }

    if ($action === 'update_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $id = (int)($_POST['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleID']), null, 422);
        if (empty($_POST['CarrierName'])) json_out(false, transp_t('api.carrierRequired'), null, 422);

        $schedRepo->updateSchedule($id, $_POST, $username);
        json_out(true, transp_t('api.scheduleUpdated'));
    }

    if ($action === 'cancel_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $id = (int)($_POST['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleID']), null, 422);

        $schedRepo->cancelSchedule($id, $username);
        json_out(true, transp_t('api.scheduleCancelled'));
    }

    if ($action === 'list_schedule_orders') {
        $id = (int)($_GET['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleID']), null, 422);
        json_out(true, "ok", $schedRepo->listScheduleOrders($id));
    }

    if ($action === 'add_order_to_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $schedId = (int)($_POST['ScheduleID'] ?? 0);
        if ($schedId <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleID']), null, 422);

        $sched = $schedRepo->getSchedule($schedId);
        if (!$sched || $sched['Status'] !== 'Active') json_out(false, transp_t('api.scheduleInvalid'), null, 422);

        $d = [
            'ExternalOrderID' => trim($_POST['ExternalOrderID'] ?? ''),
            'PioNumber'       => trim($_POST['PioNumber'] ?? ''),
            'ProgressPct'     => trim($_POST['ProgressPct'] ?? ''),
            'Notes'           => trim($_POST['Notes'] ?? ''),
        ];
        if ($d['PioNumber'] === '') json_out(false, transp_t('api.required', ['field' => 'PIO']), null, 422);
        if ($d['ProgressPct'] !== '' && (!is_numeric($d['ProgressPct']) || $d['ProgressPct'] < 0 || $d['ProgressPct'] > 100)) {
            json_out(false, transp_t('order.progressInvalid'), null, 422);
        }
        if ($d['ExternalOrderID'] !== '' && $schedRepo->scheduleHasOrder($schedId, $d['ExternalOrderID'])) {
            json_out(false, transp_t('api.orderDuplicate', ['id' => $d['ExternalOrderID']]), null, 422);
        }

        $id = $schedRepo->addOrderWithDetails($sched, $d, $username);
        json_out(true, transp_t('api.orderAddedToSchedule'), ["ScheduleOrderID" => $id]);
    }

    // ─── Import schedules + orders from Excel/CSV (parsed in the browser) ────
    if ($action === 'import_schedules' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $rows = json_decode($_POST['rows'] ?? '', true);
        if (!is_array($rows) || !$rows) json_out(false, transp_t('imp.noRows'), null, 422);
        if (count($rows) > 2000)        json_out(false, transp_t('imp.tooMany', ['max' => 2000]), null, 422);

        $res = $schedRepo->importRows($rows, $username);
        foreach ($res['errors'] as &$err) {
            $err['error'] = transp_t($err['key']);
        }
        unset($err);
        json_out(true, transp_t('imp.done', ['orders' => $res['ordersAdded'], 'schedules' => $res['schedulesCreated']]), $res);
    }

    if ($action === 'remove_order_from_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $id = (int)($_POST['ScheduleOrderID'] ?? 0);
        if ($id <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleOrderID']), null, 422);

        $schedRepo->removeOrderFromSchedule($id);
        json_out(true, transp_t('api.orderRemovedFromSchedule'));
    }

    if ($action === 'update_order_pio' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $id  = (int)($_POST['ScheduleOrderID'] ?? 0);
        $pio = trim($_POST['PioNumber'] ?? '');
        if ($id <= 0) json_out(false, transp_t('api.required', ['field' => 'ScheduleOrderID']), null, 422);

        $schedRepo->updateOrderPioInSchedule($id, $pio, $username);
        json_out(true, transp_t('api.pioUpdated'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD — Tablero en tiempo real
    // ═══════════════════════════════════════════════════════════════════════

    // ─── Live sync ───────────────────────────────────────────────────────────
    // The browser polls this every few seconds and reloads its view only when the value changes.
    if ($action === 'sync_version') {
        $stmt = sqlsrv_query($cn, "SELECT
                (SELECT ISNULL(MAX([LogID]),0) FROM [transp_AuditLog]) AS a,
                (SELECT ISNULL(MAX([LogID]),0) FROM [transp_GateBlockLog]) AS b,
                (SELECT COUNT(*) FROM [transp_Schedule]) AS c,
                (SELECT ISNULL(CONVERT(VARCHAR(23), MAX(ISNULL([UpdatedAt],[CreatedAt])), 121),'') FROM [transp_Schedule]) AS d,
                (SELECT COUNT(*) FROM [transp_ScheduleOrder]) AS e,
                (SELECT ISNULL(CHECKSUM_AGG(CHECKSUM([ScheduleOrderID],[PioNumber],[TruckID])),0) FROM [transp_ScheduleOrder]) AS f");
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC) : null;
        if (!$row) json_out(false, "sync failed", null, 500);
        json_out(true, "ok", ['v' => implode('.', $row)]);
    }

    if ($action === 'dashboard_data') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $trucks = $truckRepo->listActiveTrucks($date);
        $gates  = $gateRepo->listGatesWithStatus();
        json_out(true, "ok", ['trucks' => $trucks, 'gates' => $gates]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 1 — Registro del conductor
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'register_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canSecurity');

        $d = $_POST;
        if (empty($d['TruckNumber']))  json_out(false, transp_t('api.truckNumberRequired'), null, 422);
        if (empty($d['CarrierName']))  json_out(false, transp_t('api.carrierRequired'), null, 422);
        if (empty($d['DriverName']))   json_out(false, transp_t('api.driverRequired'), null, 422);

        // Validate schedule if provided
        if (!empty($d['ScheduleID'])) {
            $sched = $schedRepo->getSchedule((int)$d['ScheduleID']);
            if (!$sched || $sched['Status'] !== 'Active') {
                json_out(false, transp_t('api.scheduleInvalid'), null, 422);
            }
        }

        $truckId = $truckRepo->registerTruck($d, $username);
        $truckRepo->logAudit($truckId, 'REGISTER_TRUCK', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, transp_t('api.truckRegistered'), ["TruckID" => $truckId]);
    }

    if ($action === 'advance_to_phase2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canSecurity');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        if ($truck['Status'] !== 'Phase1') json_out(false, transp_t('api.notInPhase', ['n' => 1]), null, 422);

        $truckRepo->advanceToPhase2($truckId, $username);
        $truckRepo->logAudit($truckId, 'ADVANCE_PHASE2', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, transp_t('api.advancedPhase2'));
    }

    if ($action === 'get_truck') {
        $truckId = (int)($_GET['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck       = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);

        $inspections = $insRepo->getInspections($truckId);
        $gate        = $gateRepo->getActiveAssignment($truckId);
        $gateHistory = $gateRepo->getAssignmentHistory($truckId);

        json_out(true, "ok", [
            'truck'       => $truck,
            'inspections' => $inspections,
            'gate'        => $gate,
            'gateHistory' => $gateHistory,
            'orders'      => $schedRepo->listTruckOrders($truckId),
        ]);
    }

    if ($action === 'cancel_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $reason  = trim($_POST['Reason'] ?? '');
        if ($truckId <= 0)   json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);
        if ($reason === '')  json_out(false, transp_t('api.cancelReasonRequired'), null, 422);

        $truckRepo->cancelTruck($truckId, $reason, $username);
        // Free the gate and put its orders back in the pool for another truck
        $gateRepo->cancelActiveAssignment($truckId, $reason, $username);
        $schedRepo->assignOrdersToTruck($truckId, [], $username);
        $truckRepo->logAudit($truckId, 'CANCEL_TRUCK', 'transp_Truck', $truckId, null, $reason, $username);
        json_out(true, transp_t('api.truckCancelled'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 2 — Inspección del tráiler
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'submit_inspection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canInspect');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        if ($truck['Status'] !== 'Phase2') json_out(false, transp_t('api.notInPhase', ['n' => 2]), null, 422);

        $d      = $_POST;
        $pass   = (int)($d['CheckOdors']    ?? 0) &&
                  (int)($d['CheckHoles']    ?? 0) &&
                  (int)($d['CheckForeign']  ?? 0) &&
                  (int)($d['CheckChemicals']?? 0) &&
                  (int)($d['CheckFloor']    ?? 0);

        // Rejected requires comments
        if (!$pass && empty(trim($d['Comments'] ?? ''))) {
            json_out(false, transp_t('api.commentsRequired'), null, 422);
        }

        $insId = $insRepo->submitInspection($truckId, $d, $username);
        $result = $pass ? 'APPROVED' : 'REJECTED';

        $truckRepo->logAudit($truckId, "INSPECTION_{$result}", 'transp_Inspection', $insId, null, null, $username);

        // If approved, advance truck to Phase 3 automatically
        if ($pass) {
            $truckRepo->advanceToPhase3($truckId, $username);
            $truckRepo->logAudit($truckId, 'ADVANCE_PHASE3', 'transp_Truck', $truckId, null, null, $username);
        }

        json_out(true, $pass ? transp_t('api.inspectionApproved') : transp_t('api.inspectionRejected'),
            ["InspectionID" => $insId, "result" => $result]);
    }

    if ($action === 'get_inspections') {
        $truckId = (int)($_GET['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);
        json_out(true, "ok", $insRepo->getInspections($truckId));
    }

    // ─── Subir foto de evidencia ──────────────────────────────────────────────
    if ($action === 'upload_inspection_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canInspect');

        $inspectionId = (int)($_POST['InspectionID'] ?? 0);
        $truckId      = (int)($_POST['TruckID']      ?? 0);
        if ($inspectionId <= 0) json_out(false, transp_t('api.required', ['field' => 'InspectionID']), null, 422);
        if ($truckId      <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            json_out(false, transp_t('api.noFile'), null, 422);
        }

        $file    = $_FILES['photo'];
        $maxSize = 15 * 1024 * 1024; // 15 MB
        if ($file['size'] > $maxSize) json_out(false, transp_t('api.fileTooLarge'), null, 422);

        $allowed = ['jpg','jpeg','png','gif','webp','heic'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) json_out(false, transp_t('api.fileType'), null, 422);

        $uploadDir  = transp_photo_dir();
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        $stored     = 'INS_' . $inspectionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath   = $uploadDir . $stored;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            json_out(false, transp_t('api.fileSaveFailed'), null, 500);
        }

        $photoId = $insRepo->savePhoto($inspectionId, $truckId, [
            'FileName'       => substr($file['name'], 0, 490),
            'StoredFileName' => $stored,
            'FileSize'       => $file['size'],
            'Notes'          => substr(trim($_POST['Notes'] ?? ''), 0, 490),
        ], $username);

        $truckRepo->logAudit($truckId, 'UPLOAD_PHOTO', 'transp_InspectionPhoto', $photoId, null, null, $username);
        json_out(true, transp_t('api.photoUploaded'), ["PhotoID" => $photoId, "FileName" => $file['name']]);
    }

    // ─── Descargar / ver foto ─────────────────────────────────────────────────
    if ($action === 'view_inspection_photo') {
        $photoId = (int)($_GET['PhotoID'] ?? 0);
        if ($photoId <= 0) { http_response_code(400); exit(transp_t('api.invalidId')); }

        $photo = $insRepo->getPhotoById($photoId);
        if (!$photo) { http_response_code(404); exit(transp_t('api.photoNotFound')); }

        $path = transp_photo_dir() . basename($photo['StoredFileName']);
        if (!file_exists($path)) { http_response_code(404); exit(transp_t('api.fileNotFound')); }

        $mime = mime_content_type($path) ?: 'image/jpeg';
        header('Content-Type: '   . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . rawurlencode($photo['FileName']) . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    // ─── Enviar a zona de espera ──────────────────────────────────────────────
    if ($action === 'send_to_waiting_zone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canYard');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $zone    = trim($_POST['WaitingZone']  ?? '');
        $notes   = trim($_POST['WaitingNotes'] ?? '');
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);
        if ($zone === '') json_out(false, transp_t('api.waitingZoneRequired'), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck || $truck['Status'] !== 'Phase2') {
            json_out(false, transp_t('api.mustBePhase2'), null, 422);
        }

        $truckRepo->sendToWaitingZone($truckId, $zone, $notes, $username);
        $truckRepo->logAudit($truckId, 'SENT_TO_WAITING', 'transp_Truck', $truckId, null,
            json_encode(['zone' => $zone, 'notes' => $notes]), $username);
        json_out(true, transp_t('api.sentToWaiting', ['zone' => $zone]));
    }

    // ─── Llamar de regreso para re-inspección ─────────────────────────────────
    if ($action === 'recall_from_waiting' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canYard');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck || $truck['Status'] !== 'Phase2_Waiting') {
            json_out(false, transp_t('api.notInWaiting'), null, 422);
        }

        $truckRepo->recallFromWaiting($truckId, $username);
        $truckRepo->logAudit($truckId, 'RECALLED_FROM_WAITING', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, transp_t('api.recalled'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 3 — Asignación de gate y carga
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'list_gates') {
        json_out(true, "ok", $gateRepo->listGatesWithStatus());
    }

    // Shared by the truck detail (assign/reassign) and the Warehouse board.
    // $orderIds = null keeps the truck's orders as they are.
    $assignGateTx = function (int $truckId, int $gateId, string $reason, ?array $orderIds)
                    use ($cn, $truckRepo, $insRepo, $gateRepo, $schedRepo, $username) {
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);
        if ($gateId  <= 0) json_out(false, transp_t('api.required', ['field' => 'GateID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        if ($truck['Status'] !== 'Phase3') json_out(false, transp_t('api.notInPhase', ['n' => 3]), null, 422);
        if (!$insRepo->hasApproved($truckId)) json_out(false, transp_t('api.approvedInspectionRequired'), null, 422);

        $current = $gateRepo->getActiveAssignment($truckId);
        if ($current && !empty($current['LoadingClosedAt'])) json_out(false, transp_t('wh.err.loadingClosed'), null, 422);
        $isMove = $current && (int)$current['GateID'] !== $gateId;
        if ($isMove && $reason === '') json_out(false, transp_t('api.reassignReasonRequired'), null, 422);

        if (!sqlsrv_begin_transaction($cn)) throw new Exception("Could not start transaction.");
        try {
            $gate = $gateRepo->getGateForUpdate($gateId);
            if (!$gate) throw new DomainException(transp_t('wh.err.gateNotFound'));
            if ((int)$gate['IsBlocked'] === 1) {
                throw new DomainException(transp_t('wh.err.gateBlocked', ['gate' => $gate['GateName'], 'reason' => $gate['BlockReason']]));
            }
            if ($gate['OccupiedByTruckID'] && (int)$gate['OccupiedByTruckID'] !== $truckId) {
                throw new DomainException(transp_t('wh.err.gateOccupied', ['gate' => $gate['GateName'], 'truck' => $gate['OccupiedByTruck']]));
            }

            $assignId = $current && !$isMove
                ? (int)$current['AssignmentID']
                : $gateRepo->assignGate($truckId, $gateId, $username, $reason !== '' ? $reason : 'Reassigned');
            $loaded   = $orderIds === null ? null : $schedRepo->assignOrdersToTruck($truckId, $orderIds, $username);
            sqlsrv_commit($cn);
        } catch (DomainException $e) {
            sqlsrv_rollback($cn);
            json_out(false, $e->getMessage(), null, 409);
        } catch (Throwable $e) {
            sqlsrv_rollback($cn);
            throw $e;
        }

        $auditAction = !$current ? 'ASSIGN_GATE' : ($isMove ? 'REASSIGN_GATE' : 'UPDATE_LOAD_ORDERS');
        $truckRepo->logAudit($truckId, $auditAction, 'transp_GateAssignment', $assignId, null,
            json_encode(['GateID' => $gateId, 'Reason' => $reason !== '' ? $reason : null, 'Orders' => $orderIds], JSON_UNESCAPED_UNICODE), $username);

        return ['AssignmentID' => $assignId, 'GateName' => $gate['GateName'], 'Orders' => $loaded,
                'Moved' => $isMove, 'New' => !$current];
    };

    if (($action === 'assign_gate' || $action === 'reassign_gate') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canWarehouse');

        $res = $assignGateTx((int)($_POST['TruckID'] ?? 0), (int)($_POST['GateID'] ?? 0), trim($_POST['Reason'] ?? ''), null);
        json_out(true, transp_t($res['Moved'] ? 'api.gateReassigned' : 'api.gateAssigned'), $res);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WAREHOUSE — gate control (safety blocks) and gate + order assignment
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'warehouse_board') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        json_out(true, "ok", [
            'gates' => $gateRepo->listGatesWithStatus(),
            'queue' => $truckRepo->listWarehouseQueue($date),
        ]);
    }

    if ($action === 'warehouse_truck_orders') {
        transp_require($perms, 'canWarehouse');
        $truckId = (int)($_GET['TruckID'] ?? 0);
        $truck   = $truckId > 0 ? $truckRepo->getTruck($truckId) : null;
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        json_out(true, "ok", [
            'truck'  => $truck,
            'gate'   => $gateRepo->getActiveAssignment($truckId),
            'orders' => $schedRepo->listAssignableOrders($truckId, $truck['ScheduleID'] ? (int)$truck['ScheduleID'] : null,
                                                         substr((string)$truck['TruckDate'], 0, 10)),
        ]);
    }

    if ($action === 'warehouse_assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canWarehouse');

        $orderIds = array_values(array_filter(explode(',', (string)($_POST['OrderIDs'] ?? '')), 'strlen'));
        $res = $assignGateTx((int)($_POST['TruckID'] ?? 0), (int)($_POST['GateID'] ?? 0), trim($_POST['Reason'] ?? ''), $orderIds);
        $key = $res['New'] ? 'wh.assigned' : ($res['Moved'] ? 'wh.moved' : 'wh.ordersUpdated');
        json_out(true, transp_t($key, ['gate' => $res['GateName'], 'n' => (int)$res['Orders']]), $res);
    }

    if ($action === 'warehouse_unassign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canWarehouse');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $reason  = trim($_POST['Reason'] ?? '');
        if ($reason === '') json_out(false, transp_t('api.reassignReasonRequired'), null, 422);
        $current = $truckId > 0 ? $gateRepo->getActiveAssignment($truckId) : null;
        if (!$current) json_out(false, transp_t('wh.err.noGate'), null, 422);
        if (!empty($current['LoadingClosedAt'])) json_out(false, transp_t('wh.err.loadingClosed'), null, 422);

        if (!sqlsrv_begin_transaction($cn)) throw new Exception("Could not start transaction.");
        try {
            $gateRepo->cancelActiveAssignment($truckId, $reason, $username);
            $schedRepo->assignOrdersToTruck($truckId, [], $username);
            sqlsrv_commit($cn);
        } catch (Throwable $e) {
            sqlsrv_rollback($cn);
            throw $e;
        }
        $truckRepo->logAudit($truckId, 'UNASSIGN_GATE', 'transp_GateAssignment', (int)$current['AssignmentID'], null, $reason, $username);
        json_out(true, transp_t('wh.unassigned', ['gate' => $current['GateName']]));
    }

    if (($action === 'gate_block' || $action === 'gate_unblock') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canWarehouse');

        $gateId = (int)($_POST['GateID'] ?? 0);
        $reason = mb_substr(trim($_POST['Reason'] ?? ''), 0, 100);
        $notes  = mb_substr(trim($_POST['Notes']  ?? ''), 0, 500);
        if ($gateId <= 0) json_out(false, transp_t('api.required', ['field' => 'GateID']), null, 422);

        if (!sqlsrv_begin_transaction($cn)) throw new Exception("Could not start transaction.");
        try {
            $gate = $gateRepo->getGateForUpdate($gateId);
            if (!$gate) throw new DomainException(transp_t('wh.err.gateNotFound'));
            if ($action === 'gate_block') {
                if ($reason === '') throw new DomainException(transp_t('wh.err.blockReason'));
                if ((int)$gate['IsBlocked'] === 1) throw new DomainException(transp_t('wh.err.alreadyBlocked', ['gate' => $gate['GateName']]));
                if ($gate['OccupiedByTruckID']) {
                    throw new DomainException(transp_t('wh.err.blockOccupied', ['gate' => $gate['GateName'], 'truck' => $gate['OccupiedByTruck']]));
                }
                $gateRepo->blockGate($gateId, $reason, $notes, $username);
            } else {
                if ((int)$gate['IsBlocked'] !== 1) throw new DomainException(transp_t('wh.err.notBlocked', ['gate' => $gate['GateName']]));
                $gateRepo->unblockGate($gateId, $notes, $username);
            }
            sqlsrv_commit($cn);
        } catch (DomainException $e) {
            sqlsrv_rollback($cn);
            json_out(false, $e->getMessage(), null, 409);
        } catch (Throwable $e) {
            sqlsrv_rollback($cn);
            throw $e;
        }
        json_out(true, transp_t($action === 'gate_block' ? 'wh.blocked' : 'wh.unblocked', ['gate' => $gate['GateName']]));
    }

    if ($action === 'gate_history') {
        $gateId = (int)($_GET['GateID'] ?? 0);
        if ($gateId <= 0) json_out(false, transp_t('api.required', ['field' => 'GateID']), null, 422);
        json_out(true, "ok", $gateRepo->getGateHistory($gateId));
    }

    if ($action === 'confirm_gate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canShipping');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $gate = $gateRepo->getActiveAssignment($truckId);
        if (!$gate) json_out(false, transp_t('wh.err.noGate'), null, 422);
        if (!empty($gate['ShippingConfirmedAt'])) json_out(true, transp_t('api.gateConfirmed'));   // already done

        $gateRepo->confirmGate($truckId, $username);
        $truckRepo->logAudit($truckId, 'CONFIRM_GATE', 'transp_GateAssignment', (int)$gate['AssignmentID'], null, $gate['GateName'], $username);
        json_out(true, transp_t('api.gateConfirmed'));
    }

    if ($action === 'validate_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canShipping');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $gate = $gateRepo->getActiveAssignment($truckId);
        if (!$gate) json_out(false, transp_t('wh.err.noGate'), null, 422);
        if (empty($gate['ShippingConfirmedAt'])) json_out(false, transp_t('sync.err.confirmFirst'), null, 422);
        if (!empty($gate['OrdersValidatedAt'])) json_out(true, transp_t('api.ordersValidated'));   // already done

        $gateRepo->validateOrders($truckId, $username);
        $truckRepo->logAudit($truckId, 'VALIDATE_ORDERS', 'transp_GateAssignment', (int)$gate['AssignmentID'], null, null, $username);
        json_out(true, transp_t('api.ordersValidated'));
    }

    if ($action === 'close_loading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canShipping');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        if ($truck['Status'] !== 'Phase3') json_out(false, transp_t('api.notInPhase', ['n' => 3]), null, 422);
        $gate = $gateRepo->getActiveAssignment($truckId);
        if (!$gate) json_out(false, transp_t('wh.err.noGate'), null, 422);
        if (empty($gate['ShippingConfirmedAt']) || empty($gate['OrdersValidatedAt'])) {
            json_out(false, transp_t('sync.err.validateFirst'), null, 422);
        }

        $gateRepo->closeLoading($truckId, $username);
        $truckRepo->advanceToPhase4($truckId, $username);
        $truckRepo->logAudit($truckId, 'CLOSE_LOADING', 'transp_GateAssignment', $truckId, null, null, $username);
        json_out(true, transp_t('api.loadingClosed'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 4 — Cierre y documentación
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'close_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canLogistica');

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $notes   = trim($_POST['Notes']   ?? '');
        if ($truckId <= 0) json_out(false, transp_t('api.required', ['field' => 'TruckID']), null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, transp_t('api.truckNotFound'), null, 404);
        if ($truck['Status'] !== 'Phase4') json_out(false, transp_t('api.notInPhase', ['n' => 4]), null, 422);

        $truckRepo->closeTruck($truckId, $notes, $username);
        $truckRepo->logAudit($truckId, 'CLOSE_TRUCK', 'transp_Truck', $truckId, null, $notes ?: null, $username);
        json_out(true, transp_t('api.truckCompleted'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // REPORTES
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'report_history') {
        transp_require($perms, 'canReports');

        $filters = [
            'dateFrom' => trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days'))),
            'dateTo'   => trim($_GET['dateTo']   ?? date('Y-m-d')),
            'carrier'  => trim($_GET['carrier']  ?? ''),
            'status'   => trim($_GET['status']   ?? ''),
        ];
        json_out(true, "ok", $rptRepo->getHistoricalReport($filters));
    }

    if ($action === 'report_kpis') {
        transp_require($perms, 'canReports');

        $dateFrom = trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = trim($_GET['dateTo']   ?? date('Y-m-d'));
        json_out(true, "ok", $rptRepo->getKpis($dateFrom, $dateTo));
    }

    if ($action === 'report_rejections') {
        transp_require($perms, 'canReports');

        $dateFrom = trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = trim($_GET['dateTo']   ?? date('Y-m-d'));
        json_out(true, "ok", [
            'byCarrier' => $rptRepo->getInspectionRejectionsByCarrier($dateFrom, $dateTo),
            'detail'    => $insRepo->getRejectionReport($dateFrom, $dateTo),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // USERS & ROLES — who does each step (Administrator only)
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'roles_list') {
        transp_require($perms, 'canManageRoles');
        $stmt = sqlsrv_query($cn, "SELECT r.[Username], r.[Role], r.[AssignedBy], r.[AssignedAt], u.[Firstname], u.[Lastname]
                                   FROM [transp_UserRole] r
                                   LEFT JOIN [Users] u ON u.[Username] = r.[Username]
                                   ORDER BY r.[Username]");
        $users = [];
        while ($stmt && $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $u = &$users[$r['Username']];
            $u['Username'] = $r['Username'];
            $u['Name']     = trim(($r['Firstname'] ?? '') . ' ' . ($r['Lastname'] ?? ''));
            $u['Roles'][]  = $r['Role'];
            $at = $r['AssignedAt'] instanceof DateTime ? $r['AssignedAt']->format('Y-m-d H:i:s') : $r['AssignedAt'];
            if (empty($u['UpdatedAt']) || $at > $u['UpdatedAt']) { $u['UpdatedAt'] = $at; $u['UpdatedBy'] = $r['AssignedBy']; }
            unset($u);
        }
        foreach ($users as &$u) $u['Roles'] = array_values(array_intersect(TRANSP_ROLES, $u['Roles']));
        unset($u);
        json_out(true, "ok", ['users' => array_values($users), 'roles' => TRANSP_ROLES]);
    }

    if ($action === 'roles_search_users') {
        transp_require($perms, 'canManageRoles');
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) json_out(true, "ok", []);
        $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
        $stmt = sqlsrv_query($cn, "SELECT TOP 15 [Username], [Firstname], [Lastname] FROM [Users]
                                   WHERE ISNULL([IsActive], 1) = 1 AND [DeletedAt] IS NULL
                                     AND ([Username] LIKE ? OR ([Firstname] + ' ' + [Lastname]) LIKE ?)
                                   ORDER BY [Firstname], [Lastname]", [$like, $like]);
        $out = [];
        while ($stmt && $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = ['Username' => $r['Username'], 'Name' => trim($r['Firstname'] . ' ' . $r['Lastname'])];
        }
        json_out(true, "ok", $out);
    }

    if ($action === 'roles_set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        transp_require($perms, 'canManageRoles');

        $target = trim($_POST['Username'] ?? '');
        $new    = array_values(array_intersect(TRANSP_ROLES, array_filter(explode(',', (string)($_POST['Roles'] ?? '')))));
        if ($target === '') json_out(false, transp_t('api.required', ['field' => 'Username']), null, 422);

        $stmt = sqlsrv_query($cn, "SELECT TOP 1 1 FROM [Users] WHERE [Username] = ? AND [DeletedAt] IS NULL", [$target]);
        if (!$stmt || !sqlsrv_fetch_array($stmt)) json_out(false, transp_t('roles.err.userNotFound'), null, 404);
        // Never let an administrator lock themselves out
        if (strcasecmp($target, $username) === 0 && !in_array('ADMIN', $new, true)) {
            json_out(false, transp_t('roles.err.selfAdmin'), null, 422);
        }

        $old = [];
        $stmt = sqlsrv_query($cn, "SELECT [Role] FROM [transp_UserRole] WHERE [Username] = ?", [$target]);
        while ($stmt && $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $old[] = $r['Role'];

        if (!sqlsrv_begin_transaction($cn)) throw new Exception("Could not start transaction.");
        try {
            foreach (array_diff($old, $new) as $role) {
                if (!sqlsrv_query($cn, "DELETE FROM [transp_UserRole] WHERE [Username] = ? AND [Role] = ?", [$target, $role])) throw new Exception("Role delete failed.");
            }
            foreach (array_diff($new, $old) as $role) {
                if (!sqlsrv_query($cn, "INSERT INTO [transp_UserRole] ([Username],[Role],[AssignedBy]) VALUES (?,?,?)", [$target, $role, $username])) throw new Exception("Role insert failed.");
            }
            sqlsrv_query($cn, "INSERT INTO [transp_AuditLog] ([TruckID],[Action],[TableName],[RecordID],[OldData],[NewData],[ActionBy])
                               VALUES (NULL,'SET_ROLES','transp_UserRole',NULL,?,?,?)",
                         [json_encode(['user' => $target, 'roles' => $old]), json_encode(['user' => $target, 'roles' => $new]), $username]);
            sqlsrv_commit($cn);
        } catch (Throwable $e) {
            sqlsrv_rollback($cn);
            throw $e;
        }
        json_out(true, transp_t($new ? 'roles.saved' : 'roles.removed', ['user' => $target]), ['Username' => $target, 'Roles' => $new]);
    }

    json_out(false, transp_t('api.unknownAction'), ["action" => $action], 404);

} catch (Throwable $e) {
    json_out(false, $e->getMessage(), null, 500);
}
