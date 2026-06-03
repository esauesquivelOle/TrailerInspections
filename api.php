<?php
date_default_timezone_set('America/New_York');

require_once(__DIR__ . "/src/Support/json.php");
require_once(__DIR__ . "/src/Support/auth.php");
require_once(__DIR__ . "/src/Support/csrf.php");
require_once(__DIR__ . "/src/Support/db.php");
require_once(__DIR__ . "/src/Support/permissions.php");
require_once(__DIR__ . "/src/Repository/ScheduleRepository.php");
require_once(__DIR__ . "/src/Repository/TruckRepository.php");
require_once(__DIR__ . "/src/Repository/InspectionRepository.php");
require_once(__DIR__ . "/src/Repository/GateRepository.php");
require_once(__DIR__ . "/src/Repository/ReportRepository.php");

require_login_or_redirect();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $cn       = db_connect_sqlsrv();
    $schedRepo = new ScheduleRepository($cn);
    $truckRepo = new TruckRepository($cn);
    $insRepo   = new InspectionRepository($cn);
    $gateRepo  = new GateRepository($cn);
    $rptRepo   = new ReportRepository($cn);

    $username = current_username();
    $profile  = transp_get_profile($cn, $username);
    $perms    = transp_build_permissions($profile);
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
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $d = $_POST;
        if (empty($d['ExternalID'])) json_out(false, "ID de orden requerido.", null, 422);

        $id = $schedRepo->createOrder($d, $username);
        json_out(true, "Orden creada.", ["BiOrderID" => $id]);
    }

    if ($action === 'list_schedules') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        json_out(true, "ok", $schedRepo->listSchedules($date));
    }

    if ($action === 'create_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $d = $_POST;
        if (empty($d['CarrierName']))   json_out(false, "Nombre de transportista requerido.", null, 422);
        if (empty($d['ScheduleDate'])) json_out(false, "Fecha de schedule requerida.", null, 422);

        $id = $schedRepo->createSchedule($d, $username);
        json_out(true, "Schedule creado.", ["ScheduleID" => $id]);
    }

    if ($action === 'update_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $id = (int)($_POST['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, "ScheduleID requerido.", null, 422);
        if (empty($_POST['CarrierName'])) json_out(false, "Transportista requerido.", null, 422);

        $schedRepo->updateSchedule($id, $_POST, $username);
        json_out(true, "Schedule actualizado.");
    }

    if ($action === 'cancel_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $id = (int)($_POST['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, "ScheduleID requerido.", null, 422);

        $schedRepo->cancelSchedule($id, $username);
        json_out(true, "Schedule cancelado.");
    }

    if ($action === 'list_schedule_orders') {
        $id = (int)($_GET['ScheduleID'] ?? 0);
        if ($id <= 0) json_out(false, "ScheduleID requerido.", null, 422);
        json_out(true, "ok", $schedRepo->listScheduleOrders($id));
    }

    if ($action === 'add_order_to_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $schedId = (int)($_POST['ScheduleID'] ?? 0);
        if ($schedId <= 0) json_out(false, "ScheduleID requerido.", null, 422);

        $d   = $_POST;
        $id  = $schedRepo->addOrderToSchedule($schedId, $d, $username);
        json_out(true, "Orden agregada al schedule.", ["ScheduleOrderID" => $id]);
    }

    if ($action === 'remove_order_from_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $id = (int)($_POST['ScheduleOrderID'] ?? 0);
        if ($id <= 0) json_out(false, "ScheduleOrderID requerido.", null, 422);

        $schedRepo->removeOrderFromSchedule($id);
        json_out(true, "Orden eliminada del schedule.");
    }

    if ($action === 'update_order_pio' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $id  = (int)($_POST['ScheduleOrderID'] ?? 0);
        $pio = trim($_POST['PioNumber'] ?? '');
        if ($id <= 0) json_out(false, "ScheduleOrderID requerido.", null, 422);

        $schedRepo->updateOrderPioInSchedule($id, $pio, $username);
        json_out(true, "PIO actualizado.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD — Tablero en tiempo real
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'dashboard_data') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $trucks = $truckRepo->listActiveTrucks($date);
        $gates  = $gateRepo->listGatesWithStatus($date);
        json_out(true, "ok", ['trucks' => $trucks, 'gates' => $gates]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 1 — Registro del conductor
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'register_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canSecurity']) json_out(false, "Sin permisos.", null, 403);

        $d = $_POST;
        if (empty($d['TruckNumber']))  json_out(false, "Número de camión requerido.", null, 422);
        if (empty($d['CarrierName']))  json_out(false, "Transportista requerido.", null, 422);
        if (empty($d['DriverName']))   json_out(false, "Nombre del conductor requerido.", null, 422);

        // Validate schedule if provided
        if (!empty($d['ScheduleID'])) {
            $sched = $schedRepo->getSchedule((int)$d['ScheduleID']);
            if (!$sched || $sched['Status'] !== 'Active') {
                json_out(false, "Schedule inválido o cancelado.", null, 422);
            }
        }

        $truckId = $truckRepo->registerTruck($d, $username);
        $truckRepo->logAudit($truckId, 'REGISTER_TRUCK', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, "Conductor registrado. Camión en Fase 1.", ["TruckID" => $truckId]);
    }

    if ($action === 'advance_to_phase2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canSecurity']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);
        if ($truck['Status'] !== 'Phase1') json_out(false, "El camión no está en Fase 1.", null, 422);

        $truckRepo->advanceToPhase2($truckId, $username);
        $truckRepo->logAudit($truckId, 'ADVANCE_PHASE2', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, "Camión avanzado a Fase 2 — Inspección.");
    }

    if ($action === 'get_truck') {
        $truckId = (int)($_GET['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck       = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);

        $inspections = $insRepo->getInspections($truckId);
        $gate        = $gateRepo->getActiveAssignment($truckId);
        $gateHistory = $gateRepo->getAssignmentHistory($truckId);

        json_out(true, "ok", [
            'truck'       => $truck,
            'inspections' => $inspections,
            'gate'        => $gate,
            'gateHistory' => $gateHistory,
        ]);
    }

    if ($action === 'cancel_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['isAdmin'] && !$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $reason  = trim($_POST['Reason'] ?? '');
        if ($truckId <= 0)   json_out(false, "TruckID requerido.", null, 422);
        if ($reason === '')  json_out(false, "Motivo de cancelación requerido.", null, 422);

        $truckRepo->cancelTruck($truckId, $reason, $username);
        $truckRepo->logAudit($truckId, 'CANCEL_TRUCK', 'transp_Truck', $truckId, null, $reason, $username);
        json_out(true, "Camión cancelado.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 2 — Inspección del tráiler
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'submit_inspection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canInspect']) json_out(false, "Sin permisos para inspeccionar.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);
        if ($truck['Status'] !== 'Phase2') json_out(false, "El camión no está en Fase 2.", null, 422);

        $d      = $_POST;
        $pass   = (int)($d['CheckOdors']    ?? 0) &&
                  (int)($d['CheckHoles']    ?? 0) &&
                  (int)($d['CheckForeign']  ?? 0) &&
                  (int)($d['CheckChemicals']?? 0) &&
                  (int)($d['CheckFloor']    ?? 0);

        // Rejected requires comments
        if (!$pass && empty(trim($d['Comments'] ?? ''))) {
            json_out(false, "Se requiere comentario al rechazar la inspección.", null, 422);
        }

        $insId = $insRepo->submitInspection($truckId, $d, $username);
        $result = $pass ? 'APPROVED' : 'REJECTED';

        $truckRepo->logAudit($truckId, "INSPECTION_{$result}", 'transp_Inspection', $insId, null, null, $username);

        // If approved, advance truck to Phase 3 automatically
        if ($pass) {
            $truckRepo->advanceToPhase3($truckId, $username);
            $truckRepo->logAudit($truckId, 'ADVANCE_PHASE3', 'transp_Truck', $truckId, null, null, $username);
        }

        json_out(true, $pass ? "Inspección APROBADA. Camión listo para asignación de gate." : "Inspección RECHAZADA.",
            ["InspectionID" => $insId, "result" => $result]);
    }

    if ($action === 'get_inspections') {
        $truckId = (int)($_GET['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);
        json_out(true, "ok", $insRepo->getInspections($truckId));
    }

    // ─── Subir foto de evidencia ──────────────────────────────────────────────
    if ($action === 'upload_inspection_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canInspect']) json_out(false, "Sin permisos.", null, 403);

        $inspectionId = (int)($_POST['InspectionID'] ?? 0);
        $truckId      = (int)($_POST['TruckID']      ?? 0);
        if ($inspectionId <= 0) json_out(false, "InspectionID requerido.", null, 422);
        if ($truckId      <= 0) json_out(false, "TruckID requerido.", null, 422);

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            json_out(false, "No se recibió archivo o hubo un error al subir.", null, 422);
        }

        $file    = $_FILES['photo'];
        $maxSize = 15 * 1024 * 1024; // 15 MB
        if ($file['size'] > $maxSize) json_out(false, "La foto excede 15 MB.", null, 422);

        $allowed = ['jpg','jpeg','png','gif','webp','heic'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) json_out(false, "Tipo de archivo no permitido. Use JPG, PNG o GIF.", null, 422);

        $uploadDir  = 'E:/Atlanta/safety/inspections/';
        $stored     = 'INS_' . $inspectionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath   = $uploadDir . $stored;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            json_out(false, "No se pudo guardar el archivo.", null, 500);
        }

        $photoId = $insRepo->savePhoto($inspectionId, $truckId, [
            'FileName'       => substr($file['name'], 0, 490),
            'StoredFileName' => $stored,
            'FileSize'       => $file['size'],
            'Notes'          => substr(trim($_POST['Notes'] ?? ''), 0, 490),
        ], $username);

        $truckRepo->logAudit($truckId, 'UPLOAD_PHOTO', 'transp_InspectionPhoto', $photoId, null, null, $username);
        json_out(true, "Foto subida.", ["PhotoID" => $photoId, "FileName" => $file['name']]);
    }

    // ─── Descargar / ver foto ─────────────────────────────────────────────────
    if ($action === 'view_inspection_photo') {
        $photoId = (int)($_GET['PhotoID'] ?? 0);
        if ($photoId <= 0) { http_response_code(400); exit('ID inválido.'); }

        $photo = $insRepo->getPhotoById($photoId);
        if (!$photo) { http_response_code(404); exit('Foto no encontrada.'); }

        $path = 'E:/Atlanta/safety/inspections/' . $photo['StoredFileName'];
        if (!file_exists($path)) { http_response_code(404); exit('Archivo no encontrado.'); }

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
        if (!$perms['canSecurity']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $zone    = trim($_POST['WaitingZone']  ?? '');
        $notes   = trim($_POST['WaitingNotes'] ?? '');
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);
        if ($zone === '') json_out(false, "Indica la zona de espera/reparación.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck || $truck['Status'] !== 'Phase2') {
            json_out(false, "El camión debe estar en Fase 2.", null, 422);
        }

        $truckRepo->sendToWaitingZone($truckId, $zone, $notes, $username);
        $truckRepo->logAudit($truckId, 'SENT_TO_WAITING', 'transp_Truck', $truckId, null,
            json_encode(['zone' => $zone, 'notes' => $notes]), $username);
        json_out(true, "Camión enviado a zona de espera: {$zone}.");
    }

    // ─── Llamar de regreso para re-inspección ─────────────────────────────────
    if ($action === 'recall_from_waiting' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canSecurity']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck || $truck['Status'] !== 'Phase2_Waiting') {
            json_out(false, "El camión no está en zona de espera.", null, 422);
        }

        $truckRepo->recallFromWaiting($truckId, $username);
        $truckRepo->logAudit($truckId, 'RECALLED_FROM_WAITING', 'transp_Truck', $truckId, null, null, $username);
        json_out(true, "Camión llamado de regreso para re-inspección.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 3 — Asignación de gate y carga
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'list_gates') {
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        json_out(true, "ok", $gateRepo->listGatesWithStatus($date));
    }

    if ($action === 'assign_gate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $gateId  = (int)($_POST['GateID']  ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);
        if ($gateId  <= 0) json_out(false, "GateID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);
        if ($truck['Status'] !== 'Phase3') json_out(false, "Camión no está en Fase 3.", null, 422);

        // Must have approved inspection
        if (!$insRepo->hasApproved($truckId)) {
            json_out(false, "El tráiler debe tener una inspección APROBADA.", null, 422);
        }

        $assignId = $gateRepo->assignGate($truckId, $gateId, $username);
        $truckRepo->logAudit($truckId, 'ASSIGN_GATE', 'transp_GateAssignment', $assignId, null, json_encode(['GateID' => $gateId]), $username);
        json_out(true, "Gate asignado.", ["AssignmentID" => $assignId]);
    }

    if ($action === 'reassign_gate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $gateId  = (int)($_POST['GateID']  ?? 0);
        $reason  = trim($_POST['Reason']   ?? '');
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);
        if ($gateId  <= 0) json_out(false, "GateID requerido.", null, 422);
        if ($reason === '') json_out(false, "Motivo de reasignación requerido.", null, 422);

        $assignId = $gateRepo->assignGate($truckId, $gateId, $username);
        $truckRepo->logAudit($truckId, 'REASSIGN_GATE', 'transp_GateAssignment', $assignId, null, json_encode(['GateID' => $gateId, 'Reason' => $reason]), $username);
        json_out(true, "Gate reasignado.", ["AssignmentID" => $assignId]);
    }

    if ($action === 'confirm_gate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canShipping']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $gateRepo->confirmGate($truckId, $username);
        $truckRepo->logAudit($truckId, 'CONFIRM_GATE', 'transp_GateAssignment', $truckId, null, null, $username);
        json_out(true, "Gate confirmado por Shipping.");
    }

    if ($action === 'validate_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canShipping']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $gateRepo->validateOrders($truckId, $username);
        $truckRepo->logAudit($truckId, 'VALIDATE_ORDERS', 'transp_GateAssignment', $truckId, null, null, $username);
        json_out(true, "Órdenes validadas por Shipping.");
    }

    if ($action === 'close_loading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canShipping']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);
        if ($truck['Status'] !== 'Phase3') json_out(false, "Camión no está en Fase 3.", null, 422);

        $gateRepo->closeLoading($truckId, $username);
        $truckRepo->advanceToPhase4($truckId, $username);
        $truckRepo->logAudit($truckId, 'CLOSE_LOADING', 'transp_GateAssignment', $truckId, null, null, $username);
        json_out(true, "Carga cerrada. Camión en Fase 4 — Documentación.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FASE 4 — Cierre y documentación
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'close_truck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate_or_fail();
        if (!$perms['canLogistica']) json_out(false, "Sin permisos.", null, 403);

        $truckId = (int)($_POST['TruckID'] ?? 0);
        $notes   = trim($_POST['Notes']   ?? '');
        if ($truckId <= 0) json_out(false, "TruckID requerido.", null, 422);

        $truck = $truckRepo->getTruck($truckId);
        if (!$truck) json_out(false, "Camión no encontrado.", null, 404);
        if ($truck['Status'] !== 'Phase4') json_out(false, "Camión no está en Fase 4.", null, 422);

        $truckRepo->closeTruck($truckId, $notes, $username);
        $truckRepo->logAudit($truckId, 'CLOSE_TRUCK', 'transp_Truck', $truckId, null, $notes ?: null, $username);
        json_out(true, "Camión completado. Ciclo de vida cerrado.");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // REPORTES
    // ═══════════════════════════════════════════════════════════════════════

    if ($action === 'report_history') {
        if (!$perms['canLogistica'] && !$perms['isAdmin']) json_out(false, "Sin permisos.", null, 403);

        $filters = [
            'dateFrom' => trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days'))),
            'dateTo'   => trim($_GET['dateTo']   ?? date('Y-m-d')),
            'carrier'  => trim($_GET['carrier']  ?? ''),
            'status'   => trim($_GET['status']   ?? ''),
        ];
        json_out(true, "ok", $rptRepo->getHistoricalReport($filters));
    }

    if ($action === 'report_kpis') {
        if (!$perms['canLogistica'] && !$perms['isAdmin']) json_out(false, "Sin permisos.", null, 403);

        $dateFrom = trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = trim($_GET['dateTo']   ?? date('Y-m-d'));
        json_out(true, "ok", $rptRepo->getKpis($dateFrom, $dateTo));
    }

    if ($action === 'report_rejections') {
        if (!$perms['canLogistica'] && !$perms['isAdmin']) json_out(false, "Sin permisos.", null, 403);

        $dateFrom = trim($_GET['dateFrom'] ?? date('Y-m-d', strtotime('-30 days')));
        $dateTo   = trim($_GET['dateTo']   ?? date('Y-m-d'));
        json_out(true, "ok", [
            'byCarrier' => $rptRepo->getInspectionRejectionsByCarrier($dateFrom, $dateTo),
            'detail'    => $insRepo->getRejectionReport($dateFrom, $dateTo),
        ]);
    }

    json_out(false, "Acción desconocida.", ["action" => $action], 404);

} catch (Throwable $e) {
    json_out(false, $e->getMessage(), null, 500);
}
