<?php
date_default_timezone_set('America/New_York');
require_once(__DIR__ . "/src/Support/auth.php");
require_once(__DIR__ . "/src/Support/i18n.php");
require_once(__DIR__ . "/src/Support/csrf.php");
require_once(__DIR__ . "/src/Support/db.php");
require_once(__DIR__ . "/src/Support/permissions.php");

require_login_or_redirect();

$lang      = transp_lang();
$csrfToken = csrf_token();
$fullname  = current_user_fullname();
$username  = current_username();
$roles     = [];

try {
    $cn    = db_connect_sqlsrv();
    $roles = transp_get_roles($cn, $username);
} catch (Throwable $e) {
    $roles = [];
}
$perms = transp_build_permissions($roles);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?php transp_e('app.title') ?></title>

    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.10.21/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="assets/css/transportation.css?v=<?= filemtime(__DIR__ . '/assets/css/transportation.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" style="width:3rem;height:3rem;" role="status">
        <span class="sr-only"><?php transp_e('common.loading') ?></span>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- ═══ TOP NAV ═════════════════════════════════════════════════════════ -->
<nav class="navbar navbar-expand navbar-dark ocds-navbar py-1 px-3 flex-wrap">
    <span class="navbar-brand mb-0 h6 font-weight-bold">
        <i class="fas fa-truck-moving mr-2"></i><?php transp_e('app.title') ?>
    </span>
    <div class="navbar-nav ml-auto align-items-center flex-wrap">
        <span class="navbar-text small text-white-50 mr-3">
            <i class="fas fa-user mr-1"></i><?= htmlspecialchars($fullname ?: $username) ?>
            <?php if ($roles): ?>
                <?php foreach ($roles as $r): ?>
                <span class="badge role-badge role-<?= strtolower($r) ?> ml-1" title="<?php transp_e('roleDesc.' . $r) ?>"><?php transp_e('role.' . $r) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="badge role-badge role-viewer ml-1"><i class="fas fa-eye mr-1"></i><?php transp_e('lock.viewer') ?></span>
            <?php endif; ?>
        </span>
        <button class="btn btn-outline-light btn-sm nav-btn active" id="btnNavDashboard" title="<?php transp_e('nav.dashboard') ?>">
            <i class="fas fa-th-large"></i><span class="d-none d-lg-inline ml-1"><?php transp_e('nav.dashboard') ?></span>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavSchedules" title="<?php transp_e('nav.schedules') ?>">
            <i class="fas fa-calendar-alt"></i><span class="d-none d-lg-inline ml-1"><?php transp_e('nav.schedules') ?></span>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavWarehouse" title="<?php transp_e('nav.warehouse') ?>">
            <i class="fas fa-warehouse"></i><span class="d-none d-lg-inline ml-1"><?php transp_e('nav.warehouse') ?></span>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavReports" title="<?php transp_e('nav.reports') ?>" data-perm="canReports" data-perm-mode="hide">
            <i class="fas fa-chart-bar"></i><span class="d-none d-lg-inline ml-1"><?php transp_e('nav.reports') ?></span>
        </button>
        <button class="btn btn-outline-light btn-sm nav-btn ml-1" id="btnNavRoles" title="<?php transp_e('nav.roles') ?>" data-perm="canManageRoles" data-perm-mode="hide">
            <i class="fas fa-user-shield"></i><span class="d-none d-xl-inline ml-1"><?php transp_e('nav.roles') ?></span>
        </button>
        <span class="live-indicator ml-3" id="liveIndicator" title="<?php transp_e('sync.liveTitle') ?>">
            <span class="live-dot"></span><span class="d-none d-md-inline"><?php transp_e('sync.live') ?></span>
        </span>
        <div class="btn-group btn-group-sm ml-2 lang-switch" role="group" aria-label="<?php transp_e('lang.label') ?>">
            <a href="?lang=en" data-lang="en" class="btn btn-sm <?= $lang === 'en' ? 'btn-light' : 'btn-outline-light' ?>" title="English">EN</a>
            <a href="?lang=es" data-lang="es" class="btn btn-sm <?= $lang === 'es' ? 'btn-light' : 'btn-outline-light' ?>" title="Español">ES</a>
        </div>
    </div>
</nav>

<div class="container-fluid py-3">

<?php if (!$roles): ?>
<div class="lock-banner lock-banner-page mb-3"><i class="fas fa-lock mr-2"></i><?php transp_e('lock.viewOnly') ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: DASHBOARD
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewDashboard">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="mb-2">
            <h5 class="mb-0"><i class="fas fa-traffic-light text-success mr-2"></i><?php transp_e('dash.title') ?></h5>
            <small class="text-muted" id="dashboardDateLabel"></small>
            <small class="text-muted ml-2" title="<?php transp_e('dash.autoRefresh') ?>"><i class="fas fa-sync-alt fa-xs"></i> 30s</small>
        </div>
        <div class="d-flex align-items-center mb-2">
            <input type="date" id="dashDate" class="form-control form-control-sm mr-2" style="width:160px;">
            <button class="btn btn-outline-secondary btn-sm mr-2" id="btnRefreshDashboard" title="<?php transp_e('common.refresh') ?>">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button class="btn btn-primary btn-sm" id="btnNewTruck" data-perm="canSecurity">
                <i class="fas fa-plus mr-1"></i><?php transp_e('dash.newTruck') ?>
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row mb-3">
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-primary p-3">
                <div class="kpi-num text-primary" id="kpiTotal">0</div>
                <div class="kpi-lbl"><?php transp_e('dash.kpiTotal') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-warning p-3">
                <div class="kpi-num text-warning" id="kpiPhase1">0</div>
                <div class="kpi-lbl"><?php transp_e('dash.kpiPhase1') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-info p-3">
                <div class="kpi-num text-info" id="kpiPhase2">0</div>
                <div class="kpi-lbl"><?php transp_e('dash.kpiPhase2') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card border-left-success p-3">
                <div class="kpi-num text-success" id="kpiPhase34">0</div>
                <div class="kpi-lbl"><?php transp_e('dash.kpiPhase34') ?></div>
            </div>
        </div>
    </div>

    <!-- Truck Grid -->
    <div id="truckGrid" class="row"></div>
    <div id="noTrucks" class="text-center text-muted py-5" style="display:none;">
        <i class="fas fa-truck fa-3x mb-3 d-block"></i>
        <p><?php transp_e('dash.noTrucks') ?></p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: TRUCK DETAIL (Phases 1–4)
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewTruck" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <button class="btn btn-outline-secondary btn-sm mr-2" id="btnBackDashboard">
                <i class="fas fa-arrow-left mr-1"></i><?php transp_e('common.back') ?>
            </button>
            <strong id="truckTitle"></strong>
            <span id="truckStatusBadge" class="ml-2"></span>
        </div>
        <button class="btn btn-outline-danger btn-sm" id="btnCancelTruck" data-perm="canLogistica">
            <i class="fas fa-ban mr-1"></i><?php transp_e('truck.cancel') ?>
        </button>
    </div>

    <!-- Phase Progress -->
    <div class="phase-progress mb-4">
        <div class="phase-step" id="pStep1"><span class="step-num">1</span><span class="step-lbl d-none d-md-inline"><?php transp_e('step.1') ?></span></div>
        <div class="phase-connector" id="pConn1"></div>
        <div class="phase-step" id="pStep2"><span class="step-num">2</span><span class="step-lbl d-none d-md-inline"><?php transp_e('step.2') ?></span></div>
        <div class="phase-connector" id="pConn2"></div>
        <div class="phase-step" id="pStep3"><span class="step-num">3</span><span class="step-lbl d-none d-md-inline"><?php transp_e('step.3') ?></span></div>
        <div class="phase-connector" id="pConn3"></div>
        <div class="phase-step" id="pStep4"><span class="step-num">4</span><span class="step-lbl d-none d-md-inline"><?php transp_e('step.4') ?></span></div>
    </div>

    <!-- Info Summary -->
    <div class="card p-3 mb-3">
        <h6 class="border-bottom pb-2 mb-3"><i class="fas fa-info-circle mr-1 text-primary"></i><?php transp_e('truck.info') ?></h6>
        <div class="row small" id="truckInfoSummary"></div>
    </div>

    <!-- PHASE 1 Panel -->
    <div id="truckPhase1Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-warning">
            <i class="fas fa-user-check mr-1"></i><?php transp_e('p1.title') ?>
        </h6>
        <div class="alert alert-warning small mb-3">
            <i class="fas fa-exclamation-triangle mr-1"></i><?php transp_e('p1.warning') ?>
        </div>
        <div id="scheduleValidationMsg" class="mb-3"></div>
        <button class="btn btn-warning btn-sm" id="btnAdvancePhase2" data-perm="canSecurity">
            <i class="fas fa-arrow-right mr-1"></i><?php transp_e('p1.advance') ?>
        </button>
    </div>

    <!-- PHASE 2 Panel -->
    <div id="truckPhase2Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-info">
            <i class="fas fa-clipboard-check mr-1"></i><?php transp_e('p2.title') ?>
        </h6>

        <div id="inspectionHistory" class="mb-3"></div>

        <div class="card bg-light p-3" data-perm="canInspect">
            <h6 class="mb-1 text-dark"><?php transp_e('p2.newInspection') ?></h6>
            <p class="small text-muted mb-3"><?php transp_e('p2.checklistHint') ?></p>
            <div class="inspection-checklist mb-3">
                <?php foreach (['Odors' => 'odors', 'Holes' => 'holes', 'Foreign' => 'foreign', 'Chemicals' => 'chemicals', 'Floor' => 'floor'] as $field => $key): ?>
                <div class="check-item" id="chk<?= $field ?>" data-field="Check<?= $field ?>" role="checkbox" aria-checked="false" tabindex="0">
                    <div class="check-btn"><i class="fas fa-times"></i></div>
                    <div class="check-label"><?php transp_e('chk.' . $key) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mb-3">
                <label class="small font-weight-bold" for="inpInspectionComments">
                    <?php transp_e('p2.comments') ?>
                    <span class="text-danger small" id="commentsRequired"><?php transp_e('p2.commentsRequired') ?></span>
                </label>
                <textarea id="inpInspectionComments" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <button class="btn btn-info btn-sm" id="btnSubmitInspection">
                <i class="fas fa-save mr-1"></i><?php transp_e('p2.submit') ?>
            </button>
        </div>

        <!-- Shown by JS when last inspection was rejected -->
        <div id="phase2WaitingAction" class="mt-3" style="display:none;">
            <div class="alert alert-danger small mb-2">
                <i class="fas fa-exclamation-triangle mr-1"></i><?php transp_e('p2.lastRejected') ?>
            </div>
            <button class="btn btn-warning btn-sm" id="btnSendToWaiting" data-perm="canYard">
                <i class="fas fa-tools mr-1"></i><?php transp_e('p2.sendToWaiting') ?>
            </button>
        </div>
    </div>

    <!-- PHASE 2 — Waiting Zone Panel -->
    <div id="truckPhase2WaitingPanel" class="card p-3 mb-3 border-warning" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3" style="color:#d68910;">
            <i class="fas fa-tools mr-1"></i><?php transp_e('wz.title') ?>
        </h6>
        <div id="waitingZoneInfo" class="mb-3"></div>
        <div id="waitingInspectionHistory" class="mb-3"></div>
        <button class="btn btn-info btn-sm" id="btnRecallFromWaiting" data-perm="canYard">
            <i class="fas fa-redo mr-1"></i><?php transp_e('wz.recall') ?>
        </button>
    </div>

    <!-- PHASE 3 Panel -->
    <div id="truckPhase3Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-success">
            <i class="fas fa-warehouse mr-1"></i><?php transp_e('p3.title') ?>
        </h6>

        <div class="row mb-3">
            <div class="col-md-5 mb-2">
                <div class="gate-display card border-success p-3 text-center">
                    <div class="small text-muted mb-1"><?php transp_e('p3.assignedGate') ?></div>
                    <h2 class="text-success mb-1" id="currentGateDisplay">—</h2>
                    <div class="small text-muted" id="gateAssignedBy"></div>
                </div>
            </div>
            <div class="col-md-7 mb-2">
                <div class="card p-3 h-100">
                    <div class="status-row mb-2" id="rowGateAssigned">
                        <span class="status-icon" id="iconGateAssigned"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small"><?php transp_e('sync.gateAssignedStep') ?></span>
                        <span class="small text-muted ml-auto" id="gateAssignedInfo"></span>
                    </div>
                    <div class="status-row mb-2" id="rowGateConfirmed">
                        <span class="status-icon" id="iconGateConfirmed"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small"><?php transp_e('p3.gateConfirmed') ?></span>
                        <span class="small text-muted ml-auto" id="gateConfirmedInfo"></span>
                    </div>
                    <div class="status-row mb-2" id="rowOrdersValidated">
                        <span class="status-icon" id="iconOrdersValidated"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small"><?php transp_e('p3.ordersValidated') ?></span>
                        <span class="small text-muted ml-auto" id="ordersValidatedInfo"></span>
                    </div>
                    <div class="status-row" id="rowLoadingClosed">
                        <span class="status-icon" id="iconLoadingClosed"><i class="fas fa-circle text-muted"></i></span>
                        <span class="small"><?php transp_e('p3.loadingClosed') ?></span>
                        <span class="small text-muted ml-auto" id="loadingClosedInfo"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- What is next at the gate (filled by JS: waiting for Warehouse / Shipping) -->
        <div id="phase3NextStep" class="next-step mb-3" style="display:none;"></div>

        <!-- Actions: hidden once the truck reaches Phase 4 -->
        <div id="phase3Actions">
            <div class="card bg-light p-3 mb-3" data-perm="canWarehouse">
                <h6 class="mb-2"><?php transp_e('p3.assignTitle') ?></h6>
                <div class="row align-items-end">
                    <div class="col-md-4 mb-2">
                        <label class="small font-weight-bold" for="selGate"><?php transp_e('field.gate') ?></label>
                        <select id="selGate" class="form-control form-control-sm">
                            <option value=""><?php transp_e('p3.selectGate') ?></option>
                        </select>
                    </div>
                    <div class="col-md-5 mb-2">
                        <label class="small font-weight-bold" for="inpGateReason"><?php transp_e('p3.reason') ?></label>
                        <input type="text" id="inpGateReason" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 mb-2">
                        <button class="btn btn-success btn-sm btn-block" id="btnAssignGate">
                            <i class="fas fa-map-marker-alt mr-1"></i><?php transp_e('p3.assign') ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap" data-perm="canShipping">
                <button class="btn btn-outline-success btn-sm mr-2 mb-2" id="btnConfirmGate">
                    <i class="fas fa-check mr-1"></i><?php transp_e('p3.confirmGate') ?>
                </button>
                <button class="btn btn-outline-primary btn-sm mr-2 mb-2" id="btnValidateOrders">
                    <i class="fas fa-tasks mr-1"></i><?php transp_e('p3.validateOrders') ?>
                </button>
                <button class="btn btn-success btn-sm mb-2" id="btnCloseLoading">
                    <i class="fas fa-lock mr-1"></i><?php transp_e('p3.closeLoading') ?>
                </button>
            </div>
        </div>

        <div id="truckOrdersSection" class="mt-2">
            <h6 class="small font-weight-bold text-muted" id="truckOrdersTitle"></h6>
            <div id="truckOrdersList"></div>
        </div>

        <div id="gateHistorySection" class="mt-3" style="display:none;">
            <h6 class="small font-weight-bold text-muted"><?php transp_e('p3.gateHistory') ?></h6>
            <div id="gateHistory"></div>
        </div>
    </div>

    <!-- PHASE 4 Panel -->
    <div id="truckPhase4Panel" class="card p-3 mb-3" style="display:none;">
        <h6 class="border-bottom pb-2 mb-3 text-secondary">
            <i class="fas fa-file-alt mr-1"></i><?php transp_e('p4.title') ?>
        </h6>
        <div class="alert alert-info small">
            <i class="fas fa-info-circle mr-1"></i><?php transp_e('p4.info') ?>
        </div>
        <div data-perm="canLogistica">
            <label class="small font-weight-bold" for="inpClosureNotes"><?php transp_e('p4.notes') ?></label>
            <textarea id="inpClosureNotes" class="form-control form-control-sm mb-3" rows="3"
                placeholder="<?php transp_e('p4.notesPh') ?>"></textarea>
            <button class="btn btn-secondary btn-sm" id="btnCloseTruck">
                <i class="fas fa-flag-checkered mr-1"></i><?php transp_e('p4.release') ?>
            </button>
        </div>
    </div>

    <!-- Completed Panel -->
    <div id="truckCompletedPanel" class="card p-4 mb-3 border-success text-center" style="display:none;">
        <i class="fas fa-check-circle fa-3x text-success mb-2 d-block"></i>
        <h5 class="text-success"><?php transp_e('done.title') ?></h5>
        <p class="text-muted small mb-0" id="completedInfo"></p>
    </div>

    <!-- Cancelled Panel -->
    <div id="truckCancelledPanel" class="card p-4 mb-3 border-danger text-center" style="display:none;">
        <i class="fas fa-ban fa-3x text-danger mb-2 d-block"></i>
        <h5 class="text-danger"><?php transp_e('cancelled.title') ?></h5>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: SCHEDULES
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewSchedules" style="display:none;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h5 class="mb-2"><i class="fas fa-calendar-alt text-primary mr-2"></i><?php transp_e('sched.title') ?></h5>
        <div class="d-flex align-items-center mb-2">
            <input type="date" id="schedDate" class="form-control form-control-sm mr-2" style="width:160px;">
            <button class="btn btn-outline-success btn-sm mr-2" id="btnImportSchedules" data-perm="canLogistica">
                <i class="fas fa-file-excel mr-1"></i><?php transp_e('imp.button') ?>
            </button>
            <button class="btn btn-primary btn-sm" id="btnNewSchedule" data-perm="canLogistica">
                <i class="fas fa-plus mr-1"></i><?php transp_e('sched.new') ?>
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="card p-3 mb-3">
                <h6 class="border-bottom pb-2 mb-3"><?php transp_e('sched.ofDay') ?></h6>
                <div id="scheduleList"><p class="text-muted small text-center py-3"><?php transp_e('sched.pickDate') ?></p></div>
            </div>
        </div>
        <div class="col-md-7">
            <div id="scheduleEmptyPanel" class="card p-4 mb-3 text-center text-muted small">
                <i class="fas fa-hand-pointer fa-2x mb-2 d-block"></i><?php transp_e('sched.selectOne') ?>
            </div>
            <div id="scheduleDetailPanel" class="card p-3 mb-3" style="display:none;">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                    <h6 class="mb-0" id="schedDetailTitle"></h6>
                    <div data-perm="canLogistica">
                        <button class="btn btn-outline-primary btn-sm mr-1" id="btnEditSchedule">
                            <i class="fas fa-edit mr-1"></i><?php transp_e('common.edit') ?>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" id="btnCancelSchedule">
                            <i class="fas fa-ban mr-1"></i><?php transp_e('sched.cancelSchedule') ?>
                        </button>
                    </div>
                </div>
                <div id="schedDetailInfo" class="small mb-3"></div>
                <h6 class="mb-2 small font-weight-bold">
                    <i class="fas fa-box mr-1"></i><?php transp_e('sched.assignedOrders') ?>
                </h6>
                <div id="schedOrderList" class="mb-3"></div>
                <button class="btn btn-outline-primary btn-sm" id="btnAddOrder" data-perm="canLogistica">
                    <i class="fas fa-plus mr-1"></i><?php transp_e('sched.addOrder') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: WAREHOUSE — gate control
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewWarehouse" style="display:none;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="mb-2">
            <h5 class="mb-0"><i class="fas fa-warehouse text-primary mr-2"></i><?php transp_e('wh.title') ?></h5>
            <small class="text-muted"><?php transp_e('wh.subtitle') ?></small>
        </div>
        <div class="d-flex align-items-center mb-2">
            <div class="input-group input-group-sm mr-2" style="width:180px;">
                <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span></div>
                <input type="search" id="whSearch" class="form-control" placeholder="<?php transp_e('wh.searchPh') ?>">
            </div>
            <input type="date" id="whDate" class="form-control form-control-sm mr-2" style="width:150px;">
            <button class="btn btn-outline-secondary btn-sm" id="btnWhRefresh" title="<?php transp_e('common.refresh') ?>"><i class="fas fa-sync-alt"></i></button>
        </div>
    </div>

    <!-- Filter chips (also the color legend) -->
    <div class="wh-filters mb-3" id="whFilters">
        <button type="button" class="wh-chip active" data-filter="all"><span class="dot dot-all"></span><?php transp_e('wh.f.all') ?> <b id="whCountAll">0</b></button>
        <button type="button" class="wh-chip" data-filter="available"><span class="dot dot-available"></span><?php transp_e('wh.f.available') ?> <b id="whCountAvailable">0</b></button>
        <button type="button" class="wh-chip" data-filter="occupied"><span class="dot dot-occupied"></span><?php transp_e('wh.f.occupied') ?> <b id="whCountOccupied">0</b></button>
        <button type="button" class="wh-chip" data-filter="blocked"><span class="dot dot-blocked"></span><?php transp_e('wh.f.blocked') ?> <b id="whCountBlocked">0</b></button>
        <span class="wh-chip wh-chip-static"><i class="fas fa-truck mr-1"></i><?php transp_e('wh.kpiWaiting') ?> <b id="whCountWaiting">0</b></span>
    </div>

    <div class="row">
        <!-- Queue -->
        <div class="col-lg-3 mb-3">
            <div class="card wh-queue p-3">
                <h6 class="mb-1"><i class="fas fa-truck-loading text-success mr-1"></i><?php transp_e('wh.queueTitle') ?></h6>
                <p class="small text-muted mb-2"><?php transp_e('wh.queueHint') ?></p>
                <div id="whQueue"></div>
                <h6 class="mt-3 mb-2 small font-weight-bold text-muted text-uppercase"><?php transp_e('wh.incoming') ?></h6>
                <div id="whIncoming"></div>
            </div>
        </div>

        <!-- Gate map -->
        <div class="col-lg-9">
            <div id="whSelectionBar" class="wh-selection-bar mb-2" style="display:none;">
                <span><i class="fas fa-hand-pointer mr-2"></i><span id="whSelectionText"></span></span>
                <button type="button" class="btn btn-sm btn-light" id="btnWhCancelSel"><?php transp_e('common.cancel') ?></button>
            </div>
            <div id="whGateGrid" class="wh-gate-grid"></div>
            <div id="whNoGates" class="text-center text-muted small py-4" style="display:none;"><?php transp_e('wh.noGatesMatch') ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: USERS & ROLES (Administrator)
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewRoles" style="display:none;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="mb-2">
            <h5 class="mb-0"><i class="fas fa-user-shield text-primary mr-2"></i><?php transp_e('roles.title') ?></h5>
            <small class="text-muted"><?php transp_e('roles.subtitle') ?></small>
        </div>
        <div class="roles-add mb-2">
            <div class="input-group input-group-sm">
                <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-user-plus text-muted"></i></span></div>
                <input type="search" id="rolesSearch" class="form-control" placeholder="<?php transp_e('roles.searchPh') ?>" autocomplete="off">
            </div>
            <div id="rolesSearchResults" class="roles-search-results" style="display:none;"></div>
        </div>
    </div>

    <div class="card p-3 mb-3">
        <h6 class="small font-weight-bold text-muted text-uppercase mb-2"><?php transp_e('roles.legend') ?></h6>
        <div class="roles-legend">
            <?php foreach (TRANSP_ROLES as $r): ?>
            <div class="roles-legend-item">
                <span class="badge role-badge role-<?= strtolower($r) ?>"><?php transp_e('role.' . $r) ?></span>
                <span class="small text-muted"><?php transp_e('roleDesc.' . $r) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 roles-table">
                <thead class="thead-light">
                    <tr>
                        <th><?php transp_e('roles.user') ?></th>
                        <?php foreach (TRANSP_ROLES as $r): ?>
                        <th class="text-center" title="<?php transp_e('roleDesc.' . $r) ?>"><?php transp_e('role.' . $r) ?></th>
                        <?php endforeach; ?>
                        <th><?php transp_e('roles.updated') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rolesBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     VIEW: REPORTS
     ═══════════════════════════════════════════════════════════════════ -->
<div id="viewReports" style="display:none;">
    <h5 class="mb-3"><i class="fas fa-chart-bar text-primary mr-2"></i><?php transp_e('rpt.title') ?></h5>

    <div class="card p-3 mb-3">
        <div class="row align-items-end">
            <div class="col-6 col-md-2 mb-2">
                <label class="small font-weight-bold" for="rptDateFrom"><?php transp_e('rpt.from') ?></label>
                <input type="date" id="rptDateFrom" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2 mb-2">
                <label class="small font-weight-bold" for="rptDateTo"><?php transp_e('rpt.to') ?></label>
                <input type="date" id="rptDateTo" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 mb-2">
                <label class="small font-weight-bold" for="rptCarrier"><?php transp_e('field.carrier') ?></label>
                <input type="text" id="rptCarrier" class="form-control form-control-sm" placeholder="<?php transp_e('rpt.carrierPh') ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold" for="rptStatus"><?php transp_e('field.status') ?></label>
                <select id="rptStatus" class="form-control form-control-sm">
                    <option value=""><?php transp_e('rpt.all') ?></option>
                    <option value="Completed"><?php transp_e('status.Completed') ?></option>
                    <option value="Cancelled"><?php transp_e('status.Cancelled') ?></option>
                    <?php foreach ([1, 2, 3, 4] as $n): ?>
                    <option value="Phase<?= $n ?>"><?php transp_e('rpt.phaseN', ['n' => $n]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-2 d-flex align-items-end">
                <button class="btn btn-primary btn-sm mr-2" id="btnLoadReport">
                    <i class="fas fa-search mr-1"></i><?php transp_e('common.search') ?>
                </button>
                <button class="btn btn-outline-success btn-sm" id="btnExportExcel" title="<?php transp_e('rpt.exportTitle') ?>">
                    <i class="fas fa-file-excel mr-1"></i><?php transp_e('rpt.export') ?>
                </button>
            </div>
        </div>
    </div>

    <div class="row mb-3" id="rptKpis" style="display:none;">
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num" id="rKpiTotal">0</div>
                <div class="kpi-lbl"><?php transp_e('rpt.kpiTotal') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-success" id="rKpiCompleted">0</div>
                <div class="kpi-lbl"><?php transp_e('rpt.kpiCompleted') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-danger" id="rKpiRejections">0</div>
                <div class="kpi-lbl"><?php transp_e('rpt.kpiRejections') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card kpi-card p-3 text-center">
                <div class="kpi-num text-info" id="rKpiAvgIns">0</div>
                <div class="kpi-lbl"><?php transp_e('rpt.kpiAvg') ?></div>
            </div>
        </div>
    </div>

    <div class="card p-3">
        <div class="table-responsive">
            <table id="reportTable" class="table table-sm table-hover" style="width:100%">
                <thead>
                    <tr>
                        <?php foreach (['date','truck','trailer','carrier','driver','gate','inspections','rejections','status','entry','closed'] as $col): ?>
                        <th><?php transp_e('field.' . $col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /container-fluid -->

<!-- ══════ MODALS ═══════════════════════════════════════════════════════ -->

<!-- New Truck Modal -->
<div class="modal fade" id="modalNewTruck" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title"><i class="fas fa-truck mr-1"></i><?php transp_e('nt.title') ?></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntTruckNumber"><?php transp_e('nt.truckNumber') ?></label>
                        <input type="text" id="ntTruckNumber" class="form-control form-control-sm" maxlength="50">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntTrailerNumber"><?php transp_e('nt.trailerNumber') ?></label>
                        <input type="text" id="ntTrailerNumber" class="form-control form-control-sm" maxlength="50">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntLicensePlate"><?php transp_e('nt.plates') ?></label>
                        <input type="text" id="ntLicensePlate" class="form-control form-control-sm" maxlength="30">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntCarrierName"><?php transp_e('nt.carrier') ?></label>
                        <input type="text" id="ntCarrierName" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="ntDriverName"><?php transp_e('nt.driverName') ?></label>
                        <input type="text" id="ntDriverName" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntDriverLicense"><?php transp_e('nt.license') ?></label>
                        <input type="text" id="ntDriverLicense" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="ntDriverPhone"><?php transp_e('nt.phone') ?></label>
                        <input type="tel" id="ntDriverPhone" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="ntScheduleID"><?php transp_e('nt.schedule') ?></label>
                        <select id="ntScheduleID" class="form-control form-control-sm">
                            <option value=""><?php transp_e('nt.noSchedule') ?></option>
                        </select>
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="ntNotes"><?php transp_e('common.notes') ?></label>
                        <textarea id="ntNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveNewTruck">
                    <i class="fas fa-save mr-1"></i><?php transp_e('nt.register') ?>
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
                <h6 class="modal-title"><i class="fas fa-calendar-plus mr-1"></i><span id="modalScheduleTitle"><?php transp_e('sched.new') ?></span></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="nsScheduleID">
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="nsCarrier"><?php transp_e('nt.carrier') ?></label>
                        <input type="text" id="nsCarrier" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="nsTruckType"><?php transp_e('sched.truckType') ?></label>
                        <input type="text" id="nsTruckType" class="form-control form-control-sm" placeholder="<?php transp_e('sched.truckTypePh') ?>">
                    </div>
                    <div class="col-6 col-md-3 mb-2">
                        <label class="small font-weight-bold" for="nsWindowStart"><?php transp_e('sched.start') ?></label>
                        <input type="time" id="nsWindowStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-3 mb-2">
                        <label class="small font-weight-bold" for="nsWindowEnd"><?php transp_e('sched.end') ?></label>
                        <input type="time" id="nsWindowEnd" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="nsNotes"><?php transp_e('common.notes') ?></label>
                        <textarea id="nsNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveSchedule">
                    <i class="fas fa-save mr-1"></i><?php transp_e('common.save') ?>
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
                <h6 class="modal-title"><i class="fas fa-box mr-1"></i><?php transp_e('order.modalTitle') ?></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="aoOrderID"><?php transp_e('order.id') ?></label>
                        <input type="text" id="aoOrderID" class="form-control form-control-sm" placeholder="<?php transp_e('order.idPh') ?>">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="aoPio"><?php transp_e('order.pio') ?> <span class="text-danger">*</span></label>
                        <input type="text" id="aoPio" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small font-weight-bold" for="aoProgress"><?php transp_e('order.progress') ?></label>
                        <input type="number" id="aoProgress" class="form-control form-control-sm" min="0" max="100">
                    </div>
                    <div class="col-md-12 mb-2">
                        <label class="small font-weight-bold" for="aoNotes"><?php transp_e('common.notes') ?></label>
                        <textarea id="aoNotes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="alert alert-warning small mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i><?php transp_e('order.pioOnly') ?>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveOrder">
                    <i class="fas fa-save mr-1"></i><?php transp_e('order.add') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Warehouse: Gate Modal (available / occupied / blocked actions) -->
<div class="modal fade" id="modalGate" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" id="gateModalHeader">
                <h6 class="modal-title" id="gateModalTitle"></h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="gateModalBody"></div>
                <div class="mt-3">
                    <h6 class="small font-weight-bold text-muted text-uppercase mb-2"><?php transp_e('wh.history') ?></h6>
                    <div id="gateModalHistory" class="wh-history small"></div>
                </div>
            </div>
            <div class="modal-footer py-2" id="gateModalFooter"></div>
        </div>
    </div>
</div>

<!-- Warehouse: Assign gate + orders Modal -->
<div class="modal fade" id="modalAssign" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title"><i class="fas fa-truck-loading mr-1"></i><span id="assignTitle"><?php transp_e('wh.assignTitle') ?></span></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="wh-assign-head mb-3">
                    <div class="wh-assign-truck">
                        <div class="small text-muted"><?php transp_e('field.truck') ?></div>
                        <div class="h5 mb-0" id="assignTruckNum"></div>
                        <div class="small" id="assignTruckInfo"></div>
                    </div>
                    <i class="fas fa-long-arrow-alt-right fa-2x text-muted mx-3 d-none d-sm-block"></i>
                    <div class="wh-assign-gate">
                        <label class="small text-muted mb-1" for="assignGate"><?php transp_e('field.gate') ?></label>
                        <select id="assignGate" class="form-control"></select>
                    </div>
                </div>
                <div id="assignReasonWrap" class="mb-3" style="display:none;">
                    <label class="small font-weight-bold" for="assignReason"><?php transp_e('wh.moveReason') ?></label>
                    <input type="text" id="assignReason" class="form-control form-control-sm" maxlength="200">
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="fas fa-boxes mr-1 text-primary"></i><?php transp_e('wh.ordersTitle') ?></h6>
                    <div class="small">
                        <span class="badge badge-primary mr-2" id="assignCount"></span>
                        <a href="#" id="assignSelectAll"><?php transp_e('wh.selectAll') ?></a> ·
                        <a href="#" id="assignSelectNone"><?php transp_e('wh.selectNone') ?></a>
                    </div>
                </div>
                <div id="assignOrders"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-light btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-success btn-sm" id="btnConfirmAssign"><i class="fas fa-check mr-1"></i><span id="btnConfirmAssignLbl"></span></button>
            </div>
        </div>
    </div>
</div>

<!-- Import Schedules (Excel / CSV) Modal -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title"><i class="fas fa-file-excel mr-1"></i><?php transp_e('imp.title') ?></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2"><?php transp_e('imp.intro') ?></p>
                <div class="small mb-3">
                    <?php foreach (['date' => 1, 'carrier' => 1, 'truckType' => 0, 'start' => 0, 'end' => 0, 'orderId' => 0, 'pio' => 0, 'progress' => 0, 'notes' => 0] as $col => $req): ?>
                    <span class="badge <?= $req ? 'badge-primary' : 'badge-light border' ?> mr-1 mb-1"><?php transp_e('col.' . $col) ?><?= $req ? ' *' : '' ?></span>
                    <?php endforeach; ?>
                    <div class="text-muted mt-1" id="importColsHelp"></div>
                </div>
                <div class="d-flex flex-wrap align-items-center mb-3">
                    <label class="btn btn-success btn-sm mb-2 mr-2">
                        <i class="fas fa-upload mr-1"></i><?php transp_e('imp.chooseFile') ?>
                        <input type="file" id="importFile" accept=".xlsx,.xls,.csv" style="display:none;">
                    </label>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-2 mr-2" id="btnImportTemplate">
                        <i class="fas fa-download mr-1"></i><?php transp_e('imp.template') ?>
                    </button>
                    <span class="small text-muted mb-2" id="importFileName"></span>
                </div>
                <div id="importMessage"></div>
                <div id="importPreview" style="display:none;">
                    <div class="small font-weight-bold mb-2" id="importSummary"></div>
                    <div class="table-responsive import-preview">
                        <table class="table table-sm table-bordered mb-1">
                            <thead class="thead-light">
                                <tr>
                                    <th><?php transp_e('imp.row') ?></th>
                                    <?php foreach (['date','carrier','truckType','start','end','orderId','pio','progress','notes'] as $col): ?>
                                    <th><?php transp_e('col.' . $col) ?></th>
                                    <?php endforeach; ?>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="importPreviewBody"></tbody>
                        </table>
                    </div>
                    <div class="small text-muted" id="importPreviewMore"></div>
                </div>
                <div id="importResult" class="mt-3"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.close') ?></button>
                <button type="button" class="btn btn-success btn-sm" id="btnConfirmImport" disabled>
                    <i class="fas fa-file-import mr-1"></i><span id="btnConfirmImportLbl"><?php transp_e('imp.import') ?></span>
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
                <h6 class="modal-title"><i class="fas fa-ban mr-1"></i><?php transp_e('ct.title') ?></h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <label class="small font-weight-bold" for="cancelReason"><?php transp_e('ct.reason') ?></label>
                <textarea id="cancelReason" class="form-control form-control-sm" rows="3"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.back') ?></button>
                <button type="button" class="btn btn-danger btn-sm" id="btnConfirmCancelTruck"><?php transp_e('common.confirm') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Waiting Zone Modal -->
<div class="modal fade" id="modalWaitingZone" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning py-2">
                <h6 class="modal-title"><i class="fas fa-tools mr-1"></i><?php transp_e('wz.modalTitle') ?></h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?php transp_e('common.close') ?>"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <label class="small font-weight-bold" for="wzZoneName"><?php transp_e('wz.zone') ?></label>
                <input type="text" id="wzZoneName" class="form-control form-control-sm mb-2"
                    placeholder="<?php transp_e('wz.zonePh') ?>">
                <label class="small font-weight-bold" for="wzNotes"><?php transp_e('wz.notes') ?></label>
                <textarea id="wzNotes" class="form-control form-control-sm" rows="2"
                    placeholder="<?php transp_e('wz.notesPh') ?>"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-warning btn-sm" id="btnConfirmWaitingZone">
                    <i class="fas fa-arrow-right mr-1"></i><?php transp_e('common.confirm') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm / Prompt Dialog (replaces the browser's confirm() and prompt()) -->
<div class="modal fade" id="modalConfirm" tabindex="-1" role="dialog" aria-labelledby="confirmTitle" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered confirm-dialog" role="document">
        <div class="modal-content">
            <div class="modal-body">
                <div class="d-flex">
                    <div class="confirm-icon mr-3" id="confirmIcon"><i class="fas fa-question"></i></div>
                    <div class="flex-grow-1 pt-1">
                        <h6 class="confirm-title mb-1" id="confirmTitle"></h6>
                        <p class="confirm-message mb-0" id="confirmMessage"></p>
                        <div class="mt-3" id="confirmInputWrap" style="display:none;">
                            <label class="small font-weight-bold mb-1" for="confirmInput" id="confirmInputLabel"></label>
                            <input type="text" id="confirmInput" class="form-control form-control-sm" autocomplete="off">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-dismiss="modal"><?php transp_e('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmOk"><?php transp_e('common.confirm') ?></button>
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

<?php $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
<script>
window.TRANSP_PERMS = <?= json_encode($perms, $jsonFlags) ?>;
window.TRANSP_CAP_OWNERS = <?= json_encode(transp_cap_owners(), $jsonFlags) ?>;
window.TRANSP_ROLES = <?= json_encode(TRANSP_ROLES) ?>;
window.TRANSP_USER  = <?= json_encode(['username' => $username, 'fullname' => $fullname ?: $username], $jsonFlags) ?>;
window.TRANSP_LANG  = <?= json_encode($lang) ?>;
window.TRANSP_I18N  = <?= json_encode(transp_dict(), $jsonFlags) ?>;
</script>
<script src="assets/js/transportation.js?v=<?= filemtime(__DIR__ . '/assets/js/transportation.js') ?>"></script>

</body>
</html>
