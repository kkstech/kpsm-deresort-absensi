import { api, isAdmin, isSuperAdmin, role } from './api';
import { $, $$, esc, icon, toast } from './ui';

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
        container.innerHTML = `<div class="table-scroll"><table class="data-table monitoring-data-table"><thead><tr><th scope="col">Tanggal</th><th scope="col">Karyawan</th><th scope="col">Sesi</th><th scope="col">Jadwal</th><th scope="col">Masuk</th><th scope="col">Pulang</th><th scope="col">Status</th><th scope="col">Menit valid</th><th scope="col">Lembur</th>${isAdmin ? '<th scope="col">Aksi</th>' : ''}</tr></thead><tbody>${data.map((row) => {
            const status = stateLabel(row);
            const attendance = row.attendance;
            const checkout = attendance.check_out_at ?? attendance.provisional_out_at;
            const actions = isAdmin ? `<td data-label="Aksi" class="workflow-actions">${attendance.needs_review && attendance.check_in_at ? `<button type="button" class="action-btn success" data-session-action="verify" data-session-id="${row.id}" title="Verifikasi absensi" aria-label="Verifikasi absensi ${esc(row.employee.name)}">${icon('shield-check')}</button>` : ''}<button type="button" class="action-btn" data-session-action="correction" data-session-id="${row.id}" title="Koreksi langsung" aria-label="Koreksi langsung ${esc(row.employee.name)}">${icon('edit')}</button></td>` : '';
            const otMinutes = attendance.verified_overtime_minutes || 0;
            const otCell = `<td data-label="Lembur" class="number-cell">${otMinutes > 0 ? `<span class="meta-pill" style="color:var(--primary);font-weight:700;">+${otMinutes} m</span>` : '—'}</td>`;
            return `<tr><td data-label="Tanggal">${formatDate(row.work_date)}</td><td data-label="Karyawan"><b>${esc(row.employee.name)}</b><small>${esc(row.employee.department ?? 'Tanpa departemen')}</small></td><td data-label="Sesi">${esc(row.shift.name)} · sesi ${row.shift.seq}</td><td data-label="Jadwal">${formatClock(row.shift.start_at)}–${formatClock(row.shift.end_at)}</td><td data-label="Masuk">${formatClock(attendance.check_in_at)}</td><td data-label="Pulang">${checkout ? `${formatClock(checkout)}${attendance.provisional_out_at && !attendance.check_out_at ? ' · provisional' : ''}` : '—'}</td><td data-label="Status"><span class="status ${status.className}">${status.label}</span>${attendance.review_reason ? `<small>${esc(attendance.review_reason)}</small>` : ''}</td><td data-label="Menit valid" class="number-cell">${attendance.verified_minutes}</td>${otCell}${actions}</tr>`;
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
    const target = isLate
        ? (item.allowed_until ? `Target jam hadir: <strong>${formatClock(item.allowed_until)}</strong>` : `Durasi izin: <strong>${item.allowed_minutes} menit</strong>`)
        : `Check-in: <strong>${item.requested_check_in_at ? formatClock(item.requested_check_in_at) : '—'}</strong> · Checkout: <strong>${item.requested_check_out_at ? formatClock(item.requested_check_out_at) : '—'}</strong>`;
    const attachments = item.attachments?.length
        ? `<div class="workflow-modal-summary-row"><span class="modal-label">Lampiran Bukti:</span><div class="workflow-files">${item.attachments.map((file) => `<a href="/admin/api/attachments/${file.id}" target="_blank" class="workflow-file-chip">${icon('contract', 13)} <span>${esc(file.name)}</span></a>`).join('')}</div></div>`
        : '';

    const summaryCard = `
        <div class="workflow-modal-summary">
            <div class="workflow-modal-summary-row"><span class="modal-label">Karyawan:</span> <strong>${esc(item.employee.name)}</strong> (${esc(item.employee.department ?? 'Tanpa departemen')})</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Jadwal:</span> ${formatDate(item.work_date)} · ${esc(item.shift)}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">${isLate ? 'Permintaan Izin:' : 'Usulan Koreksi:'}</span> ${target}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Alasan Karyawan:</span> <p class="modal-reason-text">"${esc(item.reason)}"</p></div>
            ${attachments}
        </div>
        <hr class="modal-divider">
        <label>Keputusan
            <select name="decision" required>
                <option value="APPROVED">Setujui permohonan</option>
                <option value="REJECTED">Tolak permohonan</option>
            </select>
        </label>
        <label>Alasan keputusan (wajib)
            <textarea name="decision_reason" rows="3" maxlength="1000" placeholder="Contoh: Disetujui sesuai bukti lampiran..." required></textarea>
        </label>`;

    openWorkflowModal(isLate ? 'Putuskan Izin Terlambat' : 'Putuskan Koreksi Absensi', 'Tinjau rincian permohonan sebelum menetapkan keputusan.', summaryCard, (body) => api(path, { method: 'POST', body }), 'Simpan Keputusan');
}

function renderAttachments(items) {
    if (!items.length) return '';
    return `<div class="workflow-attachments-wrap">
        <span class="workflow-attachment-label">Bukti lampiran:</span>
        <div class="workflow-files">${items.map((file) => `<a href="/admin/api/attachments/${file.id}" target="_blank" class="workflow-file-chip" title="Buka lampiran ${esc(file.name)}">${icon('contract', 13)} <span>${esc(file.name)}</span></a>`).join('')}</div>
    </div>`;
}

function renderQueueItem(item, kind) {
    const late = kind === 'late';
    const targetLabel = late ? 'Izin Terlambat' : 'Usulan Koreksi';
    const targetValue = late
        ? (item.allowed_until ? `Target hadir pk <strong>${formatClock(item.allowed_until)}</strong>` : `Izin <strong>${item.allowed_minutes} menit</strong>`)
        : `Check-in: <strong>${item.requested_check_in_at ? formatClock(item.requested_check_in_at) : '—'}</strong> · Checkout: <strong>${item.requested_check_out_at ? formatClock(item.requested_check_out_at) : '—'}</strong>`;

    const canDecide = late ? (role === 'super_admin' || role === 'admin' || (role === 'supervisor' && item.requester_id !== Number(document.body.dataset.userId))) : isAdmin;
    const initialLetter = esc(item.employee.name.charAt(0).toUpperCase());
    const avatarHtml = item.employee?.avatar_url
        ? `<img src="${esc(item.employee.avatar_url)}" alt="${esc(item.employee.name)}" class="avatar workflow-avatar">`
        : `<span class="avatar workflow-avatar">${initialLetter}</span>`;

    return `<article class="workflow-card">
        <div class="workflow-card-header">
            <div class="workflow-user-info">
                ${avatarHtml}
                <div class="workflow-user-meta">
                    <strong>${esc(item.employee.name)}</strong>
                    <div class="workflow-sub-meta">
                        <span class="meta-pill">${esc(item.employee.department ?? 'Tanpa departemen')}</span>
                        <span>${formatDate(item.work_date)}</span>
                        <span>·</span>
                        <span>${esc(item.shift)}</span>
                    </div>
                </div>
            </div>
            <span class="status warning">Menunggu</span>
        </div>

        <div class="workflow-card-body">
            <div class="workflow-target-badge">
                ${icon(late ? 'clock' : 'check', 14)}
                <span>${targetLabel}:</span> ${targetValue}
            </div>

            <div class="workflow-reason-box">
                <span class="reason-label">Alasan:</span>
                <p class="reason-text">"${esc(item.reason)}"</p>
            </div>

            ${renderAttachments(item.attachments)}
        </div>

        ${canDecide ? `
        <div class="workflow-card-footer">
            <button type="button" class="secondary-button workflow-review-btn" data-decision="${late ? 'late' : 'correction'}" data-request-id="${item.id}">
                ${icon('eye', 15)} <span>Tinjau &amp; Putuskan</span>
            </button>
        </div>` : ''}
    </article>`;
}

function renderOvertimeItem(item) {
    const isEmployeeRequest = item.status === 'PENDING';
    const initialLetter = esc(item.employee?.name?.charAt(0).toUpperCase() ?? '?');
    const isSuperOrAdmin = role === 'super_admin' || role === 'admin';
    const avatarHtml = item.employee?.avatar_url
        ? `<img src="${esc(item.employee.avatar_url)}" alt="${esc(item.employee.name ?? '')}" class="avatar workflow-avatar">`
        : `<span class="avatar workflow-avatar">${initialLetter}</span>`;

    let statusBadge = '<span class="status warning">Menunggu Admin</span>';
    if (item.status === 'APPROVED') {
        if (item.consent_status === 'ACCEPTED') {
            statusBadge = '<span class="status success">SPL Disetujui</span>';
        } else if (item.consent_status === 'DECLINED') {
            statusBadge = '<span class="status danger">Ditolak Karyawan</span>';
        } else {
            statusBadge = '<span class="status warning">Menunggu Karyawan</span>';
        }
    } else if (item.status === 'REJECTED') {
        statusBadge = '<span class="status danger">Ditolak Admin</span>';
    } else if (item.status === 'CANCELLED') {
        statusBadge = '<span class="status neutral">Dibatalkan</span>';
    }

    const durationInfo = `${item.local_start}–${item.local_end} (${item.planned_minutes} mnt)`;
    const verifiedInfo = item.verified_minutes > 0 ? ` · <strong style="color:var(--primary);">${item.verified_minutes} mnt tervalidasi</strong>` : '';

    return `<article class="workflow-card">
        <div class="workflow-card-header">
            <div class="workflow-user-info">
                ${avatarHtml}
                <div class="workflow-user-meta">
                    <strong>${esc(item.employee?.name ?? '—')}</strong>
                    <div class="workflow-sub-meta">
                        <span class="meta-pill">${esc(item.employee?.department ?? 'Tanpa departemen')}</span>
                        <span>${formatDate(item.work_date)}</span>
                        <span>·</span>
                        <span>SPL: ${esc(item.order_number)}</span>
                    </div>
                </div>
            </div>
            ${statusBadge}
        </div>

        <div class="workflow-card-body">
            <div class="workflow-target-badge">
                ${icon('clock', 14)}
                <span>Rencana:</span> <strong>${durationInfo}</strong>${verifiedInfo}
            </div>

            <div class="workflow-reason-box">
                <span class="reason-label">Tugas / Alasan:</span>
                <p class="reason-text">"${esc(item.title)}"</p>
                ${item.notes ? `<small style="color:var(--muted);margin-top:4px;display:block;">Catatan: ${esc(item.notes)}</small>` : ''}
                ${item.consent_notes ? `<small style="color:#b45309;margin-top:2px;display:block;">Respon Karyawan: "${esc(item.consent_notes)}"</small>` : ''}
                ${item.decision_reason ? `<small style="color:#0f766e;margin-top:2px;display:block;">Alasan Keputusan: "${esc(item.decision_reason)}"</small>` : ''}
            </div>
        </div>

        <div class="workflow-card-footer" style="gap: 8px;">
            ${isEmployeeRequest && isSuperOrAdmin ? `
                <button type="button" class="secondary-button workflow-review-btn" data-overtime-decision="${item.id}">
                    ${icon('eye', 15)} <span>Tinjau &amp; Putuskan</span>
                </button>
            ` : ''}
            ${item.status === 'APPROVED' && item.actual_minutes === 0 && isSuperOrAdmin ? `
                <button type="button" class="secondary-button text-button" data-overtime-cancel="${item.id}" style="color:#b91c1c; font-size:12px;">
                    Batalkan SPL
                </button>
            ` : ''}
        </div>
    </article>`;
}

function decisionOvertimeModal(item) {
    const summaryCard = `
        <div class="workflow-modal-summary">
            <div class="workflow-modal-summary-row"><span class="modal-label">Karyawan:</span> <strong>${esc(item.employee?.name)}</strong> (${esc(item.employee?.department ?? 'Tanpa departemen')})</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Tanggal:</span> ${formatDate(item.work_date)}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Waktu Lembur:</span> <strong>${item.local_start}–${item.local_end} (${item.planned_minutes} menit)</strong></div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Tugas / Alasan:</span> <p class="modal-reason-text">"${esc(item.title)}"</p></div>
            ${item.notes ? `<div class="workflow-modal-summary-row"><span class="modal-label">Catatan:</span> <p class="modal-reason-text">${esc(item.notes)}</p></div>` : ''}
        </div>
        <hr class="modal-divider">
        <label>Keputusan
            <select name="decision" required>
                <option value="APPROVED">Setujui lembur</option>
                <option value="REJECTED">Tolak lembur</option>
            </select>
        </label>
        <label>Alasan keputusan (wajib)
            <textarea name="decision_reason" rows="3" maxlength="500" placeholder="Contoh: Disetujui sesuai kebutuhan operasional..." required></textarea>
        </label>`;

    openWorkflowModal('Putuskan Pengajuan Lembur', 'Tinjau rincian tugas dan durasi lembur sebelum menetapkan keputusan.', summaryCard, (body) => api(`overtimes/${item.id}/decision`, { method: 'POST', body }), 'Simpan Keputusan');
}

export async function showApprovals() {
    const error = $('#workflow-error');
    error.hidden = true;
    try {
        const [result, overtimeResult] = await Promise.all([
            api('workflows', { query: { status: 'PENDING' } }),
            api('overtimes', { query: $('#overtime-filter')?.value === 'all' ? {} : { status: $('#overtime-filter')?.value ?? 'PENDING' } }),
        ]);

        const lateCount = result.late_permissions.length;
        const correctionCount = result.corrections.length;

        const lateBadge = $('#late-permission-count-badge');
        if (lateBadge) lateBadge.textContent = lateCount;
        $('#late-permission-count').textContent = lateCount ? `${lateCount} permohonan menunggu keputusan` : 'Semua permohonan selesai';

        const corrBadge = $('#correction-count-badge');
        if (corrBadge) corrBadge.textContent = correctionCount;
        $('#correction-count').textContent = correctionCount ? `${correctionCount} permohonan menunggu keputusan` : 'Semua permohonan selesai';

        const emptyLate = `
            <div class="workflow-empty-state">
                <div class="empty-icon-wrap">${icon('check', 26)}</div>
                <strong>Tidak ada izin terlambat</strong>
                <span>Semua permohonan izin terlambat sudah ditinjau.</span>
            </div>`;
        const emptyCorrection = `
            <div class="workflow-empty-state">
                <div class="empty-icon-wrap">${icon('check', 26)}</div>
                <strong>Tidak ada koreksi absensi</strong>
                <span>Semua permohonan koreksi absensi sudah ditinjau.</span>
            </div>`;

        $('#late-permission-queue').innerHTML = lateCount ? result.late_permissions.map((item) => renderQueueItem(item, 'late')).join('') : emptyLate;
        $('#correction-queue').innerHTML = correctionCount ? result.corrections.map((item) => renderQueueItem(item, 'correction')).join('') : emptyCorrection;

        $('#late-permission-queue').querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', () => {
            const item = result.late_permissions.find((request) => request.id === Number(button.dataset.requestId));
            if (item) decisionModal('late', item);
        }));
        $('#correction-queue').querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', () => {
            const item = result.corrections.find((request) => request.id === Number(button.dataset.requestId));
            if (item) decisionModal('correction', item);
        }));

        // Overtime queue
        const overtimeList = overtimeResult.data ?? [];
        const pendingOtCount = overtimeList.filter((o) => o.status === 'PENDING').length;
        const otBadge = $('#overtime-count-badge');
        if (otBadge) otBadge.textContent = pendingOtCount;
        const otCount = $('#overtime-count');
        if (otCount) {
            otCount.textContent = pendingOtCount
                ? `${pendingOtCount} pengajuan menunggu keputusan`
                : (overtimeList.length ? `${overtimeList.length} SPL / pengajuan` : 'Semua antrean selesai');
        }

        const emptyOvertime = `
            <div class="workflow-empty-state">
                <div class="empty-icon-wrap">${icon('check', 26)}</div>
                <strong>Tidak ada antrean lembur</strong>
                <span>Tidak ada pengajuan atau perintah lembur pada filter ini.</span>
            </div>`;

        const otQueue = $('#overtime-queue');
        if (otQueue) {
            otQueue.innerHTML = overtimeList.length ? overtimeList.map(renderOvertimeItem).join('') : emptyOvertime;

            otQueue.querySelectorAll('[data-overtime-decision]').forEach((button) => button.addEventListener('click', () => {
                const item = overtimeList.find((r) => r.id === Number(button.dataset.overtimeDecision));
                if (item) decisionOvertimeModal(item);
            }));

            otQueue.querySelectorAll('[data-overtime-cancel]').forEach((button) => button.addEventListener('click', async () => {
                const id = Number(button.dataset.overtimeCancel);
                if (!confirm('Apakah Anda yakin ingin membatalkan perintah lembur ini?')) return;
                try {
                    await api(`overtimes/${id}/cancel`, { method: 'POST', body: { reason: 'Dibatalkan oleh admin' } });
                    toast('Perintah lembur berhasil dibatalkan.');
                    await showApprovals();
                } catch (err) {
                    toast(err.message);
                }
            }));
        }
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

let overtimeEmployees = [];

function updateOvertimeCounter() {
    const list = $('#overtime-emp-list');
    const checked = list ? $$('input[name="ot_emp_id"]:checked', list).length : 0;
    const counter = $('#overtime-emp-counter');
    if (counter) counter.textContent = `${checked} dipilih`;
}

function renderOvertimeEmployeeList(selectedDept = 'all', searchQuery = '') {
    const container = $('#overtime-emp-list');
    if (!container) return;
    const q = searchQuery.toLowerCase().trim();
    const list = overtimeEmployees.filter((e) => {
        const deptName = e.department?.name || e.dept || '';
        const matchDept = selectedDept === 'all' || deptName === selectedDept;
        const matchSearch = !q || e.name.toLowerCase().includes(q) || deptName.toLowerCase().includes(q);
        return matchDept && matchSearch;
    });

    if (!list.length) {
        container.innerHTML = '<p class="list-empty" style="grid-column: 1 / -1; padding: 12px; margin: 0; text-align: center;">Tidak ada karyawan yang cocok.</p>';
        updateOvertimeCounter();
        return;
    }

    container.innerHTML = list.map((e) => {
        const deptName = e.department?.name || e.dept || 'Tanpa Departemen';
        const posName = e.position?.name || e.position || '';
        return `
            <label class="bulk-emp-item">
                <input type="checkbox" name="ot_emp_id" value="${e.id}" data-dept="${esc(deptName)}">
                <div class="bulk-emp-info">
                    <strong>${esc(e.name)}</strong>
                    <small>${esc(deptName)}${posName ? ` · ${esc(posName)}` : ''}</small>
                </div>
            </label>
        `;
    }).join('');
    updateOvertimeCounter();
}

function updateOvertimeCalcInfo() {
    const startVal = $('#overtime-start-time')?.value;
    const endVal = $('#overtime-end-time')?.value;
    const crossDay = $('#overtime-cross-day')?.checked;
    const infoEl = $('#overtime-calc-info');
    if (!infoEl) return;

    if (!startVal || !endVal) {
        infoEl.textContent = '';
        return;
    }

    const [sh, sm] = startVal.split(':').map(Number);
    const [eh, em] = endVal.split(':').map(Number);
    let startMin = sh * 60 + sm;
    let endMin = eh * 60 + em;

    if (crossDay || endMin <= startMin) {
        if (crossDay) {
            endMin += 24 * 60;
        } else {
            infoEl.textContent = 'Perhatian: Jam selesai lebih awal dari jam mulai. Centang lembur lintas hari jika melewati tengah malam.';
            infoEl.style.color = '#dc2626';
            return;
        }
    }

    const diff = endMin - startMin;
    infoEl.style.color = 'var(--primary)';
    infoEl.textContent = `Perkiraan durasi lembur: ${diff} menit (${Math.floor(diff / 60)} jam ${diff % 60} menit)`;
}

function initOvertimeModal() {
    const modal = $('#overtime-order-modal');
    const openBtn = $('#open-overtime-order-btn');
    const form = $('#overtime-order-form');
    const errorEl = $('#overtime-modal-error');
    if (!modal || !openBtn || !form) return;

    openBtn.addEventListener('click', async () => {
        errorEl.hidden = true;
        form.reset();
        $('#overtime-work-date').value = today;
        $('#overtime-start-time').value = '17:00';
        $('#overtime-end-time').value = '20:00';
        updateOvertimeCalcInfo();

        if (!overtimeEmployees.length) {
            try {
                const res = await api('employees', { query: { status: 'active' } });
                overtimeEmployees = res.data ?? [];
                const depts = [...new Set(overtimeEmployees.map((e) => e.department?.name || e.dept).filter(Boolean))].sort();
                const deptSelect = $('#overtime-dept-filter');
                if (deptSelect) {
                    deptSelect.innerHTML = '<option value="all">Semua Departemen</option>' + depts.map((d) => `<option value="${esc(d)}">${esc(d)}</option>`).join('');
                }
            } catch (err) {
                toast(err.message);
            }
        }

        renderOvertimeEmployeeList();
        modal.showModal();
    });

    $('#overtime-emp-search')?.addEventListener('input', (e) => {
        renderOvertimeEmployeeList($('#overtime-dept-filter')?.value, e.target.value);
    });

    $('#overtime-dept-filter')?.addEventListener('change', (e) => {
        renderOvertimeEmployeeList(e.target.value, $('#overtime-emp-search')?.value);
    });

    $('#overtime-select-all')?.addEventListener('click', () => {
        const list = $('#overtime-emp-list');
        if (list) $$('input[name="ot_emp_id"]', list).forEach((cb) => { cb.checked = true; });
        updateOvertimeCounter();
    });

    $('#overtime-clear-all')?.addEventListener('click', () => {
        const list = $('#overtime-emp-list');
        if (list) $$('input[name="ot_emp_id"]', list).forEach((cb) => { cb.checked = false; });
        updateOvertimeCounter();
    });

    $('#overtime-emp-list')?.addEventListener('change', (event) => {
        if (event.target.matches('input[name="ot_emp_id"]')) updateOvertimeCounter();
    });

    $('#overtime-start-time')?.addEventListener('input', updateOvertimeCalcInfo);
    $('#overtime-end-time')?.addEventListener('input', updateOvertimeCalcInfo);
    $('#overtime-cross-day')?.addEventListener('change', updateOvertimeCalcInfo);

    modal.querySelectorAll('[data-close-modal]').forEach((btn) => btn.addEventListener('click', () => modal.close()));
    modal.addEventListener('click', (event) => { if (event.target === modal) modal.close(); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorEl.hidden = true;

        const list = $('#overtime-emp-list');
        const employeeIds = list ? $$('input[name="ot_emp_id"]:checked', list).map((cb) => Number(cb.value)) : [];
        if (!employeeIds.length) {
            errorEl.textContent = 'Pilih minimal satu karyawan untuk ditugaskan lembur.';
            errorEl.hidden = false;
            return;
        }

        const workDate = $('#overtime-work-date').value;
        const startTime = $('#overtime-start-time').value;
        const endTime = $('#overtime-end-time').value;
        const title = $('#overtime-title').value.trim();
        const notes = $('#overtime-notes').value.trim();
        const isCrossDay = $('#overtime-cross-day').checked;

        const submitBtn = $('#overtime-submit-btn');
        submitBtn.disabled = true;

        try {
            const res = await api('overtimes/order', {
                method: 'POST',
                body: {
                    employee_ids: employeeIds,
                    work_date: workDate,
                    start_time: startTime,
                    end_time: endTime,
                    title,
                    notes: notes || undefined,
                    is_cross_day: isCrossDay,
                },
            });
            toast(res.message || 'SPL berhasil diterbitkan.');
            modal.close();
            await Promise.all([showApprovals(), loadMonitoring(monitoringPage)]);
        } catch (err) {
            errorEl.textContent = err.message;
            errorEl.hidden = false;
        } finally {
            submitBtn.disabled = false;
        }
    });
}

export async function initAttendance() {
    if (initialized) return;
    initialized = true;
    setInitialDates(dashboardForm);
    setInitialDates(monitoringForm);
    $('#workflow-refresh').addEventListener('click', showApprovals);
    $('#overtime-filter')?.addEventListener('change', showApprovals);
    initOvertimeModal();
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
    const directToggle = $('#direct-late-toggle');
    const directCancel = $('#direct-late-cancel');
    if (directLateForm) {
        directLateForm.elements.work_date.value = today;
        directLateForm.elements.work_date.addEventListener('change', loadDirectSessions);

        if (directToggle) {
            const toggleForm = () => {
                const willOpen = directLateForm.hidden;
                directLateForm.hidden = !willOpen;
                directToggle.setAttribute('aria-expanded', String(willOpen));
                directToggle.innerHTML = willOpen ? '<span>− Tutup formulir</span>' : '<span>+ Buka formulir</span>';
                if (willOpen) loadDirectSessions();
            };
            directToggle.addEventListener('click', toggleForm);
            directCancel?.addEventListener('click', toggleForm);
        }

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
                directLateForm.hidden = true;
                if (directToggle) {
                    directToggle.setAttribute('aria-expanded', 'false');
                    directToggle.innerHTML = '<span>+ Buka formulir</span>';
                }
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
