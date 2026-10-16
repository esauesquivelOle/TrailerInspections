/* ─── Transportation Module — Main JS ─────────────────────────────── */
'use strict';

const API    = 'api.php';
const CSRF   = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const PERMS  = window.TRANSP_PERMS || {};
const LANG   = window.TRANSP_LANG  || 'en';
const I18N   = window.TRANSP_I18N  || {};
const LOCALE = LANG === 'es' ? 'es-MX' : 'en-US';

const CHECK_IDS = ['chkOdors','chkHoles','chkForeign','chkChemicals','chkFloor'];

// ─── State ──────────────────────────────────────────────────────────
let currentView     = 'dashboard';
let currentTruckID  = null;
let currentSchedID  = null;
let currentGate     = null;
let dashDate        = todayStr();
let schedDate       = todayStr();
let reportTable     = null;
let lastReportRows  = [];

// ─── i18n ───────────────────────────────────────────────────────────
// t('key', {name: value}) — replaces {name} placeholders; falls back to the key.
function t(key, vars) {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) Object.keys(vars).forEach(k => { s = s.split('{' + k + '}').join(vars[k]); });
    return s;
}

// ─── Utilities ──────────────────────────────────────────────────────
// Local date as YYYY-MM-DD (toISOString() is UTC and rolls to tomorrow in the evening)
function toYmd(d) {
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

function todayStr() {
    return toYmd(new Date());
}

// Date-only strings are parsed as local midnight, not UTC
function parseDate(s) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
        const [y, m, d] = s.split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    return new Date(s);
}

function fmtDate(s) {
    if (!s) return '—';
    const d = parseDate(s);
    if (isNaN(d)) return s;
    return d.toLocaleDateString(LOCALE, { year:'numeric', month:'short', day:'2-digit' });
}

function fmtDateTime(s) {
    if (!s) return '—';
    const d = parseDate(s);
    if (isNaN(d)) return s;
    return d.toLocaleString(LOCALE, { month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function showLoading(on) {
    document.getElementById('loadingOverlay').classList.toggle('active', on);
}

const TOAST_ICONS = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };

function toast(msg, type = 'info') {
    if (!TOAST_ICONS[type]) type = 'info';
    // Drop the "✓ " / "✗ " prefixes some messages carry — the icon already says it
    msg = String(msg).replace(/^[✓✗]\s*/, '');
    const el = document.createElement('div');
    el.className = `toast-msg ${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `
        <i class="fas ${TOAST_ICONS[type]} toast-icon"></i>
        <div class="toast-body-text">
            <div class="toast-title">${esc(t('toast.' + type))}</div>
            <div class="toast-text">${esc(msg)}</div>
        </div>
        <button type="button" class="toast-close" aria-label="${esc(t('common.close'))}">&times;</button>
        <div class="toast-progress"></div>`;
    const ttl = type === 'error' ? 7000 : 4500;
    el.querySelector('.toast-progress').style.animationDuration = ttl + 'ms';
    const close = () => { el.classList.add('hide'); setTimeout(() => el.remove(), 200); };
    el.querySelector('.toast-close').addEventListener('click', close);
    const box = document.getElementById('toastContainer');
    box.appendChild(el);
    while (box.children.length > 4) box.firstChild.remove();
    setTimeout(close, ttl);
}

// ─── Dialogs (Bootstrap modal instead of the browser's confirm/prompt) ──
const DIALOG_STYLES = {
    primary: { icon:'fa-question',             btn:'btn-primary' },
    success: { icon:'fa-check',                btn:'btn-success' },
    warning: { icon:'fa-exclamation-triangle', btn:'btn-warning' },
    danger:  { icon:'fa-exclamation-triangle', btn:'btn-danger'  },
};

// Resolves true/false (confirm) or the typed text/null (when opts.input is given)
function uiDialog({ title, message, confirmText, type = 'primary', icon, input }) {
    return new Promise(resolve => {
        const st     = DIALOG_STYLES[type] || DIALOG_STYLES.primary;
        const $m     = $('#modalConfirm');
        const ok     = document.getElementById('confirmOk');
        const inWrap = document.getElementById('confirmInputWrap');
        const inp    = document.getElementById('confirmInput');

        const iconEl = document.getElementById('confirmIcon');
        iconEl.className = `confirm-icon mr-3 ${type}`;
        iconEl.innerHTML = `<i class="fas ${icon || st.icon}"></i>`;
        document.getElementById('confirmTitle').textContent   = title || t('dlg.title');
        document.getElementById('confirmMessage').textContent = message || '';
        ok.className   = `btn btn-sm ${st.btn}`;
        ok.textContent = confirmText || t('common.confirm');

        inWrap.style.display = input ? '' : 'none';
        if (input) {
            document.getElementById('confirmInputLabel').textContent = input.label || '';
            inp.value       = input.value || '';
            inp.placeholder = input.placeholder || '';
        }

        let result = input ? null : false;
        const accept = () => { result = input ? inp.value.trim() : true; $m.modal('hide'); };
        ok.onclick  = accept;
        inp.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); accept(); } };

        // When opened over another modal, sit above it and keep the page scroll-locked afterwards
        const stacked = document.querySelectorAll('.modal.show').length > 0;
        $m.css('z-index', stacked ? 1070 : '');
        $m.one('shown.bs.modal', () => {
            if (stacked) $('.modal-backdrop').last().css('z-index', 1065);
            (input ? inp : ok).focus();
        });
        $m.one('hidden.bs.modal', () => {
            ok.onclick = null; inp.onkeydown = null;
            if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
            resolve(result);
        });
        $m.modal('show');
    });
}

// Resolves once the modal has fully closed (avoids two modals animating at once)
function hideModal(sel) {
    return new Promise(resolve => {
        const $m = $(sel);
        if (!$m.hasClass('show')) { resolve(); return; }
        $m.one('hidden.bs.modal', () => resolve());
        $m.modal('hide');
    });
}

const uiConfirm = opts => uiDialog(opts);
const uiPrompt  = opts => uiDialog({ ...opts, input: opts.input || {} });

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function parseApiResponse(res) {
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); }
    catch (_) {
        // Session expired → auth.php returns a redirect script instead of JSON
        if (text.indexOf('login.php') !== -1) { window.top.location.href = '../../login.php'; }
        throw new Error(t('common.unknownError') + ' (HTTP ' + res.status + ')');
    }
    if (!json.ok) throw new Error(json.message || t('common.unknownError'));
    return json.data;
}

// opts.silent = true skips the loading overlay (background refresh)
async function api(params, method = 'GET', opts = {}) {
    if (!opts.silent) showLoading(true);
    try {
        let url, fetchOpts;
        if (method === 'GET') {
            url       = API + '?' + new URLSearchParams(params);
            fetchOpts = { method: 'GET', credentials: 'same-origin' };
        } else {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            Object.entries(params).forEach(([k,v]) => { if (v !== undefined && v !== null) fd.append(k, v); });
            url       = API;
            fetchOpts = { method: 'POST', body: fd, credentials: 'same-origin' };
        }
        return await parseApiResponse(await fetch(url, fetchOpts));
    } finally {
        if (!opts.silent) showLoading(false);
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
            await parseApiResponse(await fetch(API, { method:'POST', body:fd, credentials:'same-origin' }));
        }
    } finally {
        showLoading(false);
    }
}

function statusLabel(status) {
    const key = 'status.' + status;
    return I18N[key] != null ? I18N[key] : status;
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
    return `<span class="badge ${cls[status]||'badge-secondary'}">${esc(statusLabel(status))}</span>`;
}

function isOn(v) {
    return v == '1' || v === 1 || v === true;
}

// Hide controls the user's profile cannot use (the API enforces the same rules)
// ─── Role locks ─────────────────────────────────────────────────────
// Steps that belong to another role stay visible but locked, with who owns them.
// data-perm-mode="hide" removes the element instead (whole sections like Reports).
const CAP_OWNERS = window.TRANSP_CAP_OWNERS || {};

function lockMessage(cap) {
    const roles = (CAP_OWNERS[cap] || ['ADMIN']).map(r => t('role.' + r)).join(' / ');
    return t('lock.only', { roles });
}

function applyPermissions(root = document) {
    root.querySelectorAll('[data-perm]').forEach(el => {
        const cap = el.dataset.perm;
        if (PERMS[cap] || el.dataset.locked) return;
        if (el.dataset.permMode === 'hide') { el.classList.add('d-none'); return; }
        el.dataset.locked = '1';
        const msg = lockMessage(cap);
        if (el.matches('button, a.btn, label.btn')) {
            // Not disabled on purpose: disabled buttons swallow clicks, and we want to explain the lock
            el.classList.add('btn-locked');
            el.setAttribute('aria-disabled', 'true');
            el.title = msg;
            const icon = el.querySelector('i');
            if (icon) icon.className = 'fas fa-lock mr-1';
            else el.insertAdjacentHTML('afterbegin', '<i class="fas fa-lock mr-1"></i>');
            el.querySelectorAll('input').forEach(i => { i.disabled = true; });
        } else {
            el.classList.add('perm-locked');
            el.insertAdjacentHTML('afterbegin', `<div class="lock-banner"><i class="fas fa-lock mr-2"></i>${esc(msg)}</div>`);
            el.querySelectorAll('button, input, select, textarea').forEach(x => { x.disabled = true; });
            el.querySelectorAll('[tabindex]').forEach(x => { x.tabIndex = -1; });
        }
    });
}

// Capture phase runs before any button handler: a locked click never reaches its action
function wireLockHints() {
    document.addEventListener('click', e => {
        const locked = e.target.closest('.btn-locked, .perm-locked');
        if (!locked) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        toast(lockMessage(locked.dataset.perm), 'warning');
    }, true);
}

// ─── View Routing ────────────────────────────────────────────────────
function showView(name) {
    name = name.toLowerCase();
    ['Dashboard','Truck','Schedules','Warehouse','Reports','Roles'].forEach(v => {
        document.getElementById('view'+v).style.display = (v.toLowerCase() === name ? '' : 'none');
    });
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    const navMap = { dashboard:'btnNavDashboard', truck:'btnNavDashboard', schedules:'btnNavSchedules', warehouse:'btnNavWarehouse', reports:'btnNavReports', roles:'btnNavRoles' };
    const nb = document.getElementById(navMap[name]);
    if (nb) nb.classList.add('active');
    currentView = name;

    // Keep the view in the URL hash so a reload / language switch returns here
    const hash = name === 'truck' && currentTruckID ? '#truck/' + currentTruckID : '#' + name;
    if (location.hash !== hash) history.replaceState(null, '', hash);

}

// ═══════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════
async function loadDashboard(silent = false) {
    try {
        const data = await api({ action:'dashboard_data', date: dashDate }, 'GET', { silent });
        renderDashboard(data.trucks || [], data.gates || []);
    } catch(e) {
        if (!silent) toast(e.message, 'error');
    }
}

function renderDashboard(trucks, gates) {
    document.getElementById('dashboardDateLabel').textContent = t('dash.dateLabel', { date: fmtDate(dashDate) });

    const p1  = trucks.filter(tr => tr.Status==='Phase1').length;
    const p2  = trucks.filter(tr => tr.Status==='Phase2' || tr.Status==='Phase2_Waiting').length;
    const p34 = trucks.filter(tr => tr.Status==='Phase3' || tr.Status==='Phase4').length;
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

    grid.innerHTML = trucks.map(tr => {
        const cls      = phaseClass[tr.Status] || 'phase-1';
        const gateStep = !tr.AssignedGate ? ''
            : (tr.LoadingClosedAt ? 'sync.card.loaded' : (tr.GateConfirmedAt ? 'sync.card.loading' : 'sync.card.waitShipping'));
        const gateHtml = tr.AssignedGate
            ? `<div class="truck-gate-lbl mt-1">${esc(t('field.gate'))}</div><div class="truck-gate-val">${esc(tr.AssignedGate)}</div>
               <span class="gate-step-tag ${gateStep.split('.').pop()}">${esc(t(gateStep))}</span>`
            : `<div class="truck-gate-lbl mt-1 text-muted">${esc(t(tr.Status === 'Phase3' ? 'sync.card.waitWarehouse' : 'dash.noGate'))}</div>`;
        const elapsed = tr.CreatedAt ? elapsedTime(tr.CreatedAt) : '';
        const waitingTag = tr.Status === 'Phase2_Waiting'
            ? `<div class="small mt-1" style="color:#e67e22;"><i class="fas fa-tools mr-1"></i>${esc(tr.WaitingZone || t('dash.inRepair'))}</div>`
            : '';

        return `<div class="col-sm-6 col-md-4 col-lg-3 mb-3">
            <div class="truck-card ${cls} p-3" data-truck-id="${parseInt(tr.TruckID)}" role="button" tabindex="0">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <div class="font-weight-bold">${esc(tr.TruckNumber)}</div>
                        <div class="truck-carrier">${esc(tr.CarrierName)}</div>
                    </div>
                    <span class="truck-phase-badge">${esc(statusLabel(tr.Status))}</span>
                </div>
                ${tr.DriverName    ? `<div class="small text-muted"><i class="fas fa-user mr-1"></i>${esc(tr.DriverName)}</div>` : ''}
                ${tr.TrailerNumber ? `<div class="small text-muted"><i class="fas fa-truck mr-1"></i>${esc(t('dash.trailer', { n: tr.TrailerNumber }))}</div>` : ''}
                ${waitingTag}
                ${gateHtml}
                ${elapsed ? `<div class="text-muted" style="font-size:.65rem;margin-top:.4rem;"><i class="fas fa-clock mr-1"></i>${elapsed}</div>` : ''}
            </div>
        </div>`;
    }).join('');

    grid.querySelectorAll('.truck-card').forEach(card => {
        const open = () => openTruck(parseInt(card.dataset.truckId));
        card.addEventListener('click', open);
        card.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
}

function elapsedTime(since) {
    const diff = Math.max(0, Math.floor((Date.now() - parseDate(since)) / 60000));
    if (isNaN(diff)) return '';
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
        window.scrollTo(0, 0);
    } catch(e) {
        toast(e.message, 'error');
    }
}

function renderTruck(data) {
    lastTruckData = data;
    lastTruckSig  = truckSignature(data);
    const truck       = data.truck;
    const inspections = data.inspections || [];
    const gate        = data.gate;
    const gateHistory = data.gateHistory || [];
    currentGate = gate || null;

    document.getElementById('truckTitle').textContent     = t('truck.title', { n: truck.TruckNumber });
    document.getElementById('truckStatusBadge').innerHTML = statusBadge(truck.Status);

    const closed = truck.Status === 'Completed' || truck.Status === 'Cancelled';
    document.getElementById('btnCancelTruck').style.display = closed ? 'none' : '';

    // Phase progress bar
    const phaseNum = { Phase1:1, Phase2:2, Phase2_Waiting:2, Phase3:3, Phase4:4, Completed:5, Cancelled:0 };
    const current  = phaseNum[truck.Status] ?? 1;
    [1,2,3,4].forEach(n => {
        const el  = document.getElementById('pStep'+n);
        const con = document.getElementById('pConn'+n);
        el.className = 'phase-step';
        if (n < current) el.classList.add('done');
        if (n === current) el.classList.add('active');
        if (con) con.className = 'phase-connector' + (n < current ? ' done' : '');
    });

    // Info summary
    const infoFields = [
        ['field.truck',        truck.TruckNumber],
        ['field.trailer',      truck.TrailerNumber],
        ['field.plates',       truck.LicensePlate],
        ['field.carrier',      truck.CarrierName],
        ['field.driver',       truck.DriverName],
        ['field.license',      truck.DriverLicense],
        ['field.phone',        truck.DriverPhone],
        ['field.arrival',      fmtDateTime(truck.ArrivalTime || truck.CreatedAt)],
        ['field.schedule',     truck.WindowStart ? `${truck.WindowStart}–${truck.WindowEnd}` : null],
        ['field.registeredBy', truck.Phase1By],
    ];
    document.getElementById('truckInfoSummary').innerHTML = infoFields
        .filter(([,v]) => v)
        .map(([k,v]) => `<div class="col-6 col-md-3 mb-1"><span class="text-muted">${esc(t(k))}:</span> <strong>${esc(v)}</strong></div>`)
        .join('');

    // Hide all panels
    ['Phase1','Phase2','Phase2Waiting','Phase3','Phase4','Completed','Cancelled'].forEach(p => {
        const el = document.getElementById('truck'+p+'Panel');
        if (el) el.style.display = 'none';
    });
    document.getElementById('phase2WaitingAction').style.display = 'none';

    if (truck.Status === 'Completed') {
        document.getElementById('truckCompletedPanel').style.display = '';
        document.getElementById('completedInfo').textContent =
            t('done.closedBy', { user: truck.Phase4By || '', date: fmtDateTime(truck.Phase4At) });
        return;
    }
    if (truck.Status === 'Cancelled') {
        document.getElementById('truckCancelledPanel').style.display = '';
        return;
    }

    // Phase 1
    if (truck.Status === 'Phase1') {
        document.getElementById('truckPhase1Panel').style.display = '';
        const schedMsg = document.getElementById('scheduleValidationMsg');
        schedMsg.innerHTML = truck.ScheduleID
            ? `<div class="alert alert-success small mb-0"><i class="fas fa-check-circle mr-1"></i>${esc(t('p1.validSchedule'))} <strong>${esc(truck.WindowStart)}–${esc(truck.WindowEnd)}</strong></div>`
            : `<div class="alert alert-danger small mb-0"><i class="fas fa-exclamation-triangle mr-1"></i>${esc(t('p1.noSchedule'))}</div>`;
    }

    // Phase 2 — inspection form
    if (truck.Status === 'Phase2') {
        document.getElementById('truckPhase2Panel').style.display = '';
        renderInspectionHistory(inspections, 'inspectionHistory');
        resetChecklist();
        // Offer send-to-waiting if the last inspection was rejected
        const lastIns = inspections[inspections.length - 1];
        if (lastIns && lastIns.Result === 'REJECTED') {
            document.getElementById('phase2WaitingAction').style.display = '';
        }
    }

    // Phase 2 — waiting zone
    if (truck.Status === 'Phase2_Waiting') {
        document.getElementById('truckPhase2WaitingPanel').style.display = '';
        document.getElementById('waitingZoneInfo').innerHTML = `
            <div class="alert alert-warning mb-2">
                <strong><i class="fas fa-map-marker-alt mr-1"></i>${esc(truck.WaitingZone || t('wz.noZone'))}</strong>
                ${truck.WaitingNotes ? `<div class="mt-1">${esc(truck.WaitingNotes)}</div>` : ''}
                <div class="text-muted small mt-1">${esc(t('wz.sentBy', { user: truck.SentToWaitingBy || '', date: fmtDateTime(truck.SentToWaitingAt) }))}</div>
            </div>`;
        renderInspectionHistory(inspections, 'waitingInspectionHistory');
    }

    // Phase 3 (actions) and Phase 4 (read-only gate summary + closure)
    if (truck.Status === 'Phase3' || truck.Status === 'Phase4') {
        const inPhase3 = truck.Status === 'Phase3';
        document.getElementById('truckPhase3Panel').style.display = '';
        document.getElementById('phase3Actions').style.display    = inPhase3 ? '' : 'none';
        renderPhase3(gate, gateHistory);
        renderTruckOrders(data.orders || []);
        if (inPhase3) {
            document.getElementById('inpGateReason').value = '';
            loadGatesForSelect();
        } else {
            document.getElementById('truckPhase4Panel').style.display = '';
            document.getElementById('inpClosureNotes').value = '';
        }
    }
}

function renderInspectionHistory(inspections, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!inspections.length) {
        el.innerHTML = `<p class="text-muted small">${esc(t('p2.noHistory'))}</p>`;
        return;
    }

    el.innerHTML = `<h6 class="small font-weight-bold mb-2">${esc(t('p2.history'))}</h6>` +
        inspections.map(ins => {
            const ok = ins.Result === 'APPROVED';
            const checks = [
                ['chkShort.odors',     ins.CheckOdors],
                ['chkShort.holes',     ins.CheckHoles],
                ['chkShort.foreign',   ins.CheckForeign],
                ['chkShort.chemicals', ins.CheckChemicals],
                ['chkShort.floor',     ins.CheckFloor],
            ].map(([k,v]) => `<span class="mr-2 ${isOn(v)?'text-success':'text-danger'}"><i class="fas fa-${isOn(v)?'check':'times'} mr-1"></i>${esc(t(k))}</span>`).join('');

            const photos     = ins.photos || [];
            const photosHtml = photos.length
                ? `<div class="photo-grid mt-2">${photos.map(p => {
                    const src = `${API}?action=view_inspection_photo&PhotoID=${parseInt(p.PhotoID)}`;
                    return `<a href="${src}" target="_blank" rel="noopener" class="photo-thumb" title="${esc(p.FileName)}">
                        <img src="${src}" alt="${esc(p.FileName)}" loading="lazy">
                     </a>`;
                }).join('')}</div>`
                : '';

            const uploadBtn = !ok && PERMS.canInspect
                ? `<label class="btn btn-outline-secondary btn-xs py-0 px-2 mt-2 mb-0" style="cursor:pointer;font-size:.75rem;">
                       <i class="fas fa-camera mr-1"></i>${esc(t('p2.uploadPhoto'))}
                       <input type="file" style="display:none;" accept="image/*,.heic" multiple
                              class="inp-photo-file" data-inspection-id="${parseInt(ins.InspectionID)}">
                   </label>`
                : '';

            return `<div class="history-item ${ok?'approved':'rejected'} mb-2">
                <div style="flex:1;min-width:0;">
                    <div>
                        <strong>${esc(t('p2.attempt', { n: ins.AttemptNumber }))}</strong>
                        <span class="badge ${ok?'badge-success':'badge-danger'} ml-1">${esc(t('result.' + ins.Result))}</span>
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
                toast(t('p2.photosUploaded'), 'success');
                openTruck(currentTruckID);
            } catch(e) {
                toast(e.message, 'error');
            }
        });
    });
}

function setCheck(el, on) {
    el.classList.toggle('checked', on);
    el.setAttribute('aria-checked', on ? 'true' : 'false');
    el.querySelector('i').className = on ? 'fas fa-check' : 'fas fa-times';
}

function resetChecklist() {
    document.querySelectorAll('.check-item').forEach(el => setCheck(el, false));
    document.getElementById('inpInspectionComments').value = '';
    updateCommentsRequired();
}

// The "(required…)" hint only shows while at least one item is failing
function updateCommentsRequired() {
    const allPass = CHECK_IDS.every(id => document.getElementById(id).classList.contains('checked'));
    document.getElementById('commentsRequired').style.visibility = allPass ? 'hidden' : 'visible';
}

function renderPhase3(gate, gateHistory) {
    document.getElementById('currentGateDisplay').textContent = gate ? gate.GateName : '—';
    document.getElementById('gateAssignedBy').textContent =
        gate ? t('p3.assignedBy', { user: gate.AssignedBy, date: fmtDateTime(gate.AssignedAt) }) : '';

    const setStatus = (iconId, infoId, by, at) => {
        const icon = document.getElementById(iconId);
        const info = document.getElementById(infoId);
        if (at) {
            icon.innerHTML   = '<i class="fas fa-check-circle text-success"></i>';
            info.textContent = (by || '') + ' — ' + fmtDateTime(at);
        } else {
            icon.innerHTML   = '<i class="fas fa-circle text-muted"></i>';
            info.textContent = t('common.pending');
        }
    };

    const g = gate || {};
    setStatus('iconGateAssigned',    'gateAssignedInfo',    g.AssignedBy,          gate ? g.AssignedAt : null);
    setStatus('iconGateConfirmed',   'gateConfirmedInfo',   g.ShippingConfirmedBy, g.ShippingConfirmedAt);
    setStatus('iconOrdersValidated', 'ordersValidatedInfo', g.OrdersValidatedBy,   g.OrdersValidatedAt);
    setStatus('iconLoadingClosed',   'loadingClosedInfo',   g.LoadingClosedBy,     g.LoadingClosedAt);
    renderNextStep(gate);

    const section = document.getElementById('gateHistorySection');
    if (gateHistory.length > 1) {
        section.style.display = '';
        document.getElementById('gateHistory').innerHTML = gateHistory.map(a =>
            `<div class="history-item small">
                <i class="fas fa-map-marker-alt mr-1 text-primary"></i>
                <strong>${esc(a.GateName)}</strong>
                <span class="text-muted ml-1">${fmtDateTime(a.AssignedAt)}</span>
                ${a.IsActive=='0'||a.IsActive===0
                    ? `<span class="badge badge-secondary ml-1">${esc(t('status.Cancelled'))}</span>${a.CancelReason ? `<span class="text-muted ml-1">${esc(a.CancelReason)}</span>` : ''}`
                    : ''}
            </div>`
        ).join('');
    } else {
        section.style.display = 'none';
        document.getElementById('gateHistory').innerHTML = '';
    }
}

async function loadGatesForSelect() {
    const sel = document.getElementById('selGate');
    try {
        const gates = await api({ action:'list_gates' }, 'GET', { silent: true });
        sel.innerHTML = `<option value="">${esc(t('p3.selectGate'))}</option>` +
            gates.map(g => {
                if (isOn(g.IsBlocked)) {
                    return `<option value="${parseInt(g.GateID)}" disabled>${esc(t('wh.gateBlockedOpt', { gate: g.GateName }))}</option>`;
                }
                const occ = g.OccupiedByTruck ? ` — ${esc(g.OccupiedByTruck)}` : '';
                const dis = g.OccupiedByTruckID && g.OccupiedByTruckID != currentTruckID ? ' disabled' : '';
                return `<option value="${parseInt(g.GateID)}"${dis}>${esc(g.GateName)}${occ}</option>`;
            }).join('');
    } catch(e) {
        toast(e.message, 'error');
    }
}

// What happens next at the gate, and which Shipping button is the next one
function renderNextStep(gate) {
    const box = document.getElementById('phase3NextStep');
    const g   = gate || {};
    let cls, icon, msg, nextBtn = null;
    if (!gate) {
        cls = 'wait';  icon = 'fa-warehouse';   msg = t('sync.next.warehouse');
    } else if (g.LoadingClosedAt) {
        cls = 'done';  icon = 'fa-check-double'; msg = t('sync.next.loaded', { gate: g.GateName });
    } else if (!g.ShippingConfirmedAt) {
        cls = 'ready'; icon = 'fa-check-circle'; msg = t('sync.next.confirm', { gate: g.GateName }); nextBtn = 'btnConfirmGate';
    } else if (!g.OrdersValidatedAt) {
        cls = 'ready'; icon = 'fa-tasks';        msg = t('sync.next.validate', { gate: g.GateName }); nextBtn = 'btnValidateOrders';
    } else {
        cls = 'ready'; icon = 'fa-lock';         msg = t('sync.next.close', { gate: g.GateName });    nextBtn = 'btnCloseLoading';
    }
    box.className = 'next-step mb-3 ' + cls;
    box.innerHTML = `<i class="fas ${icon} mr-2"></i><span>${esc(msg)}</span>`;
    box.style.display = '';

    // Shipping buttons follow the order confirm → validate → close (role locks stay as they are)
    ['btnConfirmGate', 'btnValidateOrders', 'btnCloseLoading'].forEach(id => {
        const btn = document.getElementById(id);
        btn.classList.toggle('btn-next', id === nextBtn);
        if (!btn.classList.contains('btn-locked')) btn.disabled = id !== nextBtn;
    });
}

// ═══════════════════════════════════════════════════════════════════
// LIVE SYNC — poll a tiny fingerprint, reload the open view only when it changes
// ═══════════════════════════════════════════════════════════════════
const SYNC_MS          = 4000;
const SYNC_TIMERS_MS   = 60000;   // elapsed-time counters ("35 min") still tick on their own
let syncVersion        = null;
let syncBusy           = false;
let syncFailures       = 0;
let syncLastFull       = Date.now();

function setLive(ok) {
    const el = document.getElementById('liveIndicator');
    if (el) el.classList.toggle('offline', !ok);
}

async function syncTick() {
    if (syncBusy || document.hidden) return;
    syncBusy = true;
    try {
        const d = await api({ action:'sync_version' }, 'GET', { silent: true });
        syncFailures = 0;
        setLive(true);
        const changed = syncVersion !== null && d.v !== syncVersion;
        syncVersion = d.v;
        if (changed || Date.now() - syncLastFull > SYNC_TIMERS_MS) {
            syncLastFull = Date.now();
            await refreshCurrentView(changed);
        }
    } catch (e) {
        if (++syncFailures >= 2) setLive(false);
    } finally {
        syncBusy = false;
    }
}

async function refreshCurrentView(changed) {
    switch (currentView) {
        case 'dashboard': return loadDashboard(true);
        case 'warehouse': return loadWarehouse(true);
        case 'truck':     return changed ? refreshTruck() : null;
        case 'schedules':
            if (!changed) return;
            await loadSchedules(true);
            if (currentSchedID && !document.querySelector('.modal.show')) refreshScheduleDetail();
            return;
    }
}

async function refreshScheduleDetail() {
    try {
        const [schedules, orders] = await Promise.all([
            api({ action:'list_schedules', date: schedDate }, 'GET', { silent: true }),
            api({ action:'list_schedule_orders', ScheduleID: currentSchedID }, 'GET', { silent: true }),
        ]);
        const sched = schedules.find(x => x.ScheduleID == currentSchedID);
        if (sched) renderScheduleDetail(sched, orders);
    } catch (e) { /* next tick will retry */ }
}

// Fingerprint of what the truck screen shows; re-render only when it differs
function truckSignature(d) {
    const tr = d.truck || {}, g = d.gate || {};
    return JSON.stringify([tr.Status, tr.ScheduleID, tr.WaitingZone, tr.Phase4At,
        g.AssignmentID, g.GateName, g.ShippingConfirmedAt, g.OrdersValidatedAt, g.LoadingClosedAt,
        (d.inspections || []).map(i => [i.InspectionID, (i.photos || []).length]),
        (d.orders || []).map(o => o.ScheduleOrderID), (d.gateHistory || []).length]);
}
let lastTruckSig  = null;
let lastTruckData = null;

// Re-render the open truck without losing what the user is typing or has checked
async function refreshTruck() {
    if (!currentTruckID || document.querySelector('.modal.show')) return;
    let d;
    try { d = await api({ action:'get_truck', TruckID: currentTruckID }, 'GET', { silent: true }); }
    catch (e) { return; }
    const sig = truckSignature(d);
    if (sig === lastTruckSig) return;

    const prevStatus = (lastTruckData && lastTruckData.truck || {}).Status;
    const prevGate   = (lastTruckData && lastTruckData.gate) || {};
    const keepIds = ['inpInspectionComments', 'inpGateReason', 'inpClosureNotes', 'selGate'];
    const kept    = {};
    keepIds.forEach(id => { const el = document.getElementById(id); if (el) kept[id] = el.value; });
    const checks  = CHECK_IDS.filter(id => document.getElementById(id).classList.contains('checked'));
    const scrollY = window.scrollY;

    renderTruck(d);

    if (d.truck.Status === prevStatus) {
        keepIds.forEach(id => { const el = document.getElementById(id); if (el && kept[id] != null && kept[id] !== '') el.value = kept[id]; });
        checks.forEach(id => setCheck(document.getElementById(id), true));
        if (document.getElementById('commentsRequired')) updateCommentsRequired();
    }
    window.scrollTo(0, scrollY);

    // Tell the user what changed and flash it
    const g = d.gate || {};
    let msg = null, flash = null;
    if (d.truck.Status !== prevStatus)                                { msg = t('sync.statusChanged', { status: statusLabel(d.truck.Status) }); }
    else if (g.AssignmentID && g.AssignmentID !== prevGate.AssignmentID) { msg = t('sync.gateAssigned', { gate: g.GateName, user: g.AssignedBy }); flash = 'rowGateAssigned'; }
    else if (g.ShippingConfirmedAt && !prevGate.ShippingConfirmedAt)   { msg = t('sync.gateConfirmed', { user: g.ShippingConfirmedBy }); flash = 'rowGateConfirmed'; }
    else if (g.OrdersValidatedAt && !prevGate.OrdersValidatedAt)       { msg = t('sync.ordersValidated', { user: g.OrdersValidatedBy }); flash = 'rowOrdersValidated'; }
    else if (prevGate.AssignmentID && !g.AssignmentID)                 { msg = t('sync.gateRemoved'); }
    if (msg) toast(msg, 'info');
    if (flash) {
        const el = document.getElementById(flash);
        if (el) { el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); }
    }
}

function startLiveSync() {
    syncTick();
    setInterval(syncTick, SYNC_MS);
    // Coming back to the tab: catch up right away
    document.addEventListener('visibilitychange', () => { if (!document.hidden) syncTick(); });
}

// ═══════════════════════════════════════════════════════════════════
// SCHEDULES
// ═══════════════════════════════════════════════════════════════════
async function loadSchedules(silent = false) {
    try {
        const list = await api({ action:'list_schedules', date: schedDate }, 'GET', { silent });
        renderScheduleList(list);
    } catch(e) {
        if (!silent) toast(e.message, 'error');
    }
}

function showScheduleDetail(on) {
    document.getElementById('scheduleDetailPanel').style.display = on ? '' : 'none';
    document.getElementById('scheduleEmptyPanel').style.display  = on ? 'none' : '';
}

function renderScheduleList(schedules) {
    const el = document.getElementById('scheduleList');
    if (!schedules.length) {
        el.innerHTML = `<p class="text-muted small text-center py-3">${esc(t('sched.none'))}</p>`;
        showScheduleDetail(false);
        currentSchedID = null;
        return;
    }
    el.innerHTML = schedules.map(s => {
        const win      = s.WindowStart ? `${esc(s.WindowStart)}–${esc(s.WindowEnd)}` : esc(t('sched.noWindow'));
        const count    = parseInt(s.OrderCount || 0);
        const withPio  = parseInt(s.OrdersWithPio || 0);
        const pioBadge = withPio < count
            ? `<span class="badge badge-warning ml-1">${withPio}/${count} PIO</span>`
            : (count > 0 ? `<span class="badge badge-success ml-1">${esc(t('sched.orders', { n: count }))}</span>` : '');
        const cancelled = s.Status === 'Cancelled';
        const active    = parseInt(s.ScheduleID) === currentSchedID ? ' active' : '';
        return `<div class="schedule-item ${cancelled?'cancelled':''}${active}" data-id="${parseInt(s.ScheduleID)}"${cancelled ? '' : ' role="button" tabindex="0"'}>
            <div class="d-flex justify-content-between align-items-start">
                <span class="schedule-carrier">${esc(s.CarrierName)}</span>
                ${cancelled ? `<span class="badge badge-secondary">${esc(t('status.Cancelled'))}</span>` : pioBadge}
            </div>
            <div class="schedule-window"><i class="fas fa-clock mr-1"></i>${win}${s.TruckType ? ` — ${esc(s.TruckType)}` : ''}</div>
        </div>`;
    }).join('');

    el.querySelectorAll('.schedule-item:not(.cancelled)').forEach(item => {
        const open = () => openSchedule(parseInt(item.dataset.id));
        item.addEventListener('click', open);
        item.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });

    // Keep the open schedule in sync, or close it if it is gone from this date
    if (currentSchedID && !schedules.some(s => parseInt(s.ScheduleID) === currentSchedID && s.Status !== 'Cancelled')) {
        showScheduleDetail(false);
        currentSchedID = null;
    }
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
    showScheduleDetail(true);
    document.getElementById('schedDetailTitle').textContent = sched.CarrierName;
    document.getElementById('schedDetailInfo').innerHTML = `
        <div><i class="fas fa-clock mr-1"></i>${esc(sched.WindowStart||'?')}–${esc(sched.WindowEnd||'?')}</div>
        ${sched.TruckType ? `<div><i class="fas fa-truck mr-1"></i>${esc(sched.TruckType)}</div>` : ''}
        ${sched.Notes     ? `<div class="text-muted"><i class="fas fa-sticky-note mr-1"></i>${esc(sched.Notes)}</div>` : ''}
    `;

    const cancelled = sched.Status === 'Cancelled';
    document.getElementById('btnEditSchedule').style.display   = cancelled ? 'none' : '';
    document.getElementById('btnCancelSchedule').style.display = cancelled ? 'none' : '';

    const canEdit = !!PERMS.canLogistica;
    document.getElementById('schedOrderList').innerHTML = orders.length
        ? orders.map(o => `
            <div class="order-row">
                <div>
                    <div class="small font-weight-bold">${esc(o.ExternalOrderID||o.BiExternalID||'N/A')}</div>
                    ${isOn(o.HasPio)
                        ? `<span class="order-pio-badge"><i class="fas fa-check mr-1"></i>PIO: ${esc(o.PioNumber)}</span>`
                        : `<span class="order-no-pio"><i class="fas fa-exclamation mr-1"></i>${esc(t('sched.noPio'))}</span>`}
                    ${o.ProgressPct != null ? `<span class="text-muted small ml-2">${parseFloat(o.ProgressPct)}%</span>` : ''}
                    ${o.BiNotes ? `<div class="text-muted small"><i class="fas fa-sticky-note mr-1"></i>${esc(o.BiNotes)}</div>` : ''}
                </div>
                ${canEdit ? `<div class="d-flex align-items-center">
                    <button class="btn btn-outline-secondary btn-xs py-0 px-1 mr-1 btn-edit-pio" title="${esc(t('sched.editPio'))}"
                            data-id="${parseInt(o.ScheduleOrderID)}" data-pio="${esc(o.PioNumber||'')}">
                        <i class="fas fa-edit" style="font-size:.65rem;"></i>
                    </button>
                    <button class="btn btn-outline-danger btn-xs py-0 px-1 btn-remove-order" title="${esc(t('sched.removeOrder'))}"
                            data-id="${parseInt(o.ScheduleOrderID)}">
                        <i class="fas fa-trash" style="font-size:.65rem;"></i>
                    </button>
                </div>` : ''}
            </div>`).join('')
        : `<p class="text-muted small">${esc(t('sched.noOrders'))}</p>`;

    document.querySelectorAll('.btn-edit-pio').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            const pio = await uiPrompt({ title: t('sched.editPio'), message: t('sched.pioPromptMsg'), icon: 'fa-edit',
                confirmText: t('common.save'), input: { label: t('sched.pioPrompt'), value: btn.dataset.pio, placeholder: 'PIO-000000' } });
            if (pio === null) return;
            try {
                await api({ action:'update_order_pio', ScheduleOrderID:btn.dataset.id, PioNumber:pio.trim() }, 'POST');
                toast(t('sched.pioUpdated'), 'success');
                openSchedule(currentSchedID);
            } catch(err) { toast(err.message,'error'); }
        });
    });

    document.querySelectorAll('.btn-remove-order').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            if (!await uiConfirm({ title: t('sched.removeOrder'), message: t('sched.confirmRemoveOrder'), type: 'danger', icon: 'fa-trash-alt', confirmText: t('common.delete') })) return;
            try {
                await api({ action:'remove_order_from_schedule', ScheduleOrderID:btn.dataset.id }, 'POST');
                toast(t('sched.orderRemoved'), 'info');
                openSchedule(currentSchedID);
            } catch(err) { toast(err.message,'error'); }
        });
    });
}

// ═══════════════════════════════════════════════════════════════════
// WAREHOUSE — gate map, safety blocks, gate + order assignment
// ═══════════════════════════════════════════════════════════════════
let whDate          = todayStr();
let whData          = { gates: [], queue: [] };
let whFilter        = 'all';
let whSelectedTruck = null;   // truck picked in the queue (tap flow)
let whDragTruckId   = null;   // truck being dragged
let whGateCtx       = null;   // gate open in the gate modal
let whAssignCtx     = null;   // { truck, current, orders }

const WH_BLOCK_REASONS = ['door', 'spill', 'maintenance', 'hazard', 'inspection', 'other'];

function gateState(g) {
    if (isOn(g.IsBlocked)) return 'blocked';
    return g.OccupiedByTruckID ? 'occupied' : 'available';
}

// "Gate 12" → "12" for the big number on the tile
function gateShort(name) {
    const m = String(name || '').match(/(\d+)\s*$/);
    return m ? m[1] : name;
}

function findGate(id)  { return (whData.gates || []).find(g => parseInt(g.GateID) === parseInt(id)); }
function findQueue(id) { return (whData.queue || []).find(q => parseInt(q.TruckID) === parseInt(id)); }
function readyTrucks() { return (whData.queue || []).filter(q => q.Status === 'Phase3'); }

async function loadWarehouse(silent = false) {
    // Don't repaint under the user while they are in a dialog or dragging
    if (silent && (whDragTruckId || document.querySelector('.modal.show'))) return;
    try {
        whData = await api({ action:'warehouse_board', date: whDate }, 'GET', { silent });
        renderWarehouse();
    } catch(e) {
        if (!silent) toast(e.message, 'error');
    }
}

function renderWarehouse() {
    const gates = whData.gates || [];
    const ready = readyTrucks();
    const count = st => gates.filter(g => gateState(g) === st).length;
    document.getElementById('whCountAll').textContent       = gates.length;
    document.getElementById('whCountAvailable').textContent = count('available');
    document.getElementById('whCountOccupied').textContent  = count('occupied');
    document.getElementById('whCountBlocked').textContent   = count('blocked');
    document.getElementById('whCountWaiting').textContent   = ready.length;

    if (whSelectedTruck && !ready.some(q => q.TruckID == whSelectedTruck.TruckID)) clearWhSelection();
    renderWhQueue(ready, (whData.queue || []).filter(q => q.Status !== 'Phase3'));
    renderWhGates();
}

function renderWhQueue(ready, incoming) {
    const qEl = document.getElementById('whQueue');
    qEl.innerHTML = ready.length ? ready.map(q => {
        const sel    = whSelectedTruck && whSelectedTruck.TruckID == q.TruckID ? ' selected' : '';
        const window = q.WindowStart ? `${esc(q.WindowStart)}–${esc(q.WindowEnd || '')}` : '';
        const ordersReady = parseInt(q.OrdersReady) || 0;
        return `<div class="wh-truck${sel}${PERMS.canWarehouse ? '' : ' wh-truck-locked'}" draggable="${PERMS.canWarehouse ? 'true' : 'false'}" data-truck-id="${parseInt(q.TruckID)}" role="button" tabindex="0">
            <div class="d-flex justify-content-between align-items-start">
                <strong class="wh-truck-num"><i class="fas fa-grip-vertical wh-grip mr-1"></i>${esc(q.TruckNumber)}</strong>
                <span class="wh-wait"><i class="far fa-clock mr-1"></i>${elapsedTime(q.ApprovedAt || q.CreatedAt)}</span>
            </div>
            <div class="small text-muted text-truncate">${esc(q.CarrierName)}</div>
            <div class="small mt-1">
                ${window ? `<span class="mr-2"><i class="far fa-calendar-alt mr-1"></i>${window}</span>` : ''}
                <span class="${ordersReady ? 'text-success' : 'text-muted'}"><i class="fas fa-boxes mr-1"></i>${esc(t('wh.ordersReady', { n: ordersReady }))}</span>
            </div>
        </div>`;
    }).join('') : `<div class="wh-empty"><i class="fas fa-check-circle mb-1 d-block"></i>${esc(t('wh.queueEmpty'))}</div>`;

    const iEl = document.getElementById('whIncoming');
    iEl.innerHTML = incoming.length ? incoming.map(q => `
        <div class="wh-incoming">
            <div class="text-truncate"><strong>${esc(q.TruckNumber)}</strong> <span class="text-muted">· ${esc(q.CarrierName)}</span></div>
            ${statusBadge(q.Status)}
        </div>`).join('') : `<div class="small text-muted">${esc(t('wh.incomingEmpty'))}</div>`;
}

function renderWhGates() {
    const grid   = document.getElementById('whGateGrid');
    const search = document.getElementById('whSearch').value.trim().toLowerCase();
    const gates  = (whData.gates || []).filter(g => {
        if (whFilter !== 'all' && gateState(g) !== whFilter) return false;
        if (!search) return true;
        return [g.GateName, g.OccupiedByTruck, g.CarrierName].some(v => String(v || '').toLowerCase().includes(search));
    });
    document.getElementById('whNoGates').style.display = gates.length ? 'none' : '';

    grid.innerHTML = gates.map(g => {
        const st  = gateState(g);
        const num = esc(gateShort(g.GateName));
        let body;
        if (st === 'available') {
            body = `<div class="wh-gate-state"><i class="fas fa-check-circle mr-1"></i>${esc(t('wh.available'))}</div>`;
        } else if (st === 'blocked') {
            body = `<div class="wh-gate-state"><i class="fas fa-lock mr-1"></i>${esc(t('wh.blockedLbl'))}</div>
                    <div class="wh-gate-sub text-truncate" title="${esc(g.BlockReason)}">${esc(g.BlockReason || '')}</div>`;
        } else {
            body = `<div class="wh-gate-truck text-truncate"><i class="fas fa-truck mr-1"></i>${esc(g.OccupiedByTruck)}</div>
                    <div class="wh-gate-sub text-truncate">${esc(g.CarrierName || '')}</div>
                    <div class="wh-gate-sub"><i class="fas fa-boxes mr-1"></i>${parseInt(g.OrderCount) || 0}
                        <span class="ml-2"><i class="far fa-clock mr-1"></i>${elapsedTime(g.AssignedAt)}</span></div>`;
        }
        const steps = st === 'occupied'
            ? `<span class="wh-gate-steps">
                   <i class="fas fa-map-marker-alt ${g.ShippingConfirmedAt ? 'on' : ''}" title="${esc(t('wh.stepConfirmed'))}"></i>
                   <i class="fas fa-tasks ${g.OrdersValidatedAt ? 'on' : ''}" title="${esc(t('wh.stepValidated'))}"></i>
               </span>` : '';
        return `<div class="wh-gate ${st}" data-gate-id="${parseInt(g.GateID)}" role="button" tabindex="0" title="${esc(g.GateName)}">
            <div class="d-flex justify-content-between align-items-start"><span class="wh-gate-num">${num}</span>${steps}</div>
            ${body}
        </div>`;
    }).join('');
}

function setWhSelection(truck) {
    whSelectedTruck = truck;
    const bar = document.getElementById('whSelectionBar');
    document.getElementById('whGateGrid').classList.toggle('targeting', !!truck);
    bar.style.display = truck ? '' : 'none';
    if (truck) document.getElementById('whSelectionText').textContent = t('wh.selected', { truck: truck.TruckNumber });
    document.querySelectorAll('#whQueue .wh-truck').forEach(el =>
        el.classList.toggle('selected', !!truck && el.dataset.truckId == truck.TruckID));
}
function clearWhSelection() { setWhSelection(null); }

// ─── Gate modal (what you can do with one gate) ─────────────────────
async function openGateModal(gateId) {
    const g = findGate(gateId);
    if (!g) return;
    whGateCtx = g;
    const st      = gateState(g);
    const header  = document.getElementById('gateModalHeader');
    const body    = document.getElementById('gateModalBody');
    const footer  = document.getElementById('gateModalFooter');
    header.className = 'modal-header py-2 text-white ' + ({ available:'bg-success', occupied:'bg-primary', blocked:'bg-danger' })[st];
    header.querySelector('.close').classList.add('text-white');
    const icon = ({ available:'fa-door-open', occupied:'fa-truck', blocked:'fa-lock' })[st];
    document.getElementById('gateModalTitle').innerHTML = `<i class="fas ${icon} mr-1"></i>${esc(g.GateName)}`;

    if (st === 'available') {
        const ready = readyTrucks();
        body.innerHTML = `
            <div class="wh-panel mb-3" data-perm="canWarehouse">
                <div class="font-weight-bold mb-2"><i class="fas fa-truck-loading text-success mr-1"></i>${esc(t('wh.assignTruck'))}</div>
                ${ready.length ? `
                <div class="d-flex">
                    <select id="gmTruck" class="form-control form-control-sm mr-2">
                        <option value="">${esc(t('wh.pickTruck'))}</option>
                        ${ready.map(q => `<option value="${parseInt(q.TruckID)}">${esc(q.TruckNumber)} — ${esc(q.CarrierName)}</option>`).join('')}
                    </select>
                    <button type="button" class="btn btn-success btn-sm text-nowrap" id="gmAssign">${esc(t('wh.continue'))}</button>
                </div>` : `<div class="small text-muted">${esc(t('wh.noReadyTrucks'))}</div>`}
            </div>
            <div class="wh-panel wh-panel-danger" data-perm="canWarehouse">
                <div class="font-weight-bold mb-1"><i class="fas fa-lock text-danger mr-1"></i>${esc(t('wh.blockGate'))}</div>
                <div class="small text-muted mb-2">${esc(t('wh.blockIntro'))}</div>
                <label class="small font-weight-bold mb-1">${esc(t('wh.blockReason'))}</label>
                <div class="wh-reasons mb-2">
                    ${WH_BLOCK_REASONS.map(r => `<button type="button" class="wh-reason" data-reason="${esc(t('wh.reason.' + r))}">${esc(t('wh.reason.' + r))}</button>`).join('')}
                </div>
                <textarea id="gmBlockNotes" class="form-control form-control-sm mb-2" rows="2" maxlength="500" placeholder="${esc(t('wh.blockNotesPh'))}"></textarea>
                <button type="button" class="btn btn-danger btn-sm" id="gmBlock"><i class="fas fa-lock mr-1"></i>${esc(t('wh.blockGate'))}</button>
            </div>`;
        footer.innerHTML = `<button type="button" class="btn btn-light btn-sm" data-dismiss="modal">${esc(t('common.close'))}</button>`;

        const btnAssign = document.getElementById('gmAssign');
        if (btnAssign) btnAssign.onclick = async () => {
            const id = document.getElementById('gmTruck').value;
            if (!id) { toast(t('wh.pickTruck'), 'warning'); return; }
            await hideModal('#modalGate');
            openAssign(parseInt(id), g.GateID);
        };
        let reason = '';
        body.querySelectorAll('.wh-reason').forEach(b => b.onclick = () => {
            reason = b.dataset.reason;
            body.querySelectorAll('.wh-reason').forEach(x => x.classList.toggle('active', x === b));
        });
        document.getElementById('gmBlock').onclick = async () => {
            if (!reason) { toast(t('wh.err.blockReason'), 'warning'); return; }
            try {
                const notes = document.getElementById('gmBlockNotes').value.trim();
                await api({ action:'gate_block', GateID: g.GateID, Reason: reason, Notes: notes }, 'POST');
                toast(t('wh.blocked', { gate: g.GateName }), 'success');
                $('#modalGate').modal('hide');
                loadWarehouse();
            } catch(e) { toast(e.message, 'error'); }
        };
    } else if (st === 'blocked') {
        body.innerHTML = `
            <div class="wh-blocked-box mb-3">
                <div class="wh-blocked-reason"><i class="fas fa-exclamation-triangle mr-2"></i>${esc(g.BlockReason || '')}</div>
                ${g.BlockNotes ? `<div class="mt-1">${esc(g.BlockNotes)}</div>` : ''}
                <div class="small text-muted mt-2">${esc(t('wh.blockedBy', { user: g.BlockedBy || '', date: fmtDateTime(g.BlockedAt) }))}</div>
            </div>
            <div data-perm="canWarehouse">
                <label class="small font-weight-bold" for="gmUnblockNotes">${esc(t('wh.unblockNotes'))}</label>
                <textarea id="gmUnblockNotes" class="form-control form-control-sm" rows="2" maxlength="500" placeholder="${esc(t('wh.unblockNotesPh'))}"></textarea>
            </div>`;
        footer.innerHTML = `
            <button type="button" class="btn btn-light btn-sm" data-dismiss="modal">${esc(t('common.close'))}</button>
            <button type="button" class="btn btn-success btn-sm" id="gmUnblock" data-perm="canWarehouse"><i class="fas fa-lock-open mr-1"></i>${esc(t('wh.unblock'))}</button>`;
        document.getElementById('gmUnblock').onclick = async () => {
            const notes = document.getElementById('gmUnblockNotes').value.trim();
            await hideModal('#modalGate');
            if (!await uiConfirm({ title: t('wh.unblock') + ' — ' + g.GateName, message: t('wh.confirmUnblock'),
                                   type: 'success', icon: 'fa-lock-open', confirmText: t('wh.unblock') })) return;
            try {
                await api({ action:'gate_unblock', GateID: g.GateID, Notes: notes }, 'POST');
                toast(t('wh.unblocked', { gate: g.GateName }), 'success');
                loadWarehouse();
            } catch(e) { toast(e.message, 'error'); }
        };
    } else {
        body.innerHTML = `
            <div class="d-flex align-items-center mb-3">
                <div class="wh-big-icon mr-3"><i class="fas fa-truck"></i></div>
                <div>
                    <div class="h5 mb-0">${esc(g.OccupiedByTruck)}</div>
                    <div class="small text-muted">${esc(g.CarrierName || '')}${g.DriverName ? ' · ' + esc(g.DriverName) : ''}</div>
                    <div class="small text-muted">${esc(t('wh.atGate', { t: elapsedTime(g.AssignedAt) }))} · ${esc(g.AssignedBy || '')}</div>
                </div>
            </div>
            <div class="d-flex mb-3 small">
                <span class="mr-3 ${g.ShippingConfirmedAt ? 'text-success' : 'text-muted'}"><i class="fas fa-${g.ShippingConfirmedAt ? 'check-circle' : 'circle'} mr-1"></i>${esc(t('wh.stepConfirmed'))}</span>
                <span class="${g.OrdersValidatedAt ? 'text-success' : 'text-muted'}"><i class="fas fa-${g.OrdersValidatedAt ? 'check-circle' : 'circle'} mr-1"></i>${esc(t('wh.stepValidated'))}</span>
            </div>
            <h6 class="small font-weight-bold text-muted text-uppercase mb-2">${esc(t('wh.loadedOrders'))}</h6>
            <div id="gmOrders"><div class="small text-muted"><i class="fas fa-spinner fa-spin"></i></div></div>`;
        footer.innerHTML = `
            <button type="button" class="btn btn-outline-secondary btn-sm mr-auto" id="gmOpenTruck"><i class="fas fa-external-link-alt mr-1"></i>${esc(t('wh.openTruck'))}</button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="gmRemove" data-perm="canWarehouse"><i class="fas fa-sign-out-alt mr-1"></i>${esc(t('wh.removeFromGate'))}</button>
            <button type="button" class="btn btn-primary btn-sm" id="gmEdit" data-perm="canWarehouse"><i class="fas fa-edit mr-1"></i>${esc(t('wh.editOrders'))}</button>`;
        const truckId = parseInt(g.OccupiedByTruckID);
        document.getElementById('gmOpenTruck').onclick = async () => { await hideModal('#modalGate'); openTruck(truckId); };
        document.getElementById('gmEdit').onclick      = async () => { await hideModal('#modalGate'); openAssign(truckId, g.GateID); };
        document.getElementById('gmRemove').onclick    = async () => {
            await hideModal('#modalGate');
            const reason = await uiPrompt({ title: t('wh.removeTitle', { truck: g.OccupiedByTruck, gate: g.GateName }),
                message: t('wh.removeMsg'), type: 'danger', icon: 'fa-sign-out-alt',
                confirmText: t('wh.removeFromGate'), input: { label: t('wh.removeReason') } });
            if (reason === null) return;
            if (!reason) { toast(t('api.reassignReasonRequired'), 'warning'); return; }
            try {
                await api({ action:'warehouse_unassign', TruckID: truckId, Reason: reason }, 'POST');
                toast(t('wh.unassigned', { gate: g.GateName }), 'success');
                loadWarehouse();
            } catch(e) { toast(e.message, 'error'); }
        };
        api({ action:'get_truck', TruckID: truckId }, 'GET', { silent: true })
            .then(d => { document.getElementById('gmOrders').innerHTML = ordersChips(d.orders || []); })
            .catch(() => { document.getElementById('gmOrders').innerHTML = ''; });
    }

    applyPermissions(body);
    applyPermissions(footer);
    document.getElementById('gateModalHistory').innerHTML = '<i class="fas fa-spinner fa-spin text-muted"></i>';
    $('#modalGate').modal('show');
    try {
        const hist = await api({ action:'gate_history', GateID: g.GateID }, 'GET', { silent: true });
        document.getElementById('gateModalHistory').innerHTML = hist.length ? hist.map(h => `
            <div class="wh-history-row">
                <span class="wh-h-badge ${esc(h.Action)}">${esc(t('wh.h.' + h.Action))}</span>
                <span class="flex-grow-1 text-truncate">
                    ${h.TruckNumber ? `<strong>${esc(h.TruckNumber)}</strong> ` : ''}${esc(h.Reason || '')}${h.Notes ? ' — ' + esc(h.Notes) : ''}
                </span>
                <span class="text-muted text-nowrap ml-2">${esc(h.By || '')} · ${fmtDateTime(h.At)}</span>
            </div>`).join('') : `<span class="text-muted">${esc(t('wh.noHistory'))}</span>`;
    } catch(e) {
        document.getElementById('gateModalHistory').innerHTML = '';
    }
}

function ordersChips(orders) {
    if (!orders.length) return `<div class="small text-muted">${esc(t('p3.noOrders'))}</div>`;
    return `<div class="d-flex flex-wrap">${orders.map(o => `
        <span class="order-chip"><i class="fas fa-box mr-1"></i>${esc(o.OrderID || 'N/A')}
            ${o.PioNumber ? `<span class="text-muted ml-1">· ${esc(o.PioNumber)}</span>` : ''}</span>`).join('')}</div>`;
}

function renderTruckOrders(orders) {
    document.getElementById('truckOrdersTitle').textContent = t('p3.ordersToLoad', { n: orders.length });
    document.getElementById('truckOrdersList').innerHTML    = ordersChips(orders);
}

// ─── Assign modal (gate + orders for one truck) ─────────────────────
async function openAssign(truckId, gateId) {
    try {
        const d = await api({ action:'warehouse_truck_orders', TruckID: truckId });
        whAssignCtx = { truck: d.truck, current: d.gate, orders: d.orders || [] };
    } catch(e) { toast(e.message, 'error'); return; }

    const { truck, current, orders } = whAssignCtx;
    const isNew = !current;
    document.getElementById('assignTitle').textContent = isNew ? t('wh.assignTitle') : t('wh.editTitle', { gate: current.GateName });
    document.getElementById('btnConfirmAssignLbl').textContent = isNew ? t('wh.confirmAssign') : t('wh.confirmSave');
    document.getElementById('assignTruckNum').textContent = truck.TruckNumber;
    document.getElementById('assignTruckInfo').innerHTML =
        `${esc(truck.CarrierName)}${truck.WindowStart ? ` · <i class="far fa-calendar-alt"></i> ${esc(truck.WindowStart)}–${esc(truck.WindowEnd || '')}` : ''}`;

    // Gate list: free gates + the truck's own gate; blocked/occupied shown but disabled
    const curGateId = current ? parseInt(current.GateID) : null;
    const sel = document.getElementById('assignGate');
    sel.innerHTML = (whData.gates || []).map(g => {
        const st   = gateState(g);
        const mine = parseInt(g.GateID) === curGateId;
        const ok   = mine || st === 'available';
        const tag  = st === 'blocked' ? ` — ${t('wh.blockedLbl')}` : (st === 'occupied' && !mine ? ` — ${g.OccupiedByTruck}` : '');
        return `<option value="${parseInt(g.GateID)}"${ok ? '' : ' disabled'}>${esc(g.GateName + tag)}</option>`;
    }).join('');
    sel.value = String(gateId || curGateId || '');
    const reasonWrap = document.getElementById('assignReasonWrap');
    const syncReason = () => { reasonWrap.style.display = curGateId && parseInt(sel.value) !== curGateId ? '' : 'none'; };
    sel.onchange = syncReason;
    document.getElementById('assignReason').value = '';
    syncReason();

    // Orders: the truck's schedule first, then other orders of the day
    const ownId = truck.ScheduleID ? parseInt(truck.ScheduleID) : null;
    const own   = orders.filter(o => ownId && parseInt(o.ScheduleID) === ownId);
    const other = orders.filter(o => !(ownId && parseInt(o.ScheduleID) === ownId));
    const checkedByDefault = o => parseInt(o.TruckID) === parseInt(truck.TruckID) || (isNew && isOn(o.HasPio) && !o.TruckID && own.includes(o));
    const row = (o, showSched) => {
        const pio = isOn(o.HasPio);
        return `<label class="wh-order${pio ? '' : ' disabled'}">
            <input type="checkbox" class="wh-order-chk" value="${parseInt(o.ScheduleOrderID)}"${pio ? '' : ' disabled'}${pio && checkedByDefault(o) ? ' checked' : ''}>
            <span class="wh-order-main">
                <strong>${esc(o.OrderID || 'N/A')}</strong>
                ${pio ? `<span class="order-pio-badge ml-1">PIO: ${esc(o.PioNumber)}</span>` : `<span class="order-no-pio ml-1">${esc(t('wh.noPioLocked'))}</span>`}
                ${o.ProgressPct != null ? `<span class="text-muted small ml-1">${parseFloat(o.ProgressPct)}%</span>` : ''}
                ${showSched ? `<div class="small text-muted">${esc(o.CarrierName)} ${o.WindowStart ? '· ' + esc(o.WindowStart) + '–' + esc(o.WindowEnd || '') : ''}</div>` : ''}
                ${o.Notes ? `<div class="small text-muted"><i class="fas fa-sticky-note mr-1"></i>${esc(o.Notes)}</div>` : ''}
            </span>
        </label>`;
    };
    const otherChecked = other.some(o => isOn(o.HasPio) && checkedByDefault(o));
    let html = '';
    if (!orders.length) {
        html = `<div class="wh-empty">${esc(t('wh.noOrders'))}</div>`;
    } else {
        if (ownId) {
            const s = own[0] || {};
            html += `<div class="wh-order-group"><div class="wh-order-group-title">${esc(t('wh.ownSchedule'))}
                ${s.CarrierName ? `<span class="text-muted font-weight-normal">— ${esc(s.CarrierName)} ${s.WindowStart ? esc(s.WindowStart) + '–' + esc(s.WindowEnd || '') : ''}</span>` : ''}</div>
                ${own.length ? own.map(o => row(o, false)).join('') : `<div class="small text-muted px-2">${esc(t('wh.noOrders'))}</div>`}</div>`;
        } else {
            html += `<div class="alert alert-info small py-2">${esc(t('wh.noSchedule'))}</div>`;
        }
        if (other.length) {
            html += `<details class="wh-order-group"${otherChecked || !ownId ? ' open' : ''}>
                <summary class="wh-order-group-title">${esc(t('wh.otherOrders', { n: other.length }))}</summary>
                ${other.map(o => row(o, true)).join('')}</details>`;
        }
    }
    const box = document.getElementById('assignOrders');
    box.innerHTML = html;
    const updateCount = () => {
        document.getElementById('assignCount').textContent =
            t('wh.selectedCount', { n: box.querySelectorAll('.wh-order-chk:checked').length });
    };
    box.querySelectorAll('.wh-order-chk').forEach(c => c.addEventListener('change', updateCount));
    updateCount();

    clearWhSelection();
    $('#modalAssign').modal('show');
}

async function confirmAssign() {
    if (!whAssignCtx) return;
    const { truck, current } = whAssignCtx;
    const gateId  = parseInt(document.getElementById('assignGate').value);
    const reason  = document.getElementById('assignReason').value.trim();
    const ids     = Array.from(document.querySelectorAll('#assignOrders .wh-order-chk:checked')).map(c => c.value);
    const moving  = current && parseInt(current.GateID) !== gateId;
    if (!gateId) { toast(t('p3.selectGateWarn'), 'warning'); return; }
    if (moving && !reason) { toast(t('p3.reasonNeeded'), 'warning'); document.getElementById('assignReason').focus(); return; }
    if (!ids.length) {
        // Stacks on top of the assign modal
        const go = await uiConfirm({ title: t('wh.confirmAssign'), message: t('wh.noOrdersConfirm'), type: 'warning', confirmText: t('wh.continue') });
        if (!go) return;
    }
    try {
        const res = await api({ action:'warehouse_assign', TruckID: truck.TruckID, GateID: gateId, Reason: reason, OrderIDs: ids.join(',') }, 'POST');
        const key = res.New ? 'wh.assigned' : (res.Moved ? 'wh.moved' : 'wh.ordersUpdated');
        toast(t(key, { gate: res.GateName, n: res.Orders }), 'success');
        $('#modalAssign').modal('hide');
        whAssignCtx = null;
        loadWarehouse();
    } catch(e) {
        toast(e.message, 'error');
        loadWarehouse(true);
    }
}

function initWarehouse() {
    if (!PERMS.canWarehouse) {
        document.getElementById('viewWarehouse').insertAdjacentHTML('afterbegin',
            `<div class="lock-banner lock-banner-page mb-3"><i class="fas fa-lock mr-2"></i>${esc(t('lock.whViewOnly'))}</div>`);
    }
    document.getElementById('whDate').value = whDate;
    document.getElementById('whDate').addEventListener('change', e => { whDate = e.target.value || todayStr(); loadWarehouse(); });
    document.getElementById('btnWhRefresh').addEventListener('click', () => loadWarehouse());
    document.getElementById('whSearch').addEventListener('input', renderWhGates);
    document.getElementById('btnWhCancelSel').addEventListener('click', clearWhSelection);
    document.getElementById('btnConfirmAssign').addEventListener('click', confirmAssign);
    document.getElementById('assignSelectAll').addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#assignOrders .wh-order-chk:not(:disabled)').forEach(c => { c.checked = true; });
        document.querySelectorAll('#assignOrders details').forEach(d => { d.open = true; });
        document.querySelector('#assignOrders .wh-order-chk')?.dispatchEvent(new Event('change'));
    });
    document.getElementById('assignSelectNone').addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#assignOrders .wh-order-chk').forEach(c => { c.checked = false; });
        document.querySelector('#assignOrders .wh-order-chk')?.dispatchEvent(new Event('change'));
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && whSelectedTruck) clearWhSelection(); });

    document.getElementById('whFilters').addEventListener('click', e => {
        const chip = e.target.closest('.wh-chip[data-filter]');
        if (!chip) return;
        whFilter = chip.dataset.filter;
        document.querySelectorAll('#whFilters .wh-chip').forEach(c => c.classList.toggle('active', c === chip));
        renderWhGates();
    });

    // Queue: tap to select, or drag
    const queue = document.getElementById('whQueue');
    const pick  = el => {
        if (!PERMS.canWarehouse) { toast(lockMessage('canWarehouse'), 'warning'); return; }
        const q = findQueue(el.dataset.truckId);
        setWhSelection(whSelectedTruck && whSelectedTruck.TruckID == q.TruckID ? null : q);
    };
    queue.addEventListener('click', e => { const el = e.target.closest('.wh-truck'); if (el) pick(el); });
    queue.addEventListener('keydown', e => {
        const el = e.target.closest('.wh-truck');
        if (el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pick(el); }
    });
    queue.addEventListener('dragstart', e => {
        const el = e.target.closest('.wh-truck');
        if (!el) return;
        whDragTruckId = parseInt(el.dataset.truckId);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(whDragTruckId));
        el.classList.add('dragging');
        document.getElementById('whGateGrid').classList.add('targeting');
    });
    queue.addEventListener('dragend', e => {
        const el = e.target.closest('.wh-truck');
        if (el) el.classList.remove('dragging');
        whDragTruckId = null;
        document.getElementById('whGateGrid').classList.toggle('targeting', !!whSelectedTruck);
        document.querySelectorAll('.wh-gate.drop-hover').forEach(g => g.classList.remove('drop-hover'));
    });

    // Gate grid: click / keyboard / drop target
    const grid = document.getElementById('whGateGrid');
    const gateClick = el => {
        const g = findGate(el.dataset.gateId);
        if (!g) return;
        if (gateState(g) === 'available' && whSelectedTruck) openAssign(parseInt(whSelectedTruck.TruckID), g.GateID);
        else openGateModal(g.GateID);
    };
    grid.addEventListener('click', e => { const el = e.target.closest('.wh-gate'); if (el) gateClick(el); });
    grid.addEventListener('keydown', e => {
        const el = e.target.closest('.wh-gate');
        if (el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); gateClick(el); }
    });
    grid.addEventListener('dragover', e => {
        const el = e.target.closest('.wh-gate.available');
        if (!el || !whDragTruckId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        grid.querySelectorAll('.drop-hover').forEach(g => { if (g !== el) g.classList.remove('drop-hover'); });
        el.classList.add('drop-hover');
    });
    grid.addEventListener('dragleave', e => {
        const el = e.target.closest('.wh-gate');
        if (el && !el.contains(e.relatedTarget)) el.classList.remove('drop-hover');
    });
    grid.addEventListener('drop', e => {
        const el = e.target.closest('.wh-gate.available');
        if (!el) return;
        e.preventDefault();
        const truckId = parseInt(e.dataTransfer.getData('text/plain')) || whDragTruckId;
        el.classList.remove('drop-hover');
        if (truckId) openAssign(truckId, parseInt(el.dataset.gateId));
    });
}

// ═══════════════════════════════════════════════════════════════════
// USERS & ROLES (Administrator) — each change saves right away
// ═══════════════════════════════════════════════════════════════════
const ALL_ROLES = window.TRANSP_ROLES || [];
let rolesUsers  = [];
let rolesSearchTimer = null;

async function loadRoles() {
    try {
        const d = await api({ action:'roles_list' });
        rolesUsers = d.users || [];
        renderRoles();
    } catch(e) { toast(e.message, 'error'); }
}

function renderRoles(highlight) {
    const body = document.getElementById('rolesBody');
    if (!rolesUsers.length) {
        body.innerHTML = `<tr><td colspan="${ALL_ROLES.length + 3}" class="text-center text-muted py-4">${esc(t('roles.none'))}</td></tr>`;
        return;
    }
    body.innerHTML = rolesUsers.map(u => {
        const me = u.Username.toLowerCase() === String((window.TRANSP_USER || {}).username || '').toLowerCase();
        return `<tr data-user="${esc(u.Username)}" class="${u.Username === highlight ? 'roles-row-new' : ''}">
            <td>
                <div class="font-weight-bold">${esc(u.Name || u.Username)}</div>
                <div class="small text-muted">${esc(u.Username)}</div>
            </td>
            ${ALL_ROLES.map(r => {
                const on   = (u.Roles || []).includes(r);
                const lock = me && r === 'ADMIN';   // can't remove your own admin role
                return `<td class="text-center">
                    <label class="role-toggle role-${r.toLowerCase()}${on ? ' on' : ''}${lock ? ' fixed' : ''}" title="${esc(t('roleDesc.' + r))}">
                        <input type="checkbox" value="${r}"${on ? ' checked' : ''}${lock ? ' disabled' : ''}>
                        <i class="fas fa-${on ? 'check' : 'plus'}"></i>
                    </label>
                </td>`;
            }).join('')}
            <td class="small text-muted text-nowrap">${u.UpdatedAt ? esc(u.UpdatedBy || '') + '<br>' + fmtDateTime(u.UpdatedAt) : ''}</td>
            <td class="text-right">${me ? '' : `<button type="button" class="btn btn-sm btn-link text-danger roles-remove" title="${esc(t('roles.removeUser'))}"><i class="fas fa-user-times"></i></button>`}</td>
        </tr>`;
    }).join('');
}

async function saveUserRoles(username, roles) {
    try {
        const res = await api({ action:'roles_set', Username: username, Roles: roles.join(',') }, 'POST', { silent: true });
        const u = rolesUsers.find(x => x.Username === username);
        if (u) { u.Roles = res.Roles; u.UpdatedBy = (window.TRANSP_USER || {}).username; u.UpdatedAt = toYmd(new Date()) + ' ' + new Date().toTimeString().slice(0, 8); }
        if (!res.Roles.length) rolesUsers = rolesUsers.filter(x => x.Username !== username);
        toast(t(res.Roles.length ? 'roles.saved' : 'roles.removed', { user: username }), 'success');
    } catch(e) {
        toast(e.message, 'error');
    }
    renderRoles();
}

function initRoles() {
    const body = document.getElementById('rolesBody');
    body.addEventListener('change', e => {
        const chk = e.target.closest('.role-toggle input');
        if (!chk) return;
        const tr    = chk.closest('tr');
        const roles = Array.from(tr.querySelectorAll('.role-toggle input:checked')).map(c => c.value);
        saveUserRoles(tr.dataset.user, roles);
    });
    body.addEventListener('click', async e => {
        const btn = e.target.closest('.roles-remove');
        if (!btn) return;
        const user = btn.closest('tr').dataset.user;
        if (!await uiConfirm({ title: t('roles.removeUser'), message: t('roles.confirmRemove', { user }),
                               type: 'danger', icon: 'fa-user-times', confirmText: t('roles.removeUser') })) return;
        saveUserRoles(user, []);
    });

    // Search active users to add them; a new user appears in the table with no roles until one is checked
    const input   = document.getElementById('rolesSearch');
    const results = document.getElementById('rolesSearchResults');
    input.addEventListener('input', () => {
        clearTimeout(rolesSearchTimer);
        const q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        rolesSearchTimer = setTimeout(async () => {
            try {
                const list = await api({ action:'roles_search_users', q }, 'GET', { silent: true });
                results.innerHTML = list.length ? list.map(u => `
                    <button type="button" class="roles-result" data-user="${esc(u.Username)}" data-name="${esc(u.Name)}">
                        <strong>${esc(u.Name || u.Username)}</strong> <span class="text-muted">${esc(u.Username)}</span>
                    </button>`).join('') : `<div class="small text-muted p-2">${esc(t('roles.noResults'))}</div>`;
                results.style.display = '';
            } catch(e) { results.style.display = 'none'; }
        }, 250);
    });
    results.addEventListener('click', e => {
        const b = e.target.closest('.roles-result');
        if (!b) return;
        if (!rolesUsers.some(u => u.Username.toLowerCase() === b.dataset.user.toLowerCase())) {
            rolesUsers.unshift({ Username: b.dataset.user, Name: b.dataset.name, Roles: [] });
            toast(t('roles.pickRole'), 'info');
        }
        input.value = '';
        results.style.display = 'none';
        renderRoles(b.dataset.user);
    });
    document.addEventListener('click', e => { if (!e.target.closest('.roles-add')) results.style.display = 'none'; });
}

// ═══════════════════════════════════════════════════════════════════
// IMPORT SCHEDULES (Excel / CSV) — parsed in the browser, validated again by the API
// ═══════════════════════════════════════════════════════════════════
const XLSX_URL      = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
const IMPORT_FIELDS = ['date','carrier','truckType','start','end','orderId','pio','progress','notes'];
const IMPORT_MAX_PREVIEW = 200;
// Accepted column headers in English and Spanish (lowercase, no accents/symbols)
const IMPORT_HEADERS = {
    date:      ['date','fecha','scheduledate','fechaschedule','fechadeschedule'],
    carrier:   ['carrier','carriername','transportista','lineatransportista'],
    truckType: ['trucktype','tipodecamion','tipocamion','tipo','type'],
    start:     ['starttime','start','windowstart','horainicio','inicio','desde'],
    end:       ['endtime','end','windowend','horafin','fin','hasta'],
    orderId:   ['orderid','order','externalorderid','ordernumber','iddeorden','idorden','orden','numerodeorden'],
    pio:       ['pionumber','pio','piono','numeropio','numerodepio'],
    progress:  ['progress','progresspct','avance','porcentaje','porcentajeavance'],
    notes:     ['notes','notas','comments','comentarios','observaciones'],
};

let xlsxPromise = null;
let importParsed = [];

function loadXlsx() {
    if (window.XLSX) return Promise.resolve();
    if (!xlsxPromise) {
        xlsxPromise = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src     = XLSX_URL;
            s.onload  = resolve;
            s.onerror = () => { xlsxPromise = null; reject(new Error(t('imp.libError'))); };
            document.head.appendChild(s);
        });
    }
    return xlsxPromise;
}

const pad2 = n => String(n).padStart(2, '0');

function normHeader(h) {
    return String(h || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

// Excel serial, Date-like text (YYYY-MM-DD, M/D/YYYY or D/M/YYYY in Spanish) → YYYY-MM-DD
function cellDate(v) {
    if (v === '' || v == null) return '';
    if (typeof v === 'number') {
        const d = XLSX.SSF.parse_date_code(v);
        return d ? `${d.y}-${pad2(d.m)}-${pad2(d.d)}` : String(v);
    }
    const s = String(v).trim();
    let m = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/);
    if (m) return `${m[1]}-${pad2(m[2])}-${pad2(m[3])}`;
    m = s.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/);
    if (m) {
        let a = +m[1], b = +m[2];
        const y = m[3].length === 2 ? '20' + m[3] : m[3];
        // Day-first in Spanish unless impossible; month-first in English unless impossible
        const dayFirst = a > 12 || (LANG === 'es' && b <= 12);
        const [mon, day] = dayFirst ? [b, a] : [a, b];
        return `${y}-${pad2(mon)}-${pad2(day)}`;
    }
    return s;
}

// Excel time fraction or text (8:00, 08:00:00, 2:30 PM) → HH:MM
function cellTime(v) {
    if (v === '' || v == null) return '';
    if (typeof v === 'number') {
        if (v >= 1 && Number.isInteger(v)) return String(v);   // not a time — let validation flag it
        const mins = Math.round((v % 1) * 1440) % 1440;
        return pad2(Math.floor(mins / 60)) + ':' + pad2(mins % 60);
    }
    const s = String(v).trim();
    const m = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap])?\.?\s*(m\.?)?$/i);
    if (!m) return s;
    let h = +m[1];
    const ap = (m[3] || '').toLowerCase();
    if (ap === 'p' && h < 12) h += 12;
    if (ap === 'a' && h === 12) h = 0;
    return pad2(h) + ':' + m[2];
}

// "50%", "50", "0.5" with a % format → 50
function cellProgress(text) {
    const s = String(text || '').trim();
    if (s === '') return '';
    const n = parseFloat(s.replace('%', '').replace(',', '.'));
    return isNaN(n) ? s : String(n);
}

// Mirrors ScheduleRepository::validateImportRow — returns an i18n key or null
function validateImportRow(r) {
    const p = r.date.split('-').map(Number);
    const d = p.length === 3 ? new Date(p[0], p[1] - 1, p[2]) : null;
    if (!d || isNaN(d) || d.getMonth() !== p[1] - 1 || d.getDate() !== p[2]) return 'imp.err.date';
    if (!r.carrier) return 'imp.err.carrier';
    const timeRe = /^([01]\d|2[0-3]):[0-5]\d$/;
    if ((r.start && !timeRe.test(r.start)) || (r.end && !timeRe.test(r.end))) return 'imp.err.time';
    if (r.start && r.end && r.end <= r.start) return 'imp.err.window';
    if (r.progress !== '' && (isNaN(r.progress) || +r.progress < 0 || +r.progress > 100)) return 'imp.err.progress';
    if (r.carrier.length > 200 || r.truckType.length > 100 || r.orderId.length > 100 || r.pio.length > 100 || r.notes.length > 500) return 'imp.err.length';
    return null;
}

function resetImport() {
    importParsed = [];
    document.getElementById('importFile').value = '';
    document.getElementById('importFileName').textContent = '';
    document.getElementById('importMessage').innerHTML    = '';
    document.getElementById('importResult').innerHTML     = '';
    document.getElementById('importPreview').style.display = 'none';
    document.getElementById('importPreviewBody').innerHTML = '';
    setImportButton(0);
}

function setImportButton(n) {
    document.getElementById('btnConfirmImport').disabled = n === 0;
    document.getElementById('btnConfirmImportLbl').textContent = t('imp.import') + (n ? ` (${n})` : '');
}

function importMessage(html, type = 'info') {
    document.getElementById('importMessage').innerHTML = html ? `<div class="alert alert-${type} small py-2">${html}</div>` : '';
}

async function handleImportFile(file) {
    const fileName = file.name;
    resetImport();
    document.getElementById('importFileName').textContent = fileName;
    importMessage(`<i class="fas fa-spinner fa-spin mr-1"></i>${esc(t('imp.loadingLib'))}`);
    try {
        await loadXlsx();
        const wb = XLSX.read(await file.arrayBuffer(), { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        // raw → numbers for dates/times; text → what the user sees (IDs, "50%")
        const raw  = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true,  defval: '', blankrows: true });
        const text = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '', blankrows: true });

        // Header = first row (within the first 10) that matches at least two known columns
        let headerIdx = -1, colMap = {};
        for (let i = 0; i < Math.min(10, raw.length) && headerIdx < 0; i++) {
            const map = {};
            raw[i].forEach((h, c) => {
                const n = normHeader(h);
                const f = IMPORT_FIELDS.find(k => IMPORT_HEADERS[k].includes(n));
                if (f && map[f] == null) map[f] = c;
            });
            if (Object.keys(map).length >= 2) { headerIdx = i; colMap = map; }
        }
        if (headerIdx < 0 || colMap.carrier == null) { importMessage(esc(t('imp.noHeader')), 'danger'); return; }

        const get = (arr, f) => colMap[f] == null ? '' : arr[colMap[f]];
        for (let i = headerIdx + 1; i < raw.length; i++) {
            const rr = raw[i] || [], tr = text[i] || [];
            if (rr.every(v => String(v).trim() === '')) continue;
            const row = {
                row:       i + 1,
                date:      cellDate(get(rr, 'date')) || schedDate,
                carrier:   String(get(tr, 'carrier')).trim(),
                truckType: String(get(tr, 'truckType')).trim(),
                start:     cellTime(get(rr, 'start')),
                end:       cellTime(get(rr, 'end')),
                orderId:   String(get(tr, 'orderId')).trim(),
                pio:       String(get(tr, 'pio')).trim(),
                progress:  cellProgress(get(tr, 'progress')),
                notes:     String(get(tr, 'notes')).trim(),
            };
            row.error = validateImportRow(row);
            importParsed.push(row);
        }
        importMessage('');
        renderImportPreview();
    } catch (e) {
        importMessage(esc(e.message === t('imp.libError') ? e.message : t('imp.readError')), 'danger');
    }
}

function renderImportPreview() {
    const valid     = importParsed.filter(r => !r.error);
    const schedKeys = new Set(valid.map(r => [r.date, r.carrier.toLowerCase(), r.start, r.end].join('|')));
    const orders    = valid.filter(r => r.orderId).length;
    const errors    = importParsed.length - valid.length;

    document.getElementById('importPreview').style.display = '';
    document.getElementById('importSummary').innerHTML =
        `<span class="${errors ? 'text-danger' : 'text-success'}">${esc(t('imp.summary', {
            rows: importParsed.length, schedules: schedKeys.size, orders, errors }))}</span>`;

    document.getElementById('importPreviewBody').innerHTML = importParsed.slice(0, IMPORT_MAX_PREVIEW).map(r => {
        const status = r.error
            ? `<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>${esc(t(r.error))}</span>`
            : (r.orderId && !r.pio
                ? `<span class="badge badge-warning">${esc(t('imp.warn.noPio'))}</span>`
                : '<i class="fas fa-check-circle text-success"></i>');
        return `<tr class="${r.error ? 'table-danger' : ''}">
            <td>${r.row}</td>
            ${IMPORT_FIELDS.map(f => `<td>${esc(r[f])}</td>`).join('')}
            <td class="text-nowrap">${status}</td>
        </tr>`;
    }).join('');
    document.getElementById('importPreviewMore').textContent =
        importParsed.length > IMPORT_MAX_PREVIEW ? t('imp.previewMore', { n: IMPORT_MAX_PREVIEW }) : '';
    setImportButton(valid.length);
}

async function confirmImport() {
    const rows = importParsed.filter(r => !r.error).map(r => {
        const o = { row: r.row };
        IMPORT_FIELDS.forEach(f => { o[f] = r[f]; });
        return o;
    });
    if (!rows.length) { toast(t('imp.noRows'), 'warning'); return; }
    try {
        const res = await api({ action: 'import_schedules', rows: JSON.stringify(rows) }, 'POST');
        setImportButton(0);   // avoid importing the same file twice by accident
        let html = `<div class="alert alert-success small mb-2"><i class="fas fa-check-circle mr-1"></i>${esc(t('imp.result', {
            created: res.schedulesCreated, reused: res.schedulesReused, added: res.ordersAdded, skipped: res.ordersSkipped }))}</div>`;
        if (res.withoutPio) {
            html += `<div class="alert alert-warning small mb-2"><i class="fas fa-exclamation-triangle mr-1"></i>${esc(t('imp.resultNoPio', { n: res.withoutPio }))}</div>`;
        }
        if (res.errors && res.errors.length) {
            html += `<div class="alert alert-danger small mb-0">${esc(t('imp.resultErrors', { n: res.errors.length }))}<ul class="mb-0">` +
                res.errors.slice(0, 50).map(e => `<li>${esc(t('imp.row'))} ${parseInt(e.row)}: ${esc(e.error)}</li>`).join('') + '</ul></div>';
        }
        document.getElementById('importResult').innerHTML = html;
        toast(t('imp.done', { orders: res.ordersAdded, schedules: res.schedulesCreated }), 'success');

        // Jump to the imported date when the current one is not part of the file
        if (res.dates && res.dates.length && !res.dates.includes(schedDate)) {
            schedDate = res.dates[0];
            document.getElementById('schedDate').value = schedDate;
            currentSchedID = null;
            showScheduleDetail(false);
        }
        loadSchedules();
    } catch (e) {
        toast(e.message, 'error');
    }
}

async function downloadImportTemplate() {
    try {
        await loadXlsx();
    } catch (e) { toast(e.message, 'error'); return; }
    const d   = schedDate;
    const ymd = d.replace(/-/g, '');
    const rows = [
        IMPORT_FIELDS.map(f => t('col.' + f)),
        [d, 'Swift Transportation', '53ft Dry Van', '08:00', '10:00', `WMS-${ymd}-001`, 'PIO-100001', 0,  ''],
        [d, 'Swift Transportation', '53ft Dry Van', '08:00', '10:00', `WMS-${ymd}-002`, 'PIO-100002', 25, ''],
        [d, 'J.B. Hunt',            '53ft Reefer',  '11:00', '13:00', `WMS-${ymd}-003`, 'PIO-100003', 0,  ''],
    ];
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [12, 26, 16, 11, 11, 22, 14, 11, 30].map(w => ({ wch: w }));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Schedules');
    XLSX.writeFile(wb, 'schedule_import_template.xlsx');
}

// ═══════════════════════════════════════════════════════════════════
// REPORTS
// ═══════════════════════════════════════════════════════════════════
function reportCells(r) {
    return [
        r.TruckDate ? String(r.TruckDate).slice(0,10) : '',
        r.TruckNumber || '',
        r.TrailerNumber || '',
        r.CarrierName || '',
        r.DriverName || '',
        r.LastGate || '',
        r.TotalInspections || 0,
        r.RejectedInspections || 0,
        statusLabel(r.Status),
        r.CreatedAt ? fmtDateTime(r.CreatedAt) : '',
        r.Phase4At ? fmtDateTime(r.Phase4At) : '',
    ];
}

async function loadReport() {
    const filters = {
        action:   'report_history',
        dateFrom: document.getElementById('rptDateFrom').value,
        dateTo:   document.getElementById('rptDateTo').value,
        carrier:  document.getElementById('rptCarrier').value.trim(),
        status:   document.getElementById('rptStatus').value,
    };
    if (filters.dateFrom && filters.dateTo && filters.dateFrom > filters.dateTo) {
        toast(t('rpt.dateRangeInvalid'), 'warning');
        return;
    }
    try {
        const [rows, kpis] = await Promise.all([
            api(filters),
            api({ action:'report_kpis', dateFrom:filters.dateFrom, dateTo:filters.dateTo }),
        ]);
        lastReportRows = rows || [];
        document.getElementById('rptKpis').style.display = '';
        document.getElementById('rKpiTotal').textContent      = kpis.totalTrucks || 0;
        document.getElementById('rKpiCompleted').textContent  = kpis.completed || 0;
        document.getElementById('rKpiRejections').textContent = kpis.rejectedInspections || 0;
        document.getElementById('rKpiAvgIns').textContent     = kpis.avgInspections || '0';

        if (reportTable) { reportTable.destroy(); reportTable = null; }
        const tbody = document.querySelector('#reportTable tbody');
        tbody.innerHTML = lastReportRows.map(r => `<tr>
            <td>${esc(r.TruckDate ? String(r.TruckDate).slice(0,10) : '')}</td>
            <td>${esc(r.TruckNumber)}</td>
            <td>${esc(r.TrailerNumber||'')}</td>
            <td>${esc(r.CarrierName)}</td>
            <td>${esc(r.DriverName||'')}</td>
            <td>${esc(r.LastGate||'')}</td>
            <td class="text-center">${parseInt(r.TotalInspections)||0}</td>
            <td class="text-center ${r.RejectedInspections>0?'text-danger':''}">${parseInt(r.RejectedInspections)||0}</td>
            <td>${statusBadge(r.Status)}</td>
            <td>${fmtDateTime(r.CreatedAt)}</td>
            <td>${r.Phase4At ? fmtDateTime(r.Phase4At) : '—'}</td>
        </tr>`).join('');

        const dtOpts = { order: [[0,'desc']], pageLength: 25 };
        if (LANG === 'es') dtOpts.language = { url:'https://cdn.datatables.net/plug-ins/1.10.21/i18n/Spanish.json' };
        reportTable = $('#reportTable').DataTable(dtOpts);
    } catch(e) {
        toast(e.message, 'error');
    }
}

// Exports every row of the last search (not just the visible DataTables page)
function exportExcel() {
    if (!lastReportRows.length) { toast(t('rpt.noData'), 'warning'); return; }
    const headers = ['date','truck','trailer','carrier','driver','gate','inspections','rejections','status','entry','closed']
        .map(k => t('field.' + k));
    const rows = lastReportRows.map(reportCells);
    const csv  = [headers, ...rows].map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = t('rpt.fileName') + '_' + todayStr() + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

// ═══════════════════════════════════════════════════════════════════
// EVENT HANDLERS
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {

    applyPermissions();

    // ─── Language switch: keep the current view (hash) after reload ──
    document.querySelectorAll('.lang-switch a').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            if (a.dataset.lang === LANG) return;
            location.href = '?lang=' + a.dataset.lang + location.hash;
        });
    });

    // ─── Date pickers init ───────────────────────────────────────
    document.getElementById('dashDate').value    = dashDate;
    document.getElementById('schedDate').value   = schedDate;
    document.getElementById('rptDateFrom').value = toYmd(new Date(Date.now() - 30*86400000));
    document.getElementById('rptDateTo').value   = todayStr();

    // ─── Nav buttons ─────────────────────────────────────────────
    document.getElementById('btnNavDashboard').addEventListener('click', () => { showView('dashboard'); loadDashboard(); });
    document.getElementById('btnNavSchedules').addEventListener('click', () => { showView('schedules'); loadSchedules(); });
    document.getElementById('btnNavReports').addEventListener('click',   () => showView('reports'));
    document.getElementById('btnNavWarehouse').addEventListener('click', () => { showView('warehouse'); loadWarehouse(); });
    document.getElementById('btnNavRoles').addEventListener('click', () => { showView('roles'); loadRoles(); });
    initRoles();
    wireLockHints();
    startLiveSync();
    initWarehouse();

    // ─── Dashboard ───────────────────────────────────────────────
    document.getElementById('btnRefreshDashboard').addEventListener('click', () => loadDashboard());
    document.getElementById('dashDate').addEventListener('change', e => { dashDate = e.target.value || todayStr(); loadDashboard(); });

    document.getElementById('btnNewTruck').addEventListener('click', async () => {
        ['ntTruckNumber','ntTrailerNumber','ntLicensePlate','ntCarrierName','ntDriverName','ntDriverLicense','ntDriverPhone','ntNotes']
            .forEach(id => { document.getElementById(id).value = ''; });
        const sel = document.getElementById('ntScheduleID');
        sel.innerHTML = `<option value="">${esc(t('nt.noSchedule'))}</option>`;
        try {
            const schedules = await api({ action:'list_schedules', date: todayStr() });
            sel.innerHTML += schedules.filter(s => s.Status==='Active').map(s =>
                `<option value="${parseInt(s.ScheduleID)}">${esc(s.CarrierName)} ${esc(s.WindowStart||'')}–${esc(s.WindowEnd||'')}</option>`
            ).join('');
        } catch(e) { toast(e.message, 'error'); }
        $('#modalNewTruck').modal('show');
    });
    $('#modalNewTruck').on('shown.bs.modal', () => document.getElementById('ntTruckNumber').focus());

    // Picking a schedule pre-fills the carrier when it is still empty
    document.getElementById('ntScheduleID').addEventListener('change', e => {
        const carrier = document.getElementById('ntCarrierName');
        const opt     = e.target.selectedOptions[0];
        if (e.target.value && !carrier.value.trim() && opt) {
            carrier.value = opt.textContent.replace(/\s+[\d:]*–[\d:]*$/, '').trim();
        }
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
        if (!truck.TruckNumber) { toast(t('nt.truckRequired'),'warning');   return; }
        if (!truck.CarrierName) { toast(t('nt.carrierRequired'),'warning'); return; }
        if (!truck.DriverName)  { toast(t('nt.driverRequired'),'warning');  return; }
        try {
            const res = await api(truck, 'POST');
            $('#modalNewTruck').modal('hide');
            toast(t('nt.registered'), 'success');
            openTruck(res.TruckID);
        } catch(e) { toast(e.message, 'error'); }
    });

    document.getElementById('btnBackDashboard').addEventListener('click', () => { showView('dashboard'); loadDashboard(); });

    // ─── Phase 1 ─────────────────────────────────────────────────
    document.getElementById('btnAdvancePhase2').addEventListener('click', async () => {
        if (!await uiConfirm({ title: t('p1.advance'), message: t('p1.confirmAdvance'), icon: 'fa-arrow-right', confirmText: t('dlg.continue') })) return;
        try {
            await api({ action:'advance_to_phase2', TruckID: currentTruckID }, 'POST');
            toast(t('p1.advanced'), 'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 2 — Checklist (click, Space or Enter) ─────────────
    document.querySelectorAll('.check-item').forEach(el => {
        const toggle = () => { setCheck(el, !el.classList.contains('checked')); updateCommentsRequired(); };
        el.addEventListener('click', toggle);
        el.addEventListener('keydown', e => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); } });
    });

    document.getElementById('btnSubmitInspection').addEventListener('click', async () => {
        const checked  = id => document.getElementById(id).classList.contains('checked') ? '1' : '0';
        const comments = document.getElementById('inpInspectionComments').value.trim();
        const allPass  = CHECK_IDS.every(id => checked(id) === '1');
        if (!allPass && !comments) {
            toast(t('p2.commentsNeeded'),'warning');
            document.getElementById('inpInspectionComments').focus();
            return;
        }
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
            const ok = res.result === 'APPROVED';
            toast(t(ok ? 'p2.approved' : 'p2.rejected'), ok ? 'success' : 'error');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 2 — Waiting zone ──────────────────────────────────
    document.getElementById('btnSendToWaiting').addEventListener('click', () => {
        document.getElementById('wzZoneName').value = '';
        document.getElementById('wzNotes').value    = '';
        $('#modalWaitingZone').modal('show');
    });
    $('#modalWaitingZone').on('shown.bs.modal', () => document.getElementById('wzZoneName').focus());

    document.getElementById('btnConfirmWaitingZone').addEventListener('click', async () => {
        const zone  = document.getElementById('wzZoneName').value.trim();
        const notes = document.getElementById('wzNotes').value.trim();
        if (!zone) { toast(t('wz.zoneRequired'),'warning'); return; }
        try {
            await api({ action:'send_to_waiting_zone', TruckID:currentTruckID, WaitingZone:zone, WaitingNotes:notes }, 'POST');
            $('#modalWaitingZone').modal('hide');
            toast(t('wz.sent'),'info');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnRecallFromWaiting').addEventListener('click', async () => {
        if (!await uiConfirm({ title: t('wz.recall'), message: t('wz.confirmRecall'), type: 'primary', icon: 'fa-redo', confirmText: t('dlg.continue') })) return;
        try {
            await api({ action:'recall_from_waiting', TruckID:currentTruckID }, 'POST');
            toast(t('wz.recalled'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 3 — Gate ──────────────────────────────────────────
    document.getElementById('btnAssignGate').addEventListener('click', async () => {
        const gateId = document.getElementById('selGate').value;
        const reason = document.getElementById('inpGateReason').value.trim();
        if (!gateId) { toast(t('p3.selectGateWarn'),'warning'); return; }
        // Changing an existing gate is a reassignment and needs a reason for the audit log
        const isReassign = !!currentGate;
        if (isReassign && !reason) {
            toast(t('p3.reasonNeeded'),'warning');
            document.getElementById('inpGateReason').focus();
            return;
        }
        try {
            await api({ action: isReassign ? 'reassign_gate' : 'assign_gate', TruckID:currentTruckID, GateID:gateId, Reason:reason }, 'POST');
            toast(t('p3.gateAssigned'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnConfirmGate').addEventListener('click', async () => {
        try {
            await api({ action:'confirm_gate', TruckID:currentTruckID }, 'POST');
            toast(t('p3.gateConfirmedToast'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnValidateOrders').addEventListener('click', async () => {
        try {
            await api({ action:'validate_orders', TruckID:currentTruckID }, 'POST');
            toast(t('p3.ordersValidatedToast'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnCloseLoading').addEventListener('click', async () => {
        if (!await uiConfirm({ title: t('p3.closeLoading'), message: t('p3.confirmCloseLoading'), type: 'success', icon: 'fa-lock', confirmText: t('p3.closeLoading') })) return;
        try {
            await api({ action:'close_loading', TruckID:currentTruckID }, 'POST');
            toast(t('p3.loadingClosedToast'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Phase 4 ─────────────────────────────────────────────────
    document.getElementById('btnCloseTruck').addEventListener('click', async () => {
        const notes = document.getElementById('inpClosureNotes').value.trim();
        if (!await uiConfirm({ title: t('p4.release'), message: t('p4.confirmRelease'), type: 'success', icon: 'fa-flag-checkered', confirmText: t('p4.release') })) return;
        try {
            await api({ action:'close_truck', TruckID:currentTruckID, Notes:notes }, 'POST');
            toast(t('p4.released'),'success');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Cancel truck ─────────────────────────────────────────────
    document.getElementById('btnCancelTruck').addEventListener('click', () => {
        document.getElementById('cancelReason').value = '';
        $('#modalCancelTruck').modal('show');
    });
    $('#modalCancelTruck').on('shown.bs.modal', () => document.getElementById('cancelReason').focus());

    document.getElementById('btnConfirmCancelTruck').addEventListener('click', async () => {
        const reason = document.getElementById('cancelReason').value.trim();
        if (!reason) { toast(t('ct.reasonRequired'),'warning'); return; }
        try {
            await api({ action:'cancel_truck', TruckID:currentTruckID, Reason:reason }, 'POST');
            $('#modalCancelTruck').modal('hide');
            toast(t('ct.cancelled'),'info');
            openTruck(currentTruckID);
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Schedules ───────────────────────────────────────────────
    document.getElementById('schedDate').addEventListener('change', e => {
        schedDate = e.target.value || todayStr();
        currentSchedID = null;
        showScheduleDetail(false);
        loadSchedules();
    });

    document.getElementById('btnNewSchedule').addEventListener('click', () => {
        ['nsScheduleID','nsCarrier','nsTruckType','nsWindowStart','nsWindowEnd','nsNotes'].forEach(id => {
            document.getElementById(id).value = '';
        });
        document.getElementById('modalScheduleTitle').textContent = t('sched.new');
        $('#modalSchedule').modal('show');
    });
    $('#modalSchedule').on('shown.bs.modal', () => document.getElementById('nsCarrier').focus());

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
            document.getElementById('modalScheduleTitle').textContent = t('sched.edit');
            $('#modalSchedule').modal('show');
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnSaveSchedule').addEventListener('click', async () => {
        const id      = document.getElementById('nsScheduleID').value;
        const carrier = document.getElementById('nsCarrier').value.trim();
        const start   = document.getElementById('nsWindowStart').value;
        const end     = document.getElementById('nsWindowEnd').value;
        if (!carrier) { toast(t('sched.carrierRequired'),'warning'); return; }
        if (start && end && end <= start) { toast(t('sched.windowInvalid'),'warning'); return; }
        const payload = {
            CarrierName:  carrier,
            TruckType:    document.getElementById('nsTruckType').value.trim(),
            WindowStart:  start,
            WindowEnd:    end,
            Notes:        document.getElementById('nsNotes').value.trim(),
            ScheduleDate: schedDate,
        };
        try {
            await api({ action: id ? 'update_schedule' : 'create_schedule', ...(id ? {ScheduleID:id} : {}), ...payload }, 'POST');
            toast(t(id ? 'sched.updated' : 'sched.created'), 'success');
            $('#modalSchedule').modal('hide');
            await loadSchedules();
            if (id) openSchedule(parseInt(id));
        } catch(e) { toast(e.message,'error'); }
    });

    document.getElementById('btnCancelSchedule').addEventListener('click', async () => {
        if (!currentSchedID) return;
        if (!await uiConfirm({ title: t('sched.cancelSchedule'), message: t('sched.confirmCancel'), type: 'danger', icon: 'fa-ban', confirmText: t('sched.cancelSchedule') })) return;
        try {
            await api({ action:'cancel_schedule', ScheduleID:currentSchedID }, 'POST');
            toast(t('sched.cancelled'),'info');
            currentSchedID = null;
            showScheduleDetail(false);
            loadSchedules();
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Import from Excel ───────────────────────────────────────
    document.getElementById('btnImportSchedules').addEventListener('click', () => {
        resetImport();
        document.getElementById('importColsHelp').textContent = t('imp.colsHelp', { date: fmtDate(schedDate) });
        $('#modalImport').modal('show');
        loadXlsx().catch(() => {});   // warm up the reader while the user picks a file
    });
    document.getElementById('importFile').addEventListener('change', e => {
        if (e.target.files.length) handleImportFile(e.target.files[0]);
    });
    document.getElementById('btnImportTemplate').addEventListener('click', downloadImportTemplate);
    document.getElementById('btnConfirmImport').addEventListener('click', confirmImport);

    document.getElementById('btnAddOrder').addEventListener('click', () => {
        ['aoOrderID','aoPio','aoProgress','aoNotes'].forEach(id => { document.getElementById(id).value = ''; });
        $('#modalAddOrder').modal('show');
    });
    $('#modalAddOrder').on('shown.bs.modal', () => document.getElementById('aoOrderID').focus());

    document.getElementById('btnSaveOrder').addEventListener('click', async () => {
        const pio      = document.getElementById('aoPio').value.trim();
        const progress = document.getElementById('aoProgress').value;
        if (!pio) { toast(t('order.pioRequired'),'warning'); return; }
        if (progress !== '' && (isNaN(progress) || progress < 0 || progress > 100)) { toast(t('order.progressInvalid'),'warning'); return; }
        try {
            await api({
                action:          'add_order_to_schedule',
                ScheduleID:      currentSchedID,
                ExternalOrderID: document.getElementById('aoOrderID').value.trim(),
                PioNumber:       pio,
                ProgressPct:     progress,
                Notes:           document.getElementById('aoNotes').value.trim(),
            }, 'POST');
            $('#modalAddOrder').modal('hide');
            toast(t('order.added'),'success');
            openSchedule(currentSchedID);
            loadSchedules();
        } catch(e) { toast(e.message,'error'); }
    });

    // ─── Reports ─────────────────────────────────────────────────
    document.getElementById('btnLoadReport').addEventListener('click', loadReport);
    document.getElementById('btnExportExcel').addEventListener('click', exportExcel);
    document.getElementById('rptCarrier').addEventListener('keydown', e => { if (e.key === 'Enter') loadReport(); });

    // ─── Initial view (restored from the URL hash) ────────────────
    const hash = location.hash.replace('#', '');
    const truckMatch = hash.match(/^truck\/(\d+)$/);
    if (truckMatch) {
        showView('dashboard');
        loadDashboard();
        openTruck(parseInt(truckMatch[1]));
    } else if (hash === 'schedules') {
        showView('schedules');
        loadSchedules();
    } else if (hash === 'roles' && PERMS.canManageRoles) {
        showView('roles');
        loadRoles();
    } else if (hash === 'warehouse') {
        showView('warehouse');
        loadWarehouse();
    } else if (hash === 'reports') {
        showView('reports');
    } else {
        showView('dashboard');
        loadDashboard();
    }
});
