<?php
date_default_timezone_set('America/New_York');
require_once(__DIR__ . "/src/Support/auth.php");
require_once(__DIR__ . "/src/Support/csrf.php");
require_once(__DIR__ . "/src/Support/db.php");
require_once(__DIR__ . "/src/Support/permissions.php");

require_login_or_redirect();

$csrfToken = csrf_token();
$fullname  = current_user_fullname();
$username  = current_username();

try {
    $cn      = db_connect_sqlsrv();
    $profile = transp_get_profile($cn, $username);
    $perms   = transp_build_permissions($profile);
} catch (Throwable $e) {
    $perms = transp_build_permissions('');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Módulo de Transportación</title>

    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.10.21/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="assets/css/transportation.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" style="width:3rem;height:3rem;" role="status"></div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- ═══ TOP NAV ═════════════════════════════════════════════════════════ -->
<nav class="navbar navbar-expand navbar-dark bg-primary py-1 px-3">
    <span class="navbar-brand mb-0 h6 font-weight-bold">
        <i class="fas fa-truck-moving mr-2"></i>Módulo de Transportación
    </span>
    <div class="navbar-nav ml-auto">
        <span class="navbar-text small text-white-50 mr-3">
            <i class="fas fa-user mr-1"></i><?= htmlspecialchars($fullname ?: $username) ?>
            <?php if ($profile): ?>
                <span class="badge badge-light ml-1"><?= htmlspecialchars($profile) ?></span>
            <?php endif; ?>
        </span>
        <button class="btn btn-outline-light btn-sm nav-btn active" id="btnNavDashboard" title="Dashboard">
            <i class="fas fa-th-large"></i>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavSchedules" title="Schedules">
            <i class="fas fa-calendar-alt"></i>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavReports" title="Reportes">
            <i class="fas fa-chart-bar"></i>
        </button>
    </div>
</nav>

<div class="container-fluid py-3">

<!-- ═══════════════════════════════════════════════════════════════════
     VISTA: DASHBOARD (Semáforo)
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewDashboard">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0"><i class="fas fa-traffic-light text-success mr-2"></i>Tablero de Operaciones</h5>
            <small class="text-muted" id="dashboardDateLabel"></small>
        </div>
        <div class="d-flex align-items-center">
            <input type="date" id="dashDate" class="form-control form-control-sm mr-2" style="width:160px;">
            <button class="btn btn-outline-secondary btn-sm mr-2" id="btnRefreshDashboard" title="Actualizar">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button class="btn btn-primary btn-sm" id="btnNewTruck">
                <i class="fas fa-plus mr-1"></i>Registrar Camión
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row mb-3">
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-primary p-3">
                <div class="kpi-num text-primary" id="kpiTotal">0</div>
                <div class="kpi-lbl">Total Activos</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-warning p-3">
                <div class="kpi-num text-warning" id="kpiPhase1">0</div>
                <div class="kpi-lbl">Fase 1 — Check-in</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-info p-3">
                <div class="kpi-num text-info" id="kpiPhase2">0</div>
                <div class="kpi-lbl">Fase 2 — Inspección</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-success p-3">
                <div class="kpi-num text-success" id="kpiPhase34">0</div>
                <div class="kpi-lbl">Fase 3/4 — Carga</div>
            </div>
        </div>
    </div>

    <!-- Truck Grid -->
    <div id="truckGrid" class="row"></div>
    <div id="noTrucks" class="text-center text-muted py-5" style="display:none;">
        <i class="fas fa-truck fa-3x mb-3 d-block"></i>
        <p>No hay camiones activos para esta fecha.</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VISTA: DETALLE DE CAMIÓN (Fases 1–4)
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewTruck" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <button class="btn btn-outline-secondary btn-sm mr-2" id="btnBackDashboard">
                <i class="fas fa-arrow-left mr-1"></i>Volver
            </button>
            <strong id="truckTitle">Camión</strong>
            <span id="truckStatusBadge" class="ml-2"></span>
        </div>
        <button class="btn btn-outline-danger btn-sm" id="btnCancelTruck">
            <i class="fas fa-ban mr-1"></i>Cancelar
        </button>
    </div>

    <!-- Phase Progress -->
    <div class="phase-progress mb-4">
        <div class="phase-step" id="pStep1"><span class="step-num">1</span><span class="step-lbl d-none d-md-inline">Check-in</span></div>
        <div class="phase-connector" id="pConn1"></div>
        <div class="phase-step" id="pStep2"><span class="step-num">2</span><span class="step-lbl d-none d-md-inline">Inspección</span></div>
        <div class="phase-connector" id="pConn2"></div>
        <div class="phase-step" id="pStep3"><span class="step-num">3</span><span class="step-lbl d-none d-md-inline">En Carga</span></div>
        <div class="phase-connector" id="pConn3"></div>
        <div class="phase-step" id="pStep4"><span class="step-num">4</span><span class="step-lbl d-none d-md-inline">Documentación</span></div>
    </div>

    <!-- Info Summary -->
    <div class="card p-3 mb-3">
        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-info-circle mr-1 text-primary"></i>Información del Camión</h6>
        <div class="row small" id="truckInfoSummary"></div>
    </div>

    <!-- FASE 1 Panel -->
    <div id="truckPhase1Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-warning">
            <i class="fas fa-user-check mr-1"></i>Fase 1 — Recibimiento del Conductor
        </h6>
        <div class="alert alert-warning small mb-3">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Verificar que el camión tiene schedule programado para hoy. Si no tiene schedule, el camión no puede avanzar.
        </div>
        <div id="scheduleValidationMsg" class="mb-3"></div>
        <button class="btn btn-warning btn-sm" id="btnAdvancePhase2">
            <i class="fas fa-arrow-right mr-1"></i>Avanzar a Inspección (Fase 2)
        </button>
    </div>

    <!-- FASE 2 Panel -->
    <div id="truckPhase2Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-info">
            <i class="fas fa-clipboard-check mr-1"></i>Fase 2 — Inspección del Tráiler
        </h6>

        <div id="inspectionHistory" class="mb-3"></div>

        <div class="card bg-light p-3">
            <h6 class="mb-3 text-dark">Nueva Inspección</h6>
            <div class="inspection-checklist mb-3">
                <div class="check-item" id="chkOdors" data-field="CheckOdors">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label">Sin olores extraños (químicos, detergentes, combustible)</div>
                </div>
                <div class="check-item" id="chkHoles" data-field="CheckHoles">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label">Sin hoyos en piso, techo o paredes</div>
                </div>
                <div class="check-item" id="chkForeign" data-field="CheckForeign">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label">Sin materiales extraños ni residuos dentro del tráiler</div>
                </div>
                <div class="check-item" id="chkChemicals" data-field="CheckChemicals">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label">Sin residuos químicos</div>
                </div>
                <div class="check-item" id="chkFloor" data-field="CheckFloor">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label">Piso completamente limpio y seco</div>
                </div>
            </div>
            <div class="mb-3">
                <label class="small font-weight-bold">
                    Comentarios / Observaciones
                    <span class="text-danger small" id="commentsRequired">(requerido si hay rechazos)</span>
                </label>
                <textarea id="inpInspectionComments" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <button class="btn btn-info btn-sm" id="btnSubmitInspection">
                <i class="fas fa-save mr-1"></i>Registrar Inspección
            </button>
        </div>

        <!-- Shown by JS when last inspection was rejected -->
        <div id="phase2WaitingAction" class="mt-3" style="display:none;">
            <div class="alert alert-danger small mb-2">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                La última inspección fue rechazada. Puedes enviar el tráiler a la zona de espera para su reparación antes de una nueva inspección.
            </div>
            <button class="btn btn-warning btn-sm" id="btnSendToWaiting">
                <i class="fas fa-tools mr-1"></i>Enviar a Zona de Espera / Reparación
            </button>
        </div>
    </div>

    <!-- FASE 2 — Zona de Espera Panel -->
    <div id="truckPhase2WaitingPanel" class="card p-3 mb-3 border-warning" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3" style="color:#d68910;">
            <i class="fas fa-tools mr-1"></i>Tráiler en Zona de Espera / Reparación
        </h6>
        <div id="waitingZoneInfo" class="mb-3"></div>
        <div id="waitingInspectionHistory" class="mb-3"></div>
        <button class="btn btn-info btn-sm" id="btnRecallFromWaiting">
            <i class="fas fa-redo mr-1"></i>Llamar para Re-inspección
        </button>
    </div>

    <!-- FASE 3 Panel -->
    <div id="truckPhase3Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-success">
            <i class="fas fa-warehouse mr-1"></i>Fase 3 — Gate y Carga
        </h6>

        <div class="row mb-3">
            <div class="col-md-5 mb-2">
                <div class="gate-display card border-success p-3 text-center">
                    <div class="small text-muted mb-1">Gate Asignado</div>
                    <h2 class="text-success mb-1" id="currentGateDisplay">—</h2>
                    <div class="small text-muted" id="gateAssignedBy"></div>
                </div>
            </div>
            <div class="col-md-7 mb-2">
                <div class="card p-3 h-100">
                    <div class="status-row mb-2">
                        <span class="status-icon" id="iconGateConfirmed"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small">Gate confirmado por Shipping</span>
                        <span class="small text-muted ml-auto" id="gateConfirmedInfo"></span>
                    </div>
                    <div class="status-row mb-2">
                        <span class="status-icon" id="iconOrdersValidated"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small">Órdenes validadas</span>
                        <span class="small text-muted ml-auto" id="ordersValidatedInfo"></span>
                    </div>
                    <div class="status-row">
                        <span class="status-icon" id="iconLoadingClosed"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small">Carga cerrada</span>
                        <span class="small text-muted ml-auto" id="loadingClosedInfo"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-light p-3 mb-3">
            <h6 class="mb-2">Asignar / Reasignar Gate</h6>
            <div class="row align-items-end">
                <div class="col-md-4 mb-2">
                    <label class="small font-weight-bold">Gate</label>
                    <select id="selGate" class="form-control form-control-sm">
                        <option value="">Seleccionar gate...</option>
                    </select>
                </div>
                <div class="col-md-5 mb-2">
                    <label class="small font-weight-bold">Motivo reasignación (si aplica)</label>
                    <input type="text" id="inpGateReason" class="form-control form-control-sm">
                </div>
                <div class="col-md-3 mb-2">
                    <button class="btn btn-success btn-sm btn-block" id="btnAssignGate">
                        <i class="fas fa-map-marker-alt mr-1"></i>Asignar
                    </button>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap">
            <button class="btn btn-outline-success btn-sm mr-2 mb-2" id="btnConfirmGate">
                <i class="fas fa-check mr-1"></i>Confirmar en Gate
            </button>
            <button class="btn btn-outline-primary btn-sm mr-2 mb-2" id="btnValidateOrders">
                <i class="fas fa-tasks mr-1"></i>Validar Órdenes
            </button>
            <button class="btn btn-success btn-sm mb-2" id="btnCloseLoading">
                <i class="fas fa-lock mr-1"></i>Cerrar Carga
            </button>
        </div>

        <div id="gateHistorySection" class="mt-3" style="display:none;">
            <h6 class="small font-weight-bold text-muted">Historial de Gates</h6>
            <div id="gateHistory"></div>
        </div>
    </div>

    <!-- FASE 4 Panel -->
    <div id="truckPhase4Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-secondary">
            <i class="fas fa-file-alt mr-1"></i>Fase 4 — Cierre y Documentación
        </h6>
        <div class="alert alert-info small">
            <i class="fas fa-info-circle mr-1"></i>
            Logística entrega la documentación al conductor y cierra el ciclo de vida del camión.
        </div>
        <label class="small font-weight-bold">Notas de documentación / cierre</label>
        <textarea id="inpClosureNotes" class="form-control form-control-sm mb-3" rows="3"
            placeholder="Documentos entregados, observaciones finales..."></textarea>
        <button class="btn btn-secondary btn-sm" id="btnCloseTruck">
            <i class="fas fa-flag-checkered mr-1"></i>Liberar Camión
        </button>
    </div>

    <!-- Completed Panel -->
    <div id="truckCompletedPanel" class="card p-4 mb-3 border-success text-center" style="display:none;">
        <i class="fas fa-check-circle fa-3x text-success mb-2 d-block"></i>
        <h5 class="text-success">Ciclo Completado</h5>
        <p class="text-muted small mb-0" id="completedInfo"></p>
    </div>

    <!-- Cancelled Panel -->
    <div id="truckCancelledPanel" class="card p-4 mb-3 border-danger text-center" style="display:none;">
        <i class="fas fa-ban fa-3x text-danger mb-2 d-block"></i>
        <h5 class="text-danger">Camión Cancelado</h5>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VISTA: SCHEDULES
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewSchedules" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fas fa-calendar-alt text-primary mr-2"></i>Gestión de Schedules</h5>
        <div class="d-flex align-items-center">
            <input type="date" id="schedDate" class="form-control form-control-sm mr-2" style="width:160px;">
            <button class="btn btn-primary btn-sm" id="btnNewSchedule">
                <i class="fas fa-plus mr-1"></i>Nuevo Schedule
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card p-3 mb-3">
                <h6 class="border-bottom pb-2 mb-3">Schedules del Día</h6>
                <div id="scheduleList"><p class="text-muted small text-center py-3">Selecciona una fecha.</p></div>
            </div>
        </div>
        <div class="col-md-7">
            <div id="scheduleDetailPanel" class="card p-3 mb-3" style="display:none;">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                    <h6 class="mb-0" id="schedDetailTitle">Schedule</h6>
                    <div>
                        <button class="btn btn-outline-primary btn-sm mr-1" id="btnEditSchedule">
                            <i class="fas fa-edit mr-1"></i>Editar
                        </button>
                        <button class="btn btn-outline-danger btn-sm" id="btnCancelSchedule">
                            <i class="fas fa-ban mr-1"></i>Cancelar
                        </button>
                    </div>
                </div>
                <div id="schedDetailInfo" class="small mb-3"></div>
                <h6 class="mb-2 small font-weight-bold">
                    <i class="fas fa-box mr-1"></i>Órdenes Asignadas
                </h6>
                <div id="schedOrderList" class="mb-3"></div>
                <button class="btn btn-outline-primary btn-sm" id="btnAddOrder">
                    <i class="fas fa-plus mr-1"></i>Agregar Orden
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VISTA: REPORTES
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewReports" style="display:none;">
    <h5 class="mb-3"><i class="fas fa-chart-bar text-primary mr-2"></i>Reportes Históricos</h5>

    <div class="card p-3 mb-3">
        <div class="row align-items-end">
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Desde</label>
                <input type="date" id="rptDateFrom" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Hasta</label>
                <input type="date" id="rptDateTo" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 mb-2">
                <label class="small font-weight-bold">Transportista</label>
                <input type="text" id="rptCarrier" class="form-control form-control-sm" placeholder="Buscar...">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Estatus</label>
                <select id="rptStatus" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="Completed">Completados</option>
                    <option value="Cancelled">Cancelados</option>
                    <option value="Phase1">Fase 1</option>
                    <option value="Phase2">Fase 2</option>
                    <option value="Phase3">Fase 3</option>
                    <option value="Phase4">Fase 4</option>
                </select>
            </div>
            <div class="col-md-3 mb-2 d-flex align-items-end">
                <button class="btn btn-primary btn-sm mr-2" id="btnLoadReport">
                    <i class="fas fa-search mr-1"></i>Buscar
                </button>
                <button class="btn btn-outline-success btn-sm" id="btnExportExcel">
                    <i class="fas fa-file-excel mr-1"></i>Excel
                </button>
            </div>
        </div>
    </div>

    <div class="row mb-3" id="rptKpis" style="display:none;">
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num" id="rKpiTotal">0</div>
                <div class="kpi-lbl">Total Camiones</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-success" id="rKpiCompleted">0</div>
                <div class="kpi-lbl">Completados</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-danger" id="rKpiRejections">0</div>
                <div class="kpi-lbl">Inspecciones Rechazadas</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-info" id="rKpiAvgIns">0</div>
                <div class="kpi-lbl">Prom. Inspecciones/Camión</div>
            </div>
        </div>
    </div>

    <div class="card p-3">
        <table id="reportTable" class="table table-sm table-hover" style="width:100%">
            <thead>
                <tr>
                    <th>Fecha</th><th>Camión</th><th>Tráiler</th><th>Transportista</th>
                    <th>Conductor</th><th>Gate</th><th>Inspecciones</th><th>Rechazos</th>
                    <th>Estatus</th><th>Entrada</th><th>Cierre</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

</div><!-- /container-fluid -->

<!-- ══════ MODALS ═══════════════════════════════════════════════════════ -->

<!-- New Truck Modal -->
<div class="modal fade" id="modalNewTruck" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title"><i class="fas fa-truck mr-1"></i>Registrar Camión — Fase 1</h6>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Número de Camión *</label>
                        <input type="text" id="ntTruckNumber" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Número de Tráiler</label>
                        <input type="text" id="ntTrailerNumber" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Placas</label>
                        <input type="text" id="ntLicensePlate" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Transportista *</label>
                        <input type="text" id="ntCarrierName" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Nombre del Conductor *</label>
                        <input type="text" id="ntDriverName" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Licencia</label>
                        <input type="text" id="ntDriverLicense" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Teléfono</label>
                        <input type="text" id="ntDriverPhone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Schedule Programado</label>
                        <select id="ntScheduleID" class="form-control form-control-sm">
                            <option value="">Sin schedule asignado</option>
                        </select>
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Notas</label>
                        <textarea id="ntNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveNewTruck">
                    <i class="fas fa-save mr-1"></i>Registrar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- New / Edit Schedule Modal -->
<div class="modal fade" id="modalSchedule" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title" id="modalScheduleTitle"><i class="fas fa-calendar-plus mr-1"></i>Nuevo Schedule</h6>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="nsScheduleID">
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Transportista *</label>
                        <input type="text" id="nsCarrier" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Tipo de Camión</label>
                        <input type="text" id="nsTruckType" class="form-control form-control-sm" placeholder="53ft, Refrigerado...">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="small font-weight-bold">Hora Inicio</label>
                        <input type="time" id="nsWindowStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="small font-weight-bold">Hora Fin</label>
                        <input type="time" id="nsWindowEnd" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Notas</label>
                        <textarea id="nsNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveSchedule">
                    <i class="fas fa-save mr-1"></i>Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Order Modal -->
<div class="modal fade" id="modalAddOrder" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title"><i class="fas fa-box mr-1"></i>Agregar Orden al Schedule</h6>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">ID de Orden</label>
                        <input type="text" id="aoOrderID" class="form-control form-control-sm" placeholder="Ej. WMS-20260429-001">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">Número PIO <span class="text-danger">*</span></label>
                        <input type="text" id="aoPio" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold">% Avance</label>
                        <input type="number" id="aoProgress" class="form-control form-control-sm" min="0" max="100">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold">Notas</label>
                        <textarea id="aoNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="alert alert-warning small mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Solo se pueden programar órdenes con número PIO registrado.
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveOrder">
                    <i class="fas fa-save mr-1"></i>Agregar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Truck Modal -->
<div class="modal fade" id="modalCancelTruck" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title"><i class="fas fa-ban mr-1"></i>Cancelar Camión</h6>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <label class="small font-weight-bold">Motivo de cancelación *</label>
                <textarea id="cancelReason" class="form-control form-control-sm" rows="3"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Volver</button>
                <button type="button" class="btn btn-danger btn-sm" id="btnConfirmCancelTruck">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- Waiting Zone Modal -->
<div class="modal fade" id="modalWaitingZone" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning py-2">
                <h6 class="modal-title"><i class="fas fa-tools mr-1"></i>Enviar a Zona de Espera</h6>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <label class="small font-weight-bold">Zona / Área *</label>
                <input type="text" id="wzZoneName" class="form-control form-control-sm mb-2"
                    placeholder="Ej. Área de reparación A, Bahía 3...">
                <label class="small font-weight-bold">Notas adicionales</label>
                <textarea id="wzNotes" class="form-control form-control-sm" rows="2"
                    placeholder="Problema encontrado, instrucciones..."></textarea>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning btn-sm" id="btnConfirmWaitingZone">
                    <i class="fas fa-arrow-right mr-1"></i>Confirmar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JS Dependencies -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.21/js/dataTables.bootstrap4.min.js"></script>

<script>
window.TRANSP_PERMS = <?= json_encode($perms, JSON_UNESCAPED_UNICODE) ?>;
window.TRANSP_USER  = <?= json_encode(['username' => $username, 'fullname' => htmlspecialchars($fullname ?: $username)]) ?>;
</script>
<script src="assets/js/transportation.js"></script>

</body>
</html>
