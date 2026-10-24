import { api, isAdmin, isSuperAdmin, role } from './api';
import { $, esc, toast } from './ui';

const today = document.body.dataset.today;
const dashboardForm = $('#dashboard-filters');
const monitoringForm = $('#monitoring-filters');
let monitoringFilters = {};
let monitoringPage = 1;
let initialized = false;
let monitoringRows = new Map();

const formatClock = (value) => value ? new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value)) : '—';
const formatDate = (value) => new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00+07:00`));
const formatMinutes = (minutes) => `${Math.floor(minutes / 60)}j ${minutes % 60}m`;

function filterValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function setInitialDates(form) {
    form.elements.from.value = today;
    form.elements.to.value = today;
}

function populateFilters({ departments, employees }) {
    [dashboardForm, monitoringForm].forEach((form) => {
        const department = form.elements.department_id;
        const employee = form.elements.employee_id;
        department.innerHTML = '<option value="">Semua departemen</option>' + departments.map((item) => `<option value="${item.id}">${esc(item.name)}</option>`).join('');
        employee.innerHTML = '<option value="">Semua karyawan</option>' + employees.map((item) => `<option value="${item.id}">${esc(item.name)} · ${esc(item.code)}</option>`).join('');
    });
}

function stateLabel(row) {
    const state = row.attendance.state;
    if (state === 'NEEDS_REVIEW') {
        const reason = row.attendance.review_reason;
        return { label: reason === 'AUTO_CHECKOUT' ? 'Auto checkout · review' : 'Perlu review', className: 'danger' };
    }
    return ({
        PRESENT_LATE: { label: 'Terlambat', className: 'warning' },
        PRESENT_LATE_PERMITTED: { label: 'Terlambat berizin', className: 'success' },
        IN_PROGRESS: { label: 'Sedang bekerja', className: 'blue' },
        COMPLETE: { label: 'Selesai', className: 'success' },
        NOT_CHECKED_IN: { label: 'Belum check-in', className: 'neutral' },
        SCHEDULED: { label: 'Terjadwal', className: 'neutral' },
        ABSENT: { label: 'Tidak hadir', className: 'danger' },
    })[state] ?? { label: 'Tidak ada data', className: 'neutral' };
}

function renderRecent(rows) {
    const container = $('#dashboard-activity');
    if (!rows.length) {
        container.innerHTML = '<p class="list-empty">Belum ada event absensi pada rentang ini.</p>';
        return;
    }
    container.innerHTML = `<div class="table-scroll"><table class="data-table attendance-data-table"><thead><tr><th scope="col">Karyawan</th><th scope="col">Sesi</th><th scope="col">Masuk</th><th scope="col">Pulang</th><th scope="col">Status</th></tr></thead><tbody>${rows.map((row) => {
        const status = stateLabel(row);
        const checkout = row.attendance.check_out_at ?? row.attendance.provisional_out_at;
        return `<tr><td data-label="Karyawan"><b>${esc(row.employee.name)}</b><small>${esc(row.employee.department ?? 'Tanpa departemen')}</small></td><td data-label="Sesi">${esc(row.shift.name)} · ${formatDate(row.work_date)}</td><td data-label="Masuk">${formatClock(row.attendance.check_in_at)}</td><td data-label="Pulang">${checkout ? `${formatClock(checkout)}${row.attendance.provisional_out_at && !row.attendance.check_out_at ? ' · provisional' : ''}` : '—'}</td><td data-label="Status"><span class="status ${status.className}">${status.label}</span></td></tr>`;
    }).join('')}</tbody></table></div>`;
}

function showError(selector, message) {
    const box = $(selector);
    box.textContent = message;
    box.hidden = false;
}

async function loadDashboard(filters = filterValues(dashboardForm)) {
    const error = $('#dashboard-error');
    error.hidden = true;
    $('#dashboard-activity').setAttribute('aria-busy', 'true');
    try {
        const { summary, recent } = await api('dashboard/attendance', { query: filters });
        $('#metric-employees').textContent = summary.scheduled_employees;
        $('#metric-checkins').textContent = summary.checked_in_sessions;
        $('#metric-sessions').textContent = `${summary.scheduled_sessions} sesi dijadwalkan`;
        $('#metric-review').textContent = summary.review_sessions;
        $('#metric-minutes').textContent = formatMinutes(summary.verified_minutes);
        $('#dashboard-finding').textContent = summary.review_sessions
            ? `${summary.review_sessions} sesi atau event perlu ditinjau dalam rentang yang dipilih.`
            : 'Tidak ada sesi atau event yang ditandai untuk review dalam rentang yang dipilih.';
        renderRecent(recent);
    } catch (failure) {
        showError('#dashboard-error', failure.message);
        $('#dashboard-activity').innerHTML = '<p class="list-empty">Data absensi gagal dimuat.</p>';
    } finally {
        $('#dashboard-activity').removeAttribute('aria-busy');
    }
}

function renderMonitoring({ data, meta }) {
    monitoringRows = new Map(data.map((row) => [String(row.id), row]));
    $('#monitoring-count').textContent = `${meta.total} sesi pada rentang ini`;
    const container = $('#monitoring-table');
    if (!data.length) {
        container.innerHTML = '<p class="list-empty">Tidak ada sesi untuk filter yang dipilih.</p>';
    } else {
        container.innerHTML = `<div class="table-scroll"><table class="data-table monitoring-data-table"><thead><tr><th scope="col">Tanggal</th><th scope="col">Karyawan</th><th scope="col">Sesi</th><th scope="col">Jadwal</th><th scope="col">Masuk</th><th scope="col">Pulang</th><th scope="col">Status</th><th scope="col">Menit valid</th>${isAdmin ? '<th scope="col">Aksi</th>' : ''}</tr></thead><tbody>${data.map((row) => {
            const status = stateLabel(row);
            const attendance = row.attendance;
            const checkout = attendance.check_out_at ?? attendance.provisional_out_at;
            const actions = isAdmin ? `<td data-label="Aksi" class="workflow-actions">${attendance.needs_review && attendance.check_in_at ? `<button type="button" class="text-button" data-session-action="verify" data-session-id="${row.id}">Verifikasi</button>` : ''}<button type="button" class="text-button" data-session-action="correction" data-session-id="${row.id}">Koreksi langsung</button></td>` : '';
            return `<tr><td data-label="Tanggal">${formatDate(row.work_date)}</td><td data-label="Karyawan"><b>${esc(row.employee.name)}</b><small>${esc(row.employee.department ?? 'Tanpa departemen')}</small></td><td data-label="Sesi">${esc(row.shift.name)} · sesi ${row.shift.seq}</td><td data-label="Jadwal">${formatClock(row.shift.start_at)}–${formatClock(row.shift.end_at)}</td><td data-label="Masuk">${formatClock(attendance.check_in_at)}</td><td data-label="Pulang">${checkout ? `${formatClock(checkout)}${attendance.provisional_out_at && !attendance.check_out_at ? ' · provisional' : ''}` : '—'}</td><td data-label="Status"><span class="status ${status.className}">${status.label}</span>${attendance.review_reason ? `<small>${esc(attendance.review_reason)}</small>` : ''}</td><td data-label="Menit valid" class="number-cell">${attendance.verified_minutes}</td>${actions}</tr>`;
        }).join('')}</tbody></table></div>`;
    }

    const pagination = $('#monitoring-pagination');
    pagination.innerHTML = meta.last_page > 1
        ? `<button type="button" class="secondary-button" data-page="${meta.current_page - 1}"${meta.current_page <= 1 ? ' disabled' : ''}>Sebelumnya</button><span>Halaman ${meta.current_page} dari ${meta.last_page}</span><button type="button" class="secondary-button" data-page="${meta.current_page + 1}"${meta.current_page >= meta.last_page ? ' disabled' : ''}>Berikutnya</button>`
        : '';
    pagination.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => loadMonitoring(Number(button.dataset.page))));
}

async function loadMonitoring(page = 1) {
    const error = $('#monitoring-error');
    error.hidden = true;
    monitoringPage = page;
    $('#monitoring-table').setAttribute('aria-busy', 'true');
    try {
        renderMonitoring(await api('attendance', { query: { ...monitoringFilters, page } }));
    } catch (failure) {
        showError('#monitoring-error', failure.message);
        $('#monitoring-table').innerHTML = '<p class="list-empty">Data monitoring gagal dimuat.</p>';
    } finally {
        $('#monitoring-table').removeAttribute('aria-busy');
    }
}

export async function showDashboard() {
    await loadDashboard();
}

export async function showMonitoring() {
    if (!monitoringFilters.from) monitoringFilters = filterValues(monitoringForm);
    await loadMonitoring(monitoringPage);
}

function inputDateTime(value) {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value));
    const fields = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${fields.year}-${fields.month}-${fields.day}T${fields.hour}:${fields.minute}`;
}

function formDateToUtc(value) {
    return value ? new Date(`${value}:00+07:00`).toISOString() : '';
}

function openWorkflowModal(title, hint, fields, submit, submitLabel = 'Simpan') {
    const dialog = $('#workflow-modal');
    const form = $('#workflow-modal-form');
    $('#workflow-modal-title').textContent = title;
    $('#workflow-modal-hint').textContent = hint;
    $('#workflow-modal-fields').innerHTML = fields;
    $('#workflow-modal-error').hidden = true;
    $('#workflow-modal-submit').textContent = submitLabel;
    form.onsubmit = async (event) => {
        event.preventDefault();
        const body = new FormData(form);
        for (const [key, value] of [...body.entries()]) {
            if (value === '' || (value instanceof File && value.size === 0)) body.delete(key);
        }
        for (const key of ['allowed_until', 'check_in_at', 'check_out_at']) {
            if (body.has(key) && body.get(key)) body.set(key, formDateToUtc(body.get(key)));
        }
        for (const key of ['requested_check_in_at', 'requested_check_out_at']) {
            if (body.has(key) && body.get(key)) body.set(key, formDateToUtc(body.get(key)));
        }
        try {
            await submit(body);
            dialog.close();
            toast('Perubahan tersimpan.');
            await Promise.all([loadDashboard(), loadMonitoring(monitoringPage), showApprovals()]);
        } catch (failure) {
            $('#workflow-modal-error').textContent = failure.message;
            $('#workflow-modal-error').hidden = false;
        }
    };
    dialog.showModal();
}

function decisionModal(kind, item) {
    const isLate = kind === 'late';
    const path = isLate ? `late-permissions/${item.id}/decision` : `attendance-corrections/${item.id}/decision`;
    openWorkflowModal(isLate ? 'Putuskan izin terlambat' : 'Putuskan koreksi absensi', `${item.employee.name} · ${formatDate(item.work_date)} · ${item.shift}`, `
        <label>Keputusan<select name="decision" required><option value="APPROVED">Setujui</option><option value="REJECTED">Tolak</option></select></label>
        <label>Alasan keputusan<textarea name="decision_reason" rows="3" maxlength="1000" required></textarea></label>`, (body) => api(path, { method: 'POST', body }));
}

function renderAttachments(items) {
    return items.length ? `<small class="workflow-files">Bukti: ${items.map((file) => `<a href="/admin/api/attachments/${file.id}">${esc(file.name)}</a>`).join(', ')}</small>` : '';
}

function renderQueueItem(item, kind) {
    const late = kind === 'late';
    const target = late ? (item.allowed_until ? `Target hadir ${formatClock(item.allowed_until)}` : `Izin terlambat ${item.allowed_minutes} menit`) : `Check-in ${item.requested_check_in_at ? formatClock(item.requested_check_in_at) : '—'} · checkout ${item.requested_check_out_at ? formatClock(item.requested_check_out_at) : '—'}`;
    const canDecide = late ? (role === 'super_admin' || role === 'admin' || (role === 'supervisor' && item.requester_id !== Number(document.body.dataset.userId))) : isAdmin;
    return `<article class="workflow-item"><div class="workflow-item-heading"><div><strong>${esc(item.employee.name)}</strong><small>${esc(item.employee.department ?? 'Tanpa departemen')} · ${formatDate(item.work_date)} · ${esc(item.shift)}</small></div><span class="status warning">Menunggu</span></div><p>${esc(item.reason)}</p><small>${target}</small>${renderAttachments(item.attachments)}${canDecide ? `<div class="workflow-item-actions"><button type="button" class="secondary-button" data-decision="${late ? 'late' : 'correction'}" data-request-id="${item.id}">Tinjau</button></div>` : ''}</article>`;
}

export async function showApprovals() {
    const error = $('#workflow-error');
    error.hidden = true;
    try {
        const result = await api('workflows', { query: { status: 'PENDING' } });
        $('#late-permission-count').textContent = `${result.late_permissions.length} menunggu keputusan`;
        $('#correction-count').textContent = `${result.corrections.length} menunggu keputusan`;
        $('#late-permission-queue').innerHTML = result.late_permissions.length ? result.late_permissions.map((item) => renderQueueItem(item, 'late')).join('') : '<p class="list-empty">Tidak ada izin terlambat yang menunggu.</p>';
        $('#correction-queue').innerHTML = result.corrections.length ? result.corrections.map((item) => renderQueueItem(item, 'correction')).join('') : '<p class="list-empty">Tidak ada koreksi yang menunggu.</p>';
        $('#late-permission-queue').querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', () => {
            const item = result.late_permissions.find((request) => request.id === Number(button.dataset.requestId));
            if (item) decisionModal('late', item);
        }));
        $('#correction-queue').querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', () => {
            const item = result.corrections.find((request) => request.id === Number(button.dataset.requestId));
            if (item) decisionModal('correction', item);
        }));
    } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
    }
}

function openVerify(row) {
    openWorkflowModal('Verifikasi checkout', `${row.employee.name} · ${formatDate(row.work_date)} · ${row.shift.name} sesi ${row.shift.seq}`, `
        <label>Waktu checkout yang dikonfirmasi<input type="datetime-local" name="check_out_at" required></label>
        <label>Bukti atau dasar verifikasi<textarea name="reason" rows="3" maxlength="2000" required></textarea></label>
        <p class="field-hint">Masukkan waktu berdasarkan bukti yang diperiksa. Jam akhir jadwal tidak diisi otomatis.</p>`, (body) => api(`attendance/${row.id}/verify`, { method: 'POST', body }));
}

function openDirectCorrection(row) {
    openWorkflowModal('Koreksi absensi langsung', `${row.employee.name} · ${formatDate(row.work_date)} · ${row.shift.name} sesi ${row.shift.seq}`, `
        <label>Check-in yang benar<input type="datetime-local" name="check_in_at" value="${inputDateTime(row.attendance.check_in_at)}"></label>
        <label>Check-out yang benar<input type="datetime-local" name="check_out_at" value="${inputDateTime(row.attendance.check_out_at)}"></label>
        <label>Alasan<textarea name="reason" rows="3" maxlength="2000" required></textarea></label>
        <label>Bukti (JPG, PNG, PDF)<input type="file" name="evidence" accept="image/jpeg,image/png,application/pdf"></label>`, (body) => api(`attendance-corrections/direct/${row.id}`, { method: 'POST', body }));
}

async function loadDirectSessions() {
    const form = $('#direct-late-form');
    if (!form) return;
    const select = form.elements.shift_session_id;
    try {
        const result = await api('attendance', { query: { from: form.elements.work_date.value, to: form.elements.work_date.value, per_page: 100 } });
        select.innerHTML = '<option value="">Pilih sesi</option>' + result.data.map((row) => `<option value="${row.id}">${esc(row.employee.name)} · ${esc(row.shift.name)} ${row.shift.seq} · ${formatClock(row.shift.start_at)}–${formatClock(row.shift.end_at)}</option>`).join('');
    } catch (failure) {
        $('#workflow-error').textContent = failure.message;
        $('#workflow-error').hidden = false;
    }
}

export async function initAttendance() {
    if (initialized) return;
    initialized = true;
    setInitialDates(dashboardForm);
    setInitialDates(monitoringForm);
    $('#workflow-refresh').addEventListener('click', showApprovals);
    $('#workflow-modal').querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => $('#workflow-modal').close()));
    $('#workflow-modal').addEventListener('click', (event) => { if (event.target === $('#workflow-modal')) $('#workflow-modal').close(); });
    $('#monitoring-table').addEventListener('click', (event) => {
        const button = event.target.closest('[data-session-action]');
        const row = button && monitoringRows.get(button.dataset.sessionId);
        if (!row) return;
        if (button.dataset.sessionAction === 'verify') openVerify(row);
        if (button.dataset.sessionAction === 'correction') openDirectCorrection(row);
    });
    const directLateForm = $('#direct-late-form');
    if (directLateForm) {
        directLateForm.elements.work_date.value = today;
        directLateForm.elements.work_date.addEventListener('change', loadDirectSessions);
        directLateForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const body = new FormData(directLateForm);
            body.delete('work_date');
            for (const [key, value] of [...body.entries()]) if (value === '' || (value instanceof File && value.size === 0)) body.delete(key);
            if (body.has('allowed_until')) body.set('allowed_until', formDateToUtc(body.get('allowed_until')));
            try {
                await api('late-permissions/direct', { method: 'POST', body });
                toast('Izin terlambat langsung disimpan.');
                directLateForm.reset();
                directLateForm.elements.work_date.value = today;
                await Promise.all([loadDashboard(), loadMonitoring(monitoringPage), showApprovals()]);
            } catch (failure) {
                showError('#workflow-error', failure.message);
            }
        });
        await loadDirectSessions();
    }
    try {
        populateFilters(await api('attendance/filters'));
    } catch (error) {
        showError('#dashboard-error', error.message);
        showError('#monitoring-error', error.message);
    }
    dashboardForm.addEventListener('submit', (event) => {
        event.preventDefault();
        loadDashboard();
    });
    monitoringForm.addEventListener('submit', (event) => {
        event.preventDefault();
        monitoringFilters = filterValues(monitoringForm);
        loadMonitoring(1);
    });
    await loadDashboard();
}
