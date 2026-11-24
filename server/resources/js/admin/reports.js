import { api, isAdmin } from './api';
import { $, $$, confirmDialog, esc, openForm, toast } from './ui';

let initialized = false;
let masterData = { departments: [], employees: [] };
let activeTab = 'summary';
let loadedStatus = { from: '', to: '', status: 'PROVISIONAL' };

const state = {
    from: '',
    to: '',
    department_id: '',
    employee_id: '',
    q: '',
    page: 1,
};

function getTodayString() {
    return document.body.dataset.today || new Date().toISOString().slice(0, 10);
}

function getStartOfMonth(dateStr) {
    const d = new Date(dateStr);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    return `${y}-${m}-01`;
}

function getLastMonthRange() {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - 1);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const lastDay = new Date(y, d.getMonth() + 1, 0).getDate();
    return {
        from: `${y}-${m}-01`,
        to: `${y}-${m}-${String(lastDay).padStart(2, '0')}`,
    };
}

function getPastDays(days) {
    const end = new Date(getTodayString());
    const start = new Date(end);
    start.setDate(start.getDate() - (days - 1));
    return {
        from: start.toISOString().slice(0, 10),
        to: end.toISOString().slice(0, 10),
    };
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
        const d = new Date(`${dateStr}T00:00:00+07:00`);
        return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(d);
    } catch {
        return dateStr;
    }
}

function getAvatarHtml(name, avatarUrl) {
    const initial = (name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
    return avatarUrl
        ? `<img src="${esc(avatarUrl)}" alt="${esc(name)}" class="avatar" style="width:34px;height:34px;border-radius:8px;object-fit:cover;margin-right:10px;flex-shrink:0;" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar',style:'width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:10px;flex-shrink:0;font-size:11px;background:#e2ece4;color:var(--primary);font-weight:700;',textContent:'${esc(initial)}'}))">`
        : `<span class="avatar" style="width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:10px;flex-shrink:0;font-size:11px;background:#e2ece4;color:var(--primary);font-weight:700;">${esc(initial)}</span>`;
}

function getStatusBadge(state) {
    const map = {
        COMPLETE: { label: 'Hadir Lengkap', cls: 'success' },
        PRESENT_LATE_PERMITTED: { label: 'Terlambat Berizin', cls: 'success' },
        PRESENT_LATE: { label: 'Terlambat', cls: 'warning' },
        IN_PROGRESS: { label: 'Sedang Berjalan', cls: 'blue' },
        NEEDS_REVIEW: { label: 'Butuh Review', cls: 'danger' },
        SCHEDULED: { label: 'Terjadwal', cls: 'neutral' },
        NOT_CHECKED_IN: { label: 'Belum Masuk', cls: 'neutral' },
        ABSENT: { label: 'Tidak Hadir', cls: 'danger' },
    };
    const s = map[state] || { label: state, cls: 'neutral' };
    return `<span class="status ${s.cls}">${esc(s.label)}</span>`;
}

function getOvertimeBadge(status) {
    const map = {
        APPROVED: { label: 'Disetujui', cls: 'success' },
        REJECTED: { label: 'Ditolak', cls: 'danger' },
        PENDING_APPROVAL: { label: 'Menunggu Persetujuan', cls: 'warning' },
        CANCELLED: { label: 'Dibatalkan', cls: 'neutral' },
    };
    const s = map[status] || { label: status, cls: 'neutral' };
    return `<span class="status ${s.cls}">${esc(s.label)}</span>`;
}

function getEmptyStateHtml(colspan, message, submessage = 'Coba ubah rentang tanggal atau filter yang dipilih.') {
    return `<tr>
        <td colspan="${colspan}" style="text-align: center; padding: 48px 16px;">
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;">
                <svg class="icon" style="width: 38px; height: 38px; color: var(--muted); opacity: 0.6;"><use href="#i-inbox"/></svg>
                <strong style="color: var(--ink); font-size: 15px;">${esc(message)}</strong>
                <span style="color: var(--muted); font-size: 13px;">${esc(submessage)}</span>
            </div>
        </td>
    </tr>`;
}

function getLoadingStateHtml(colspan, message = 'Memuat data laporan...') {
    return `<tr>
        <td colspan="${colspan}" style="text-align: center; padding: 40px 16px;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 10px; color: var(--muted); font-size: 14px; font-weight: 500;">
                <svg class="icon" style="width: 20px; height: 20px; animation: spin 1s linear infinite;"><use href="#i-clock"/></svg>
                <span>${esc(message)}</span>
            </div>
        </td>
    </tr>`;
}

export async function showReports() {
    if (!initialized) {
        initReportsUi();
        initialized = true;
    }
    await loadReportFilters();
    await loadCurrentTab();
}

function initReportsUi() {
    const today = getTodayString();
    state.from = getStartOfMonth(today);
    state.to = today;

    const fromInput = $('#report-from');
    const toInput = $('#report-to');
    if (fromInput) fromInput.value = state.from;
    if (toInput) toInput.value = state.to;
    const finalizeButton = $('#report-finalize-btn');
    if (finalizeButton) {
        finalizeButton.hidden = !isAdmin;
        finalizeButton.addEventListener('click', finalizeReport);
    }
    const reopenButton = $('#report-reopen-btn');
    if (reopenButton) {
        reopenButton.hidden = !isAdmin;
        reopenButton.addEventListener('click', reopenReport);
    }
    [fromInput, toInput].forEach((input) => input?.addEventListener('change', syncPeriodActionButtons));
    syncPeriodActionButtons();

    // Preset buttons
    $$('[data-report-preset]').forEach((btn) => {
        btn.addEventListener('click', () => {
            $$('[data-report-preset]').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');

            const preset = btn.dataset.reportPreset;
            if (preset === 'today') {
                state.from = today;
                state.to = today;
            } else if (preset === '7d') {
                const range = getPastDays(7);
                state.from = range.from;
                state.to = range.to;
            } else if (preset === 'month') {
                state.from = getStartOfMonth(today);
                state.to = today;
            } else if (preset === 'last-month') {
                const range = getLastMonthRange();
                state.from = range.from;
                state.to = range.to;
            }

            if (fromInput) fromInput.value = state.from;
            if (toInput) toInput.value = state.to;
            state.page = 1;
            loadCurrentTab();
        });
    });

    // Filter Form
    const form = $('#report-filters');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            $$('[data-report-preset]').forEach((b) => b.classList.remove('active'));
            state.from = $('#report-from').value;
            state.to = $('#report-to').value;
            state.department_id = $('#report-dept').value;
            state.employee_id = $('#report-employee').value;
            state.q = $('#report-search').value.trim();
            state.page = 1;
            loadCurrentTab();
        });
    }

    // Reset button
    const resetBtn = $('#report-reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            state.from = getStartOfMonth(today);
            state.to = today;
            state.department_id = '';
            state.employee_id = '';
            state.q = '';
            state.page = 1;

            if (fromInput) fromInput.value = state.from;
            if (toInput) toInput.value = state.to;
            $('#report-dept').value = '';
            $('#report-employee').value = '';
            $('#report-search').value = '';

            $$('[data-report-preset]').forEach((b) => {
                b.classList.toggle('active', b.dataset.reportPreset === 'month');
            });

            filterEmployeeDropdown();
            loadCurrentTab();
        });
    }

    // Department dropdown change -> filter employee options
    const deptSelect = $('#report-dept');
    if (deptSelect) {
        deptSelect.addEventListener('change', () => {
            filterEmployeeDropdown();
        });
    }

    // Tabs
    $$('[data-report-tab]').forEach((tabBtn) => {
        tabBtn.addEventListener('click', () => {
            const target = tabBtn.dataset.reportTab;
            if (activeTab === target) return;

            activeTab = target;
            $$('[data-report-tab]').forEach((b) => {
                const isCurrent = b === tabBtn;
                b.classList.toggle('active', isCurrent);
                b.setAttribute('aria-selected', String(isCurrent));
            });

            $('#tab-panel-summary').hidden = activeTab !== 'summary';
            $('#tab-panel-details').hidden = activeTab !== 'details';
            $('#tab-panel-overtime').hidden = activeTab !== 'overtime';
            $('#tab-panel-shift-breakdown').hidden = activeTab !== 'shift-breakdown';

            state.page = 1;
            loadCurrentTab();
        });
    });

    // Pagination for details
    const prevBtn = $('#report-details-prev-btn');
    const nextBtn = $('#report-details-next-btn');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (state.page > 1) {
                state.page--;
                loadDetailsTab();
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            state.page++;
            loadDetailsTab();
        });
    }

    // Export buttons
    const btnExcel = $('#btn-export-excel');
    if (btnExcel) {
        btnExcel.addEventListener('click', () => {
            triggerExport('excel');
        });
    }

    const btnPdf = $('#btn-export-pdf');
    if (btnPdf) {
        btnPdf.addEventListener('click', () => {
            triggerExport('pdf');
        });
    }
}

async function loadReportFilters() {
    try {
        const res = await api('reports/filters');
        masterData = res;
        populateFilters();
    } catch (e) {
        toast('Gagal memuat filter laporan: ' + e.message, 'error');
    }
}

function populateFilters() {
    const deptSelect = $('#report-dept');
    if (deptSelect && masterData.departments) {
        const currentVal = deptSelect.value;
        let html = '<option value="">Semua departemen</option>';
        masterData.departments.forEach((d) => {
            html += `<option value="${d.id}">${esc(d.name)}</option>`;
        });
        deptSelect.innerHTML = html;
        if (currentVal) deptSelect.value = currentVal;
    }

    filterEmployeeDropdown();
}

function filterEmployeeDropdown() {
    const empSelect = $('#report-employee');
    const deptId = $('#report-dept')?.value;
    if (!empSelect) return;

    const currentVal = empSelect.value;
    let list = masterData.employees || [];
    if (deptId) {
        list = list.filter((e) => String(e.department_id) === String(deptId));
    }

    let html = '<option value="">Semua karyawan</option>';
    list.forEach((e) => {
        html += `<option value="${e.id}">${esc(e.code)} · ${esc(e.name)}</option>`;
    });
    empSelect.innerHTML = html;
    if (currentVal && list.some((e) => String(e.id) === String(currentVal))) {
        empSelect.value = currentVal;
    } else {
        empSelect.value = '';
    }
}

async function loadCurrentTab() {
    if (activeTab === 'summary') {
        await loadSummaryTab();
    } else if (activeTab === 'details') {
        await loadDetailsTab();
    } else if (activeTab === 'overtime') {
        await loadOvertimeTab();
    } else if (activeTab === 'shift-breakdown') {
        await loadShiftBreakdownTab();
    }
}

async function loadShiftBreakdownTab() {
    const tbody = $('#report-shift-breakdown-tbody');
    if (tbody) tbody.innerHTML = getLoadingStateHtml(9, 'Memuat frekuensi shift...');

    try {
        const query = {
            from: state.from,
            to: state.to,
            department_id: state.department_id,
            employee_id: state.employee_id,
            q: state.q,
        };
        const response = await api('reports/shift-breakdown', { query });
        renderPeriodStatus(response.period_status);
        renderShiftBreakdownTable(response.summary_rows || response.data, response.kpi);
    } catch (error) {
        if (tbody) tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--danger,#dc2626);font-weight:500;">${esc(error.message)}</td></tr>`;
        toast('Gagal memuat frekuensi shift: ' + error.message, 'error');
    }
}

function renderShiftBreakdownTable(rows, kpi) {
    const tbody = $('#report-shift-breakdown-tbody');
    if (!tbody) return;
    if (!rows?.length) {
        tbody.innerHTML = getEmptyStateHtml(9, 'Belum ada shift yang memiliki check-in pada periode ini.');
        return;
    }

    if (rows[0]?.shift_counts) {
        let html = rows.map((row) => {
            const employee = row.employee;
            const sc = row.shift_counts || {};
            return `<tr>
                <td data-label="Karyawan"><b>${esc(employee.name)}</b><small style="display:block;color:var(--muted);">${esc(employee.code)}</small></td>
                <td data-label="Departemen">${esc(employee.department)}</td>
                <td data-label="Shift 1" class="number-cell" style="text-align:center;">${sc.shift_1 > 0 ? `<b style="color:var(--primary);">${sc.shift_1}</b>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Shift 2" class="number-cell" style="text-align:center;">${sc.shift_2 > 0 ? `<b style="color:#d97706;">${sc.shift_2}</b>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Shift 3" class="number-cell" style="text-align:center;">${sc.shift_3 > 0 ? `<b style="color:#7c3aed;">${sc.shift_3}</b>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Middle" class="number-cell" style="text-align:center;">${sc.middle > 0 ? `<b style="color:#d97706;">${sc.middle}</b>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Split Khusus" class="number-cell" style="text-align:center;">${sc.split > 0 ? `<b style="color:#059669;">${sc.split}</b>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Tukar Shift" class="number-cell" style="text-align:center;">${sc.exchange > 0 ? `<span class="status warning" style="min-height:22px;padding:2px 8px;font-size:11.5px;">${sc.exchange}</span>` : '<span style="color:var(--muted);">—</span>'}</td>
                <td data-label="Total Hadir" class="number-cell" style="text-align:center;font-weight:700;">${sc.total_shifts ?? row.attended_days}</td>
            </tr>`;
        }).join('');

        if (kpi) {
            html += `<tr class="report-total-row">
                <td colspan="2" style="text-align: left; padding: 14px 16px;">
                    <strong>TOTAL KESELURUHAN</strong>
                    <small style="display: block; color: var(--muted); font-weight: 400; margin-top: 2px;">Akumulasi ${kpi.total_employees} karyawan</small>
                </td>
                <td class="number-cell" style="text-align:center;color:var(--primary);"><b>${kpi.total_shift_1 ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;color:#d97706;"><b>${kpi.total_shift_2 ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;color:#7c3aed;"><b>${kpi.total_shift_3 ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;color:#d97706;"><b>${kpi.total_middle ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;color:#059669;"><b>${kpi.total_split ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;"><b>${kpi.total_exchange ?? 0}</b></td>
                <td class="number-cell" style="text-align:center;font-weight:700;"><b>${(kpi.total_shift_1 ?? 0) + (kpi.total_shift_2 ?? 0) + (kpi.total_shift_3 ?? 0) + (kpi.total_middle ?? 0) + (kpi.total_split ?? 0)}</b></td>
            </tr>`;
        }
        tbody.innerHTML = html;
        return;
    }

    tbody.innerHTML = rows.map((row) => {
        const employee = row.employee;
        return `<tr>
            <td data-label="Karyawan"><b>${esc(employee.name)}</b><small style="display:block;color:var(--muted);">${esc(employee.code)}</small></td>
            <td data-label="Departemen">${esc(employee.department)}</td>
            <td colspan="6" data-label="Kategori Shift">${esc(row.category_name)}</td>
            <td data-label="Jumlah Shift Hadir" style="text-align:center;font-weight:700;">${esc(row.shift_count)}</td>
        </tr>`;
    }).join('');
}

async function loadSummaryTab() {
    const tbody = $('#report-summary-tbody');
    if (tbody) {
        tbody.innerHTML = getLoadingStateHtml(12, 'Memuat rekapitulasi kehadiran...');
    }

    try {
        const query = {
            from: state.from,
            to: state.to,
            department_id: state.department_id,
            employee_id: state.employee_id,
            q: state.q,
        };
        const data = await api('reports/summary', { query });
        renderPeriodStatus(data.period_status, data.generated_at_local);
        renderKpi(data.kpi);
        renderSummaryTable(data.rows, data.kpi);
    } catch (e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align: center; padding: 32px; color: var(--danger, #dc2626); font-weight: 500;">${esc(e.message)}</td></tr>`;
        }
        toast('Gagal memuat rekapitulasi: ' + e.message, 'error');
    }
}

function renderPeriodStatus(status, generatedAt) {
    loadedStatus = { from: state.from, to: state.to, status };
    const blockersBox = $('#report-finalize-blockers');
    if (blockersBox) {
        blockersBox.hidden = true;
        blockersBox.textContent = '';
    }
    const badge = $('#report-status-badge');
    const metaGen = $('#report-meta-generated');

    if (badge) {
        if (status === 'FINALIZED') {
            badge.className = 'report-status-badge final';
            badge.textContent = 'Rekap Resmi (Final)';
        } else {
            badge.className = 'report-status-badge provisional';
            badge.textContent = 'Draft (Provisional)';
        }
    }
    if (metaGen && generatedAt) {
        metaGen.textContent = `Penarikan: ${generatedAt}`;
    }
    syncPeriodActionButtons();
}

function isFullEndedMonthRange(from, to) {
    if (!from || !to) return false;
    const [fromYear, fromMonth, fromDay] = from.split('-').map(Number);
    const [toYear, toMonth, toDay] = to.split('-').map(Number);
    const start = new Date(Date.UTC(fromYear, fromMonth - 1, fromDay));
    const end = new Date(Date.UTC(toYear, toMonth - 1, toDay));
    const today = getTodayString();
    const [todayYear, todayMonth] = today.split('-').map(Number);
    const currentMonthStart = Date.UTC(todayYear, todayMonth - 1, 1);
    const lastDay = new Date(Date.UTC(toYear, toMonth, 0)).getUTCDate();

    return start.getUTCFullYear() === fromYear
        && start.getUTCMonth() === fromMonth - 1
        && start.getUTCDate() === 1
        && end.getUTCFullYear() === toYear
        && end.getUTCMonth() === toMonth - 1
        && toDay === lastDay
        && end.getTime() >= start.getTime()
        && (end.getTime() - start.getTime()) / 86400000 <= 62
        && end.getTime() < currentMonthStart;
}

function syncPeriodActionButtons() {
    const from = $('#report-from')?.value || state.from;
    const to = $('#report-to')?.value || state.to;
    const statusMatchesRange = loadedStatus.from === state.from && loadedStatus.to === state.to;
    const inputsMatchState = from === state.from && to === state.to;
    const canAct = isAdmin && isFullEndedMonthRange(from, to) && inputsMatchState && statusMatchesRange;
    const finalizeButton = $('#report-finalize-btn');
    const reopenButton = $('#report-reopen-btn');
    if (finalizeButton) {
        finalizeButton.hidden = !isAdmin || loadedStatus.status === 'FINALIZED';
        finalizeButton.disabled = !canAct
            || loadedStatus.status !== 'PROVISIONAL';
    }
    if (reopenButton) {
        reopenButton.hidden = !isAdmin || loadedStatus.status !== 'FINALIZED';
        reopenButton.disabled = !canAct
            || loadedStatus.status !== 'FINALIZED';
    }
}

async function finalizeReport() {
    const button = $('#report-finalize-btn');
    if (!button || button.disabled) return;

    const confirmed = await confirmDialog({
        title: 'Finalkan laporan?',
        message: 'Pemeriksaan mencakup semua departemen dan karyawan, termasuk yang tidak tampil karena filter. Laporan tetap membaca data terbaru dan absensi tetap bisa diedit.',
        confirmLabel: 'Finalkan Laporan',
    });
    if (!confirmed) return;

    const blockersBox = $('#report-finalize-blockers');
    if (blockersBox) {
        blockersBox.hidden = true;
        blockersBox.textContent = '';
    }
    button.disabled = true;
    try {
        await api('reports/finalize', { method: 'POST', body: { from: state.from, to: state.to } });
        toast('Laporan berhasil difinalkan.');
        await loadCurrentTab();
    } catch (error) {
        const blockers = error.details?.blockers || [];
        const count = Number(error.details?.count || 0);
        if (blockersBox && blockers.length) {
            const entries = blockers.slice(0, 10).map((item) => {
                const employee = item.employee || {};
                return `${item.work_date} · ${employee.name || 'Karyawan'}${employee.code ? ` (${employee.code})` : ''}`;
            });
            if (count > entries.length) entries.push(`dan ${count - entries.length} sesi lainnya`);
            blockersBox.textContent = `Belum bisa difinalkan. Verifikasi absensi berikut: ${entries.join('; ')}.`;
            blockersBox.hidden = false;
        }
        toast(error.message || 'Gagal memfinalkan laporan.', 'error');
        syncPeriodActionButtons();
    }
}

function reopenReport() {
    const button = $('#report-reopen-btn');
    if (!button || button.disabled) return;

    openForm({
        title: 'Buka ulang laporan',
        hint: 'Laporan akan kembali berstatus draft dan masuk ke revisi baru. Tuliskan alasan untuk audit.',
        fields: [{
            name: 'reason',
            label: 'Alasan buka ulang',
            type: 'textarea',
            rows: 3,
            required: true,
            hint: 'Minimal 3 karakter, maksimal 500 karakter.',
        }],
        submitLabel: 'Buka ulang',
        onSubmit: async ({ reason }) => {
            const normalizedReason = String(reason ?? '').trim();
            if (normalizedReason.length < 3 || normalizedReason.length > 500) {
                throw new Error('Alasan harus berisi 3 sampai 500 karakter.');
            }

            const result = await api('reports/reopen', {
                method: 'POST',
                body: { from: state.from, to: state.to, reason: normalizedReason },
            });
            toast(`Laporan dibuka ulang. Revisi ${Object.values(result.revisions ?? {}).join(', ')} siap diproses.`);
            await loadCurrentTab();
        },
    });
}

function renderKpi(kpi) {
    if (!kpi) return;
    const formatRp = (num) => 'Rp ' + Number(num || 0).toLocaleString('id-ID');

    const totalEmpEl = $('#kpi-total-employees');
    if (totalEmpEl) totalEmpEl.textContent = Number(kpi.total_employees || 0).toLocaleString('id-ID');

    const attendedDaysEl = $('#kpi-total-attended-days');
    if (attendedDaysEl) attendedDaysEl.textContent = Number(kpi.total_attended_days || 0).toLocaleString('id-ID');

    const rateEl = $('#kpi-attendance-rate');
    if (rateEl) rateEl.textContent = `${kpi.attendance_rate || 0}% hadir`;

    const verifiedEl = $('#kpi-total-verified-hours');
    if (verifiedEl) verifiedEl.textContent = kpi.total_verified_hours_formatted || '0j 0m';

    const otEl = $('#kpi-total-overtime-hours');
    if (otEl) otEl.textContent = kpi.total_overtime_hours_formatted || '0j 0m';
    const actualOtEl = $('#kpi-total-overtime-actual-hours');
    if (actualOtEl) actualOtEl.textContent = `Aktual: ${kpi.total_actual_overtime_hours_formatted || '0j 0m'}`;

    const lateCountEl = $('#kpi-total-late-count');
    if (lateCountEl) lateCountEl.textContent = `${Number(kpi.total_late_count || 0)}x`;

    const lateMinEl = $('#kpi-total-late-minutes');
    if (lateMinEl) lateMinEl.textContent = `${Number(kpi.total_late_minutes || 0).toLocaleString('id-ID')} m`;

    const allowanceEl = $('#kpi-total-allowance');
    if (allowanceEl) allowanceEl.textContent = formatRp(kpi.total_allowance_amount);

    const splitEl = $('#kpi-allowance-split');
    if (splitEl) splitEl.textContent = `Makan: ${formatRp(kpi.total_meal_allowance)} · Trs: ${formatRp(kpi.total_transport_allowance)}`;
}

function renderShiftCountsHtml(counts) {
    if (!counts) return '<span style="color: var(--muted);">—</span>';
    const pills = [];
    if (counts.shift_1 > 0) pills.push(`<span class="shift-pill shift-pill-s1" title="Shift 1: ${counts.shift_1}">S1: ${counts.shift_1}</span>`);
    if (counts.shift_2 > 0) pills.push(`<span class="shift-pill shift-pill-s2" title="Shift 2: ${counts.shift_2}">S2: ${counts.shift_2}</span>`);
    if (counts.shift_3 > 0) pills.push(`<span class="shift-pill shift-pill-s3" title="Shift 3: ${counts.shift_3}">S3: ${counts.shift_3}</span>`);
    if (counts.middle > 0) pills.push(`<span class="shift-pill shift-pill-s2" title="Middle: ${counts.middle}">Mid: ${counts.middle}</span>`);
    if (counts.split > 0) pills.push(`<span class="shift-pill shift-pill-split" title="Split Khusus: ${counts.split}">Split: ${counts.split}</span>`);
    if (counts.exchange > 0) pills.push(`<span class="shift-pill shift-pill-swap" title="Tukar Shift: ${counts.exchange}">Tukar: ${counts.exchange}</span>`);

    return pills.length ? `<div class="shift-pill-group">${pills.join('')}</div>` : '<span style="color: var(--muted);">—</span>';
}

function renderSummaryTable(rows, kpi) {
    const tbody = $('#report-summary-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = getEmptyStateHtml(12, 'Tidak ada data rekapitulasi kehadiran.');
        return;
    }

    const formatRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

    let html = '';
    rows.forEach((r) => {
        const emp = r.employee;
        const avatar = getAvatarHtml(emp.name, emp.avatar_url);
        const lateHtml = r.late_count > 0
            ? `<span class="status warning" style="min-height:22px;padding:2px 8px;font-size:11.5px;">${r.late_count}x (${r.late_minutes}m)</span>`
            : '<span style="color: var(--muted);">—</span>';

        const otHtml = r.overtime_minutes > 0
            ? `<span class="status success" style="min-height:22px;padding:2px 8px;font-size:11.5px;">+${esc(r.overtime_hours_formatted)}</span>`
            : '<span style="color: var(--muted);">—</span>';
        const actualOtHtml = r.overtime_actual_minutes > 0
            ? `<span style="font-weight:600;">${esc(r.overtime_actual_hours_formatted)}</span>`
            : '<span style="color: var(--muted);">—</span>';

        const leaveHtml = r.leave_days > 0
            ? `<span style="font-weight: 600;">${r.leave_days} h</span>`
            : '<span style="color: var(--muted);">—</span>';

        const reviewHtml = r.review_count > 0
            ? `<span class="status danger" style="min-height:22px;padding:2px 8px;font-size:11.5px;">${r.review_count}</span>`
            : '<span style="color: var(--muted);">—</span>';

        html += `<tr>
            <td data-label="Karyawan">
                <div style="display:flex;align-items:center;">
                    ${avatar}
                    <div>
                        <div style="font-weight: 700; color: var(--ink); line-height: 1.3;">${esc(emp.name)}</div>
                        <small style="color: var(--muted); font-size: 12px;"><code>${esc(emp.code)}</code> · ${esc(emp.position || emp.department)}</small>
                    </div>
                </div>
            </td>
            <td data-label="Departemen">${esc(emp.department)}</td>
            <td data-label="Hari Hadir / Jadwal" class="number-cell" style="text-align: center;">
                <b>${r.attended_days}</b> <small style="color: var(--muted);">/ ${r.scheduled_days} h</small>
            </td>
            <td data-label="Sesi Hadir" class="number-cell" style="text-align: center;">
                ${r.attended_sessions} <small style="color: var(--muted);">/ ${r.scheduled_sessions}</small>
            </td>
            <td data-label="Rincian Shift" style="text-align: center;">
                ${renderShiftCountsHtml(r.shift_counts)}
            </td>
            <td data-label="Jam Reguler" class="number-cell" style="text-align: center;">
                <b style="color: var(--primary);">${esc(r.verified_hours_formatted)}</b>
            </td>
            <td data-label="Terlambat" style="text-align: center;">
                ${lateHtml}
            </td>
            <td data-label="Lembur Aktual" style="text-align: center;">${actualOtHtml}</td>
            <td data-label="Lembur Diakui" style="text-align: center;">
                ${otHtml}
            </td>
            <td data-label="Cuti" class="number-cell" style="text-align: center;">
                ${leaveHtml}
            </td>
            <td data-label="Review" style="text-align: center;">
                ${reviewHtml}
            </td>
            <td data-label="Total Tunjangan" class="number-cell" style="text-align: right; font-weight: 700; color: var(--ink);">
                ${formatRp(r.total_allowance)}
            </td>
        </tr>`;
    });

    if (kpi) {
        html += `<tr class="report-total-row">
            <td colspan="2" style="text-align: left; padding: 14px 16px;">
                <strong>TOTAL KESELURUHAN</strong>
                <small style="display: block; color: var(--muted); font-weight: 400; margin-top: 2px;">Akumulasi ${kpi.total_employees} karyawan</small>
            </td>
            <td class="number-cell" style="text-align: center;"><b>${kpi.total_attended_days} h</b></td>
            <td class="number-cell" style="text-align: center;"><b>${kpi.total_attended_sessions}</b> <small style="color: var(--muted);">/ ${kpi.total_scheduled_sessions}</small></td>
            <td style="text-align: center;">
                ${renderShiftCountsHtml({
                    shift_1: kpi.total_shift_1,
                    shift_2: kpi.total_shift_2,
                    shift_3: kpi.total_shift_3,
                    middle: kpi.total_middle,
                    split: kpi.total_split,
                    exchange: kpi.total_exchange,
                })}
            </td>
            <td class="number-cell" style="text-align: center; color: var(--primary);"><b>${esc(kpi.total_verified_hours_formatted)}</b></td>
            <td style="text-align: center;">${kpi.total_late_count}x <small style="color: var(--muted);">(${kpi.total_late_minutes}m)</small></td>
            <td style="text-align: center;">${esc(kpi.total_actual_overtime_hours_formatted)}</td>
            <td style="text-align: center; color: var(--primary);"><b>${esc(kpi.total_overtime_hours_formatted)}</b></td>
            <td class="number-cell" style="text-align: center;"><b>${kpi.total_leave_days} h</b></td>
            <td style="text-align: center;">${kpi.total_review_count > 0 ? `<span class="status danger">${kpi.total_review_count}</span>` : '—'}</td>
            <td class="number-cell" style="text-align: right; color: var(--primary); font-size: 14px;"><b>${formatRp(kpi.total_allowance_amount)}</b></td>
        </tr>`;
    }

    tbody.innerHTML = html;
}

async function loadDetailsTab() {
    const tbody = $('#report-details-tbody');
    const pagination = $('#report-details-pagination');
    if (tbody) {
        tbody.innerHTML = getLoadingStateHtml(12, 'Memuat rincian sesi kehadiran...');
    }

    try {
        const query = {
            from: state.from,
            to: state.to,
            department_id: state.department_id,
            employee_id: state.employee_id,
            q: state.q,
            page: state.page,
            per_page: 50,
        };
        const res = await api('reports/details', { query });
        renderPeriodStatus(res.period_status);
        renderDetailsTable(res.data);
        renderPagination(res.meta);
    } catch (e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align: center; padding: 32px; color: var(--danger, #dc2626); font-weight: 500;">${esc(e.message)}</td></tr>`;
        }
        if (pagination) pagination.hidden = true;
        toast('Gagal memuat detail sesi: ' + e.message, 'error');
    }
}

function renderDetailsTable(rows) {
    const tbody = $('#report-details-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = getEmptyStateHtml(12, 'Tidak ada rekaman sesi untuk periode dan filter ini.');
        return;
    }

    const formatRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

    let html = '';
    rows.forEach((r) => {
        const emp = r.employee;
        const att = r.attendance;
        const sh = r.shift;
        const al = r.allowance;
        const avatar = getAvatarHtml(emp.name, emp.avatar_url);

        const lateStr = att.is_late
            ? `<span class="status warning" style="min-height:22px;padding:2px 6px;font-size:11px;">+${att.late_minutes} m</span>`
            : '<span style="color: var(--muted);">—</span>';

        html += `<tr>
            <td data-label="Tanggal" style="white-space: nowrap;">
                <b style="color: var(--ink);">${formatDate(r.work_date)}</b>
                <small style="display: block; color: var(--muted); font-size: 11px;"><code>${esc(r.work_date)}</code></small>
            </td>
            <td data-label="Karyawan">
                <div style="display:flex;align-items:center;">
                    ${avatar}
                    <div>
                        <div style="font-weight: 700; color: var(--ink);">${esc(emp.name)}</div>
                        <small style="color: var(--muted); font-size: 12px;"><code>${esc(emp.code)}</code> · ${esc(emp.department)}</small>
                    </div>
                </div>
            </td>
            <td data-label="Shift / Sesi">
                <b>${esc(sh.name)}</b>
                <small style="display: block; color: var(--muted); font-size: 11.5px;">Sesi ${sh.seq}</small>
            </td>
            <td data-label="Jadwal Shift" class="number-cell" style="text-align: center; white-space: nowrap;">
                <code>${esc(sh.schedule_clock)}</code>
            </td>
            <td data-label="Jam Masuk" class="number-cell" style="text-align: center; white-space: nowrap;">
                ${att.check_in_clock !== '-' ? `<b>${esc(att.check_in_clock)}</b>` : '<span style="color: var(--muted);">—</span>'}
                ${att.check_in_latitude != null && att.check_in_longitude != null ? `<small class="gps-coord-meta">${esc(Number(att.check_in_latitude).toFixed(5))}, ${esc(Number(att.check_in_longitude).toFixed(5))}${att.check_in_distance != null ? ` · ${esc(att.check_in_distance)}m` : ''}</small>` : ''}
            </td>
            <td data-label="Jam Pulang" class="number-cell" style="text-align: center; white-space: nowrap;">
                ${att.check_out_clock !== '-' ? `<b>${esc(att.check_out_clock)}</b>` : '<span style="color: var(--muted);">—</span>'}
                ${att.checkout_source_label ? `<small style="display:block;color:var(--muted);">${esc(att.checkout_source_label)}</small>` : ''}
                ${att.auto_checkout_clock && att.auto_checkout_clock !== '-' && att.checkout_source !== 'SYSTEM_AUTO' ? `<small style="display:block;color:var(--muted);">Checkout otomatis ${esc(att.auto_checkout_clock)}</small>` : ''}
                ${att.check_out_latitude != null && att.check_out_longitude != null ? `<small class="gps-coord-meta">${esc(Number(att.check_out_latitude).toFixed(5))}, ${esc(Number(att.check_out_longitude).toFixed(5))}${att.check_out_distance != null ? ` · ${esc(att.check_out_distance)}m` : ''}</small>` : ''}
            </td>
            <td data-label="Status" style="text-align: center;">
                ${getStatusBadge(att.state)}
            </td>
            <td data-label="Terlambat" style="text-align: center;">${lateStr}</td>
            <td data-label="Jam Valid" class="number-cell" style="text-align: center;">
                <b>${esc(att.verified_hours_formatted)}</b>
            </td>
            <td data-label="Lembur Aktual" class="number-cell" style="text-align: center;">
                ${r.overtime_actual_minutes > 0 ? `<span>${r.overtime_actual_minutes} m</span>` : '<span style="color: var(--muted);">—</span>'}
            </td>
            <td data-label="Lembur Diakui" class="number-cell" style="text-align: center;">
                ${r.overtime_verified_minutes > 0 ? `<span class="status success" style="min-height:22px;padding:2px 6px;font-size:11px;">+${r.overtime_verified_minutes} m</span>` : '<span style="color: var(--muted);">—</span>'}
            </td>
            <td data-label="Tunjangan" class="number-cell" style="text-align: right; font-weight: 600;">
                ${al.total_amount > 0 ? formatRp(al.total_amount) : '<span style="color: var(--muted);">—</span>'}
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function renderPagination(meta) {
    const bar = $('#report-details-pagination');
    const info = $('#report-details-pagination-info');
    const prevBtn = $('#report-details-prev-btn');
    const nextBtn = $('#report-details-next-btn');

    if (!bar || !meta) return;

    if (meta.total <= meta.per_page) {
        bar.hidden = true;
        return;
    }

    bar.hidden = false;
    info.textContent = `Menampilkan ${meta.from || 0} · ${meta.to || 0} dari ${meta.total} rekaman (Halaman ${meta.current_page} dari ${meta.last_page})`;
    prevBtn.disabled = meta.current_page <= 1;
    nextBtn.disabled = meta.current_page >= meta.last_page;
}

async function loadOvertimeTab() {
    const tbody = $('#report-overtime-tbody');
    if (tbody) {
        tbody.innerHTML = getLoadingStateHtml(10, 'Memuat rekap lembur...');
    }

    try {
        const query = {
            from: state.from,
            to: state.to,
            department_id: state.department_id,
            employee_id: state.employee_id,
            q: state.q,
        };
        const res = await api('reports/overtime', { query });
        renderPeriodStatus(res.period_status);
        renderOvertimeTable(res.data);
    } catch (e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 32px; color: var(--danger, #dc2626); font-weight: 500;">${esc(e.message)}</td></tr>`;
        }
        toast('Gagal memuat rekap lembur: ' + e.message, 'error');
    }
}

function renderOvertimeTable(rows) {
    const tbody = $('#report-overtime-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = getEmptyStateHtml(10, 'Tidak ada data perintah lembur (SPL) yang disetujui.');
        return;
    }

    let html = '';
    rows.forEach((r) => {
        const empName = r.employee_name || (r.employee?.name ?? '-');
        const empCode = r.employee?.code ?? '';
        const deptName = r.department_name || (r.employee?.department ?? '-');
        const avatar = getAvatarHtml(empName, r.employee?.avatar_url);
        const executionStatus = r.execution_status === 'REALIZED' ? 'Terealisasi' : 'Belum terealisasi';

        html += `<tr>
            <td data-label="No. SPL">
                <code style="font-weight: 700; color: var(--primary);">${esc(r.order_number)}</code>
            </td>
            <td data-label="Tanggal" style="white-space: nowrap;">
                <b>${formatDate(r.work_date)}</b>
                <small style="display: block; color: var(--muted); font-size: 11px;"><code>${esc(r.work_date)}</code></small>
            </td>
            <td data-label="Karyawan">
                <div style="display:flex;align-items:center;">
                    ${avatar}
                    <div>
                        <div style="font-weight: 700; color: var(--ink);">${esc(empName)}</div>
                        <small style="color: var(--muted); font-size: 12px;">${empCode ? `<code>${esc(empCode)}</code> · ` : ''}${esc(deptName)}</small>
                    </div>
                </div>
            </td>
            <td data-label="Departemen">${esc(deptName)}</td>
            <td data-label="Uraian Tugas Lembur">
                <b style="color: var(--ink);">${esc(r.title)}</b>
                ${r.session_name ? `<small style="display: block; color: var(--muted);">${esc(r.session_name)}</small>` : ''}
            </td>
            <td data-label="Rentang SPL" class="number-cell" style="text-align: center; white-space: nowrap;">
                <code>${esc(r.local_start || '—')} · ${esc(r.local_end || '—')}</code>
            </td>
            <td data-label="Durasi Rencana" class="number-cell" style="text-align: center;">${r.planned_minutes || 0} m</td>
            <td data-label="Durasi Aktual" class="number-cell" style="text-align: center;">${r.actual_minutes || 0} m</td>
            <td data-label="Durasi Diakui" class="number-cell" style="text-align: center; font-weight: 700; color: var(--primary);">
                +${r.verified_minutes || 0} m
            </td>
            <td data-label="Status" style="text-align: center;">${getOvertimeBadge(r.status)}<small style="display:block;color:var(--muted);margin-top:3px;">${executionStatus}</small></td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function triggerExport(format) {
    const params = new URLSearchParams();
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);
    if (state.department_id) params.set('department_id', state.department_id);
    if (state.employee_id) params.set('employee_id', state.employee_id);
    if (state.q) params.set('q', state.q);

    if (format === 'pdf') {
        const type = activeTab === 'details' ? 'detail' : activeTab === 'overtime' ? 'overtime' : activeTab === 'shift-breakdown' ? 'shift-breakdown' : 'summary';
        params.set('type', type);
        window.location.assign(`/admin/api/reports/export/pdf?${params.toString()}`);
    } else {
        window.location.assign(`/admin/api/reports/export/excel?${params.toString()}`);
    }
}
