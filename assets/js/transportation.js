/* ─── Transportation Module — Main JS ─────────────────────────────── */
'use strict';

const API   = 'api.php';
const CSRF  = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const PERMS = window.TRANSP_PERMS || {};

// ─── State ──────────────────────────────────────────────────────────
let currentView     = 'dashboard';
let currentTruckID  = null;
let currentSchedID  = null;
let dashDate        = todayStr();
let schedDate       = todayStr();
let reportTable     = null;
let dashAutoRefresh = null;

// ─── Utilities ──────────────────────────────────────────────────────
function todayStr() {
    return new Date().toISOString().slice(0, 10);
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s);
    if (isNaN(d)) return s;
    return d.toLocaleDateString('es-MX', { year:'numeric', month:'short', day:'2-digit' });
}

function fmtDateTime(s) {
    if (!s) return '—';
    const d = new Date(s);
    if (isNaN(d)) return s;
    return d.toLocaleString('es-MX', { month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function showLoading(on) {
    document.getElementById('loadingOverlay').classList.toggle('active', on);
}

function toast(msg, type = 'info') {
    const el = document.createElement('div');
    el.className = `toast-msg ${type}`;
    el.textContent = msg;
    document.getElementById('toastContainer').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function api(params, method = 'GET') {
    showLoading(true);
    try {
        let url, opts;
        if (method === 'GET') {
            url  = API + '?' + new URLSearchParams(params);
            opts = { method: 'GET' };
        } else {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            Object.entries(params).forEach(([k,v]) => { if (v !== undefined && v !== null) fd.append(k, v); });
            url  = API;
            opts = { method: 'POST', body: fd };
        }
        const res  = await fetch(url, opts);
        const json = await res.json();
        if (!json.ok) throw new Error(json.message || 'Error desconocido');
        return json.data;
    } finally {
        showLoading(false);
    }
}

async function uploadPhotosForInspection(inspectionId, truckId, files) {
    showLoading(true);
    try {
        for (const file of files) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('action', 'upload_inspection_photo');
            fd.append('InspectionID', inspectionId);
            fd.append('TruckID', truckId);
            fd.append('photo', file);
            const res  = await fetch(API, { method:'POST', body:fd });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message || 'Error subiendo foto');
        }
    } finally {
        showLoading(false);
    }
}

function statusLabel(status) {
    const MAP = {
        Phase1:        'Fase 1 — Check-in',
        Phase2:        'Fase 2 — Inspección',
        Phase2_Waiting:'Zona de Espera',
        Phase3:        'Fase 3 — En Carga',
        Phase4:        'Fase 4 — Documentación',
        Completed:     'Completado',
        Cancelled:     'Cancelado',
    };
    return MAP[status] || status;
}

function statusBadge(status) {
    const cls = {
        Phase1:        'badge-phase1',
        Phase2:        'badge-phase2',
        Phase2_Waiting:'badge-waiting',
        Phase3:        'badge-phase3',
        Phase4:        'badge-phase4',
        Completed:     'badge-completed',
        Cancelled:     'badge-cancelled',
    };
    return `<span class="badge ${cls[status]||'badge-secondary'}">${statusLabel(status)}</span>`;
}

// ─── View Routing ────────────────────────────────────────────────────
function showView(name) {
    ['Dashboard','Truck','Schedules','Reports'].forEach(v => {
        document.getElementById('view'+v).style.display = (v.toLowerCase()===name.toLowerCase() ? '' : 'none');
    });
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    const navMap = { dashboard:'btnNavDashboard', schedules:'btnNavSchedules', reports:'btnNavReports' };
    const nb = document.getElementById(navMap[name.toLowerCase()]);
    if (nb) nb.classList.add('active');
    currentView = name.toLowerCase();
    clearInterval(dashAutoRefresh);
    if (name.toLowerCase() === 'dashboard') {
        dashAutoRefresh = setInterval(loadDashboard, 30000);
    }
}

// ═══════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════
async function loadDashboard() {
    try {
        const data = await api({ action:'dashboard_data', date: dashDate });
        renderDashboard(data.trucks || [], data.gates || []);
    } catch(e) {
        toast(e.message, 'error');
    }
}

function renderDashboard(trucks, gates) {
    document.getElementById('dashboardDateLabel').textContent = 'Fecha: ' + fmtDate(dashDate);

    const p1  = trucks.filter(t => t.Status==='Phase1').length;
    const p2  = trucks.filter(t => t.Status==='Phase2' || t.Status==='Phase2_Waiting').length;
    const p34 = trucks.filter(t => t.Status==='Phase3' || t.Status==='Phase4').length;
    document.getElementById('kpiTotal').textContent   = trucks.length;
    document.getElementById('kpiPhase1').textContent  = p1;
    document.getElementById('kpiPhase2').textContent  = p2;
    document.getElementById('kpiPhase34').textContent = p34;

    const grid = document.getElementById('truckGrid');
    const none = document.getElementById('noTrucks');

    if (!trucks.length) {
        grid.innerHTML = '';
        none.style.display = '';
        return;
    }
    none.style.display = 'none';

    const phaseClass = {
        Phase1:'phase-1', Phase2:'phase-2', Phase2_Waiting:'phase-waiting',
        Phase3:'phase-3', Phase4:'phase-4', Completed:'phase-4', Cancelled:'',
    };

    grid.innerHTML = trucks.map(t => {
        const cls      = phaseClass[t.Status] || 'phase-1';
        const gateHtml = t.AssignedGate
            ? `<div class="truck-gate-lbl mt-1">Gate</div><div class="truck-gate-val">${esc(t.AssignedGate)}</div>`
            : `<div class="truck-gate-lbl mt-1 text-muted">Sin gate</div>`;
        const elapsed = t.CreatedAt ? elapsedTime(t.CreatedAt) : '';
        const waitingTag = t.Status === 'Phase2_Waiting'
            ? `<div class="small mt-1" style="color:#e67e22;"><i class="fas fa-tools mr-1"></i>${esc(t.WaitingZone||'En reparación')}</div>`
            : '';

        return `<div class="col-sm-6 col-md-4 col-lg-3 mb-3">
            <div class="truck-card ${cls} p-3" data-truck-id="${t.TruckID}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <div class="font-weight-bold">${esc(t.TruckNumber)}</div>
                        <div class="truck-carrier">${esc(t.CarrierName)}</div>
                    </div>
                    <span class="truck-phase-badge">${statusLabel(t.Status)}</span>
                </div>
                ${t.DriverName   ? `<div class="small text-muted"><i class="fas fa-user mr-1"></i>${esc(t.DriverName)}</div>`  : ''}
                ${t.TrailerNumber? `<div class="small text-muted"><i class="fas fa-truck mr-1"></i>Tráiler: ${esc(t.TrailerNumber)}</div>` : ''}
                ${waitingTag}
                ${gateHtml}
                ${elapsed ? `<div class="text-muted" style="font-size:.65rem;margin-top:.4rem;"><i class="fas fa-clock mr-1"></i>${elapsed}</div>` : ''}
            </div>
        </div>`;
    }).join('');

    grid.querySelectorAll('.truck-card').forEach(card => {
        card.addEventListener('click', () => openTruck(parseInt(card.dataset.truckId)));
    });
}

function elapsedTime(since) {
    const diff = Math.floor((Date.now() - new Date(since)) / 60000);
    if (diff < 60) return diff + ' min';
    return Math.floor(diff/60) + 'h ' + (diff%60) + 'm';
}

// ═══════════════════════════════════════════════════════════════════
// TRUCK DETAIL
// ═══════════════════════════════════════════════════════════════════
async function openTruck(truckId) {
    currentTruckID = truckId;
    try {
        const data = await api({ action:'get_truck', TruckID: truckId });
        renderTruck(data);
        showView('truck');
    } catch(e) {
        toast(e.message, 'error');
    }
}

function renderTruck(data) {
    const truck       = data.truck;
    const inspections = data.inspections || [];
    const gate        = data.gate;
    const gateHistory = data.gateHistory || [];

    document.getElementById('truckTitle').textContent      = 'Camión ' + truck.TruckNumber;
    document.getElementById('truckStatusBadge').innerHTML  = statusBadge(truck.Status);

    // Phase progress bar
    const phaseNum = { Phase1:1, Phase2:2, Phase2_Waiting:2, Phase3:3, Phase4:4, Completed:4, Cancelled:0 };
    const current  = phaseNum[truck.Status] || 1;
    [1,2,3,4].forEach(n => {
        const el  = document.getElementById('pStep'+n);
        const con = document.getElementById('pConn'+n);
        el.className = 'phase-step';
        if (n < current) el.classList.add('done');
        if (n === current && truck.Status !== 'Completed' && truck.Status !== 'Cancelled') el.classList.add('active');
        if (con) con.className = 'phase-connector' + (n < current ? ' done' : '');
    });

    // Info summary
    const infoFields = [
        ['Camión',        truck.TruckNumber],
        ['Tráiler',       truck.TrailerNumber],
        ['Placas',        truck.LicensePlate],
        ['Transportista', truck.CarrierName],
        ['Conductor',     truck.DriverName],
        ['Licencia',      truck.DriverLicense],
        ['Teléfono',      truck.DriverPhone],
        ['Llegada',       fmtDateTime(truck.ArrivalTime || truck.CreatedAt)],
        ['Schedule',      truck.WindowStart ? `${truck.WindowStart}–${truck.WindowEnd}` : null],
        ['Registrado por',truck.Phase1By],
    ];
    document.getElementById('truckInfoSummary').innerHTML = infoFields
        .filter(([,v]) => v)
        .map(([l,v]) => `<div class="col-6 col-md-3 mb-1"><span class="text-muted">${l}:</span> <strong>${esc(v)}</strong></div>`)
        .join('');

    // Hide all panels
    ['Phase1','Phase2','Phase2Waiting','Phase3','Phase4','Completed','Cancelled'].forEach(p => {
        const el = document.getElementById('truck'+p+'Panel');
        if (el) el.style.display = 'none';
    });
    const waitingAction = document.getElementById('phase2WaitingAction');
    if (waitingAction) waitingAction.style.display = 'none';

    if (truck.Status === 'Completed') {
        document.getElementById('truckCompletedPanel').style.display = '';
        document.getElementById('completedInfo').textContent =
            'Cerrado por ' + (truck.Phase4By||'') + ' — ' + fmtDateTime(truck.Phase4At);
        return;
    }
    if (truck.Status === 'Cancelled') {
        document.getElementById('truckCancelledPanel').style.display = '';
        return;
    }

    // Phase 1
    if (truck.Status === 'Phase1') {
        document.getElementById('truckPhase1Panel').style.display = '';
        const hasSchedule = !!truck.ScheduleID;
        const schedMsg    = document.getElementById('scheduleValidationMsg');
        schedMsg.innerHTML = hasSchedule
            ? `<div class="alert alert-success small mb-0"><i class="fas fa-check-circle mr-1"></i>Schedule válido: <strong>${esc(truck.WindowStart)}–${esc(truck.WindowEnd)}</strong></div>`
            : `<div class="alert alert-danger small mb-0"><i class="fas fa-exclamation-triangle mr-1"></i>Este camión no tiene schedule programado para hoy.</div>`;
    }

    // Phase 2 — inspection form
    if (truck.Status === 'Phase2') {
        document.getElementById('truckPhase2Panel').style.display = '';
        renderInspectionHistory(inspections, 'inspectionHistory');
        resetChecklist();
        // Show send-to-waiting action if last inspection was rejected
        const lastIns = inspections[inspections.length - 1];
        if (waitingAction && lastIns && lastIns.Result === 'REJECTED') {
            waitingAction.style.display = '';
        }
    }

    // Phase 2 — waiting zone
    if (truck.Status === 'Phase2_Waiting') {
        document.getElementById('truckPhase2WaitingPanel').style.display = '';
        document.getElementById('waitingZoneInfo').innerHTML = `
            <div class="alert alert-warning mb-2">
                <strong><i class="fas fa-map-marker-alt mr-1"></i>${esc(truck.WaitingZone || 'Sin zona especificada')}</strong>
                ${truck.WaitingNotes ? `<div class="mt-1">${esc(truck.WaitingNotes)}</div>` : ''}
                <div class="text-muted small mt-1">Enviado por ${esc(truck.SentToWaitingBy||'')} — ${fmtDateTime(truck.SentToWaitingAt)}</div>
            </div>`;
        renderInspectionHistory(inspections, 'waitingInspectionHistory');
    }

    // Phase 3
    if (truck.Status === 'Phase3') {
        document.getElementById('truckPhase3Panel').style.display = '';
        renderPhase3(gate, gateHistory);
        loadGatesForSelect();
    }

    // Phase 4
    if (truck.Status === 'Phase4') {
        document.getElementById('truckPhase4Panel').style.display = '';
        document.getElementById('truckPhase3Panel').style.display = '';
        if (gate) renderPhase3(gate, gateHistory);
    }
}

function renderInspectionHistory(inspections, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!inspections.length) {
        el.innerHTML = '<p class="text-muted small">Sin inspecciones previas.</p>';
        return;
    }

    el.innerHTML = '<h6 class="small font-weight-bold mb-2">Historial de Inspecciones</h6>' +
        inspections.map(ins => {
            const ok = ins.Result === 'APPROVED';
            const checks = [
                ['Olores',    ins.CheckOdors],
                ['Hoyos',     ins.CheckHoles],
                ['Materiales',ins.CheckForeign],
                ['Químicos',  ins.CheckChemicals],
                ['Piso',      ins.CheckFloor],
            ].map(([l,v]) => `<span class="mr-2 ${v=='1'||v===1?'text-success':'text-danger'}"><i class="fas fa-${v=='1'||v===1?'check':'times'} mr-1"></i>${l}</span>`).join('');

            const photos     = ins.photos || [];
            const photosHtml = photos.length
                ? `<div class="photo-grid mt-2">${photos.map(p =>
                    `<a href="api.php?action=view_inspection_photo&PhotoID=${p.PhotoID}" target="_blank" class="photo-thumb" title="${esc(p.FileName)}">
                        <img src="api.php?action=view_inspection_photo&PhotoID=${p.PhotoID}" alt="${esc(p.FileName)}">
                     </a>`).join('')}</div>`
                : '';

            const uploadBtn = !ok
                ? `<label class="btn btn-outline-secondary btn-xs py-0 px-2 mt-2 mb-0" style="cursor:pointer;font-size:.75rem;">
                       <i class="fas fa-camera mr-1"></i>Subir foto
                       <input type="file" style="display:none;" accept="image/*,.heic" multiple
                              class="inp-photo-file" data-inspection-id="${ins.InspectionID}">
                   </label>`
                : '';

            return `<div class="history-item ${ok?'approved':'rejected'} mb-2">
                <div style="flex:1;min-width:0;">
                    <div>
                        <strong>Intento ${ins.AttemptNumber}</strong>
                        <span class="badge ${ok?'badge-success':'badge-danger'} ml-1">${ins.Result}</span>
                        <span class="text-muted small ml-2">${fmtDateTime(ins.InspectedAt)}</span>
                        <span class="text-muted small ml-1">— ${esc(ins.InspectedBy)}</span>
                    </div>
                    <div class="mt-1 small">${checks}</div>
                    ${ins.Comments ? `<div class="text-muted small mt-1"><i class="fas fa-comment mr-1"></i>${esc(ins.Comments)}</div>` : ''}
                    ${photosHtml}
                    ${uploadBtn}
                </div>
            </div>`;
        }).join('');

    el.querySelectorAll('.inp-photo-file').forEach(inp => {
        inp.addEventListener('change', async () => {
            if (!inp.files.length) return;
            const insId = parseInt(inp.dataset.inspectionId);
            try {
                await uploadPhotosForInspection(insId, currentTruckID, inp.files);
                toast('Foto(s) subidas correctamente', 'success');
                openTruck(currentTruckID);
            } catch(e) {
                toast(e.message, 'error');
            }
        });
    });
}

function resetChecklist() {
    document.querySelectorAll('.check-item').forEach(el => {
        el.classList.remove('checked');
        el.querySelector('i').className = 'fas fa-times';
    });
    document.getElementById('inpInspectionComments').value = '';
}

function renderPhase3(gate, gateHistory) {
    document.getElementById('currentGateDisplay').textContent = gate ? gate.GateName : '—';
    document.getElementById('gateAssignedBy').textContent =
        gate ? ('Asignado por ' + gate.AssignedBy + ' — ' + fmtDateTime(gate.AssignedAt)) : '';

    const setStatus = (iconId, infoId, val, by, at) => {
        const icon = document.getElementById(iconId);
        const info = document.getElementById(infoId);
        if (val) {
            icon.innerHTML = '<i class="fas fa-check-circle text-success"></i>';
            if (info) info.textContent = esc(by) + ' — ' + fmtDateTime(at);
        } else {
            icon.innerHTML = '<i class="fas fa-circle text-muted"></i>';
            if (info) info.textContent = 'Pendiente';
        }
    };

    if (gate) {
        setStatus('iconGateConfirmed',  'gateConfirmedInfo',  gate.ShippingConfirmedAt, gate.ShippingConfirmedBy, gate.ShippingConfirmedAt);
        setStatus('iconOrdersValidated','ordersValidatedInfo', gate.OrdersValidatedAt,  gate.OrdersValidatedBy,   gate.OrdersValidatedAt);
        setStatus('iconLoadingClosed',  'loadingClosedInfo',   gate.LoadingClosedAt,    gate.LoadingClosedBy,     gate.LoadingClosedAt);
    }

    if (gateHistory.length > 1) {
        document.getElementById('gateHistorySection').style.display = '';
        document.getElementById('gateHistory').innerHTML = gateHistory.map(a =>
            `<div class="history-item small">
                <i class="fas fa-map-marker-alt mr-1 text-primary"></i>
                <strong>${esc(a.GateName)}</strong>
                <span class="text-muted ml-1">${fmtDateTime(a.AssignedAt)}</span>
                ${a.IsActive=='0'||a.IsActive===0
                    ? `<span class="badge badge-secondary ml-1">Cancelado</span>${a.CancelReason ? `<span class="text-muted ml-1">${esc(a.CancelReason)}</span>` : ''}`
                    : ''}
            </div>`
        ).join('');
    }
}

async function loadGatesForSelect() {
    try {
        const gates = await api({ action:'list_gates', date: todayStr() });
        const sel = document.getElementById('selGate');
        sel.innerHTML = '<option value="">Seleccionar gate...</option>' +
            gates.map(g => {
                const occ = g.OccupiedByTruck ? ` — ${g.OccupiedByTruck}` : '';
                const dis = g.OccupiedByTruckID && g.OccupiedByTruckID != currentTruckID ? ' disabled' : '';
                return `<option value="${g.GateID}"${dis}>${esc(g.GateName)}${occ}</option>`;
            }).join('');
    } catch(e) { /* silent */ }
}

// ═══════════════════════════════════════════════════════════════════
// SCHEDULES
// ═══════════════════════════════════════════════════════════════════
async function loadSchedules() {
    try {
        const list = await api({ action:'list_schedules', date: schedDate });
        renderScheduleList(list);
    } catch(e) {
        toast(e.message, 'error');
    }
}

function renderScheduleList(schedules) {
    const el = document.getElementById('scheduleList');
    if (!schedules.length) {
        el.innerHTML = '<p class="text-muted small text-center py-3">Sin schedules para esta fecha.</p>';
        document.getElementById('scheduleDetailPanel').style.display = 'none';
        return;
    }
    el.innerHTML = schedules.map(s => {
        const win      = s.WindowStart ? `${s.WindowStart}–${s.WindowEnd}` : 'Sin horario';
        const pioBadge = parseInt(s.OrdersWithPio||0) < parseInt(s.OrderCount||0)
            ? `<span class="badge badge-warning ml-1">${s.OrdersWithPio}/${s.OrderCount} PIO</span>`
            : (s.OrderCount > 0 ? `<span class="badge badge-success ml-1">${s.OrderCount} órdenes</span>` : '');
        const cancelled = s.Status === 'Cancelled';
        return `<div class="schedule-item ${cancelled?'cancelled':''}" data-id="${s.ScheduleID}">
            <div class="d-flex justify-content-between align-items-start">
                <span class="schedule-carrier">${esc(s.CarrierName)}</span>
                ${cancelled ? '<span class="badge badge-secondary">Cancelado</span>' : pioBadge}
            </div>
            <div class="schedule-window"><i class="fas fa-clock mr-1"></i>${win}${s.TruckType ? ` — ${esc(s.TruckType)}` : ''}</div>
        </div>`;
    }).join('');

    el.querySelectorAll('.schedule-item:not(.cancelled)').forEach(item => {
        item.addEventListener('click', () => openSchedule(parseInt(item.dataset.id)));
    });
}

async function openSchedule(schedId) {
    currentSchedID = schedId;
    document.querySelectorAll('.schedule-item').forEach(el =>
        el.classList.toggle('active', parseInt(el.dataset.id) === schedId));
    try {
        const [schedules, orders] = await Promise.all([
            api({ action:'list_schedules', date: schedDate }),
            api({ action:'list_schedule_orders', ScheduleID: schedId }),
        ]);
        const sched = schedules.find(s => s.ScheduleID == schedId);
        if (sched) renderScheduleDetail(sched, orders);
    } catch(e) {
        toast(e.message, 'error');
    }
}

function renderScheduleDetail(sched, orders) {
    document.getElementById('scheduleDetailPanel').style.display = '';
    document.getElementById('schedDetailTitle').textContent = sched.CarrierName;
    document.getElementById('schedDetailInfo').innerHTML = `
        <div><i class="fas fa-clock mr-1"></i>${sched.WindowStart||'?'}–${sched.WindowEnd||'?'}</div>
        ${sched.TruckType ? `<div><i class="fas fa-truck mr-1"></i>${esc(sched.TruckType)}</div>` : ''}
        ${sched.Notes     ? `<div class="text-muted"><i class="fas fa-sticky-note mr-1"></i>${esc(sched.Notes)}</div>` : ''}
    `;

    const cancelled = sched.Status === 'Cancelled';
    document.getElementById('btnEditSchedule').style.display   = cancelled ? 'none' : '';
    document.getElementById('btnCancelSchedule').style.display = cancelled ? 'none' : '';

    document.getElementById('schedOrderList').innerHTML = orders.length
        ? orders.map(o => `
            <div class="order-row">
                <div>
                    <div class="small font-weight-bold">${esc(o.ExternalOrderID||o.BiExternalID||'N/A')}</div>
                    ${o.HasPio=='1'||o.HasPio===1
                        ? `<span class="order-pio-badge"><i class="fas fa-check mr-1"></i>PIO: ${esc(o.PioNumber)}</span>`
                        : `<span class="order-no-pio"><i class="fas fa-exclamation mr-1"></i>Sin PIO</span>`}
                    ${o.ProgressPct != null ? `<span class="text-muted small ml-2">${o.ProgressPct}%</span>` : ''}
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-outline-secondary btn-xs py-0 px-1 btn-edit-pio"
                            data-id="${o.ScheduleOrderID}" data-pio="${esc(o.PioNumber||'')}">
                        <i class="fas fa-edit" style="font-size:.65rem;"></i>
                    </button>
                    <button class="btn btn-outline-danger btn-xs py-0 px-1 btn-remove-order"
                            data-id="${o.ScheduleOrderID}">
                        <i class="fas fa-trash" style="font-size:.65rem;"></i>
                    </button>
                </div>
            </div>`).join('')
        : '<p class="text-muted small">Sin órdenes asignadas.</p>';

    document.querySelectorAll('.btn-edit-pio').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            const pio = prompt('Número PIO:', btn.dataset.pio);
            if (pio === null) return;
            try {
                await api({ action:'update_order_pio', ScheduleOrderID:btn.dataset.id, PioNumber:pio }, 'POST');
                toast('PIO actualizado', 'success');
                openSchedule(currentSchedID);
            } catch(e) { toast(e.message,'error'); }
        });
    });

    document.querySelectorAll('.btn-remove-order').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            if (!confirm('¿Eliminar esta orden del schedule?')) return;
            try {
                await api({ action:'remove_order_from_schedule', ScheduleOrderID:btn.dataset.id }, 'POST');
                toast('Orden eliminada', 'info');
                openSchedule(currentSchedID);
            } catch(e) { toast(e.message,'error'); }
        });
    });
}

// ═══════════════════════════════════════════════════════════════════
// REPORTS
// ═══════════════════════════════════════════════════════════════════
async function loadReport() {
    const filters = {
        action:   'report_history',
        dateFrom: document.getElementById('rptDateFrom').value,
        dateTo:   document.getElementById('rptDateTo').value,
        carrier:  document.getElementById('rptCarrier').value,
        status:   document.getElementById('rptStatus').value,
    };
    try {
        const [rows, kpis] = await Promise.all([
            api(filters),
            api({ action:'report_kpis', dateFrom:filters.dateFrom, dateTo:filters.dateTo }),
        ]);
        document.getElementById('rptKpis').style.display = '';
        document.getElementById('rKpiTotal').textContent      = kpis.totalTrucks;
        document.getElementById('rKpiCompleted').textContent  = kpis.completed;
        document.getElementById('rKpiRejections').textContent = kpis.rejectedInspections;
        document.getElementById('rKpiAvgIns').textContent     = kpis.avgInspections || '0';

        if (reportTable) { reportTable.destroy(); reportTable = null; }
        const tbody = document.querySelector('#reportTable tbody');
        tbody.innerHTML = rows.map(r => `<tr>
            <td>${esc(r.TruckDate ? r.TruckDate.slice(0,10) : '')}</td>
            <td>${esc(r.TruckNumber)}</td>
            <td>${esc(r.TrailerNumber||'')}</td>
            <td>${esc(r.CarrierName)}</td>
            <td>${esc(r.DriverName||'')}</td>
            <td>${esc(r.LastGate||'')}</td>
            <td class="text-center">${r.TotalInspections||0}</td>
            <td class="text-center ${r.RejectedInspections>0?'text-danger':''}">${r.RejectedInspections||0}</td>
            <td>${statusBadge(r.Status)}</td>
            <td>${fmtDateTime(r.CreatedAt)}</td>
            <td>${r.Phase4At ? fmtDateTime(r.Phase4At) : '—'}</td>
        </tr>`).join('');

        reportTable = $('#reportTable').DataTable({
            language: { url:'//cdn.datatables.net/plug-ins/1.10.21/i18n/Spanish.json' },
            order: [[0,'desc']],
            pageLength: 25,
        });
    } catch(e) {
        toast(e.message, 'error');
    }
}

function exportExcel() {
    const rows = [];
    document.querySelectorAll('#reportTable tbody tr').forEach(tr => {
        rows.push(Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()));
    });
    const headers = ['Fecha','Camión','Tráiler','Transportista','Conductor','Gate','Inspecciones','Rechazos','Estatus','Entrada','Cierre'];
    const csv  = [headers, ...rows].map(r => r.map(c => '"'+c.replace(/"/g,'""')+'"').join(',')).join('\n');
    const blob = new Blob(['﻿'+csv], { type:'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'transportacion_' + todayStr() + '.csv';
    a.click();
}

// ═══════════════════════════════════════════════════════════════════
// EVENT HANDLERS
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {

    // ─── Date pickers init ───────────────────────────────────────
    document.getElementById('dashDate').value    = dashDate;
    document.getElementById('schedDate').value   = schedDate;
    document.getElementById('rptDateFrom').value = new Date(Date.now()-30*86400000).toISOString().slice(0,10);
    document.getElementById('rptDateTo').value   = todayStr();

    // ─── Nav buttons ─────────────────────────────────────────────
    document.getElementById('btnNavDashboard').addEventListener('click', () => { showView('dashboard'); loadDashboard(); });
    document.getElementById('btnNavSchedules').addEventListener('click', () => { showView('schedules'); loadSchedules(); });
    document.getElementById('btnNavReports').addEventListener('click',   () => showView('reports'));

    // ─── Dashboard ───────────────────────────────────────────────
    document.getElementById('btnRefreshDashboard').addEventListener('click', loadDashboard);
    document.getElementById('dashDate').addEventListener('change', e => { dashDate = e.target.value; loadDashboard(); });

    document.getElementById('btnNewTruck').addEventListener('click', async () => {
        try {
            const schedules = await api({ action:'list_schedules', date: todayStr() });
            const sel = document.getElementById('ntScheduleID');
            sel.innerHTML = '<option value="">Sin schedule asignado</option>' +
                schedules.filter(s => s.Status==='Active').map(s =>
                    `<option value="${s.ScheduleID}">${esc(s.CarrierName)} ${s.WindowStart||''}–${s.WindowEnd||''}</option>`
                ).join('');
        } catch(e) { /* ignore */ }
        $('#modalNewTruck').modal('show');
    });

    document.getElementById('btnSaveNewTruck').addEventListener('click', async () => {
        const truck = {
            action:        'register_truck',
            TruckDate:     todayStr(),
            TruckNumber:   document.getElementById('ntTruckNumber').value.trim(),
            TrailerNumber: document.getElementById('ntTrailerNumber').value.trim(),
            LicensePlate:  document.getElementById('ntLicensePlate').value.trim(),
            CarrierName:   document.getElementById('ntCarrierName').value.trim(),
            DriverName:    document.getElementById('ntDriverName').value.trim(),
            DriverLicense: document.getElementById('ntDriverLicense').value.trim(),
            DriverPhone:   document.getElementById('ntDriverPhone').value.trim(),
            ScheduleID:    document.getElementById('ntScheduleID').value,
            Notes:         document.getElementById('ntNotes').value.trim(),
        };
        if (!truck.TruckNumber) { toast('Número de camión requerido','warning'); return; }
        if (!truck.CarrierName) { toast('Transportista requerido','warning');     return; }
        if (!truck.DriverName)  { toast('Nombre del conductor requerido','warning'); return; }
        try {
            const res = await api(truck, 'POST');
            $('#modalNewTruck').modal('hide');
            toast('Camión registrado', 'success');
            openTruck(res.TruckID);
        } catch(e) { toast(e.message, 'error'); }
    });

    document.getElementById('btnBackDashboard').addEventListener('click', () => { showView('dashboard'); loadDashboard(); });

    // ─── Phase 1 ─────────────────────────────────────────────────
    document.getElementById('btnAdvancePhase2').addEventListener('click', async () => {
        if (!confirm('¿Confirmar avance a Fase 2 — Inspección?')) return;
        try {
            await api({ action:'advance_to_phase2', TruckID: currentTruckID }, 'POST');
            toast('Camión en Fase 2', 'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 2 — Checklist ─────────────────────────────────────
    document.querySelectorAll('.check-item').forEach(el => {
        el.addEventListener('click', () => {
            el.classList.toggle('checked');
            el.querySelector('i').className = el.classList.contains('checked') ? 'fas fa-check' : 'fas fa-times';
        });
    });

    document.getElementById('btnSubmitInspection').addEventListener('click', async () => {
        const checked  = id => document.getElementById(id).classList.contains('checked') ? '1' : '0';
        const comments = document.getElementById('inpInspectionComments').value.trim();
        const allPass  = ['chkOdors','chkHoles','chkForeign','chkChemicals','chkFloor'].every(id =>
            document.getElementById(id).classList.contains('checked'));
        if (!allPass && !comments) { toast('Ingresa comentarios explicando los rechazos','warning'); return; }
        try {
            const res = await api({
                action:         'submit_inspection',
                TruckID:        currentTruckID,
                CheckOdors:     checked('chkOdors'),
                CheckHoles:     checked('chkHoles'),
                CheckForeign:   checked('chkForeign'),
                CheckChemicals: checked('chkChemicals'),
                CheckFloor:     checked('chkFloor'),
                Comments:       comments,
            }, 'POST');
            toast(res.result==='APPROVED' ? '✓ Inspección APROBADA' : '✗ Inspección RECHAZADA',
                  res.result==='APPROVED' ? 'success' : 'error');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 2 — Waiting zone ──────────────────────────────────
    document.getElementById('btnSendToWaiting').addEventListener('click', () => {
        document.getElementById('wzZoneName').value = '';
        document.getElementById('wzNotes').value    = '';
        $('#modalWaitingZone').modal('show');
    });

    document.getElementById('btnConfirmWaitingZone').addEventListener('click', async () => {
        const zone  = document.getElementById('wzZoneName').value.trim();
        const notes = document.getElementById('wzNotes').value.trim();
        if (!zone) { toast('Indica la zona o área de espera','warning'); return; }
        try {
            await api({ action:'send_to_waiting_zone', TruckID:currentTruckID, WaitingZone:zone, WaitingNotes:notes }, 'POST');
            $('#modalWaitingZone').modal('hide');
            toast('Camión enviado a zona de espera','info');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnRecallFromWaiting').addEventListener('click', async () => {
        if (!confirm('¿Llamar al camión para re-inspección?')) return;
        try {
            await api({ action:'recall_from_waiting', TruckID:currentTruckID }, 'POST');
            toast('Camión llamado para re-inspección','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 3 — Gate ──────────────────────────────────────────
    document.getElementById('btnAssignGate').addEventListener('click', async () => {
        const gateId = document.getElementById('selGate').value;
        const reason = document.getElementById('inpGateReason').value.trim();
        if (!gateId) { toast('Selecciona un gate','warning'); return; }
        try {
            await api({ action: reason ? 'reassign_gate' : 'assign_gate', TruckID:currentTruckID, GateID:gateId, Reason:reason }, 'POST');
            toast('Gate asignado','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnConfirmGate').addEventListener('click', async () => {
        try {
            await api({ action:'confirm_gate', TruckID:currentTruckID }, 'POST');
            toast('Gate confirmado','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnValidateOrders').addEventListener('click', async () => {
        try {
            await api({ action:'validate_orders', TruckID:currentTruckID }, 'POST');
            toast('Órdenes validadas','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnCloseLoading').addEventListener('click', async () => {
        if (!confirm('¿Cerrar la carga de este camión y avanzar a Fase 4?')) return;
        try {
            await api({ action:'close_loading', TruckID:currentTruckID }, 'POST');
            toast('Carga cerrada. Fase 4 activa','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 4 ─────────────────────────────────────────────────
    document.getElementById('btnCloseTruck').addEventListener('click', async () => {
        const notes = document.getElementById('inpClosureNotes').value.trim();
        if (!confirm('¿Confirmar cierre y liberación del camión?')) return;
        try {
            await api({ action:'close_truck', TruckID:currentTruckID, Notes:notes }, 'POST');
            toast('Camión liberado','success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Cancel truck ─────────────────────────────────────────────
    document.getElementById('btnCancelTruck').addEventListener('click', () => {
        document.getElementById('cancelReason').value = '';
        $('#modalCancelTruck').modal('show');
    });

    document.getElementById('btnConfirmCancelTruck').addEventListener('click', async () => {
        const reason = document.getElementById('cancelReason').value.trim();
        if (!reason) { toast('Motivo requerido','warning'); return; }
        try {
            await api({ action:'cancel_truck', TruckID:currentTruckID, Reason:reason }, 'POST');
            $('#modalCancelTruck').modal('hide');
            toast('Camión cancelado','info');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Schedules ───────────────────────────────────────────────
    document.getElementById('schedDate').addEventListener('change', e => { schedDate = e.target.value; loadSchedules(); });

    document.getElementById('btnNewSchedule').addEventListener('click', () => {
        ['nsScheduleID','nsCarrier','nsTruckType','nsWindowStart','nsWindowEnd','nsNotes'].forEach(id => {
            document.getElementById(id).value = '';
        });
        document.getElementById('modalScheduleTitle').textContent = 'Nuevo Schedule';
        $('#modalSchedule').modal('show');
    });

    document.getElementById('btnEditSchedule').addEventListener('click', async () => {
        if (!currentSchedID) return;
        try {
            const schedules = await api({ action:'list_schedules', date:schedDate });
            const s = schedules.find(x => x.ScheduleID == currentSchedID);
            if (!s) return;
            document.getElementById('nsScheduleID').value  = s.ScheduleID;
            document.getElementById('nsCarrier').value     = s.CarrierName;
            document.getElementById('nsTruckType').value   = s.TruckType||'';
            document.getElementById('nsWindowStart').value = s.WindowStart||'';
            document.getElementById('nsWindowEnd').value   = s.WindowEnd||'';
            document.getElementById('nsNotes').value       = s.Notes||'';
            document.getElementById('modalScheduleTitle').textContent = 'Editar Schedule';
            $('#modalSchedule').modal('show');
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnSaveSchedule').addEventListener('click', async () => {
        const id      = document.getElementById('nsScheduleID').value;
        const carrier = document.getElementById('nsCarrier').value.trim();
        if (!carrier) { toast('Transportista requerido','warning'); return; }
        const payload = {
            CarrierName:  carrier,
            TruckType:    document.getElementById('nsTruckType').value.trim(),
            WindowStart:  document.getElementById('nsWindowStart').value,
            WindowEnd:    document.getElementById('nsWindowEnd').value,
            Notes:        document.getElementById('nsNotes').value.trim(),
            ScheduleDate: schedDate,
        };
        try {
            await api({ action: id ? 'update_schedule' : 'create_schedule', ...(id ? {ScheduleID:id} : {}), ...payload }, 'POST');
            toast(id ? 'Schedule actualizado' : 'Schedule creado', 'success');
            $('#modalSchedule').modal('hide');
            loadSchedules();
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnCancelSchedule').addEventListener('click', async () => {
        if (!currentSchedID || !confirm('¿Cancelar este schedule?')) return;
        try {
            await api({ action:'cancel_schedule', ScheduleID:currentSchedID }, 'POST');
            toast('Schedule cancelado','info');
            loadSchedules();
            document.getElementById('scheduleDetailPanel').style.display = 'none';
            currentSchedID = null;
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnAddOrder').addEventListener('click', () => {
        ['aoOrderID','aoPio','aoProgress','aoNotes'].forEach(id => { document.getElementById(id).value = ''; });
        $('#modalAddOrder').modal('show');
    });

    document.getElementById('btnSaveOrder').addEventListener('click', async () => {
        const pio = document.getElementById('aoPio').value.trim();
        if (!pio) { toast('Número PIO requerido para programar la orden','warning'); return; }
        try {
            await api({
                action:          'add_order_to_schedule',
                ScheduleID:      currentSchedID,
                ExternalOrderID: document.getElementById('aoOrderID').value.trim(),
                PioNumber:       pio,
                ProgressPct:     document.getElementById('aoProgress').value,
                Notes:           document.getElementById('aoNotes').value.trim(),
            }, 'POST');
            $('#modalAddOrder').modal('hide');
            toast('Orden agregada','success');
            openSchedule(currentSchedID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Reports ─────────────────────────────────────────────────
    document.getElementById('btnLoadReport').addEventListener('click', loadReport);
    document.getElementById('btnExportExcel').addEventListener('click', exportExcel);

    // ─── Initial load ─────────────────────────────────────────────
    showView('dashboard');
    loadDashboard();
    dashAutoRefresh = setInterval(loadDashboard, 30000);
});
