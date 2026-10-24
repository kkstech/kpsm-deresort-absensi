import { api, invalidate, isAdmin, lookup } from './api';
import { $, $$, confirmDialog, esc, errorInto, loadingInto, openForm, renderTable, toast } from './ui';

const table = $('#settings-table');
const siteForm = $('#site-settings-form');
const siteError = $('#site-settings-error');
const opts = (list) => list.map((x) => ({ value: x.id, label: x.code ? `${x.name} (${x.code})` : x.name }));
const hhmm = (t) => String(t).slice(0, 5).replace(':', '.');
const sessionsText = (t) => t.sessions.map((s) => `${hhmm(s.local_start)}–${hhmm(s.local_end)}${s.day_offset ? ' (+1)' : ''}`).join(', ');
const roleLabel = { super_admin: 'Super Admin', admin: 'Admin', supervisor: 'Supervisor' };
const scopeLabel = { global: 'Semua karyawan', department: 'Departemen', employee: 'Perorangan' };

const nameOnly = (singular) => ({
    columns: [
        { label: 'Nama', cell: (x) => `<b>${esc(x.name)}</b>` },
        { label: 'Karyawan', cell: (x) => esc(x.employees_count ?? 0) },
    ],
    fields: async () => [{ name: 'name', label: `Nama ${singular}`, required: true, maxlength: 100 }],
    deletable: true,
});

const tabs = {
    templates: {
        title: 'Template shift', hint: 'Jadwal baru menyalin jam dari template. Jadwal lama tidak berubah.', singular: 'template',
        columns: [
            { label: 'Nama', cell: (t) => `<b>${esc(t.name)}</b>` },
            { label: 'Jenis', cell: (t) => t.type === 'regular' ? '<span class="status success">Reguler</span>' : '<span class="status warning">Khusus</span>' },
            { label: 'Sesi', cell: (t) => esc(sessionsText(t)) },
        ],
        fields: async () => [
            { name: 'name', label: 'Nama template', required: true, maxlength: 100 },
            { name: 'type', label: 'Jenis', type: 'select', required: true, options: [{ value: 'regular', label: 'Reguler (1 sesi)' }, { value: 'special', label: 'Khusus (1 sampai 4 sesi)' }], default: 'regular' },
            { name: 'sessions', label: 'Sesi kerja', type: 'sessions', hint: 'Jam selesai lebih kecil dari jam mulai berarti lewat tengah malam.' },
        ],
        deletable: true,
    },
    policies: {
        title: 'Aturan kehadiran', hint: 'Aturan perorangan menang atas departemen. Departemen menang atas aturan umum.', singular: 'aturan',
        columns: [
            { label: 'Berlaku untuk', cell: (p) => `<b>${esc(scopeLabel[p.scope_type])}</b><small>${esc(p.department?.name ?? p.employee?.name ?? '')}</small>` },
            { label: 'Toleransi', cell: (p) => `${esc(p.grace_minutes)} menit` },
            { label: 'Cek lokasi', cell: (p) => p.use_location_check ? 'Ya' : 'Tidak' },
            { label: 'Bukti izin/koreksi', cell: (p) => `${p.late_permission_proof_required ? 'Izin wajib' : 'Izin opsional'} · ${p.correction_proof_required ? 'Koreksi wajib' : 'Koreksi opsional'}` },
            { label: 'Periode', cell: (p) => `${esc(p.effective_from)}<small>${p.effective_to ? `sampai ${esc(p.effective_to)}` : 'tanpa batas'}</small>` },
        ],
        fields: async () => {
            const [departments, employees] = await Promise.all([lookup('departments'), api('employees', { query: { status: 'active' } }).then((r) => r.data)]);
            return [
                { name: 'scope_type', label: 'Berlaku untuk', type: 'select', required: true, options: Object.entries(scopeLabel).map(([value, label]) => ({ value, label })), default: 'global' },
                { name: 'department_id', label: 'Departemen', type: 'select', numeric: true, blank: 'Pilih departemen', options: opts(departments), showIf: (v) => v.scope_type === 'department' },
                { name: 'employee_id', label: 'Karyawan', type: 'select', numeric: true, blank: 'Pilih karyawan', options: opts(employees), showIf: (v) => v.scope_type === 'employee' },
                { name: 'grace_minutes', label: 'Toleransi terlambat (menit)', type: 'number', min: 0, max: 120, required: true, default: 0, hint: 'Lewat toleransi, menit terlambat dihitung dari jam mulai.' },
                { name: 'use_location_check', label: 'Wajib cek lokasi saat absen', type: 'checkbox' },
                { name: 'late_permission_proof_required', label: 'Bukti wajib untuk izin terlambat', type: 'checkbox' },
                { name: 'correction_proof_required', label: 'Bukti wajib untuk koreksi absensi', type: 'checkbox' },
                { name: 'effective_from', label: 'Mulai berlaku', type: 'date', required: true },
                { name: 'effective_to', label: 'Berakhir', type: 'date', hint: 'Kosongkan bila tanpa batas.' },
            ];
        },
        deletable: true,
    },
    departments: { title: 'Departemen', hint: 'Departemen yang masih punya karyawan tidak bisa dihapus.', singular: 'departemen', ...nameOnly('departemen') },
    positions: { title: 'Jabatan', hint: 'Jabatan yang masih dipakai tidak bisa dihapus.', singular: 'jabatan', ...nameOnly('jabatan') },
    locations: {
        title: 'Lokasi kerja', hint: 'Mode radius menolak absen di luar jarak yang diatur.', singular: 'lokasi',
        columns: [
            { label: 'Nama', cell: (l) => `<b>${esc(l.name)}</b>` },
            { label: 'Mode', cell: (l) => l.mode === 'radius' ? `Radius ${esc(l.radius_meters)} m` : 'Tanpa cek lokasi' },
            { label: 'Titik', cell: (l) => l.latitude ? `${esc(l.latitude)}, ${esc(l.longitude)}` : '-' },
        ],
        fields: async () => [
            { name: 'name', label: 'Nama lokasi', required: true, maxlength: 100 },
            { name: 'mode', label: 'Mode', type: 'select', required: true, options: [{ value: 'none', label: 'Tanpa cek lokasi' }, { value: 'radius', label: 'Radius dari titik' }], default: 'none' },
            { name: 'latitude', label: 'Latitude', type: 'number', step: 'any', showIf: (v) => v.mode === 'radius' },
            { name: 'longitude', label: 'Longitude', type: 'number', step: 'any', showIf: (v) => v.mode === 'radius' },
            { name: 'radius_meters', label: 'Radius (meter)', type: 'number', min: 10, max: 5000, showIf: (v) => v.mode === 'radius' },
            { name: 'max_accuracy_meters', label: 'Akurasi GPS terburuk yang diterima (meter)', type: 'number', min: 5, max: 1000, showIf: (v) => v.mode === 'radius' },
        ],
        deletable: true,
    },
    users: {
        title: 'Akun admin', hint: 'Akun untuk masuk ke admin web.', singular: 'akun',
        columns: [
            { label: 'Nama', cell: (u) => `<b>${esc(u.name)}</b><small>${esc(u.email)}</small>` },
            { label: 'Peran', cell: (u) => `${esc(roleLabel[u.role])}<small>${esc(u.can_view_all ? 'Semua departemen' : u.department?.name ?? '')}</small>` },
            { label: 'Status', cell: (u) => u.is_active ? '<span class="status success">Aktif</span>' : '<span class="status neutral">Nonaktif</span>' },
        ],
        fields: async (item) => [
            { name: 'name', label: 'Nama', required: true, maxlength: 120 },
            { name: 'email', label: 'Email login', type: 'email', required: true },
            { name: 'password', label: item ? 'Password baru' : 'Password', type: 'password', required: !item, autocomplete: 'new-password', hint: item ? 'Kosongkan bila tidak diganti.' : 'Minimal 8 karakter.' },
            { name: 'role', label: 'Peran', type: 'select', required: true, options: Object.entries(roleLabel).map(([value, label]) => ({ value, label })), default: 'supervisor' },
            { name: 'department_id', label: 'Departemen', type: 'select', numeric: true, blank: 'Tidak terikat departemen', options: opts(await lookup('departments')) },
            { name: 'can_view_all', label: 'Boleh lihat semua departemen', type: 'checkbox' },
            { name: 'is_active', label: 'Akun aktif', type: 'checkbox', default: true },
        ],
    },
};

let active = 'general';
let rows = [];
let siteSettings = {};
const previewUrls = {};

function setAssetPreview(name, url) {
    const image = $(`#site-${name}-preview`);
    const empty = $(`#site-${name}-empty`);
    image.hidden = !url;
    image.src = url ?? '';
    empty.hidden = Boolean(url);
}

function clearAssetPreview(name) {
    if (previewUrls[name]) URL.revokeObjectURL(previewUrls[name]);
    previewUrls[name] = null;
}

function refreshAssetPreview(name) {
    const file = siteForm.elements[name].files[0];
    const remove = siteForm.elements[`remove_${name}`].checked;
    if (remove || !file) clearAssetPreview(name);
    else if (!previewUrls[name]) previewUrls[name] = URL.createObjectURL(file);
    const url = remove ? null : previewUrls[name] ?? siteSettings[`${name}_url`];
    setAssetPreview(name, url);

    return url;
}

function updateSitePreview() {
    const form = siteForm.elements;
    $('#site-preview-name').textContent = form.site_name.value.trim() || 'Deresort Attendance';

    const logoUrl = previewUrls.logo ?? (form.remove_logo.checked ? null : siteSettings.logo_url);
    const logo = $('#site-preview-logo');
    logo.hidden = !logoUrl;
    logo.src = logoUrl ?? '';
    $('#site-preview-globe').hidden = Boolean(logoUrl);

    const contact = [form.contact_email.value.trim(), form.contact_phone.value.trim(), form.contact_address.value.trim()].filter(Boolean);
    $('#site-preview-contact').replaceChildren(...contact.map((value) => {
        const line = document.createElement('span');
        line.textContent = value;
        return line;
    }));
}

function showSiteSettings(settings) {
    siteSettings = settings;
    assetInputs.forEach(clearAssetPreview);
    siteForm.elements.site_name.value = settings.site_name ?? '';
    siteForm.elements.contact_email.value = settings.contact_email ?? '';
    siteForm.elements.contact_phone.value = settings.contact_phone ?? '';
    siteForm.elements.contact_address.value = settings.contact_address ?? '';
    siteForm.elements.remove_logo.checked = false;
    siteForm.elements.remove_favicon.checked = false;
    siteForm.elements.logo.value = '';
    siteForm.elements.favicon.value = '';
    $('#site-logo-remove-wrap').hidden = !settings.logo_url;
    $('#site-favicon-remove-wrap').hidden = !settings.favicon_url;
    refreshAssetPreview('logo');
    refreshAssetPreview('favicon');
    siteError.hidden = true;
    updateSitePreview();

    document.title = settings.site_name;
    $$('[data-site-name]').forEach((element) => { element.textContent = settings.site_name; });
    $$('[data-site-logo]').forEach((image) => {
        image.src = settings.logo_url ?? '';
        image.hidden = !settings.logo_url;
    });
    let favicon = $('link[rel="icon"]');
    if (settings.favicon_url) {
        favicon ??= Object.assign(document.createElement('link'), { rel: 'icon' });
        favicon.href = settings.favicon_url;
        document.head.append(favicon);
    } else {
        favicon?.remove();
    }
}

async function loadGeneral() {
    $('#settings-title').textContent = 'Umum';
    $('#settings-hint').textContent = 'Identitas dan kontak yang tampil di website.';
    $('#settings-add').hidden = true;
    table.hidden = true;
    siteForm.hidden = false;
    siteError.textContent = 'Memuat pengaturan…';
    siteError.hidden = false;
    try {
        const { data } = await api('site-settings');
        showSiteSettings(data);
    } catch (error) {
        siteError.textContent = error.message;
        siteError.hidden = false;
    }
}

async function load() {
    if (active === 'general') return loadGeneral();
    const tab = tabs[active];
    $('#settings-title').textContent = tab.title;
    $('#settings-hint').textContent = tab.hint;
    $('#settings-add').textContent = `+ Tambah ${tab.singular}`;
    $('#settings-add').hidden = !isAdmin;
    siteForm.hidden = true;
    table.hidden = false;
    loadingInto(table);
    try {
        rows = (await api(active)).data;
        renderTable(table, {
            rows,
            columns: tab.columns,
            empty: `Belum ada ${tab.singular}.`,
            actions: (x) => `<button type="button" class="text-button" data-action="edit" data-id="${x.id}">Ubah</button>${tab.extraAction?.(x) ?? ''}${tab.deletable ? `<button type="button" class="text-button danger-text" data-action="delete" data-id="${x.id}">Hapus</button>` : ''}`,
        });
    } catch (error) {
        errorInto(table, error);
    }
}

async function openItem(item) {
    const tab = tabs[active];
    const path = active;
    openForm({
        title: item ? `Ubah ${tab.singular}` : `Tambah ${tab.singular}`,
        fields: await tab.fields(item),
        values: item ?? {},
        onSubmit: async (body) => {
            await api(item ? `${path}/${item.id}` : path, { method: item ? 'PUT' : 'POST', body });
            invalidate(path);
            toast(`${tab.title} disimpan.`);
            load();
        },
    });
}

async function run(fn, message) {
    try {
        await fn();
        invalidate(active);
        toast(message);
        load();
    } catch (error) {
        toast(error.message);
    }
}

table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const item = button && rows.find((x) => String(x.id) === button.dataset.id);
    if (!item) return;
    if (button.dataset.action === 'edit') openItem(item);
    if (button.dataset.action === 'delete' && await confirmDialog({ title: `Hapus ${tabs[active].singular}?`, message: 'Data yang sudah dihapus tidak dapat dipulihkan.', confirmLabel: 'Hapus', danger: true })) {
        run(() => api(`${active}/${item.id}`, { method: 'DELETE' }), 'Data dihapus.');
    }
});

$('#settings-add').addEventListener('click', () => openItem(null));
$$('[data-settings-tab]').forEach((button) => button.addEventListener('click', () => {
    active = button.dataset.settingsTab;
    $$('[data-settings-tab]').forEach((b) => b.setAttribute('aria-selected', String(b === button)));
    load();
}));

export const showSettings = load;

const assetInputs = ['logo', 'favicon'];
assetInputs.forEach((name) => {
    siteForm.elements[name].addEventListener('change', () => {
        siteForm.elements[`remove_${name}`].checked = false;
        clearAssetPreview(name);
        refreshAssetPreview(name);
        updateSitePreview();
    });
    siteForm.elements[`remove_${name}`].addEventListener('change', () => {
        if (siteForm.elements[`remove_${name}`].checked) siteForm.elements[name].value = '';
        refreshAssetPreview(name);
        updateSitePreview();
    });
});

siteForm.addEventListener('input', (event) => {
    if (event.target.matches('[name="site_name"], [name="contact_email"], [name="contact_phone"], [name="contact_address"]')) updateSitePreview();
});

siteForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    siteError.hidden = true;
    $$('.field-error', siteForm).forEach((error) => { error.textContent = ''; error.hidden = true; });
    const submit = $('#site-settings-save');
    submit.disabled = true;
    try {
        const { data } = await api('site-settings', { method: 'POST', body: new FormData(siteForm) });
        showSiteSettings(data);
        toast('Pengaturan website disimpan.');
    } catch (error) {
        siteError.textContent = error.message;
        siteError.hidden = false;
        Object.entries(error.details ?? {}).forEach(([field, messages]) => {
            const slot = $(`#site-${field.replaceAll('_', '-')}-error`);
            if (slot) {
                slot.textContent = Array.isArray(messages) ? messages[0] : String(messages);
                slot.hidden = false;
            }
        });
        const details = Object.values(error.details ?? {}).flat();
        if (details.length) siteError.textContent = details.join(' ');
    } finally {
        submit.disabled = false;
    }
});
