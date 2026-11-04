import { api, isAdmin, isSuperAdmin, role } from './api';
import { $, $$, esc, icon, renderPagination, renderSkeletonTable, toast } from './ui';

const today = document.body.dataset.today;
const dashboardForm = $('#dashboard-filters');
const monitoringForm = $('#monitoring-filters');
let monitoringFilters = {};
let monitoringPage = 1;
let monitoringState = '';
let initialized = false;
let monitoringRows = new Map();

const formatClock = (value) => value ? new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value)) : '—';
const formatDate = (value) => new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00+07:00`));
const formatMinutes = (minutes) => `${Math.floor(minutes / 60)}j ${minutes % 60}m`;

function formatAttendanceDateTime(value, timeZone = 'Asia/Jakarta') {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    const options = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
    try {
        return new Intl.DateTimeFormat('id-ID', { ...options, timeZone }).format(date);
    } catch {
        return new Intl.DateTimeFormat('id-ID', { ...options, timeZone: 'Asia/Jakarta' }).format(date);
    }
}

function renderEventTimeMeta(deviceTime, serverTime, deviceTimezone, source) {
    if (!deviceTime && !serverTime) return '';
    let deviceTimeZone = 'Asia/Jakarta';
    let deviceZoneLabel = 'zona perangkat tidak tercatat; ditampilkan WIB';
    if (deviceTimezone) {
        try {
            new Intl.DateTimeFormat('id-ID', { timeZone: deviceTimezone });
            deviceTimeZone = deviceTimezone;
            deviceZoneLabel = deviceTimezone;
        } catch {
            deviceZoneLabel = 'zona perangkat tidak valid; ditampilkan WIB';
        }
    }
    const deviceLabel = deviceTime
        ? `${formatAttendanceDateTime(deviceTime, deviceTimeZone)} (${esc(deviceZoneLabel)})`
        : source === 'ONLINE' ? 'tidak dikirim untuk presensi online' : 'tidak tersedia';
    const serverLabel = serverTime ? `${formatAttendanceDateTime(serverTime)} WIB` : '—';

    return `<div class="attendance-time-meta"><small><strong>Perangkat:</strong> ${esc(deviceLabel)}</small><small><strong>Server menerima:</strong> ${esc(serverLabel)}</small></div>`;
}

function renderGpsMeta(lat, lng, distance, accuracy) {
    if (lat == null || lng == null) {
        return distance != null ? `<small class="gps-coord-meta">${esc(distance)} m</small>` : '';
    }
    const latStr = Number(lat).toFixed(5);
    const lngStr = Number(lng).toFixed(5);
    const distPart = distance != null ? ` · ${esc(distance)}m` : (accuracy != null ? ` · ±${esc(accuracy)}m` : '');
    const mapUrl = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(latStr)}&mlon=${encodeURIComponent(lngStr)}#map=18/${encodeURIComponent(latStr)}/${encodeURIComponent(lngStr)}`;
    return `<a href="${mapUrl}" target="_blank" rel="noopener noreferrer" class="gps-coord-link" title="Buka koordinat ${esc(latStr)}, ${esc(lngStr)} di OpenStreetMap">${icon('locate', 11)}<span>${esc(latStr)}, ${esc(lngStr)}${distPart}</span></a>`;
}

function filterValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function syncMonitoringStateButtons() {
    $$('[data-monitoring-state]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.monitoringState === monitoringState));
    });
}

function setInitialDates(form) {
    form.elements.from.value = today;
    form.elements.to.value = today;
}

function reviewReasonLabel(reason) {
    return ({
        OFFLINE_CLOCK_UNVERIFIED: 'Absensi offline perlu dicocokkan dengan waktu perangkat.',
        OFFLINE_CLOCK_FUTURE: 'Waktu perangkat tercatat lebih jauh dari waktu server.',
        OFFLINE_TOO_OLD: 'Waktu perangkat berada di luar batas sinkronisasi offline.',
        GPS_ACCURACY_LOW: 'Akurasi GPS berada di luar batas aturan lokasi.',
        GPS_REQUIRED: 'Data lokasi wajib belum tersedia.',
        OUTSIDE_RADIUS: 'Lokasi tercatat di luar radius area kerja.',
        FAKE_GPS_DETECTED: 'Absensi tercatat menggunakan lokasi tiruan.',
        AUTO_CHECKOUT: 'Sesi ditutup otomatis karena checkout belum tercatat.',
        CHECKOUT_AFTER_AUTO: 'Checkout dicatat setelah sistem menutup sesi otomatis.',
        EARLY_CHECKOUT_NOT_APPROVED: 'Checkout lebih awal belum didukung izin yang disetujui.',
        OUTSIDE_WINDOW: 'Waktu absensi berada di luar jendela sesi.',
        NOT_CHECKED_IN: 'Checkout offline diterima sebelum check-in tercatat.',
        SCHEDULE_CHANGED: 'Jadwal berubah saat event absensi disinkronkan.',
        SCHEDULE_CANCELLED: 'Jadwal shift sudah dibatalkan.',
        SCHEDULE_NOT_PUBLISHED: 'Jadwal belum diterbitkan saat event absensi diterima.',
        PREVIOUS_PERIOD_LOCKED: 'Periode sebelumnya terkunci saat event diterima.',
        PERIOD_LOCKED: 'Periode absensi sudah dikunci.',
        ALREADY_CHECKED_IN: 'Check-in sudah tercatat sebelumnya untuk sesi ini.',
        ALREADY_CHECKED_OUT: 'Check-out sudah tercatat sebelumnya untuk sesi ini.',
        AUTO_CHECKOUT_DUE: 'Batas waktu sesi telah lewat sebelum event absensi diterima.',
        OPEN_SESSION_EXISTS: 'Masih ada sesi sebelumnya yang belum diselesaikan.',
    })[reason] ?? (reason || 'Periksa waktu, lokasi, dan bukti absensi.');
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
        return { label: `Perlu verifikasi · ${reviewReasonLabel(reason)}`, className: 'danger' };
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

function renderRecent(rows, meta) {
    const container = $('#dashboard-activity');
    if (!rows.length) {
        container.innerHTML = '<p class="list-empty">Belum ada event absensi pada rentang ini.</p>';
    } else {
        container.innerHTML = `<div class="table-scroll"><table class="data-table attendance-data-table"><thead><tr><th scope="col">Karyawan</th><th scope="col">Sesi</th><th scope="col">Masuk</th><th scope="col">Pulang</th><th scope="col">Status</th></tr></thead><tbody>${rows.map((row) => {
            const status = stateLabel(row);
            const checkout = row.attendance.check_out_at ?? row.attendance.provisional_out_at;
            const inGps = renderGpsMeta(row.attendance.check_in_latitude, row.attendance.check_in_longitude, row.attendance.check_in_distance, row.attendance.check_in_accuracy);
            const outGps = renderGpsMeta(row.attendance.check_out_latitude, row.attendance.check_out_longitude, row.attendance.check_out_distance, row.attendance.check_out_accuracy);
            return `<tr><td data-label="Karyawan"><b>${esc(row.employee.name)}</b><small>${esc(row.employee.department ?? 'Tanpa departemen')}</small></td><td data-label="Sesi">${esc(row.shift.name)} · ${formatDate(row.work_date)}</td><td data-label="Masuk">${formatClock(row.attendance.check_in_at)}${inGps}</td><td data-label="Pulang">${checkout ? `${formatClock(checkout)}${row.attendance.provisional_out_at && !row.attendance.check_out_at ? ' · provisional' : ''}` : '—'}${outGps}</td><td data-label="Status"><span class="status ${status.className}">${status.label}</span></td></tr>`;
        }).join('')}</tbody></table></div>`;
    }
    renderPagination($('#dashboard-activity-pagination'), meta, (page) => loadDashboard(filterValues(dashboardForm), page));
}

function showError(selector, message) {
    const box = $(selector);
    box.textContent = message;
    box.hidden = false;
}

function renderReviewBox(reviewItems) {
    const box = $('#dashboard-review-box');
    if (!box) return;
    if (!reviewItems || !reviewItems.length) {
        box.innerHTML = `
            <div class="review-empty-state">
                <span class="review-empty-icon">${icon('check', 18)}</span>
                <strong>Tidak ada sesi tertunda</strong>
                <small>Seluruh absensi telah tercatat sesuai ketentuan operasional.</small>
            </div>
        `;
        return;
    }
    box.innerHTML = `<div class="review-items-list">${reviewItems.map((item) => {
        const reason = reviewReasonLabel(item.attendance?.review_reason);
        return `
            <div class="review-alert-item">
                <div class="review-item-main">
                    <span class="review-badge-pill" style="display:inline-flex;align-items:center;gap:4px;">${icon('alert', 12)}<span>Perlu Verifikasi</span></span>
                    <strong class="review-emp-name">${esc(item.employee.name)}</strong>
                    <span class="review-shift-info">${esc(item.shift.name)} · ${formatDate(item.work_date)}</span>
                    <small class="review-reason-text">${esc(reason)}</small>
                </div>
            </div>
        `;
    }).join('')}</div>`;
}

async function loadDashboard(filters = filterValues(dashboardForm), recentPage = 1) {
    const error = $('#dashboard-error');
    error.hidden = true;
    const actEl = $('#dashboard-activity');
    if (actEl) {
        actEl.setAttribute('aria-busy', 'true');
        actEl.innerHTML = renderSkeletonTable(4, 5);
    }

    // Dynamic greeting & date
    const hour = new Date().getHours();
    const timeGreeting = hour < 11 ? 'Selamat Pagi' : hour < 15 ? 'Selamat Siang' : hour < 18 ? 'Selamat Sore' : 'Selamat Malam';
    const greetingEl = $('#dashboard-greeting');
    if (greetingEl) {
        const parts = greetingEl.textContent.split(',');
        const userName = parts.length > 1 ? parts.slice(1).join(',').trim() : '';
        if (userName) greetingEl.textContent = `${timeGreeting}, ${userName}`;
    }
    const dateStrEl = $('#dashboard-date-str');
    if (dateStrEl) {
        const todayObj = new Date();
        const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        dateStrEl.textContent = `${days[todayObj.getDay()]}, ${todayObj.getDate()} ${months[todayObj.getMonth()]} ${todayObj.getFullYear()}`;
    }

    try {
        const { summary, recent, recent_meta, review_items } = await api('dashboard/attendance', { query: { ...filters, recent_page: recentPage } });

        // 1. Metric Cards
        const rate = Number(summary.attendance_rate ?? 0);
        const rateEl = $('#metric-attendance-rate');
        if (rateEl) rateEl.textContent = `${rate}%`;
        const rateFill = $('#metric-rate-fill');
        if (rateFill) rateFill.style.width = `${Math.min(rate, 100)}%`;
        const sessionsRatioEl = $('#metric-sessions-ratio');
        if (sessionsRatioEl) sessionsRatioEl.textContent = `${summary.checked_in_sessions} dari ${summary.scheduled_sessions} sesi check-in`;

        $('#metric-employees').textContent = summary.scheduled_employees;
        $('#metric-checkins').textContent = `${summary.checked_in_sessions} sesi check-in`;
        $('#metric-sessions').textContent = `${summary.scheduled_sessions} sesi terjadwal`;

        $('#metric-review').textContent = summary.review_sessions;
        const pendingLeaves = summary.pending_leaves_count ?? 0;
        const pendingLeavesEl = $('#metric-pending-leaves');
        if (pendingLeavesEl) pendingLeavesEl.textContent = `${pendingLeaves} pengajuan cuti`;

        $('#metric-minutes').textContent = formatMinutes(summary.verified_minutes);

        $('#dashboard-finding').textContent = summary.review_sessions
            ? `${summary.review_sessions} sesi absensi memerlukan tindakan peninjauan.`
            : 'Seluruh sesi absensi tercatat tertib dan terverifikasi.';

        // 2. Status Breakdown Bar
        const total = Number(summary.scheduled_sessions || 0);
        const onTime = Number(summary.on_time_count || 0);
        const inProgress = Number(summary.in_progress_count || 0);
        const late = Number(summary.late_count || 0);
        const reviewCount = Number(summary.review_sessions || 0);
        const absent = Math.max(0, total - (onTime + inProgress + late + reviewCount));

        const barTotal = total > 0 ? total : 1;
        const pct = (n) => total > 0 ? `${((n / barTotal) * 100).toFixed(1)}%` : '0%';

        const barOnTime = $('.bar-ontime');
        if (barOnTime) barOnTime.style.width = pct(onTime);
        const barInProgress = $('.bar-inprogress');
        if (barInProgress) barInProgress.style.width = pct(inProgress);
        const barLate = $('.bar-late');
        if (barLate) barLate.style.width = pct(late);
        const barReview = $('.bar-review');
        if (barReview) barReview.style.width = pct(reviewCount);
        const barAbsent = $('.bar-absent');
        if (barAbsent) barAbsent.style.width = pct(absent);

        const legOnTime = $('#leg-ontime');
        if (legOnTime) legOnTime.textContent = onTime;
        const legInProgress = $('#leg-inprogress');
        if (legInProgress) legInProgress.textContent = inProgress;
        const legLate = $('#leg-late');
        if (legLate) legLate.textContent = late;
        const legReview = $('#leg-review');
        if (legReview) legReview.textContent = reviewCount;
        const legAbsent = $('#leg-absent');
        if (legAbsent) legAbsent.textContent = absent;
        const totalSessionsEl = $('#breakdown-total-sessions');
        if (totalSessionsEl) totalSessionsEl.textContent = `${total} Total Sesi`;

        // 3. Render Recent Table
        renderRecent(recent, recent_meta);

        // 4. Render Review Box
        renderReviewBox(review_items);
    } catch (failure) {
        showError('#dashboard-error', failure.message);
        $('#dashboard-activity').innerHTML = '<p class="list-empty">Data absensi gagal dimuat.</p>';
        const reviewBox = $('#dashboard-review-box');
        if (reviewBox) reviewBox.innerHTML = '<p class="list-empty">Gagal memuat tinjauan.</p>';
    } finally {
        $('#dashboard-activity').removeAttribute('aria-busy');
    }
}

function getMonitoringRange(preset) {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const fmt = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    if (preset === 'today') {
        const t = fmt(d);
        return { from: t, to: t };
    }
    if (preset === 'yesterday') {
        const y = new Date(d);
        y.setDate(d.getDate() - 1);
        const yStr = fmt(y);
        return { from: yStr, to: yStr };
    }
    if (preset === 'week') {
        const past = new Date(d);
        past.setDate(d.getDate() - 6);
        return { from: fmt(past), to: fmt(d) };
    }
    if (preset === 'month') {
        const first = new Date(d.getFullYear(), d.getMonth(), 1);
        return { from: fmt(first), to: fmt(d) };
    }
    return { from: today, to: today };
}

function renderMonitoring({ data, meta, summary }) {
    monitoringRows = new Map(data.map((row) => [String(row.id), row]));
    $('#monitoring-count').textContent = `${meta.total} sesi pada rentang ini`;

    let completeCount = 0;
    let inProgressCount = 0;
    let lateCount = 0;
    let reviewCount = 0;

    data.forEach((row) => {
        const s = row.attendance.state;
        if (s === 'COMPLETE') completeCount++;
        else if (s === 'IN_PROGRESS') inProgressCount++;
        else if (s === 'PRESENT_LATE' || s === 'PRESENT_LATE_PERMITTED') lateCount++;
        else if (s === 'NEEDS_REVIEW') reviewCount++;
    });

    const statTotal = $('#stat-total-sessions');
    if (statTotal) statTotal.textContent = summary?.total ?? meta.total;
    const statComplete = $('#stat-complete-sessions');
    if (statComplete) statComplete.textContent = summary?.complete ?? completeCount;
    const statInProgress = $('#stat-inprogress-sessions');
    if (statInProgress) statInProgress.textContent = summary?.in_progress ?? inProgressCount;
    const statLate = $('#stat-late-sessions');
    if (statLate) statLate.textContent = summary?.late ?? lateCount;
    const statReview = $('#stat-review-sessions');
    if (statReview) statReview.textContent = summary?.review ?? reviewCount;
    syncMonitoringStateButtons();

    const container = $('#monitoring-table');
    if (!data.length) {
        container.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: var(--muted);">
                <div style="display: flex; justify-content: center; margin-bottom: 10px; color: var(--muted);">${icon('file-text', 36)}</div>
                <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">Tidak ada catatan absensi untuk filter yang dipilih.</p>
                <small style="display: block; margin-top: 4px;">Ubah filter atau rentang tanggal untuk melihat data lainnya.</small>
            </div>
        `;
    } else {
        container.innerHTML = `<div class="table-scroll"><table class="data-table monitoring-data-table"><thead><tr><th scope="col" class="monitoring-date-session-col">Tanggal / sesi</th><th scope="col" style="min-width:210px;">Karyawan</th><th scope="col" style="min-width:90px;">Masuk</th><th scope="col" style="min-width:110px;">Pulang</th><th scope="col" style="min-width:150px;">Status</th><th scope="col" class="number-cell" style="min-width:100px;">Menit valid</th><th scope="col" class="number-cell" style="min-width:90px;">Lembur</th>${isAdmin ? '<th scope="col" style="min-width:90px;">Aksi</th>' : ''}</tr></thead><tbody>${data.map((row) => {
            const status = stateLabel(row);
            const attendance = row.attendance;
            const checkout = attendance.check_out_at ?? attendance.provisional_out_at;
            const actions = isAdmin ? `<td data-label="Aksi" class="workflow-actions">${attendance.needs_review && attendance.check_in_at ? `<button type="button" class="action-btn success" data-session-action="verify" data-session-id="${row.id}" title="Verifikasi absensi" aria-label="Verifikasi absensi ${esc(row.employee.name)}">${icon('shield-check')}</button>` : ''}<button type="button" class="action-btn" data-session-action="correction" data-session-id="${row.id}" title="Koreksi langsung" aria-label="Koreksi langsung ${esc(row.employee.name)}">${icon('edit')}</button></td>` : '';
            const otMinutes = attendance.verified_overtime_minutes || 0;
            const otCell = `<td data-label="Lembur" class="number-cell">${otMinutes > 0 ? `<span class="meta-pill" style="color:var(--primary);font-weight:700;">+${otMinutes} m</span>` : '—'}</td>`;

            const checkInPhotoBtn = attendance.check_in_photo_url
                ? `<button type="button" class="photo-badge-btn" data-photo-url="${esc(attendance.check_in_photo_url)}" data-photo-caption="Check-In · ${esc(row.employee.name)} · ${formatClock(attendance.check_in_at)}" title="Lihat Foto Check-In">${icon('camera', 13)}</button>`
                : '';
            const checkOutPhotoBtn = attendance.check_out_photo_url
                ? `<button type="button" class="photo-badge-btn" data-photo-url="${esc(attendance.check_out_photo_url)}" data-photo-caption="Check-Out · ${esc(row.employee.name)} · ${formatClock(checkout)}" title="Lihat Foto Check-Out">${icon('camera', 13)}</button>`
                : '';
            const mockBadge = attendance.has_mock_event
                ? `<span class="status danger" style="font-size:10px;padding:1px 5px;margin-left:4px;" title="Terdeteksi Fake GPS">Fake GPS</span>`
                : '';

            const initial = (row.employee.name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
            const avatar = row.employee.avatar_url
                ? `<img src="${esc(row.employee.avatar_url)}" alt="${esc(row.employee.name)}" class="avatar" style="width:34px;height:34px;border-radius:8px;object-fit:cover;margin-right:10px;flex-shrink:0;" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar',style:'width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:10px;flex-shrink:0;font-size:11px;background:#e2ece4;color:var(--primary);font-weight:700;',textContent:'${esc(initial)}'}))">`
                : `<span class="avatar" style="width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:10px;flex-shrink:0;font-size:11px;background:#e2ece4;color:var(--primary);font-weight:700;">${esc(initial)}</span>`;

            return `<tr>
                <td data-label="Tanggal / sesi" class="monitoring-date-session-col">
                    <b>${formatDate(row.work_date)}</b>
                    <span class="monitoring-session-name" title="${esc(row.shift.name)}">${esc(row.shift.name)}</span>
                    <small>Sesi ${row.shift.seq} · ${formatClock(row.shift.start_at)}–${formatClock(row.shift.end_at)}</small>
                </td>
                <td data-label="Karyawan">
                    <div style="display:flex;align-items:center;">
                        ${avatar}
                        <div>
                            <b>${esc(row.employee.name)}</b>
                            <small style="color:var(--muted);">${esc(row.employee.code ?? '-')} · ${esc(row.employee.department ?? 'Tanpa departemen')}</small>
                        </div>
                    </div>
                </td>
                <td data-label="Masuk">
                    <div style="display:flex;align-items:center;gap:6px;">
                        ${attendance.check_in_at ? `<b style="color:var(--ink);">${formatClock(attendance.check_in_at)}</b>` : '<span style="color:var(--muted);">—</span>'}
                        ${checkInPhotoBtn}
                    </div>
                </td>
                <td data-label="Pulang">
                    <div style="display:flex;align-items:center;gap:6px;">
                        ${checkout ? `<b style="color:var(--ink);">${formatClock(checkout)}</b>${attendance.provisional_out_at && !attendance.check_out_at ? ' <span class="status warning" style="font-size:10px;padding:1px 5px;margin-left:4px;">Provisional</span>' : ''}` : '<span style="color:var(--muted);">—</span>'}
                        ${checkOutPhotoBtn}
                    </div>
                </td>
                <td data-label="Status"><span class="status ${status.className}">${esc(status.label)}</span>${mockBadge}${attendance.review_reason ? `<small style="display:block;margin-top:2px;color:var(--danger);">${esc(reviewReasonLabel(attendance.review_reason))}</small>` : ''}</td>
                <td data-label="Menit valid" class="number-cell" style="font-weight:600;">${attendance.verified_minutes} m</td>
                ${otCell}
                ${actions}
            </tr>`;
        }).join('')}</tbody></table></div>`;
    }

    const pagination = $('#monitoring-pagination');
    renderPagination(pagination, meta, (newPage) => loadMonitoring(newPage));
}

async function loadMonitoring(page = 1) {
    const error = $('#monitoring-error');
    error.hidden = true;
    monitoringPage = page;
    const monTable = $('#monitoring-table');
    if (monTable) {
        monTable.setAttribute('aria-busy', 'true');
        monTable.innerHTML = renderSkeletonTable(6, 8);
    }
    try {
        const query = { ...monitoringFilters, page, per_page: 15 };
        if (monitoringState) query.state = monitoringState;
        renderMonitoring(await api('attendance', { query }));
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
    const submitBtn = $('#workflow-modal-submit');
    submitBtn.textContent = submitLabel;
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
        const prevLabel = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.textContent = 'Menyimpan…';
        try {
            await submit(body);
            dialog.close();
            toast('Perubahan tersimpan.');
            await Promise.all([loadDashboard(), loadMonitoring(monitoringPage), showApprovals()]);
        } catch (failure) {
            $('#workflow-modal-error').textContent = failure.message;
            $('#workflow-modal-error').hidden = false;
            toast(failure.message || 'Gagal menyimpan perubahan.', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.removeAttribute('aria-busy');
            submitBtn.textContent = prevLabel;
        }
    };
    dialog.showModal();
}

function decisionModal(kind, item) {
    const isLate = kind === 'late';
    const path = isLate ? `late-permissions/${item.id}/decision` : `attendance-corrections/${item.id}/decision`;
    const target = isLate
        ? (item.permission_type === 'temporary_exit'
            ? `Keluar: <strong>${formatClock(item.leave_at)}</strong> · kembali: <strong>${formatClock(item.return_at)}</strong>`
            : item.permission_type === 'early_departure'
                ? `Pulang lebih awal: <strong>${formatClock(item.leave_at)}</strong>`
                : item.allowed_until ? `Target jam hadir: <strong>${formatClock(item.allowed_until)}</strong>` : `Durasi izin: <strong>${item.allowed_minutes} menit</strong>`)
        : `Check-in: <strong>${item.requested_check_in_at ? formatClock(item.requested_check_in_at) : '—'}</strong> · Checkout: <strong>${item.requested_check_out_at ? formatClock(item.requested_check_out_at) : '—'}</strong>`;
    const attachments = item.attachments?.length
        ? `<div class="workflow-modal-summary-row"><span class="modal-label">Lampiran Bukti:</span><div class="workflow-files">${item.attachments.map(renderAttachmentLink).join('')}</div></div>`
        : '';

    const summaryCard = `
        <div class="workflow-modal-summary">
            <div class="workflow-modal-summary-row"><span class="modal-label">Karyawan:</span> <strong>${esc(item.employee.name)}</strong> (${esc(item.employee.department ?? 'Tanpa departemen')})</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Jadwal:</span> ${formatDate(item.work_date)} · ${esc(item.shift)}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">${isLate ? (item.permission_type === 'temporary_exit' ? 'Izin keluar sementara:' : item.permission_type === 'early_departure' ? 'Izin pulang lebih awal:' : 'Izin terlambat:') : 'Usulan Koreksi:'}</span> ${target}</div>
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

    openWorkflowModal(isLate ? 'Putuskan Izin Absensi' : 'Putuskan Koreksi Absensi', 'Tinjau rincian permohonan sebelum menetapkan keputusan.', summaryCard, (body) => api(path, { method: 'POST', body }), 'Simpan Keputusan');
}

function renderAttachmentLink(file) {
    const label = esc(file.name);
    if (['image/jpeg', 'image/png'].includes(file.mime_type)) {
        return `<button type="button" class="workflow-file-chip" data-attachment-preview="${file.id}" data-attachment-name="${label}" aria-label="Pratinjau bukti ${label}">${icon('eye', 13)} <span>${label}</span></button>`;
    }

    return `<a href="/admin/api/attachments/${file.id}" target="_blank" rel="noopener noreferrer" class="workflow-file-chip">${icon('contract', 13)} <span>${label}</span></a>`;
}

function renderAttachments(items) {
    if (!items.length) return '';
    return `<div class="workflow-attachments-wrap">
        <span class="workflow-attachment-label">Bukti lampiran:</span>
        <div class="workflow-files">${items.map(renderAttachmentLink).join('')}</div>
    </div>`;
}

function renderQueueItem(item, kind) {
    const late = kind === 'late';
    const permissionType = item.permission_type ?? 'late_arrival';
    const targetLabel = late
        ? ({ late_arrival: 'Izin Terlambat', temporary_exit: 'Izin Keluar Sementara', early_departure: 'Izin Pulang Lebih Awal' })[permissionType] ?? 'Izin Absensi'
        : 'Usulan Koreksi';
    const targetValue = late
        ? permissionType === 'temporary_exit'
            ? `Rencana keluar <strong>${formatClock(item.leave_at)}</strong> · kembali <strong>${formatClock(item.return_at)}</strong>`
            : permissionType === 'early_departure'
                ? `Rencana pulang <strong>${formatClock(item.leave_at)}</strong>`
                : (item.allowed_until ? `Target hadir pk <strong>${formatClock(item.allowed_until)}</strong>` : `Izin <strong>${item.allowed_minutes} menit</strong>`)
        : `Check-in: <strong>${item.requested_check_in_at ? formatClock(item.requested_check_in_at) : '—'}</strong> · Checkout: <strong>${item.requested_check_out_at ? formatClock(item.requested_check_out_at) : '—'}</strong>`;

    const canDecide = isAdmin;
    const initialLetter = esc(item.employee.name.charAt(0).toUpperCase());
    const avatarHtml = item.employee?.avatar_url
        ? `<img src="${esc(item.employee.avatar_url)}" alt="${esc(item.employee.name)}" class="avatar workflow-avatar" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar workflow-avatar',textContent:'${initialLetter}'}))">`
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
        ? `<img src="${esc(item.employee.avatar_url)}" alt="${esc(item.employee.name ?? '')}" class="avatar workflow-avatar" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar workflow-avatar',textContent:'${initialLetter}'}))">`
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

function exchangeScheduleMarkup(assignment) {
    if (!assignment) return 'Tidak ada jadwal tujuan';
    const sessions = assignment.sessions?.map((session) => `${esc(session.local_start)}–${esc(session.local_end)}`).join(' · ');
    return `<strong>${formatDate(assignment.work_date)}</strong> · ${esc(assignment.template_name ?? 'Shift')}${sessions ? ` · ${sessions}` : ''}`;
}

function renderShiftExchangeItem(item) {
    const employeeName = item.employee?.name ?? '—';
    const initials = esc(employeeName.trim().split(/\s+/).slice(0, 2).map((name) => name[0]).join('').toUpperCase() || '?');
    const isDirectSwap = item.swap_mode === 'direct';
    const kind = isDirectSwap ? 'Tukar jadwal' : 'Pelimpahan shift';
    const approvalLabel = item.approval_tier === 'super_admin' ? 'Menunggu Super Admin' : 'Menunggu Admin';

    return `<article class="workflow-card">
        <div class="workflow-card-header">
            <div class="workflow-user-info">
                <span class="avatar workflow-avatar">${initials}</span>
                <div class="workflow-user-meta">
                    <strong>${esc(employeeName)}</strong>
                    <div class="workflow-sub-meta">
                        <span class="meta-pill">${esc(item.employee?.department ?? 'Tanpa departemen')}</span>
                        <span>${kind}</span>
                    </div>
                </div>
            </div>
            <span class="status warning">${approvalLabel}</span>
        </div>
        <div class="workflow-card-body">
            <div class="workflow-target-badge">
                ${icon('swap', 14)} <span>Penerima shift:</span> <strong>${esc(item.target_employee?.name ?? '—')}</strong>
                ${item.target_employee?.department ? `<span class="meta-pill">${esc(item.target_employee.department)}</span>` : ''}
            </div>
            <div class="workflow-modal-summary-row">
                <span class="modal-label">Shift pemohon</span>
                ${exchangeScheduleMarkup(item.assignment)}
            </div>
            <div class="workflow-modal-summary-row">
                <span class="modal-label">Shift tujuan</span>
                ${isDirectSwap ? exchangeScheduleMarkup(item.target_assignment) : 'Penerima mengambil alih shift pemohon'}
            </div>
            <div class="workflow-reason-box">
                <span class="reason-label">Alasan pengajuan</span>
                <p class="reason-text">${esc(item.reason)}</p>
            </div>
        </div>
        <div class="workflow-card-footer">
            <button type="button" class="workflow-review-btn" data-shift-exchange-review="${item.id}">
                ${icon('eye', 15)} <span>Tinjau &amp; Putuskan</span>
            </button>
        </div>
    </article>`;
}

function decisionShiftExchangeModal(item) {
    const hasVacancy = item.swap_mode === 'delegation'
        || (item.target_assignment && item.assignment?.work_date !== item.target_assignment.work_date);
    const fields = `
        <div class="workflow-modal-summary">
            <div class="workflow-modal-summary-row"><span class="modal-label">Pemohon</span><strong>${esc(item.employee?.name ?? '—')}</strong> · ${esc(item.employee?.department ?? 'Tanpa departemen')}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Penerima</span><strong>${esc(item.target_employee?.name ?? '—')}</strong> · ${esc(item.target_employee?.department ?? 'Tanpa departemen')}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Shift pemohon</span>${exchangeScheduleMarkup(item.assignment)}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Shift tujuan</span>${item.swap_mode === 'direct' ? exchangeScheduleMarkup(item.target_assignment) : 'Penerima mengambil alih shift pemohon'}</div>
            <div class="workflow-modal-summary-row"><span class="modal-label">Alasan pengajuan</span><p class="modal-reason-text">${esc(item.reason)}</p></div>
        </div>
        <label>Keputusan
            <select name="decision" id="shift-exchange-decision" required>
                <option value="APPROVED">Setujui tukar shift</option>
                <option value="REJECTED">Tolak pengajuan</option>
            </select>
        </label>
        ${hasVacancy ? `<label id="shift-exchange-vacancy-control">Penanganan roster pemohon
            <select name="vacancy_action">
                <option value="roster_off" ${item.vacancy_action === 'roster_off' || !item.vacancy_action ? 'selected' : ''}>Roster Off</option>
                <option value="regular" ${item.vacancy_action === 'regular' ? 'selected' : ''}>Cuti</option>
                <option value="day_off" ${item.vacancy_action === 'day_off' ? 'selected' : ''}>Deposit Off</option>
            </select>
        </label>` : ''}
        <label id="shift-exchange-cross-unit-control">Konfirmasi perpindahan lintas departemen
            <select name="confirm_cross_unit">
                <option value="0">Belum dikonfirmasi</option>
                <option value="1">Saya mengonfirmasi perpindahan lintas departemen</option>
            </select>
        </label>
        <label><span id="shift-exchange-reason-label">Catatan persetujuan (opsional)</span>
            <textarea name="reason" id="shift-exchange-decision-reason" rows="3" maxlength="1000" placeholder="Tuliskan alasan keputusan bila diperlukan"></textarea>
        </label>`;

    openWorkflowModal(
        'Putuskan Tukar Shift',
        'Tinjau jadwal pemohon dan penerima sebelum menyimpan keputusan.',
        fields,
        async (body) => {
            try {
                return await api(`shift-exchanges/${item.id}/decide`, { method: 'POST', body });
            } catch (failure) {
                const blockers = failure.details?.blockers?.map((blocker) => blocker.message).filter(Boolean) ?? [];
                if (blockers.length) failure.message = `${failure.message} ${blockers.join(' ')}`;
                throw failure;
            }
        },
        'Simpan Keputusan',
    );

    const decision = $('#shift-exchange-decision');
    const reasonLabel = $('#shift-exchange-reason-label');
    const reason = $('#shift-exchange-decision-reason');
    const vacancyControl = $('#shift-exchange-vacancy-control');
    const crossUnitControl = $('#shift-exchange-cross-unit-control');
    const updateReasonRequirement = () => {
        const rejected = decision.value === 'REJECTED';
        reason.required = rejected;
        reasonLabel.textContent = rejected ? 'Alasan penolakan (wajib)' : 'Catatan persetujuan (opsional)';
        if (vacancyControl) vacancyControl.hidden = rejected;
        crossUnitControl.hidden = rejected;
    };
    decision.addEventListener('change', updateReasonRequirement);
    updateReasonRequirement();
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
        const [result, overtimeResult, recommendationResult, exchangeResult] = await Promise.all([
            api('workflows', { query: { status: 'PENDING' } }),
            isAdmin ? api('overtimes', { query: $('#overtime-filter')?.value === 'all' ? {} : { status: $('#overtime-filter')?.value ?? 'PENDING' } }) : Promise.resolve({ data: [] }),
            isAdmin ? api('overtimes/recommendations') : Promise.resolve({ data: [] }),
            isAdmin ? api('shift-exchanges', { query: { scope: 'admin', status: 'PENDING' } }) : Promise.resolve({ data: [] }),
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

        const recommendations = recommendationResult.data ?? [];
        const recommendationCount = $('#overtime-recommendation-count');
        const recommendationList = $('#overtime-recommendation-list');
        if (recommendationCount) {
            recommendationCount.textContent = recommendations.length
                ? `${recommendations.length} karyawan sedang bertugas dan shift terakhirnya segera berakhir`
                : 'Belum ada sesi shift aktif yang berakhir dalam dua jam ke depan.';
        }
        if (recommendationList) {
            recommendationList.innerHTML = recommendations.length ? recommendations.map((item) => {
                const startSuffix = Number(item.start_day_offset) > 0 ? ` (+${Number(item.start_day_offset)})` : '';
                const endSuffix = Number(item.end_day_offset) > 0 ? ` (+${Number(item.end_day_offset)})` : '';
                const shiftEndSuffix = Number(item.shift_end_day_offset) > 0 ? ` (+${Number(item.shift_end_day_offset)})` : '';
                return `
                    <article class="workflow-card">
                        <div class="workflow-card-header">
                            <div class="workflow-user-info">
                                <div class="workflow-user-meta">
                                    <strong>${esc(item.employee.name)}</strong>
                                    <div class="workflow-sub-meta">
                                        <span class="meta-pill">${esc(item.employee.department ?? 'Tanpa departemen')}</span>
                                        <span>${esc(item.shift)}</span>
                                    </div>
                                </div>
                            </div>
                            <span class="status warning">Shift berakhir ${esc(item.shift_end_time)}${shiftEndSuffix}</span>
                        </div>
                        <div class="workflow-card-footer">
                            <span class="workflow-sub-meta">Usulan SPL: ${esc(item.suggested_start_time)}${startSuffix}–${esc(item.suggested_end_time)}${endSuffix} (2 jam)</span>
                            <button
                                type="button"
                                class="secondary-button"
                                data-overtime-recommendation="${item.employee.id}"
                                data-work-date="${esc(item.work_date)}"
                                data-start-time="${esc(item.suggested_start_time)}"
                                data-end-time="${esc(item.suggested_end_time)}"
                                data-start-day-offset="${Number(item.start_day_offset) || 0}"
                                data-end-day-offset="${Number(item.end_day_offset) || 0}"
                                data-cross-day="${item.is_cross_day ? 'true' : 'false'}"
                            >Siapkan SPL</button>
                        </div>
                    </article>
                `;
            }).join('') : `
                <div class="workflow-empty-state">
                    <strong>Belum ada rekomendasi lembur</strong>
                    <span>Daftar muncul saat karyawan sedang check-in dan sesi terakhirnya berakhir dalam dua jam.</span>
                </div>`;
        }

        const exchangeList = exchangeResult.data ?? [];
        const exchangeBadge = $('#shift-exchange-count-badge');
        const exchangeCount = $('#shift-exchange-count');
        const exchangeQueue = $('#shift-exchange-queue');
        if (exchangeBadge) exchangeBadge.textContent = exchangeList.length;
        if (exchangeCount) exchangeCount.textContent = exchangeList.length
            ? `${exchangeList.length} pengajuan menunggu keputusan`
            : 'Tidak ada pengajuan menunggu keputusan';
        if (exchangeQueue) {
            exchangeQueue.innerHTML = exchangeList.length ? exchangeList.map(renderShiftExchangeItem).join('') : `
                <div class="workflow-empty-state">
                    <div class="empty-icon-wrap">${icon('check', 26)}</div>
                    <strong>Tidak ada pengajuan tukar shift</strong>
                    <span>Pengajuan baru akan tampil di sini untuk ditinjau.</span>
                </div>`;
            exchangeQueue.querySelectorAll('[data-shift-exchange-review]').forEach((button) => button.addEventListener('click', () => {
                const item = exchangeList.find((exchange) => exchange.id === Number(button.dataset.shiftExchangeReview));
                if (item) decisionShiftExchangeModal(item);
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

function syncOvertimeDayOffsets() {
    const startOffset = Number($('#overtime-start-day-offset')?.value ?? 0);
    const endOffset = $('#overtime-end-day-offset');
    if (endOffset) endOffset.value = String(startOffset + ($('#overtime-cross-day')?.checked ? 1 : 0));
}

async function openOvertimeOrderModal(employeeId = null, workDate = today, recommendation = null) {
    const modal = $('#overtime-order-modal');
    const form = $('#overtime-order-form');
    const errorEl = $('#overtime-modal-error');
    errorEl.hidden = true;
    form.reset();
    $('#overtime-work-date').value = workDate;
    $('#overtime-start-time').value = recommendation?.startTime ?? '17:00';
    $('#overtime-end-time').value = recommendation?.endTime ?? '20:00';
    $('#overtime-start-day-offset').value = String(recommendation?.startDayOffset ?? 0);
    $('#overtime-end-day-offset').value = String(recommendation?.endDayOffset ?? 0);
    $('#overtime-cross-day').checked = recommendation?.isCrossDay ?? false;
    syncOvertimeDayOffsets();
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
    if (employeeId) {
        const checkbox = $(`#overtime-emp-list input[name="ot_emp_id"][value="${employeeId}"]`);
        if (checkbox) checkbox.checked = true;
        updateOvertimeCounter();
    }
    modal.showModal();
}

function initOvertimeModal() {
    const modal = $('#overtime-order-modal');
    const openBtn = $('#open-overtime-order-btn');
    const form = $('#overtime-order-form');
    const errorEl = $('#overtime-modal-error');
    if (!modal || !openBtn || !form) return;

    openBtn.addEventListener('click', () => openOvertimeOrderModal());
    $('#overtime-recommendation-list')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-overtime-recommendation]');
        if (button) openOvertimeOrderModal(Number(button.dataset.overtimeRecommendation), button.dataset.workDate, {
            startTime: button.dataset.startTime,
            endTime: button.dataset.endTime,
            startDayOffset: Number(button.dataset.startDayOffset) || 0,
            endDayOffset: Number(button.dataset.endDayOffset) || 0,
            isCrossDay: button.dataset.crossDay === 'true',
        });
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
    $('#overtime-cross-day')?.addEventListener('change', () => {
        syncOvertimeDayOffsets();
        updateOvertimeCalcInfo();
    });
    $('#overtime-work-date')?.addEventListener('change', () => {
        $('#overtime-start-day-offset').value = '0';
        syncOvertimeDayOffsets();
    });

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
        syncOvertimeDayOffsets();
        const startDayOffset = Number($('#overtime-start-day-offset').value);
        const endDayOffset = Number($('#overtime-end-day-offset').value);

        if (!workDate) {
            errorEl.textContent = 'Pilih tanggal kerja terlebih dahulu.';
            errorEl.hidden = false;
            return;
        }
        if (!startTime || !endTime) {
            errorEl.textContent = 'Isi jam mulai dan jam selesai.';
            errorEl.hidden = false;
            return;
        }
        if (title.length < 3) {
            errorEl.textContent = 'Judul tugas lembur minimal 3 karakter.';
            errorEl.hidden = false;
            return;
        }

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
                    start_day_offset: startDayOffset,
                    end_day_offset: endDayOffset,
                },
            });
            toast(res.message || 'SPL berhasil diterbitkan.');
            modal.close();
            await Promise.all([showApprovals(), loadMonitoring(monitoringPage)]);
        } catch (err) {
            errorEl.textContent = err.message;
            errorEl.hidden = false;
            toast(err.message || 'Gagal menerbitkan SPL.', 'error');
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
    const photoModal = $('#photo-preview-modal');
    if (photoModal) {
        photoModal.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => photoModal.close()));
        photoModal.addEventListener('click', (event) => { if (event.target === photoModal) photoModal.close(); });
    }

    const openPhotoPreview = (url, caption, title = 'Foto Presensi') => {
        const img = $('#photo-preview-img');
        const cap = $('#photo-preview-caption');
        if (photoModal && img) {
            img.src = url;
            img.alt = title === 'Foto Presensi' ? 'Foto Presensi' : caption || title;
            $('#photo-preview-title').textContent = title;
            if (cap) cap.textContent = caption || '';
            photoModal.showModal();
        }
    };

    document.addEventListener('click', (event) => {
        const previewButton = event.target.closest('[data-attachment-preview]');
        if (previewButton) {
            openPhotoPreview(`/admin/api/attachments/${previewButton.dataset.attachmentPreview}/preview`, previewButton.dataset.attachmentName, 'Pratinjau Lampiran');
        }
    });

    $('#monitoring-table').addEventListener('click', (event) => {
        const photoBtn = event.target.closest('.photo-badge-btn');
        if (photoBtn) {
            openPhotoPreview(photoBtn.dataset.photoUrl, photoBtn.dataset.photoCaption);
            return;
        }

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
                toast(failure.message || 'Gagal menyimpan izin terlambat.', 'error');
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

    const dashboardPresets = $$('#dashboard-preset-chips .preset-chip');
    dashboardPresets.forEach((btn) => {
        btn.addEventListener('click', () => {
            dashboardPresets.forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            const { from, to } = getMonitoringRange(btn.dataset.preset);
            dashboardForm.elements.from.value = from;
            dashboardForm.elements.to.value = to;
            loadDashboard();
        });
    });
    monitoringForm.addEventListener('submit', (event) => {
        event.preventDefault();
        monitoringFilters = filterValues(monitoringForm);
        loadMonitoring(1);
    });

    $$('[data-monitoring-state]').forEach((button) => {
        button.addEventListener('click', () => {
            const selectedState = button.dataset.monitoringState;
            monitoringState = selectedState && selectedState !== monitoringState ? selectedState : '';
            syncMonitoringStateButtons();
            loadMonitoring(1);
        });
    });

    const monitoringPresets = $$('[data-monitoring-preset]', monitoringForm);
    monitoringPresets.forEach((btn) => {
        btn.addEventListener('click', () => {
            monitoringPresets.forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            const { from, to } = getMonitoringRange(btn.dataset.monitoringPreset);
            monitoringForm.elements.from.value = from;
            monitoringForm.elements.to.value = to;
            monitoringFilters = filterValues(monitoringForm);
            loadMonitoring(1);
        });
    });

    let monitoringSearchDebounce;
    $('#monitoring-q')?.addEventListener('input', () => {
        clearTimeout(monitoringSearchDebounce);
        monitoringSearchDebounce = setTimeout(() => {
            monitoringFilters = filterValues(monitoringForm);
            loadMonitoring(1);
        }, 300);
    });

    monitoringForm.elements.department_id?.addEventListener('change', () => {
        monitoringFilters = filterValues(monitoringForm);
        loadMonitoring(1);
    });
    monitoringForm.elements.employee_id?.addEventListener('change', () => {
        monitoringFilters = filterValues(monitoringForm);
        loadMonitoring(1);
    });
    await loadDashboard();
}
