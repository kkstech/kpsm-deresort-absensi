import { api, lookup } from './api';
import { $, confirmDialog, esc, errorInto, icon, loadingInto, openForm, toast } from './ui';

const table = $('#leave-table');
const settingsForm = $('#leave-settings-form');
let yearSettings = null;
let leaveRows = [];
const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const typeLabel = { regular: 'Cuti reguler', day_off: 'Day off' };
const statusLabel = { PENDING: ['warning', 'Menunggu'], APPROVED: ['success', 'Disetujui'], REJECTED: ['neutral', 'Ditolak'], CANCELLED: ['neutral', 'Dibatalkan'] };

async function loadSettings() {
    try {
        const requestedYear = settingsForm.elements.year.value || todayKey().slice(0, 4);
        const { data } = await api('leave-settings', { query: { year: requestedYear } });
        yearSettings = data;
        settingsForm.elements.year.value = data.year;
        settingsForm.elements.regular_quota.value = data.regular_quota;
        settingsForm.elements.day_off_quota.value = data.day_off_quota;
        $('#leave-opening-state').textContent = data.opening_confirmed_at
            ? `Saldo ${data.year} dibuka ${new Date(data.opening_confirmed_at).toLocaleDateString('id-ID')}.`
            : `Saldo ${data.year} belum dibuka. Karyawan belum dapat mengajukan cuti.`;
        $('#leave-opening-confirm').hidden = Boolean(data.opening_confirmed_at);
    } catch (error) {
        $('#leave-opening-state').textContent = error.message;
    }
}

async function loadRequests() {
    loadingInto(table);
    try {
        leaveRows = (await api('leaves', { query: { status: $('#leave-status-filter').value } })).data;
        render();
    } catch (error) {
        errorInto(table, error);
    }
}

async function loadBalances() {
    const target = $('#leave-balance-table');
    loadingInto(target);
    try {
        const { data } = await api('leave-balances', { query: { year: yearSettings?.year } });
        $('#leave-balance-count').textContent = `${data.length} karyawan · ${yearSettings?.year ?? ''}`;
        target.innerHTML = data.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Karyawan</th><th>Cuti reguler</th><th>Day off</th><th>Aksi</th></tr></thead><tbody>${data.map((item) => `<tr><td><b>${esc(item.name)}</b><small>${esc(item.code)} · ${esc(item.department ?? '')}</small></td><td>${item.regular.available} tersedia<small>${item.regular.used} dipakai · ${item.regular.reserved} dipesan dari ${item.regular.entitled}</small></td><td>${item.day_off.available} tersedia<small>${item.day_off.used} dipakai · ${item.day_off.reserved} dipesan dari ${item.day_off.entitled}</small></td><td data-label="Aksi" class="row-actions"><button type="button" class="action-btn" data-adjust-quota="${item.employee_id}" title="Atur kuota cuti" aria-label="Atur kuota cuti ${esc(item.name)}">${icon('adjust')}</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="list-empty">Belum ada karyawan aktif.</p>';
    } catch (error) {
        errorInto(target, error);
    }
}

async function adjustQuota(employeeId) {
    const { data } = await api('employees', { query: { status: 'active' } });
    const employee = data.find((item) => item.id === Number(employeeId));
    if (!employee) return;
    const year = yearSettings?.year ?? Number(todayKey().slice(0, 4));
    openForm({
        title: `Atur kuota · ${employee.name}`,
        hint: 'Koreksi kuota tersimpan sebagai penyesuaian terpisah dan tercatat di audit.',
        fields: [
            { name: 'type', label: 'Jenis kuota', type: 'select', required: true, options: [{ value: 'regular', label: 'Cuti reguler' }, { value: 'day_off', label: 'Day off' }] },
            { name: 'year', label: 'Tahun kuota', type: 'number', required: true, min: 2000, max: 2100 },
            { name: 'delta', label: 'Perubahan jumlah hari (+/-)', type: 'number', required: true, min: -60, max: 60 },
            { name: 'reason', label: 'Alasan penyesuaian', required: true, maxlength: 1000 },
        ],
        values: { year, delta: 1 },
        onSubmit: async (body) => {
            await api(`leave-quota/${employee.id}/adjustments`, { method: 'POST', body: { ...body, year: Number(body.year), delta: Number(body.delta) } });
            toast('Kuota disesuaikan.');
            await loadBalances();
        },
    });
}

function render() {
    $('#leave-list-count').textContent = `${leaveRows.length} pengajuan`;
    if (!leaveRows.length) {
        table.innerHTML = '<p class="list-empty">Belum ada pengajuan cuti pada filter ini.</p>';
        return;
    }
    table.innerHTML = `<div class="table-scroll"><table class="data-table"><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Jenis</th><th>Alasan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>${leaveRows.map((item) => {
        const [tone, label] = statusLabel[item.status] ?? ['neutral', item.status];
        const actions = item.status === 'PENDING'
            ? `<button type="button" class="action-btn success" data-action="approve" data-id="${item.id}" title="Setujui permohonan cuti" aria-label="Setujui permohonan cuti ${esc(item.employee?.name ?? '')}">${icon('check')}</button><button type="button" class="action-btn danger" data-action="reject" data-id="${item.id}" title="Tolak permohonan cuti" aria-label="Tolak permohonan cuti ${esc(item.employee?.name ?? '')}">${icon('x')}</button>`
            : item.status === 'APPROVED' ? `<button type="button" class="action-btn danger" data-action="cancel" data-id="${item.id}" title="Batalkan cuti &amp; kembalikan kuota" aria-label="Batalkan cuti dan kembalikan kuota ${esc(item.employee?.name ?? '')}">${icon('undo')}</button>` : '';
        const dateLabel = item.end_date && item.end_date !== item.work_date
            ? `${esc(item.work_date)} s.d. ${esc(item.end_date)}<small>${item.days_count ?? 1} hari</small>`
            : esc(item.work_date);
        return `<tr><td data-label="Tanggal">${dateLabel}</td><td data-label="Karyawan"><b>${esc(item.employee?.name ?? 'Karyawan')}</b><small>${esc(item.employee?.department?.name ?? '')}</small></td><td data-label="Jenis">${esc(typeLabel[item.type] ?? item.type)}</td><td data-label="Alasan">${esc(item.reason)}</td><td data-label="Status"><span class="status ${tone}">${label}</span>${item.decision_reason ? `<small>${esc(item.decision_reason)}</small>` : ''}</td><td data-label="Aksi" class="row-actions">${actions || '—'}</td></tr>`;
    }).join('')}</tbody></table></div>`;
}

function openDirectLeave() {
    api('employees', { query: { status: 'active' } }).then(({ data: employees }) => openForm({
        title: 'Catat cuti karyawan',
        hint: 'Bisa untuk tanggal berjalan atau lampau selama periode masih terbuka. Absensi yang sudah tercatat tidak dapat diubah menjadi cuti.',
        fields: [
            { name: 'employee_id', label: 'Karyawan', type: 'select', numeric: true, required: true, blank: 'Pilih karyawan', options: employees.map((item) => ({ value: item.id, label: `${item.name} (${item.code})` })) },
            { name: 'work_date', label: 'Tanggal mulai', type: 'date', required: true, default: todayKey() },
            { name: 'end_date', label: 'Tanggal akhir (opsional, jika > 1 hari)', type: 'date' },
            { name: 'type', label: 'Potong kuota', type: 'select', required: true, options: [{ value: 'regular', label: 'Cuti reguler' }, { value: 'day_off', label: 'Day off' }] },
            { name: 'reason', label: 'Alasan pencatatan', required: true, maxlength: 1000 },
        ],
        onSubmit: async (body) => {
            await api('leaves/direct', { method: 'POST', body });
            toast('Cuti dicatat dan kuota diperbarui.');
            await Promise.all([loadRequests(), loadBalances()]);
            return true;
        },
    })).catch((error) => toast(error.message));
}

function decide(item, decision) {
    openForm({
        title: `${decision === 'APPROVED' ? 'Setujui' : 'Tolak'} cuti · ${item.employee?.name ?? 'Karyawan'}`,
        hint: `${typeLabel[item.type]} · ${item.work_date}. Cuti yang disetujui akan mengosongkan jadwal hari itu bila belum ada absensi.`,
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
        title: `Batalkan cuti · ${item.employee?.name ?? 'Karyawan'}`,
        hint: 'Kuota dikembalikan satu kali. Pembatalan ini tercatat di riwayat.',
        fields: [{ name: 'reason', label: 'Alasan pembatalan', required: true, maxlength: 1000 }],
        onSubmit: async (body) => {
            await api(`leaves/${item.id}/cancel-approved`, { method: 'POST', body });
            toast('Cuti dibatalkan dan kuota dikembalikan.');
            await Promise.all([loadRequests(), loadBalances()]);
        },
    });
}

settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = $('#leave-settings-error');
    error.hidden = true;
    try {
        await api('leave-settings', { method: 'PUT', body: {
            year: Number(settingsForm.elements.year.value),
            regular_quota: Number(settingsForm.elements.regular_quota.value),
            day_off_quota: Number(settingsForm.elements.day_off_quota.value),
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
    title: `Konfirmasi saldo cuti ${yearSettings?.year ?? ''}`,
    hint: 'Pastikan kuota dan aturan prorata sudah diperiksa sebelum mengaktifkan pengajuan karyawan.',
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

$('#leave-direct-add').addEventListener('click', openDirectLeave);
$('#leave-status-filter').addEventListener('change', loadRequests);
$('#leave-balance-table').addEventListener('click', (event) => {
    const button = event.target.closest('[data-adjust-quota]');
    if (button) adjustQuota(button.dataset.adjustQuota).catch((error) => toast(error.message));
});
table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const item = button && leaveRows.find((row) => String(row.id) === button.dataset.id);
    if (!item) return;
    if (button.dataset.action === 'approve') decide(item, 'APPROVED');
    if (button.dataset.action === 'reject') decide(item, 'REJECTED');
    if (button.dataset.action === 'cancel' && await confirmDialog({ title: 'Batalkan cuti yang disetujui?', message: `Kuota ${typeLabel[item.type].toLowerCase()} untuk ${item.work_date} akan dikembalikan.`, confirmLabel: 'Batalkan cuti', danger: true })) cancelApproved(item);
});

export async function showLeaves() {
    await loadSettings();
    await Promise.all([loadRequests(), loadBalances()]);
}
