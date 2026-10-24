import { api, invalidate, isAdmin, lookup } from './api';
import { $, confirmDialog, esc, errorInto, loadingInto, openForm, renderTable, toast } from './ui';

const table = $('#employee-table');
const filters = { q: $('#employee-q'), department_id: $('#employee-department'), status: $('#employee-status') };
let rows = [];
let debounce;

const opts = (list, label = (x) => x.name) => list.map((x) => ({ value: x.id, label: label(x) }));

async function fields(withAccount) {
    const [departments, positions, locations] = await Promise.all([lookup('departments'), lookup('positions'), lookup('locations')]);
    const list = [
        { name: 'code', label: 'Kode karyawan', required: true, maxlength: 32, hint: 'Huruf, angka, titik, strip. Contoh: FO-001' },
        { name: 'name', label: 'Nama lengkap', required: true, maxlength: 120 },
        { name: 'department_id', label: 'Departemen', type: 'select', numeric: true, required: true, blank: 'Pilih departemen', options: opts(departments) },
        { name: 'position_id', label: 'Jabatan', type: 'select', numeric: true, required: true, blank: 'Pilih jabatan', options: opts(positions) },
        { name: 'work_location_id', label: 'Lokasi kerja', type: 'select', numeric: true, blank: 'Tidak diatur', options: opts(locations) },
        { name: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Nonaktif' }], default: 'active' },
        { name: 'started_on', label: 'Mulai kerja', type: 'date', required: true },
        { name: 'ended_on', label: 'Berhenti kerja', type: 'date' },
        { name: 'phone', label: 'No. HP', type: 'tel', maxlength: 32, autocomplete: 'off' },
        { name: 'email', label: 'Email pribadi', type: 'email', maxlength: 255 },
    ];
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
            { label: 'Akun app', cell: (e) => e.user ? `${esc(e.user.email)}<small>${e.devices_count} perangkat</small>` : '<span class="status neutral">Belum ada</span>' },
            { label: 'Status', cell: (e) => e.status === 'active' ? '<span class="status success">Aktif</span>' : '<span class="status neutral">Nonaktif</span>' },
        ],
        actions: isAdmin ? (e) => `<button type="button" class="text-button" data-action="edit" data-id="${e.id}">Ubah</button>
            <button type="button" class="text-button" data-action="account" data-id="${e.id}">${e.user ? 'Ganti password' : 'Buat akun'}</button>
            ${e.user && e.devices_count ? `<button type="button" class="text-button" data-action="revoke" data-id="${e.id}">Keluarkan perangkat</button>` : ''}
            ${e.status === 'active' ? `<button type="button" class="text-button danger-text" data-action="deactivate" data-id="${e.id}">Nonaktifkan</button>` : ''}` : null,
    });
}

async function openEmployee(employee) {
    openForm({
        title: employee ? `Ubah ${employee.name}` : 'Tambah karyawan',
        hint: employee ? 'Pindah departemen tercatat di riwayat penempatan.' : 'Akun app bisa dibuat sekarang atau nanti.',
        fields: await fields(!employee),
        values: employee ?? {},
        onSubmit: async (values) => {
            const { make_account: _, ...body } = values;
            await api(employee ? `employees/${employee.id}` : 'employees', { method: employee ? 'PUT' : 'POST', body });
            toast(employee ? 'Data karyawan disimpan.' : 'Karyawan ditambahkan.');
            load();
        },
    });
}

function openAccount(employee) {
    openForm({
        title: employee.user ? `Ganti password ${employee.name}` : `Buat akun ${employee.name}`,
        hint: employee.user ? 'Semua perangkat karyawan ini akan keluar.' : 'Karyawan login di app pakai email ini.',
        submitLabel: employee.user ? 'Ganti password' : 'Buat akun',
        fields: [
            { name: 'email', label: 'Email login app', type: 'email', required: !employee.user },
            { name: 'password', label: 'Password baru', type: 'password', required: true, autocomplete: 'new-password', hint: 'Minimal 8 karakter.' },
        ],
        values: { email: employee.user?.email },
        onSubmit: async (body) => {
            await api(`employees/${employee.id}/account`, { method: 'POST', body });
            toast('Akun disimpan.');
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
    if (action === 'account') openAccount(employee);
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

export const refreshEmployeeLookups = () => ['departments', 'positions', 'locations'].forEach(invalidate);
