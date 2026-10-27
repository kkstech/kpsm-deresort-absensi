import { api, invalidate, isAdmin, lookup } from './api';
import { $, confirmDialog, esc, errorInto, icon, loadingInto, openForm, renderTable, toast } from './ui';

const table = $('#employee-table');
const filters = { q: $('#employee-q'), department_id: $('#employee-department'), status: $('#employee-status') };
let rows = [];
let debounce;

const opts = (list, label = (x) => x.name) => list.map((x) => ({ value: x.id, label: label(x) }));

async function fields(withAccount, employee) {
    const [departments, positions, locations] = await Promise.all([lookup('departments'), lookup('positions'), lookup('locations')]);
    const list = [
        { name: 'code', label: 'Kode karyawan', required: true, maxlength: 32, hint: 'Huruf, angka, titik, strip. Contoh: FO-001' },
        { name: 'name', label: 'Nama lengkap', required: true, maxlength: 120 },
        { name: 'department_id', label: 'Departemen', type: 'select', numeric: true, required: true, blank: 'Pilih departemen', options: opts(departments) },
        { name: 'position_id', label: 'Jabatan', type: 'select', numeric: true, required: true, blank: 'Pilih jabatan', options: opts(positions) },
        { name: 'work_location_id', label: 'Lokasi kerja', type: 'select', numeric: true, blank: 'Tidak diatur', options: opts(locations) },
        { name: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Nonaktif' }], default: 'active' },
        { name: 'phone', label: 'No. HP', type: 'tel', maxlength: 32, autocomplete: 'off' },
        { name: 'email', label: 'Email', type: 'email', maxlength: 255, hint: employee?.user ? `Mengikuti akun login: ${employee.user.email}` : 'Email pribadi karyawan.' },
    ];
    if (!employee) list.splice(5, 0, { name: 'started_on', label: 'Mulai kerja', type: 'date', required: true });
    if (withAccount) {
        list.push(
            { name: 'make_account', label: 'Buat akun app sekarang', type: 'checkbox' },
            { name: 'account_email', label: 'Email login app', type: 'email', showIf: (v) => v.make_account },
            { name: 'account_password', label: 'Password awal', type: 'password', autocomplete: 'new-password', hint: 'Minimal 8 karakter. Berikan langsung ke karyawan.', showIf: (v) => v.make_account },
        );
    }
    return list;
}

async function load() {
    loadingInto(table);
    try {
        rows = (await api('employees', { query: { q: filters.q.value.trim(), department_id: filters.department_id.value, status: filters.status.value } })).data;
        render();
    } catch (error) {
        errorInto(table, error);
    }
}

function render() {
    renderTable(table, {
        rows,
        empty: 'Belum ada karyawan yang cocok dengan filter.',
        columns: [
            { label: 'Karyawan', cell: (e) => `<b>${esc(e.name)}</b><small>${esc(e.code)}</small>` },
            { label: 'Departemen', cell: (e) => `${esc(e.department?.name)}<small>${esc(e.position?.name)}</small>` },
            { label: 'Level', cell: (e) => e.level ? `${esc(e.level.name)}<small>${esc(e.level.code)}</small>` : '<span class="status warning">Belum diatur</span>' },
            { label: 'Status kerja', cell: (e) => {
                const type = e.employment?.type;
                const label = type === 'contract' ? `Kontrak${e.employment.ends_on ? ` sampai ${e.employment.ends_on}` : ''}` : type === 'permanent' ? 'Tetap' : 'Perlu dilengkapi';
                const tone = ['overdue', 'urgent'].includes(e.employment?.alert) ? 'warning' : 'neutral';
                return `<span class="status ${tone}">${esc(label)}</span>`;
            } },
            { label: 'Akun app', cell: (e) => {
                if (!e.user) return '<span class="status neutral">Belum ada</span>';
                const roleBadge = ['super_admin', 'admin', 'supervisor'].includes(e.user.role)
                    ? ` <span class="status blue" style="font-size: 10px; padding: 1px 5px; margin-left: 4px;">${esc(e.user.role)}</span>`
                    : '';
                return `<b>${esc(e.user.email)}</b>${roleBadge}<small>${e.devices_count} perangkat</small>`;
            } },
            { label: 'Status', cell: (e) => e.status === 'active' ? '<span class="status success">Aktif</span>' : '<span class="status neutral">Nonaktif</span>' },
        ],
        actions: isAdmin ? (e) => `
            <button type="button" class="action-btn" data-action="edit" data-id="${e.id}" title="Ubah data karyawan" aria-label="Ubah data ${esc(e.name)}">${icon('edit')}</button>
            <button type="button" class="action-btn" data-action="employment" data-id="${e.id}" title="Status kerja &amp; kontrak" aria-label="Status kerja ${esc(e.name)}">${icon('contract')}</button>
            <button type="button" class="action-btn" data-action="level" data-id="${e.id}" title="Ubah level karyawan" aria-label="Ubah level ${esc(e.name)}">${icon('award')}</button>
            <button type="button" class="action-btn" data-action="account" data-id="${e.id}" title="${e.user ? 'Ganti password / kelola akun' : 'Hubungkan / buat akun app'}" aria-label="${e.user ? 'Ganti password' : 'Buat akun'} ${esc(e.name)}">${icon(e.user ? 'key' : 'user-plus')}</button>
            ${e.user ? `<button type="button" class="action-btn" data-action="unlink" data-id="${e.id}" title="Putuskan tautan akun app" aria-label="Putuskan tautan ${esc(e.name)}">${icon('link-slash')}</button>` : ''}
            ${e.user && e.devices_count ? `<button type="button" class="action-btn" data-action="revoke" data-id="${e.id}" title="Keluarkan semua perangkat" aria-label="Keluarkan perangkat ${esc(e.name)}">${icon('device-logout')}</button>` : ''}
            ${e.status === 'active' ? `<button type="button" class="action-btn danger" data-action="deactivate" data-id="${e.id}" title="Nonaktifkan karyawan" aria-label="Nonaktifkan ${esc(e.name)}">${icon('user-x')}</button>` : ''}` : null,
    });
}

async function openEmployee(employee) {
    openForm({
        title: employee ? `Ubah ${employee.name}` : 'Tambah karyawan',
        hint: employee ? 'Pindah departemen tercatat di riwayat penempatan.' : 'Akun app bisa dibuat sekarang atau nanti.',
        fields: await fields(!employee, employee),
        values: employee ? { ...employee, email: employee.user?.email || employee.email } : {},
        onSubmit: async (values) => {
            const { make_account: _, ...body } = values;
            await api(employee ? `employees/${employee.id}` : 'employees', { method: employee ? 'PUT' : 'POST', body });
            toast(employee ? 'Data karyawan disimpan.' : 'Karyawan ditambahkan.');
            load();
        },
    });
}

async function openEmployment(employee) {
    const current = employee.employment ?? {};
    openForm({
        title: `Status kerja · ${employee.name}`,
        hint: 'Riwayat tidak menghapus tanggal atau akun lama. Kontrak yang berakhir memberi pengingat dan tidak menonaktifkan akun.',
        fields: [
            { name: 'type', label: 'Jenis karyawan', type: 'select', required: true, options: [{ value: 'permanent', label: 'Tetap' }, { value: 'contract', label: 'Kontrak' }], default: current.type === 'contract' ? 'contract' : 'permanent' },
            { name: 'starts_on', label: 'Berlaku mulai', type: 'date', required: true },
            { name: 'ends_on', label: 'Kontrak berakhir', type: 'date', required: true, showIf: (value) => value.type === 'contract' },
            { name: 'reason', label: 'Alasan perubahan', required: true, maxlength: 1000 },
        ],
        values: { starts_on: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date()), ends_on: current.ends_on ?? '' },
        onSubmit: async (body) => {
            await api(`employees/${employee.id}/employment`, { method: 'POST', body });
            toast('Status kerja disimpan.');
            load();
        },
    });
}

async function openLevel(employee) {
    const levels = await lookup('employee-levels');
    const history = (await api(`employees/${employee.id}/levels`)).data;
    openForm({
        title: `Level · ${employee.name}`,
        hint: 'Level dan tanggal berlakunya disimpan ke riwayat karyawan.',
        fields: [
            { name: 'level_id', label: 'Level tunjangan', type: 'select', numeric: true, required: true, blank: 'Pilih level', options: opts(levels, (x) => `${x.name} (${x.code})`) },
            { name: 'effective_from', label: 'Berlaku mulai', type: 'date', required: true },
            { name: 'reason', label: 'Alasan perubahan', required: true, maxlength: 1000 },
        ],
        values: { level_id: employee.level?.id ?? history.at(-1)?.level_id, effective_from: new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date()) },
        onSubmit: async (body) => {
            await api(`employee-levels/${employee.id}/assign`, { method: 'POST', body });
            invalidate('employees');
            toast('Level tunjangan disimpan.');
            load();
        },
    });
}

async function openAccount(employee) {
    if (employee.user) {
        openForm({
            title: `Ganti password · ${employee.name}`,
            hint: 'Semua perangkat karyawan ini akan keluar dan harus login ulang.',
            submitLabel: 'Ganti password',
            fields: [
                { name: 'email', label: 'Email login app', type: 'email', required: true },
                { name: 'password', label: 'Password baru', type: 'password', required: true, autocomplete: 'new-password', hint: 'Minimal 8 karakter.' },
            ],
            values: { email: employee.user.email },
            onSubmit: async (body) => {
                await api(`employees/${employee.id}/account`, { method: 'POST', body });
                toast('Password akun berhasil diperbarui.');
                load();
            },
        });
        return;
    }

    const unlinkedUsers = (await api('unlinked-users')).data;
    const hasUnlinked = unlinkedUsers.length > 0;

    openForm({
        title: `Hubungkan Akun App · ${employee.name}`,
        hint: hasUnlinked
            ? 'Anda bisa menautkan akun user/admin yang sudah ada, atau membuatkan akun baru.'
            : 'Karyawan login di mobile app menggunakan akun ini.',
        submitLabel: 'Simpan Akun',
        fields: hasUnlinked ? [
            {
                name: 'mode',
                label: 'Metode akun',
                type: 'select',
                required: true,
                options: [
                    { value: 'link', label: 'Tautkan dengan user yang sudah ada (Admin / Supervisor / User)' },
                    { value: 'create', label: 'Buat akun login baru' },
                ],
                default: 'link',
            },
            {
                name: 'user_id',
                label: 'Pilih akun user',
                type: 'select',
                numeric: true,
                blank: 'Pilih akun user…',
                options: opts(unlinkedUsers, (u) => `${u.name} (${u.email} - ${u.role})`),
                showIf: (v) => v.mode === 'link',
            },
            {
                name: 'email',
                label: 'Email login app',
                type: 'email',
                showIf: (v) => v.mode === 'create',
            },
            {
                name: 'password',
                label: 'Password awal',
                type: 'password',
                autocomplete: 'new-password',
                hint: 'Minimal 8 karakter. Berikan ke karyawan.',
                showIf: (v) => v.mode === 'create',
            },
        ] : [
            { name: 'email', label: 'Email login app', type: 'email', required: true },
            { name: 'password', label: 'Password awal', type: 'password', required: true, autocomplete: 'new-password', hint: 'Minimal 8 karakter. Berikan ke karyawan.' },
        ],
        values: { email: employee.email ?? '' },
        onSubmit: async (body) => {
            if (hasUnlinked && body.mode === 'link') {
                if (!body.user_id) throw new Error('Pilih akun user yang ingin ditautkan.');
                await api(`employees/${employee.id}/link-user`, { method: 'POST', body: { user_id: body.user_id } });
                toast('Akun user berhasil ditautkan ke karyawan.');
            } else {
                await api(`employees/${employee.id}/account`, { method: 'POST', body: { email: body.email, password: body.password } });
                toast('Akun baru berhasil dibuat.');
            }
            load();
        },
    });
}

async function act(path, message) {
    try {
        await api(path, { method: 'POST' });
        toast(message);
        load();
    } catch (error) {
        toast(error.message);
    }
}

table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const employee = button && rows.find((e) => String(e.id) === button.dataset.id);
    if (!employee) return;
    const action = button.dataset.action;
    if (action === 'edit') openEmployee(employee);
    if (action === 'employment') openEmployment(employee);
    if (action === 'level') openLevel(employee);
    if (action === 'account') openAccount(employee);
    if (action === 'unlink' && await confirmDialog({ title: 'Putuskan tautan akun?', message: `Putuskan hubungan antara karyawan ${employee.name} dengan akun ${employee.user.email}? Karyawan tidak akan dapat login ke mobile app sampai ditautkan kembali.`, confirmLabel: 'Putuskan tautan', danger: true })) act(`employees/${employee.id}/unlink-user`, 'Tautan akun berhasil diputuskan.');
    if (action === 'revoke' && await confirmDialog({ title: 'Keluarkan perangkat?', message: `Keluarkan semua perangkat ${employee.name}? Karyawan harus login ulang.`, confirmLabel: 'Keluarkan', danger: true })) act(`employees/${employee.id}/revoke-devices`, 'Perangkat dikeluarkan.');
    if (action === 'deactivate' && await confirmDialog({ title: 'Nonaktifkan karyawan?', message: `Nonaktifkan ${employee.name}? Akun app ikut nonaktif.`, confirmLabel: 'Nonaktifkan', danger: true })) act(`employees/${employee.id}/deactivate`, 'Karyawan dinonaktifkan.');
});

$('#employee-add').addEventListener('click', () => openEmployee(null));
filters.q.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(load, 250); });
filters.department_id.addEventListener('change', load);
filters.status.addEventListener('change', load);

let started = false;
export async function showEmployees() {
    if (!started) {
        started = true;
        try {
            const departments = await lookup('departments');
            filters.department_id.insertAdjacentHTML('beforeend', departments.map((d) => `<option value="${d.id}">${esc(d.name)}</option>`).join(''));
        } catch (error) {
            toast(error.message);
        }
    }
    load();
}

export const refreshEmployeeLookups = () => ['departments', 'positions', 'locations', 'employee-levels'].forEach(invalidate);
