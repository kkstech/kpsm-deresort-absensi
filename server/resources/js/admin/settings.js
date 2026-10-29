import { api, invalidate, isAdmin, lookup } from './api';
import { $, $$, confirmDialog, esc, errorInto, icon, loadingInto, openForm, renderTable, toast } from './ui';

const table = $('#settings-table');
const siteForm = $('#site-settings-form');
const siteError = $('#site-settings-error');
const opts = (list, label = (x) => x.code ? `${x.name} (${x.code})` : x.name) => list.map((x) => ({ value: x.id, label: label(x) }));
const hhmm = (t) => String(t).slice(0, 5).replace(':', '.');
const sessionsText = (t) => t.sessions.map((s) => `${hhmm(s.local_start)}${s.start_day_offset ? ' (+1)' : ''}–${hhmm(s.local_end)}${s.day_offset ? ` (+${s.day_offset})` : ''}`).join(', ');
const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
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
            { name: 'name', label: 'Nama template shift', required: true, maxlength: 100, hint: 'Contoh: Pagi Reguler, Sore, Malam, atau Split Resto' },
            { name: 'type', label: 'Jenis shift', type: 'select', required: true, options: [{ value: 'regular', label: 'Reguler (1 sesi operasional)' }, { value: 'special', label: 'Khusus / Split (2 sampai 4 sesi)' }], default: 'regular', hint: 'Pilih jenis shift untuk menentukan jumlah sesi.' },
            { name: 'sessions', label: 'Konfigurasi Sesi Kerja', type: 'sessions', fullWidth: true, hint: 'Atur jam mulai, jam selesai, dan waktu istirahat per sesi.' },
        ],
        grid: true,
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
    levels: {
        endpoint: 'employee-levels',
        title: 'Level karyawan', hint: 'Level terpisah dari jabatan dan menjadi dasar tarif tunjangan.', singular: 'level',
        columns: [{ label: 'Nama level', cell: (x) => `<b>${esc(x.name)}</b><small>${esc(x.code)}</small>` }, { label: 'Karyawan', cell: (x) => esc(x.employees_count ?? 0) }],
        fields: async () => [
            { name: 'code', label: 'Kode level', required: true, maxlength: 32 },
            { name: 'name', label: 'Nama level', required: true, maxlength: 100 },
        ], deletable: true,
    },
    allowances: {
        endpoint: 'allowance-policies', title: 'Tarif tunjangan', hint: 'Tarif efektif per level. Snapshot tarif dibuat saat siklus absensi dimulai.', singular: 'tarif', readOnly: true,
        columns: [
            { label: 'Level', cell: (x) => `<b>${esc(x.level?.name)}</b><small>${esc(x.level?.code)}</small>` },
            { label: 'Makan', cell: (x) => `Rp ${Number(x.meal_amount).toLocaleString('id-ID')}` },
            { label: 'Transport', cell: (x) => `Rp ${Number(x.transport_amount).toLocaleString('id-ID')}` },
            { label: 'Berlaku', cell: (x) => `${esc(x.effective_from)}<small>${x.effective_to ? `sampai ${esc(x.effective_to)}` : 'selanjutnya'}</small>` },
        ],
        fields: async () => {
            const levels = await lookup('employee-levels');
            return [
                { name: 'level_id', label: 'Level', type: 'select', numeric: true, required: true, blank: 'Pilih level', options: opts(levels) },
                { name: 'meal_amount', label: 'Uang makan per siklus', type: 'currency', required: true, hint: 'Tarif uang makan per hari/siklus (contoh: Rp 25.000).' },
                { name: 'transport_amount', label: 'Uang transport per siklus', type: 'currency', required: true, hint: 'Tarif uang transport per hari/siklus (contoh: Rp 15.000).' },
                { name: 'effective_from', label: 'Berlaku mulai', type: 'date', required: true, default: todayKey() },
                { name: 'effective_to', label: 'Berlaku sampai', type: 'date' },
                { name: 'retro_reason', label: 'Alasan perubahan surut', required: true, maxlength: 1000, showIf: (value) => value.effective_from && value.effective_from < todayKey() },
            ];
        }, preview: true, deletable: false,
    },
    'allowance-overrides': {
        endpoint: 'allowance-overrides', title: 'Pengecualian individu', hint: 'Nilai yang kosong mengikuti tarif level. Setiap pengecualian memiliki alasan dan masa berlaku.', singular: 'pengecualian', readOnly: true,
        columns: [
            { label: 'Karyawan', cell: (x) => `<b>${esc(x.employee?.name)}</b><small>${esc(x.employee?.code)}</small>` },
            { label: 'Makan', cell: (x) => x.meal_amount == null ? 'Tarif level' : `Rp ${Number(x.meal_amount).toLocaleString('id-ID')}` },
            { label: 'Transport', cell: (x) => x.transport_amount == null ? 'Tarif level' : `Rp ${Number(x.transport_amount).toLocaleString('id-ID')}` },
            { label: 'Berlaku', cell: (x) => `${esc(x.effective_from)}<small>${x.effective_to ? `sampai ${esc(x.effective_to)}` : 'selanjutnya'}</small>` },
        ],
        fields: async () => {
            const employees = (await api('employees', { query: { status: 'active' } })).data;
            return [
                { name: 'employee_id', label: 'Karyawan', type: 'select', numeric: true, required: true, blank: 'Pilih karyawan', options: opts(employees, (x) => `${x.name} (${x.code})`) },
                { name: 'meal_amount', label: 'Uang makan per siklus', type: 'currency', hint: 'Kosongkan jika ingin mengikuti tarif level karyawan.' },
                { name: 'transport_amount', label: 'Uang transport per siklus', type: 'currency', hint: 'Kosongkan jika ingin mengikuti tarif level karyawan.' },
                { name: 'effective_from', label: 'Berlaku mulai', type: 'date', required: true, default: todayKey() },
                { name: 'effective_to', label: 'Berlaku sampai', type: 'date' },
                { name: 'reason', label: 'Alasan pengecualian', required: true, maxlength: 1000 },
                { name: 'retro_reason', label: 'Alasan perubahan surut', required: true, maxlength: 1000, showIf: (value) => value.effective_from && value.effective_from < todayKey() },
            ];
        }, preview: true, deletable: false,
    },
    users: {
        title: 'Akun admin', hint: 'Akun untuk masuk ke admin web.', singular: 'akun',
        columns: [
            { label: 'Nama', cell: (u) => `<b>${esc(u.name)}</b><small>${esc(u.email)}</small>` },
            { label: 'Peran', cell: (u) => `${esc(roleLabel[u.role])}<small>${esc(u.can_view_all ? 'Semua departemen' : u.department?.name ?? '')}</small>` },
            { label: 'Profil Karyawan (Absensi)', cell: (u) => u.employee
                ? `<span class="status success">Terhubung</span><small>${esc(u.employee.code)} - ${esc(u.employee.name)}</small>`
                : '<span class="status neutral">Belum terhubung</span>'
            },
            { label: 'Status', cell: (u) => u.is_active ? '<span class="status success">Aktif</span>' : '<span class="status neutral">Nonaktif</span>' },
        ],
        extraAction: (u) => u.employee
            ? `<button type="button" class="action-btn" data-action="unlink-emp" data-id="${u.id}" title="Putuskan tautan karyawan" aria-label="Putuskan tautan karyawan ${esc(u.name)}">${icon('link-slash')}</button>`
            : `<button type="button" class="action-btn" data-action="link-emp" data-id="${u.id}" title="Hubungkan ke data karyawan" aria-label="Hubungkan ke data karyawan ${esc(u.name)}">${icon('link')}</button>`,
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

function getInitialSettingsTab() {
    const raw = window.location.hash.replace(/^#\/?/, '').trim();
    const parts = raw.split('/');
    if (parts[0] === 'settings' && parts[1]) {
        const sub = parts[1];
        if (sub === 'general' || tabs[sub]) return sub;
    }
    try {
        const saved = localStorage.getItem('deresort_settings_tab');
        if (saved && (saved === 'general' || tabs[saved])) return saved;
    } catch (_) {}
    return 'general';
}

function validSettingsTab(tabName) {
    if (tabName === 'general') return true;
    if (!tabs[tabName]) return false;
    const btn = $(`[data-settings-tab="${tabName}"]`);
    return Boolean(btn);
}

let active = getInitialSettingsTab();
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

    const contactItems = [];
    if (form.contact_email.value.trim()) {
        contactItems.push({ label: 'Email:', val: form.contact_email.value.trim() });
    }
    if (form.contact_phone.value.trim()) {
        contactItems.push({ label: 'No. Telp:', val: form.contact_phone.value.trim() });
    }
    if (form.contact_address.value.trim()) {
        contactItems.push({ label: 'Alamat:', val: form.contact_address.value.trim() });
    }
    $('#site-preview-contact').replaceChildren(...contactItems.map((item) => {
        const line = document.createElement('div');
        line.className = 'site-preview-contact-item';
        line.innerHTML = `<span class="auth-contact-label">${item.label}</span> <span>${esc(item.val)}</span>`;
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
    if (!validSettingsTab(active)) {
        active = 'general';
        try { localStorage.setItem('deresort_settings_tab', 'general'); } catch (_) {}
    }
    $$('[data-settings-tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.settingsTab === active)));
    if (active === 'general') return loadGeneral();
    const tab = tabs[active];
    const endpoint = tab.endpoint ?? active;
    $('#settings-title').textContent = tab.title;
    $('#settings-hint').textContent = tab.hint;
    $('#settings-add').textContent = `+ Tambah ${tab.singular}`;
    $('#settings-add').hidden = !isAdmin;
    siteForm.hidden = true;
    table.hidden = false;
    loadingInto(table);
    try {
        rows = (await api(endpoint)).data;
        renderTable(table, {
            rows,
            columns: tab.columns,
            empty: `Belum ada ${tab.singular}.`,
            actions: (x) => `${tab.readOnly ? '' : `<button type="button" class="action-btn" data-action="edit" data-id="${x.id}" title="Ubah ${esc(tab.singular)}" aria-label="Ubah ${esc(tab.singular)}">${icon('edit')}</button>`}${tab.extraAction?.(x) ?? ''}${tab.deletable ? `<button type="button" class="action-btn danger" data-action="delete" data-id="${x.id}" title="Hapus ${esc(tab.singular)}" aria-label="Hapus ${esc(tab.singular)}">${icon('trash')}</button>` : ''}`,
        });
    } catch (error) {
        errorInto(table, error);
    }
}

async function openItem(item) {
    const tab = tabs[active];
    const path = tab.endpoint ?? active;
    openForm({
        title: item ? `Ubah ${tab.singular}` : `Tambah ${tab.singular}`,
        fields: await tab.fields(item),
        values: item ?? {},
        grid: Boolean(tab.grid),
        onSubmit: async (body) => {
            if (tab.preview && body.effective_from < todayKey()) {
                const { data: preview } = await api(`${path}/preview`, { method: 'POST', body });
                const message = `Tarif ini berdampak pada ${preview.affected_cycles} siklus; ${preview.snapshots_to_recalculate} snapshot lama akan dihitung ulang. Total sekarang: makan Rp ${Number(preview.current_meal_amount).toLocaleString('id-ID')} dan transport Rp ${Number(preview.current_transport_amount).toLocaleString('id-ID')}.`;
                if (!await confirmDialog({ title: 'Pratinjau perubahan surut', message, confirmLabel: 'Terapkan perubahan' })) return false;
                body.preview_token = preview.preview_token;
            }
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
        const path = tabs[active]?.endpoint ?? active;
        invalidate(path);
        invalidate(active);
        toast(message);
        load();
    } catch (error) {
        toast(error.message);
    }
}

async function openLinkEmployee(user) {
    const [unlinkedEmployees, departments, positions, locations] = await Promise.all([
        api('unlinked-employees').then((r) => r.data),
        lookup('departments'),
        lookup('positions'),
        lookup('locations'),
    ]);

    const hasUnlinked = unlinkedEmployees.length > 0;

    openForm({
        title: `Hubungkan Karyawan · ${user.name}`,
        hint: 'User dapat didaftarkan sebagai karyawan baru atau ditautkan ke data karyawan yang belum memiliki akun.',
        submitLabel: 'Simpan',
        fields: [
            {
                name: 'mode',
                label: 'Metode integrasi',
                type: 'select',
                required: true,
                options: [
                    ...(hasUnlinked ? [{ value: 'link', label: 'Tautkan ke karyawan yang sudah ada' }] : []),
                    { value: 'create', label: 'Buat profil karyawan baru untuk user ini' },
                ],
                default: hasUnlinked ? 'link' : 'create',
            },
            {
                name: 'employee_id',
                label: 'Pilih karyawan',
                type: 'select',
                numeric: true,
                blank: 'Pilih karyawan…',
                options: opts(unlinkedEmployees, (e) => `${e.name} (${e.code} - ${e.department?.name ?? ''})`),
                showIf: (v) => v.mode === 'link',
            },
            {
                name: 'code',
                label: 'Kode karyawan baru',
                maxlength: 32,
                hint: 'Contoh: ADM-001, SPV-001, atau HR-001',
                showIf: (v) => v.mode === 'create',
            },
            {
                name: 'department_id',
                label: 'Departemen',
                type: 'select',
                numeric: true,
                blank: 'Pilih departemen',
                options: opts(departments),
                default: user.department_id,
                showIf: (v) => v.mode === 'create',
            },
            {
                name: 'position_id',
                label: 'Jabatan',
                type: 'select',
                numeric: true,
                blank: 'Pilih jabatan',
                options: opts(positions),
                showIf: (v) => v.mode === 'create',
            },
            {
                name: 'work_location_id',
                label: 'Lokasi kerja',
                type: 'select',
                numeric: true,
                blank: 'Tidak diatur',
                options: opts(locations),
                showIf: (v) => v.mode === 'create',
            },
            {
                name: 'started_on',
                label: 'Tanggal mulai kerja',
                type: 'date',
                default: todayKey(),
                showIf: (v) => v.mode === 'create',
            },
        ],
        onSubmit: async (body) => {
            if (body.mode === 'link') {
                if (!body.employee_id) throw new Error('Pilih data karyawan terlebih dahulu.');
                await api(`users/${user.id}/link-employee`, { method: 'POST', body: { employee_id: body.employee_id } });
                toast('Karyawan berhasil ditautkan ke akun user.');
            } else {
                if (!body.code) throw new Error('Kode karyawan wajib diisi.');
                if (!body.department_id) throw new Error('Departemen wajib dipilih.');
                if (!body.position_id) throw new Error('Jabatan wajib dipilih.');
                if (!body.started_on) throw new Error('Tanggal mulai kerja wajib diisi.');
                await api(`users/${user.id}/create-employee`, {
                    method: 'POST',
                    body: {
                        code: body.code,
                        department_id: body.department_id,
                        position_id: body.position_id,
                        work_location_id: body.work_location_id || null,
                        started_on: body.started_on,
                    },
                });
                toast('Profil karyawan baru berhasil dibuat dan ditautkan ke user.');
            }
            invalidate('users');
            invalidate('employees');
            load();
        },
    });
}

table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const item = button && rows.find((x) => String(x.id) === button.dataset.id);
    if (!item) return;
    if (button.dataset.action === 'edit') openItem(item);
    if (button.dataset.action === 'link-emp') openLinkEmployee(item);
    if (button.dataset.action === 'unlink-emp' && await confirmDialog({
        title: 'Putuskan tautan karyawan?',
        message: `Putuskan hubungan akun user ${item.name} dengan data karyawan ${item.employee?.name} (${item.employee?.code})? User ini tidak akan tercatat dalam jadwal dan tidak bisa absen di mobile app sampai ditautkan kembali.`,
        confirmLabel: 'Putuskan tautan',
        danger: true,
    })) {
        run(async () => {
            await api(`users/${item.id}/unlink-employee`, { method: 'POST' });
            invalidate('employees');
        }, 'Tautan karyawan berhasil diputuskan.');
    }
    if (button.dataset.action === 'delete' && await confirmDialog({ title: `Hapus ${tabs[active].singular}?`, message: 'Data yang sudah dihapus tidak dapat dipulihkan.', confirmLabel: 'Hapus', danger: true })) {
        const path = tabs[active]?.endpoint ?? active;
        run(() => api(`${path}/${item.id}`, { method: 'DELETE' }), 'Data dihapus.');
    }
});

$('#settings-add').addEventListener('click', () => openItem(null));
$$('[data-settings-tab]').forEach((button) => button.addEventListener('click', () => {
    active = button.dataset.settingsTab;
    try {
        localStorage.setItem('deresort_settings_tab', active);
    } catch (_) {}
    if (active && active !== 'general') {
        history.replaceState(null, '', `#settings/${active}`);
    } else {
        history.replaceState(null, '', '#settings');
    }
    $$('[data-settings-tab]').forEach((b) => b.setAttribute('aria-selected', String(b === button)));
    load();
}));

export async function showSettings() {
    active = getInitialSettingsTab();
    if (!validSettingsTab(active)) active = 'general';
    await load();
}

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
