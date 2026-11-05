import { api, isSuperAdmin, lookup } from './api';
import { $, $$, confirmDialog, esc, errorInto, icon, loadingInto, openForm, renderPagination, toast } from './ui';

const table = $('#leave-table');
const leavePagination = $('#leave-pagination');
let leavePage = 1;
const leavePerPage = 20;
let leaveMeta = null;
const balanceTable = $('#leave-balance-table');
const settingsForm = $('#leave-settings-form');
const quotaFields = $('#leave-quota-fields');
const requestSearch = $('#leave-request-search');
const statusFilter = $('#leave-status-filter');
const balanceSearch = $('#leave-balance-search');
const balanceDeptFilter = $('#leave-balance-dept-filter');
const leaveTabs = $$('[data-leave-tab]');
const directLeaveButton = $('#leave-direct-add');

if (directLeaveButton && !isSuperAdmin) directLeaveButton.hidden = true;

let yearSettings = null;
let allRequests = [];
let allBalances = [];

const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const typeName = (item) => item.leave_type?.name ?? item.type ?? 'Cuti';
const statusLabel = {
    PENDING: ['warning', 'Menunggu Persetujuan'],
    APPROVED: ['success', 'Disetujui'],
    REJECTED: ['neutral', 'Ditolak'],
    CANCELLED: ['neutral', 'Dibatalkan'],
};

function formatDateIndo(dateStr) {
    if (!dateStr) return '-';
    try {
        const [y, m, d] = dateStr.split('-');
        const date = new Date(Number(y), Number(m) - 1, Number(d));
        return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
    } catch (_) {
        return dateStr;
    }
}

function formatDateRange(startDate, endDate, daysCount) {
    const startFormatted = formatDateIndo(startDate);
    if (!endDate || endDate === startDate) {
        return `<b>${esc(startFormatted)}</b><small style="color:var(--muted);">${daysCount ?? 1} hari</small>`;
    }
    const endFormatted = formatDateIndo(endDate);
    return `<b>${esc(startFormatted)}</b> <span style="color:var(--muted);">s.d.</span> <b>${esc(endFormatted)}</b><small style="color:var(--muted);">${daysCount ?? 1} hari</small>`;
}

async function initBalanceDepartments() {
    if (!balanceDeptFilter || balanceDeptFilter.options.length > 1) return;
    try {
        const depts = await lookup('departments');
        (depts ?? []).forEach((d) => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            balanceDeptFilter.appendChild(opt);
        });
    } catch (_) {}
}

async function loadSettings() {
    try {
        const requestedYear = settingsForm.elements.year.value || todayKey().slice(0, 4);
        const { data } = await api('leave-settings', { query: { year: requestedYear } });
        yearSettings = data;
        settingsForm.elements.year.value = data.year;
        renderQuotaFields(data.quotas ?? []);

        const isOpened = Boolean(data.opening_confirmed_at);
        const openingDate = data.opening_confirmed_at ? new Date(data.opening_confirmed_at).toLocaleDateString('id-ID') : '';

        $('#leave-opening-state').textContent = isOpened
            ? `Saldo ${data.year} telah dibuka pada ${openingDate}. Karyawan dapat mengajukan cuti.`
            : `Saldo ${data.year} belum dibuka. Karyawan belum dapat mengajukan cuti sampai saldo awal dikonfirmasi.`;

        $('#leave-opening-confirm').hidden = isOpened;

        // Update Top Metric
        const yearMetric = $('#leave-metric-year');
        const yearBadge = $('#leave-metric-year-badge');
        const yearDesc = $('#leave-metric-year-desc');

        if (yearMetric) yearMetric.textContent = `Tahun ${data.year}`;
        if (yearBadge) {
            yearBadge.textContent = isOpened ? 'DIBUKA' : 'BELUM DIBUKA';
            yearBadge.className = `metric-badge ${isOpened ? 'success' : 'warning'}`;
        }
        if (yearDesc) {
            yearDesc.textContent = isOpened ? `Dibuka ${openingDate}` : 'Perlu konfirmasi saldo awal';
        }
    } catch (error) {
        $('#leave-opening-state').textContent = error.message;
    }
}

function renderQuotaFields(quotas) {
    if (!quotaFields) return;
    quotaFields.replaceChildren(...quotas.map((type) => {
        const label = document.createElement('label');
        const title = document.createElement('span');
        const input = document.createElement('input');
        const hint = document.createElement('small');
        title.textContent = type.name;
        input.type = 'number';
        input.min = '0';
        input.max = '60';
        input.required = true;
        input.value = type.quota;
        input.dataset.leaveQuota = type.type;
        hint.textContent = type.is_active ? `Default baru: ${type.default_quota} hari/tahun` : 'Jenis nonaktif; saldo lama tetap dihitung.';
        label.append(title, input, hint);
        return label;
    }));
}

async function loadRequests(page = leavePage) {
    leavePage = page;
    loadingInto(table);
    try {
        const res = await api('leaves', {
            query: {
                status: statusFilter?.value || '',
                page: leavePage,
                per_page: leavePerPage,
            },
        });
        allRequests = res.data ?? [];
        leaveMeta = res.meta ?? null;

        // Update top metrics & pending badge
        const pendingCount = allRequests.filter((r) => r.status === 'PENDING').length;
        const approvedCount = allRequests.filter((r) => r.status === 'APPROVED').length;

        const pendingMetric = $('#leave-metric-pending');
        if (pendingMetric) pendingMetric.textContent = pendingCount;

        const approvedMetric = $('#leave-metric-approved');
        if (approvedMetric) approvedMetric.textContent = approvedCount;

        const tabBadge = $('#leave-pending-tab-badge');
        if (tabBadge) {
            if (pendingCount > 0) {
                tabBadge.textContent = pendingCount;
                tabBadge.style.display = 'inline-block';
            } else {
                tabBadge.style.display = 'none';
            }
        }

        renderRequestsTable();
    } catch (error) {
        errorInto(table, error);
        if (leavePagination) leavePagination.innerHTML = '';
    }
}

function filterRequests() {
    const q = (requestSearch?.value ?? '').trim().toLowerCase();
    const st = statusFilter?.value ?? '';

    return allRequests.filter((item) => {
        if (st && item.status !== st) return false;
        if (q) {
            const name = (item.employee?.name ?? '').toLowerCase();
            const code = (item.employee?.code ?? '').toLowerCase();
            const dept = (item.employee?.department?.name ?? '').toLowerCase();
            const reason = (item.reason ?? '').toLowerCase();
            if (!name.includes(q) && !code.includes(q) && !dept.includes(q) && !reason.includes(q)) return false;
        }
        return true;
    });
}

function renderRequestsTable() {
    const rows = filterRequests();
    const countEl = $('#leave-list-count');
    if (countEl) {
        countEl.textContent = rows.length === allRequests.length
            ? `${rows.length} pengajuan ditemukan`
            : `Menampilkan ${rows.length} dari ${allRequests.length} pengajuan`;
    }

    if (!rows.length) {
        const emptyMsg = allRequests.length
            ? 'Tidak ada pengajuan cuti yang cocok dengan pencarian / filter ini.'
            : 'Belum ada pengajuan cuti pada status ini.';
        table.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: var(--muted);">
                <div style="display: flex; justify-content: center; margin-bottom: 10px; color: var(--muted);">${icon('file-text', 36)}</div>
                <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">${esc(emptyMsg)}</p>
                <small style="display: block; margin-top: 4px;">Ubah filter status atau kata kunci pencarian.</small>
            </div>
        `;
        return;
    }

    const bodyHtml = rows.map((item) => {
        const [tone, label] = statusLabel[item.status] ?? ['neutral', item.status];
        const actions = item.status === 'PENDING' && isSuperAdmin
            ? `
                <button type="button" class="action-btn success" data-action="approve" data-id="${item.id}" title="Setujui permohonan cuti" aria-label="Setujui permohonan cuti ${esc(item.employee?.name ?? '')}">${icon('check')}</button>
                <button type="button" class="action-btn danger" data-action="reject" data-id="${item.id}" title="Tolak permohonan cuti" aria-label="Tolak permohonan cuti ${esc(item.employee?.name ?? '')}">${icon('x')}</button>
            `
                : item.status === 'APPROVED' && isSuperAdmin
                ? `<button type="button" class="action-btn danger" data-action="cancel" data-id="${item.id}" title="Batalkan cuti &amp; kembalikan kuota" aria-label="Batalkan cuti dan kembalikan kuota ${esc(item.employee?.name ?? '')}">${icon('undo')}</button>`
                : item.status === 'PENDING' ? '<small class="field-hint">Menunggu keputusan Super Admin</small>' : '';

        const dateHtml = formatDateRange(item.work_date, item.end_date, item.days_count);

        const initial = (item.employee?.name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
        const avatar = item.employee?.avatar_url
            ? `<img src="${esc(item.employee.avatar_url)}" alt="${esc(item.employee.name)}" class="avatar" style="width:34px;height:34px;border-radius:8px;object-fit:cover;margin-right:12px;flex-shrink:0;" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar',style:'width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;background:#e2ece4;color:var(--primary);',textContent:'${esc(initial)}'}))">`
            : `<span class="avatar" style="width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;background:#e2ece4;color:var(--primary);">${esc(initial)}</span>`;

        return `
            <tr>
                <td data-label="Tanggal">${dateHtml}</td>
                <td data-label="Karyawan">
                    <div style="display:flex;align-items:center;">
                        ${avatar}
                        <div>
                            <b>${esc(item.employee?.name ?? 'Karyawan')}</b>
                            <small style="color:var(--muted);">${esc(item.employee?.code ?? '-')} · ${esc(item.employee?.department?.name ?? 'Tanpa Departemen')}</small>
                        </div>
                    </div>
                </td>
                <td data-label="Jenis">
                    <span class="status neutral">${esc(typeName(item))}</span>
                </td>
                <td data-label="Alasan" style="max-width:240px;line-height:1.4;">
                    <span>${esc(item.reason)}</span>
                </td>
                <td data-label="Status">
                    <span class="status ${tone}">${label}</span>
                    ${item.decision_reason ? `<small style="display:block;margin-top:3px;color:var(--muted);">${esc(item.decision_reason)}</small>` : ''}
                </td>
                <td data-label="Aksi" class="row-actions">${actions || '<span style="color:var(--muted);font-size:12px;">—</span>'}</td>
            </tr>
        `;
    }).join('');

    table.innerHTML = `
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" style="min-width:140px;">Tanggal Cuti</th>
                        <th scope="col" style="min-width:220px;">Karyawan</th>
                        <th scope="col" style="min-width:120px;">Jenis</th>
                        <th scope="col" style="min-width:180px;">Alasan</th>
                        <th scope="col" style="min-width:140px;">Status</th>
                        <th scope="col" style="min-width:90px;text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    ${bodyHtml}
                </tbody>
            </table>
        </div>
    `;
    renderPagination(leavePagination, leaveMeta, (newPage) => {
        loadRequests(newPage);
    });
}

async function loadBalances() {
    loadingInto(balanceTable);
    try {
        await initBalanceDepartments();
        const { data } = await api('leave-balances', { query: { year: yearSettings?.year } });
        allBalances = data ?? [];

        const empMetric = $('#leave-metric-employees');
        if (empMetric) empMetric.textContent = allBalances.length;

        renderBalancesTable();
    } catch (error) {
        errorInto(balanceTable, error);
    }
}

function filterBalances() {
    const q = (balanceSearch?.value ?? '').trim().toLowerCase();
    const deptId = balanceDeptFilter?.value ?? '';

    return allBalances.filter((item) => {
        if (deptId && String(item.department_id) !== String(deptId)) return false;
        if (q) {
            const name = (item.name ?? '').toLowerCase();
            const code = (item.code ?? '').toLowerCase();
            const dept = (item.department ?? '').toLowerCase();
            if (!name.includes(q) && !code.includes(q) && !dept.includes(q)) return false;
        }
        return true;
    });
}

function renderBalancesTable() {
    const rows = filterBalances();
    const countEl = $('#leave-balance-count');
    if (countEl) {
        countEl.textContent = rows.length === allBalances.length
            ? `${rows.length} karyawan · Tahun ${yearSettings?.year ?? ''}`
            : `Menampilkan ${rows.length} dari ${allBalances.length} karyawan · Tahun ${yearSettings?.year ?? ''}`;
    }

    if (!rows.length) {
        const emptyMsg = allBalances.length
            ? 'Tidak ada data saldo karyawan yang cocok dengan pencarian.'
            : 'Belum ada data saldo karyawan aktif.';
        balanceTable.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: var(--muted);">
                <div style="display: flex; justify-content: center; margin-bottom: 10px; color: var(--muted);">${icon('users', 36)}</div>
                <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">${esc(emptyMsg)}</p>
                <small style="display: block; margin-top: 4px;">Ubah filter departemen atau kata kunci pencarian.</small>
            </div>
        `;
        return;
    }

    const bodyHtml = rows.map((item) => {
        const initial = (item.name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
        const avatar = item.avatar_url
            ? `<img src="${esc(item.avatar_url)}" alt="${esc(item.name)}" class="avatar" style="width:34px;height:34px;border-radius:8px;object-fit:cover;margin-right:12px;flex-shrink:0;" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar',style:'width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;background:#e2ece4;color:var(--primary);',textContent:'${esc(initial)}'}))">`
            : `<span class="avatar" style="width:34px;height:34px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;background:#e2ece4;color:var(--primary);">${esc(initial)}</span>`;

        const typeCells = (item.types ?? []).map((type) => {
            const tone = type.available > type.entitled / 2 ? 'high' : type.available > 0 ? 'medium' : 'empty';
            return `
                <td data-label="${esc(type.name)}">
                    <div class="quota-meter"><span class="quota-meter-badge ${tone}">${type.available} / ${type.entitled} hari</span></div>
                    <small style="display:block;margin-top:4px;color:var(--muted);">${type.used} dipakai · ${type.reserved} dipesan</small>
                </td>
            `;
        }).join('');

        return `
            <tr>
                <td data-label="Karyawan">
                    <div style="display:flex;align-items:center;">
                        ${avatar}
                        <div>
                            <b>${esc(item.name)}</b>
                            <small style="color:var(--muted);">${esc(item.code)} · ${esc(item.department ?? 'Tanpa Departemen')}</small>
                        </div>
                    </div>
                </td>
                ${typeCells}
                <td data-label="Aksi" class="row-actions">
                    <button type="button" class="action-btn" data-adjust-quota="${item.employee_id}" title="Sesuaikan kuota cuti" aria-label="Sesuaikan kuota cuti ${esc(item.name)}">
                        ${icon('adjust')}
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    const typeHeaders = (rows[0]?.types ?? []).map((type) => `<th scope="col" style="min-width:180px;">${esc(type.name)}</th>`).join('');
    balanceTable.innerHTML = `
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" style="min-width:220px;">Karyawan</th>
                        ${typeHeaders}
                        <th scope="col" style="min-width:80px;text-align:right;">Atur</th>
                    </tr>
                </thead>
                <tbody>
                    ${bodyHtml}
                </tbody>
            </table>
        </div>
    `;
}

function switchTab(tabKey) {
    leaveTabs.forEach((tab) => {
        const isMatch = tab.dataset.leaveTab === tabKey;
        tab.setAttribute('aria-selected', String(isMatch));
    });

    $$('.leave-tab-panel').forEach((panel) => {
        panel.hidden = panel.id !== `leave-tab-panel-${tabKey}`;
    });
}

async function adjustQuota(employeeId) {
    const { data } = await api('employees', { query: { status: 'active' } });
    const leaveTypeRows = yearSettings?.quotas ?? (await api('leave-types')).data.map((type) => ({ type: type.code, name: type.name, is_active: type.is_active }));
    const employee = data.find((item) => item.id === Number(employeeId));
    if (!employee) return;
    const year = yearSettings?.year ?? Number(todayKey().slice(0, 4));
    openForm({
        title: `Atur Kuota · ${employee.name}`,
        hint: 'Penyesuaian kuota tercatat terpisah dan dapat diaudit secara riil.',
        fields: [
            { name: 'type', label: 'Jenis kuota', type: 'select', required: true, options: leaveTypeRows.map((type) => ({ value: type.type, label: `${type.name}${type.is_active ? '' : ' (Nonaktif)'}` })) },
            { name: 'year', label: 'Tahun kuota', type: 'number', required: true, min: 2000, max: 2100 },
            { name: 'delta', label: 'Perubahan hari (+ / -)', type: 'number', required: true, min: -60, max: 60, hint: 'Gunakan angka positif untuk menambah, negatif untuk mengurangi.' },
            { name: 'reason', label: 'Alasan penyesuaian', required: true, maxlength: 1000 },
        ],
        values: { year, delta: 1 },
        onSubmit: async (body) => {
            await api(`leave-quota/${employee.id}/adjustments`, { method: 'POST', body: { ...body, year: Number(body.year), delta: Number(body.delta) } });
            toast('Kuota berhasil disesuaikan.');
            await loadBalances();
        },
    });
}

function openDirectLeave() {
    Promise.all([
        api('employees', { query: { status: 'active' } }),
        api('leave-types'),
    ]).then(([{ data: employees }, { data: leaveTypes }]) => openForm({
        title: 'Catat Cuti Karyawan',
        hint: 'Cuti langsung oleh admin. Absensi yang sudah tercatat pada hari tersebut tidak dapat diganti menjadi cuti.',
        fields: [
            { name: 'employee_id', label: 'Karyawan', type: 'select', numeric: true, required: true, blank: 'Pilih karyawan…', options: employees.map((item) => ({ value: item.id, label: `${item.name} (${item.code})` })) },
            { name: 'work_date', label: 'Tanggal mulai', type: 'date', required: true, default: todayKey() },
            { name: 'end_date', label: 'Tanggal akhir (opsional, jika > 1 hari)', type: 'date' },
            { name: 'type', label: 'Jenis cuti', type: 'select', required: true, options: leaveTypes.filter((type) => type.is_active).map((type) => ({ value: type.code, label: `${type.name} · ${type.default_quota} hari/tahun` })) },
            { name: 'reason', label: 'Alasan pencatatan', required: true, maxlength: 1000 },
        ],
        onSubmit: async (body) => {
            await api('leaves/direct', { method: 'POST', body });
            toast('Cuti berhasil dicatat dan kuota diperbarui.');
            await Promise.all([loadRequests(), loadBalances()]);
            return true;
        },
    })).catch((error) => toast(error.message, 'error'));
}

function decide(item, decision) {
    openForm({
        title: `${decision === 'APPROVED' ? 'Setujui' : 'Tolak'} Cuti · ${item.employee?.name ?? 'Karyawan'}`,
        hint: `${typeName(item)} · ${formatDateIndo(item.work_date)}. Cuti yang disetujui akan mengosongkan jadwal hari tersebut bila belum ada absensi.`,
        fields: [{ name: 'decision_reason', label: 'Catatan keputusan', required: true, maxlength: 1000 }],
        onSubmit: async (body) => {
            await api(`leaves/${item.id}/decision`, { method: 'POST', body: { ...body, decision } });
            toast(decision === 'APPROVED' ? 'Cuti disetujui.' : 'Pengajuan ditolak.');
            await Promise.all([loadRequests(), loadBalances()]);
        },
    });
}

function cancelApproved(item) {
    openForm({
        title: `Batalkan Cuti · ${item.employee?.name ?? 'Karyawan'}`,
        hint: 'Kuota akan dikembalikan ke saldo karyawan dan pembatalan tercatat di riwayat audit.',
        fields: [{ name: 'reason', label: 'Alasan pembatalan', required: true, maxlength: 1000 }],
        onSubmit: async (body) => {
            await api(`leaves/${item.id}/cancel-approved`, { method: 'POST', body });
            toast('Cuti dibatalkan dan kuota dikembalikan.');
            await Promise.all([loadRequests(), loadBalances()]);
        },
    });
}

// Sub-tab click listener
leaveTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        switchTab(tab.dataset.leaveTab);
    });
});

// Click card pending to jump to requests tab with PENDING filter
$('#leave-card-pending')?.addEventListener('click', () => {
    switchTab('requests');
    if (statusFilter) {
        statusFilter.value = 'PENDING';
        renderRequestsTable();
    }
});

let requestSearchDebounce;
requestSearch?.addEventListener('input', () => {
    clearTimeout(requestSearchDebounce);
    requestSearchDebounce = setTimeout(renderRequestsTable, 200);
});

statusFilter?.addEventListener('change', () => {
    loadRequests();
});

let balanceSearchDebounce;
balanceSearch?.addEventListener('input', () => {
    clearTimeout(balanceSearchDebounce);
    balanceSearchDebounce = setTimeout(renderBalancesTable, 200);
});

balanceDeptFilter?.addEventListener('change', renderBalancesTable);

settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = $('#leave-settings-error');
    error.hidden = true;
    try {
        await api('leave-settings', { method: 'PUT', body: {
            year: Number(settingsForm.elements.year.value),
            quotas: [...quotaFields.querySelectorAll('[data-leave-quota]')].map((input) => ({
                type: input.dataset.leaveQuota,
                quota: Number(input.value),
            })),
            reason: settingsForm.elements.reason.value.trim(),
        } });
        toast('Kuota tahunan disimpan.');
        settingsForm.elements.reason.value = '';
        await loadSettings();
        await loadBalances();
    } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
    }
});

$('#leave-opening-confirm').addEventListener('click', () => openForm({
    title: `Konfirmasi Saldo Cuti ${yearSettings?.year ?? ''}`,
    hint: 'Pastikan kuota dan aturan tahunan sudah sesuai sebelum mengaktifkan hak pengajuan cuti karyawan.',
    fields: [{ name: 'reason', label: 'Catatan pemeriksaan', required: true, maxlength: 1000 }],
    onSubmit: async (body) => {
        await api('leave-settings/confirm', { method: 'POST', body: { ...body, year: yearSettings?.year } });
        toast('Saldo cuti tahun berjalan dibuka.');
        await loadSettings();
        await loadBalances();
    },
}));

settingsForm.elements.year.addEventListener('change', async () => {
    await loadSettings();
    await loadBalances();
});

directLeaveButton?.addEventListener('click', openDirectLeave);

$('#leave-balance-table').addEventListener('click', (event) => {
    const button = event.target.closest('[data-adjust-quota]');
    if (button) adjustQuota(button.dataset.adjustQuota).catch((error) => toast(error.message, 'error'));
});

table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const item = button && allRequests.find((row) => String(row.id) === button.dataset.id);
    if (!item) return;
    if (button.dataset.action === 'approve') decide(item, 'APPROVED');
    if (button.dataset.action === 'reject') decide(item, 'REJECTED');
    if (button.dataset.action === 'cancel' && await confirmDialog({ title: 'Batalkan cuti yang disetujui?', message: `Kuota ${typeName(item).toLowerCase()} untuk ${item.work_date} akan dikembalikan.`, confirmLabel: 'Batalkan cuti', danger: true })) cancelApproved(item);
});

export async function showLeaves() {
    await loadSettings();
    await Promise.all([loadRequests(), loadBalances()]);
}
