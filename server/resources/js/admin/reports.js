import { api } from './api';
import { $, $$, esc, toast } from './ui';

let initialized = false;
let masterData = { departments: [], employees: [] };
let activeTab = 'summary';

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
            $$('[data-report-tab]').forEach((b) => b.classList.toggle('active', b === tabBtn));

            $('#tab-panel-summary').hidden = activeTab !== 'summary';
            $('#tab-panel-details').hidden = activeTab !== 'details';
            $('#tab-panel-overtime').hidden = activeTab !== 'overtime';

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
        toast('Gagal memuat filter laporan: ' + e.message);
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
        html += `<option value="${e.id}">${esc(e.code)} - ${esc(e.name)}</option>`;
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
    }
}

async function loadSummaryTab() {
    const tbody = $('#report-summary-tbody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 24px; color: var(--text-muted);">Memuat rekapitulasi kehadiran...</td></tr>';
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
            tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 24px; color: var(--danger, #dc2626);">${esc(e.message)}</td></tr>`;
        }
        toast('Gagal memuat rekapitulasi: ' + e.message);
    }
}

function renderPeriodStatus(status, generatedAt) {
    const badge = $('#report-status-badge');
    const metaGen = $('#report-meta-generated');

    if (badge) {
        if (status === 'FINALIZED') {
            badge.className = 'report-status-badge final';
            badge.textContent = 'REKAP RESMI (FINAL)';
        } else {
            badge.className = 'report-status-badge provisional';
            badge.textContent = 'DRAFT (PROVISIONAL)';
        }
    }
    if (metaGen && generatedAt) {
        metaGen.textContent = `Waktu penarikan: ${generatedAt}`;
    }
}

function renderKpi(kpi) {
    if (!kpi) return;
    const formatRp = (num) => 'Rp ' + Number(num || 0).toLocaleString('id-ID');

    $('#kpi-total-employees').textContent = Number(kpi.total_employees || 0).toLocaleString('id-ID');
    $('#kpi-total-attended-days').textContent = Number(kpi.total_attended_days || 0).toLocaleString('id-ID');
    $('#kpi-attendance-rate').textContent = `Tingkat kehadiran: ${kpi.attendance_rate || 0}%`;
    $('#kpi-total-verified-hours').textContent = kpi.total_verified_hours_formatted || '0j 0m';
    $('#kpi-total-overtime-hours').textContent = kpi.total_overtime_hours_formatted || '0j 0m';
    $('#kpi-total-late-count').textContent = `${Number(kpi.total_late_count || 0)}x`;
    $('#kpi-total-late-minutes').textContent = `Total: ${Number(kpi.total_late_minutes || 0).toLocaleString('id-ID')} menit`;
    $('#kpi-total-allowance').textContent = formatRp(kpi.total_allowance_amount);
    $('#kpi-allowance-split').textContent = `Makan: ${formatRp(kpi.total_meal_allowance)} | Trs: ${formatRp(kpi.total_transport_allowance)}`;
}

function renderSummaryTable(rows, kpi) {
    const tbody = $('#report-summary-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 32px; color: var(--text-muted);">Tidak ada data karyawan untuk periode dan filter ini.</td></tr>';
        return;
    }

    const formatRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

    let html = '';
    rows.forEach((r) => {
        const emp = r.employee;
        const lateHtml = r.late_count > 0
            ? `<span style="color: var(--warning, #b45309); font-weight: 600;">${r.late_count}x</span> <span style="font-size: 11px; color: var(--text-muted);">(${r.late_minutes}m)</span>`
            : '<span style="color: var(--success, #15803d);">-</span>';

        html += `<tr>
            <td data-label="Karyawan">
                <div style="font-weight: 600; color: var(--ink);">${esc(emp.name)}</div>
                <div style="font-size: 12px; color: var(--text-muted);"><code>${esc(emp.code)}</code> &bull; ${esc(emp.position)}</div>
            </td>
            <td data-label="Departemen">${esc(emp.department)}</td>
            <td data-label="Hari Hadir / Jadwal" style="text-align: center;">
                <strong>${r.attended_days}</strong> / ${r.scheduled_days} h
            </td>
            <td data-label="Sesi Hadir" style="text-align: center;">
                ${r.attended_sessions} / ${r.scheduled_sessions}
            </td>
            <td data-label="Jam Reguler" style="text-align: center;">
                <strong>${esc(r.verified_hours_formatted)}</strong>
            </td>
            <td data-label="Terlambat" style="text-align: center;">
                ${lateHtml}
            </td>
            <td data-label="Lembur Diakui" style="text-align: center;">
                ${esc(r.overtime_hours_formatted)}
            </td>
            <td data-label="Cuti" style="text-align: center;">
                ${r.leave_days > 0 ? `${r.leave_days} h` : '-'}
            </td>
            <td data-label="Review" style="text-align: center;">
                ${r.review_count > 0 ? `<span style="color: var(--danger, #dc2626); font-weight: 600;">${r.review_count}</span>` : '-'}
            </td>
            <td data-label="Total Tunjangan" style="text-align: right; font-weight: 600;">
                ${formatRp(r.total_allowance)}
            </td>
        </tr>`;
    });

    if (kpi) {
        html += `<tr style="background: var(--surface-soft, #f8fafc); font-weight: 700; border-top: 2px solid var(--border, #cbd5e1);">
            <td colspan="2" style="text-align: left;">TOTAL KESELURUHAN</td>
            <td style="text-align: center;">${kpi.total_attended_days} h</td>
            <td style="text-align: center;">${kpi.total_attended_sessions} / ${kpi.total_scheduled_sessions}</td>
            <td style="text-align: center;">${esc(kpi.total_verified_hours_formatted)}</td>
            <td style="text-align: center;">${kpi.total_late_count}x (${kpi.total_late_minutes}m)</td>
            <td style="text-align: center;">${esc(kpi.total_overtime_hours_formatted)}</td>
            <td style="text-align: center;">${kpi.total_leave_days} h</td>
            <td style="text-align: center;">${kpi.total_review_count}</td>
            <td style="text-align: right; color: var(--primary);">${formatRp(kpi.total_allowance_amount)}</td>
        </tr>`;
    }

    tbody.innerHTML = html;
}

async function loadDetailsTab() {
    const tbody = $('#report-details-tbody');
    const pagination = $('#report-details-pagination');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 24px; color: var(--text-muted);">Memuat rincian sesi kehadiran...</td></tr>';
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
        renderDetailsTable(res.data);
        renderPagination(res.meta);
    } catch (e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 24px; color: var(--danger, #dc2626);">${esc(e.message)}</td></tr>`;
        }
        if (pagination) pagination.hidden = true;
        toast('Gagal memuat detail sesi: ' + e.message);
    }
}

function renderDetailsTable(rows) {
    const tbody = $('#report-details-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 32px; color: var(--text-muted);">Tidak ada rekaman sesi untuk periode dan filter ini.</td></tr>';
        return;
    }

    const formatRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

    let html = '';
    rows.forEach((r) => {
        const emp = r.employee;
        const att = r.attendance;
        const sh = r.shift;
        const al = r.allowance;

        const lateStr = att.is_late
            ? `<span style="color: var(--warning, #b45309); font-weight: 600;">${att.late_minutes} m</span>`
            : '-';

        html += `<tr>
            <td data-label="Tanggal" style="white-space: nowrap;"><code>${esc(r.work_date)}</code></td>
            <td data-label="Karyawan">
                <div style="font-weight: 600;">${esc(emp.name)}</div>
                <div style="font-size: 12px; color: var(--text-muted);"><code>${esc(emp.code)}</code> &bull; ${esc(emp.department)}</div>
            </td>
            <td data-label="Shift / Sesi">${esc(sh.name)} Sesi ${sh.seq}</td>
            <td data-label="Jadwal Shift" style="text-align: center; white-space: nowrap;">${esc(sh.schedule_clock)}</td>
            <td data-label="Jam Masuk" style="text-align: center; white-space: nowrap;">${esc(att.check_in_clock)}</td>
            <td data-label="Jam Pulang" style="text-align: center; white-space: nowrap;">${esc(att.check_out_clock)}</td>
            <td data-label="Status" style="text-align: center;">
                <span style="font-size: 12px; font-weight: 600;">${esc(att.state_label)}</span>
            </td>
            <td data-label="Terlambat" style="text-align: center;">${lateStr}</td>
            <td data-label="Jam Valid" style="text-align: center;">${esc(att.verified_hours_formatted)}</td>
            <td data-label="Lembur" style="text-align: center;">${r.overtime_minutes > 0 ? `${r.overtime_minutes} m` : '-'}</td>
            <td data-label="Tunjangan" style="text-align: right;">${al.total_amount > 0 ? formatRp(al.total_amount) : '-'}</td>
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
    info.textContent = `Menampilkan ${meta.from || 0} - ${meta.to || 0} dari ${meta.total} rekaman (Halaman ${meta.current_page} dari ${meta.last_page})`;
    prevBtn.disabled = meta.current_page <= 1;
    nextBtn.disabled = meta.current_page >= meta.last_page;
}

async function loadOvertimeTab() {
    const tbody = $('#report-overtime-tbody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 24px; color: var(--text-muted);">Memuat rekap lembur...</td></tr>';
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
        renderOvertimeTable(res.data);
    } catch (e) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 24px; color: var(--danger, #dc2626);">${esc(e.message)}</td></tr>`;
        }
        toast('Gagal memuat rekap lembur: ' + e.message);
    }
}

function renderOvertimeTable(rows) {
    const tbody = $('#report-overtime-tbody');
    if (!tbody) return;

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 32px; color: var(--text-muted);">Tidak ada perintah lembur untuk periode dan filter ini.</td></tr>';
        return;
    }

    let html = '';
    rows.forEach((r) => {
        const statusMap = {
            APPROVED: '<span style="color: var(--success, #15803d); font-weight: 600;">Disetujui</span>',
            REJECTED: '<span style="color: var(--danger, #dc2626); font-weight: 600;">Ditolak</span>',
            CANCELLED: '<span style="color: var(--muted); font-weight: 600;">Dibatalkan</span>',
            PENDING_APPROVAL: '<span style="color: var(--warning, #b45309); font-weight: 600;">Menunggu Persetujuan</span>',
        };

        html += `<tr>
            <td data-label="No. SPL"><code>${esc(r.order_number)}</code></td>
            <td data-label="Tanggal"><code>${esc(r.work_date)}</code></td>
            <td data-label="Karyawan">
                <div style="font-weight: 600;">${esc(r.employee_name)}</div>
            </td>
            <td data-label="Departemen">${esc(r.department_name || '-')}</td>
            <td data-label="Uraian Tugas Lembur">${esc(r.title)}</td>
            <td data-label="Jam Pelaksanaan" style="text-align: center;">${esc(r.local_start || '-')} - ${esc(r.local_end || '-')}</td>
            <td data-label="Durasi Rencana" style="text-align: center;">${r.planned_minutes || 0} m</td>
            <td data-label="Durasi Diakui" style="text-align: center; font-weight: 600;">${r.verified_minutes || 0} m</td>
            <td data-label="Status" style="text-align: center;">${statusMap[r.status] || esc(r.status)}</td>
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
        const type = activeTab === 'details' ? 'detail' : 'summary';
        params.set('type', type);
        window.location.assign(`/admin/api/reports/export/pdf?${params.toString()}`);
    } else {
        window.location.assign(`/admin/api/reports/export/excel?${params.toString()}`);
    }
}
