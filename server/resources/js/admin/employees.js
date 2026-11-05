import { api, invalidate, isAdmin, lookup } from './api';
import { $, confirmDialog, esc, errorInto, icon, loadingInto, openForm, renderPagination, renderTable, toast } from './ui';

const table = $('#employee-table');
const pagination = $('#employee-pagination');
let employeePage = 1;
const employeePerPage = 15;
let employeeMeta = null;
const filters = {
    q: $('#employee-q'),
    code: $('#employee-code'),
    department_id: $('#employee-department'),
    level_id: $('#employee-level'),
    status: $('#employee-status'),
};
let rows = [];
let debounce;

const opts = (list, label = (x) => x.name) => list.map((x) => ({ value: x.id, label: label(x) }));

async function fields(withAccount, employee) {
    const [departments, positions, locations, levels] = await Promise.all([
        lookup('departments'),
        lookup('positions'),
        lookup('locations'),
        lookup('employee-levels'),
    ]);
    const list = [
        { name: 'avatar', label: 'Foto profil', type: 'file', accept: 'image/*', hint: 'Format JPG, PNG, atau WEBP. Maksimal 5MB.', fullWidth: true },

        { name: 'sec_personal', label: '1. Data Pribadi & Kontak', hint: 'Identitas utama dan kontak aktif karyawan', icon: 'user', type: 'section' },
        { name: 'name', label: 'Nama lengkap', required: true, maxlength: 120, placeholder: 'Contoh: Nadia Anjani' },
        { name: 'code', label: 'Nomor induk / Kode (NIK)', required: true, maxlength: 32, placeholder: 'Contoh: FO-001' },
        { name: 'phone', label: 'No. HP / WhatsApp', type: 'tel', maxlength: 32, autocomplete: 'off', placeholder: 'Contoh: 081234567890' },
        { name: 'email', label: 'Alamat email', type: 'email', maxlength: 255, placeholder: 'Contoh: karyawan@deresort.id' },
        { name: 'place_of_birth', label: 'Tempat lahir', maxlength: 120 },
        { name: 'date_of_birth', label: 'Tanggal lahir', type: 'date', hint: 'Dipakai untuk menampilkan usia dan pengingat pensiun.' },
        { name: 'religion', label: 'Agama', type: 'select', blank: 'Pilih agama', options: [
            { value: 'islam', label: 'Islam' }, { value: 'protestant', label: 'Kristen Protestan' },
            { value: 'catholic', label: 'Kristen Katolik' }, { value: 'hindu', label: 'Hindu' },
            { value: 'buddhist', label: 'Buddha' }, { value: 'confucian', label: 'Konghucu' }, { value: 'other', label: 'Lainnya' },
        ] },
        { name: 'gender', label: 'Jenis kelamin', type: 'select', blank: 'Pilih jenis kelamin', options: [{ value: 'male', label: 'Laki-laki' }, { value: 'female', label: 'Perempuan' }] },
        { name: 'marital_status', label: 'Status pernikahan', type: 'select', blank: 'Pilih status pernikahan', options: [
            { value: 'single', label: 'Belum menikah' }, { value: 'married', label: 'Menikah' },
            { value: 'divorced', label: 'Cerai hidup' }, { value: 'widowed', label: 'Cerai mati' },
        ] },
        { name: 'permanent_address', label: 'Alamat asal', type: 'textarea', maxlength: 2000, fullWidth: true },
        { name: 'current_address', label: 'Alamat sekarang / domisili', type: 'textarea', maxlength: 2000, fullWidth: true },

        { name: 'sec_job', label: '2. Kepegawaian & Penempatan', hint: 'Pengaturan unit kerja, jabatan, dan kelompok tunjangan', icon: 'contract', type: 'section' },
        { name: 'department_id', label: 'Departemen', type: 'select', numeric: true, required: true, blank: 'Pilih departemen', options: opts(departments) },
        { name: 'position_id', label: 'Jabatan', type: 'select', numeric: true, required: true, blank: 'Pilih jabatan', options: opts(positions) },
        { name: 'level_id', label: 'Level tunjangan', type: 'select', numeric: true, blank: 'Belum diatur / Tanpa level', options: opts(levels, (x) => `${x.name} (${x.code})`), hint: 'Wajib sebelum karyawan bisa absen. Dasar tunjangan makan dan transport.' },
        { name: 'work_location_id', label: 'Lokasi penempatan kerja', type: 'select', numeric: true, blank: 'Tidak diatur', options: opts(locations), hint: 'Wajib sebelum karyawan bisa absen. Titik GPS dipakai jika validasi lokasi aktif.' },
        { name: 'base_salary', label: 'Gaji Pokok (Rp / bulan)', type: 'number', min: 0, step: 1, hint: 'Hanya dapat dilihat dan diubah Admin atau Superadmin.' },
        { name: 'status', label: 'Status karyawan', type: 'select', required: true, options: [{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Nonaktif' }], default: 'active' },
        { name: 'started_on', label: 'Tanggal mulai kerja', type: 'date', required: !employee },
    ];
    if (withAccount) {
        list.push(
            { name: 'sec_account', label: '3. Akses Mobile App', hint: 'Buat kredensial login aplikasi mobile karyawan (login memakai username / NIK)', icon: 'key', type: 'section' },
            { name: 'make_account', label: 'Buat akun mobile app sekarang', hint: 'Karyawan dapat langsung masuk ke aplikasi menggunakan username (NIK) dan password ini', type: 'checkbox', fullWidth: true },
            { name: 'account_password', label: 'Password awal', type: 'password', autocomplete: 'new-password', hint: 'Bisa menggunakan kombinasi angka atau password sederhana.', showIf: (v) => v.make_account, placeholder: 'Contoh: 123456' },
        );
    }
    return list;
}

async function load(page = employeePage) {
    employeePage = page;
    loadingInto(table);
    try {
        const res = await api('employees', {
            query: {
                q: filters.q.value.trim(),
                code: filters.code?.value.trim() ?? '',
                department_id: filters.department_id.value,
                level_id: filters.level_id?.value ?? '',
                status: filters.status.value,
                page: employeePage,
                per_page: employeePerPage,
            },
        });
        rows = res.data;
        employeeMeta = res.meta ?? null;
        render();
    } catch (error) {
        errorInto(table, error);
        if (pagination) pagination.innerHTML = '';
    }
}

function render() {
    closeMoreMenu();
    renderTable(table, {
        rows,
        empty: 'Belum ada karyawan yang cocok dengan filter.',
        columns: [
            { label: 'Karyawan', cell: (e) => {
                const initial = (e.name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
                const avatar = e.avatar_url
                    ? `<img src="${esc(e.avatar_url)}" alt="${esc(e.name)}" class="avatar" style="width:36px;height:36px;border-radius:8px;object-fit:cover;margin-right:12px;flex-shrink:0;" onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'avatar',style:'width:36px;height:36px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;',textContent:'${esc(initial)}'}))">`
                    : `<span class="avatar" style="width:36px;height:36px;border-radius:8px;display:inline-grid;place-items:center;margin-right:12px;flex-shrink:0;font-size:12px;">${esc(initial)}</span>`;
                const age = e.current_age !== null && e.current_age !== undefined
                    ? `<small style="display:block;">${esc(e.current_age)} tahun</small>`
                    : '';
                return `<div style="display:flex;align-items:center;">${avatar}<div><b>${esc(e.name)}</b>${age}<small style="display:block;">${esc(e.code)}</small></div></div>`;
            } },
            { label: 'Departemen', cell: (e) => `${esc(e.department?.name)}<small>${esc(e.position?.name)}</small>` },
            { label: 'Level & Status Kerja', cell: (e) => {
                const levelHtml = e.level
                    ? `<b>${esc(e.level.name)}</b> <span style="color:var(--muted);font-size:11px;font-weight:400;">(${esc(e.level.code)})</span>`
                    : '<span style="color:var(--muted);font-size:12px;font-style:italic;">Belum diatur</span>';
                const type = e.employment?.type;
                const label = type === 'contract'
                    ? `Kontrak${e.employment.ends_on ? ` s/d ${e.employment.ends_on}` : ''}`
                    : type === 'permanent'
                        ? 'Tetap'
                        : 'Perlu dilengkapi';
                const tone = ['overdue', 'urgent'].includes(e.employment?.alert)
                    ? 'warning'
                    : (type === 'contract' ? 'blue' : 'neutral');
                const retirementLabel = e.retirement_status === 'reached'
                    ? 'Usia pensiun tercapai'
                    : e.retirement_status === 'soon'
                        ? `Pensiun ≤12 bulan · ${e.retirement_date}`
                        : '';
                return `<div>${levelHtml}<div style="margin-top:4px;"><span class="status ${tone}" style="font-size:10.5px;padding:1px 6px;">${esc(label)}</span></div>${retirementLabel ? `<small style="display:block;margin-top:5px;"><span class="status warning" style="font-size:10.5px;padding:1px 6px;">${esc(retirementLabel)}</span></small>` : ''}</div>`;
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
            <button type="button" class="action-btn" data-action="account" data-id="${e.id}" title="${e.user ? 'Ganti password / kelola akun' : 'Hubungkan / buat akun app'}" aria-label="${e.user ? 'Ganti password' : 'Buat akun'} ${esc(e.name)}">${icon(e.user ? 'key' : 'user-plus')}</button>
            <button type="button" class="action-btn" data-action="more" data-id="${e.id}" title="Aksi lainnya" aria-label="Aksi lainnya untuk ${esc(e.name)}" aria-haspopup="menu" aria-expanded="false">${icon('more-horizontal')}</button>` : null,
    });
    renderPagination(pagination, employeeMeta, (newPage) => {
        load(newPage);
    });
}

async function openEmployee(employee) {
    openForm({
        title: employee ? `Ubah Data · ${employee.name}` : 'Tambah Karyawan Baru',
        hint: employee ? 'Pindah departemen atau level tunjangan otomatis tercatat di riwayat penempatan.' : 'Akun app bisa dibuat sekarang atau nanti.',
        grid: true,
        fields: await fields(!employee, employee),
        values: employee ? {
            ...employee,
            level_id: employee.level_id ?? employee.level?.id ?? '',
            email: employee.user?.email || employee.email,
            avatar: employee.avatar_url,
            started_on: employee.started_on ? employee.started_on.slice(0, 10) : '',
        } : {},
        onSubmit: async (values) => {
            const { make_account: _, ...body } = values;
            if (body.avatar instanceof File) {
                const form = new FormData();
                Object.entries(body).forEach(([k, v]) => {
                    if (v !== null && v !== undefined) form.append(k, v);
                });
                if (employee) {
                    form.append('_method', 'PUT');
                    await api(`employees/${employee.id}`, { method: 'POST', body: form });
                } else {
                    await api('employees', { method: 'POST', body: form });
                }
            } else {
                delete body.avatar;
                await api(employee ? `employees/${employee.id}` : 'employees', { method: employee ? 'PUT' : 'POST', body });
            }
            invalidate('employees');
            toast(employee ? 'Data karyawan berhasil disimpan.' : 'Karyawan berhasil ditambahkan.');
            load();
        },
    });
}

async function openEmployment(employee) {
    const current = employee.employment ?? {};
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
    openForm({
        title: `Status Kerja · ${employee.name}`,
        hint: 'Gunakan tanggal efektif sebenarnya. Tanggal kontrak boleh sebelum sistem digunakan.',
        fields: [
            { name: 'type', label: 'Jenis karyawan', type: 'select', required: true, options: [{ value: 'permanent', label: 'Tetap' }, { value: 'contract', label: 'Kontrak' }], default: current.type === 'contract' ? 'contract' : 'permanent' },
            { name: 'starts_on', label: 'Berlaku mulai', type: 'date', required: true, hint: 'Bisa diisi dengan tanggal historis, minimal tanggal mulai kerja.' },
            { name: 'ends_on', label: 'Kontrak berakhir', type: 'date', required: true, showIf: (value) => value.type === 'contract' },
            { name: 'reason', label: 'Alasan perubahan', type: 'textarea', required: true, maxlength: 1000, placeholder: 'Keterangan perpanjangan kontrak atau pengangkatan karyawan...' },
        ],
        values: {
            starts_on: current.type === 'unknown' ? (current.starts_on ?? employee.started_on ?? today) : today,
            ends_on: current.ends_on ?? '',
        },
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
        title: `Level Tunjangan · ${employee.name}`,
        hint: 'Perubahan level tunjangan dicatat otomatis ke riwayat penempatan.',
        fields: [
            { name: 'level_id', label: 'Level tunjangan', type: 'select', numeric: true, required: true, blank: 'Pilih level', options: opts(levels, (x) => `${x.name} (${x.code})`) },
            { name: 'effective_from', label: 'Berlaku mulai', type: 'date', required: true },
            { name: 'reason', label: 'Alasan perubahan', type: 'textarea', required: true, maxlength: 1000, placeholder: 'Tuliskan alasan penyesuaian level tunjangan...' },
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
            title: `Ganti Password · ${employee.name}`,
            hint: `Akun: ${employee.user.username || employee.code}. Perangkat karyawan akan diminta login ulang dengan password baru.`,
            submitLabel: 'Ganti password',
            fields: [
                { name: 'password', label: 'Password baru', type: 'password', required: true, autocomplete: 'new-password', hint: 'Bisa menggunakan angka atau password sederhana.', placeholder: 'Password baru' },
            ],
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
            ? 'Anda bisa menautkan akun user/admin yang sudah ada, atau membuatkan password login baru.'
            : `Karyawan akan login di mobile app menggunakan username (${employee.code}) dan password ini.`,
        submitLabel: 'Simpan Akun',
        fields: hasUnlinked ? [
            {
                name: 'mode',
                label: 'Metode akun',
                type: 'select',
                required: true,
                options: [
                    { value: 'create', label: `Buat password login mobile baru (Username: ${employee.code})` },
                    { value: 'link', label: 'Tautkan dengan user yang sudah ada (Admin / Supervisor / User)' },
                ],
                default: 'create',
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
                name: 'password',
                label: 'Password awal',
                type: 'password',
                autocomplete: 'new-password',
                hint: 'Bisa menggunakan kombinasi angka atau password sederhana. Berikan ke karyawan.',
                placeholder: 'Contoh: 123456',
                showIf: (v) => v.mode === 'create',
            },
        ] : [
            { name: 'password', label: 'Password awal', type: 'password', required: true, autocomplete: 'new-password', hint: 'Bisa menggunakan kombinasi angka atau password sederhana. Berikan ke karyawan.', placeholder: 'Contoh: 123456' },
        ],
        values: {},
        onSubmit: async (body) => {
            if (hasUnlinked && body.mode === 'link') {
                if (!body.user_id) throw new Error('Pilih akun user yang ingin ditautkan.');
                await api(`employees/${employee.id}/link-user`, { method: 'POST', body: { user_id: body.user_id } });
                toast('Akun user berhasil ditautkan ke karyawan.');
            } else {
                await api(`employees/${employee.id}/account`, { method: 'POST', body: { password: body.password } });
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
        toast(error.message, 'error');
    }
}

function formatDateTime(iso) {
    if (!iso) return '-';
    try {
        const d = new Date(iso);
        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Jakarta',
        }).format(d);
    } catch (_) {
        return String(iso).slice(0, 16).replace('T', ' ');
    }
}

let activeMoreButton = null;
let moreMenuEl = null;

function closeMoreMenu() {
    if (moreMenuEl) {
        moreMenuEl.hidden = true;
    }
    if (activeMoreButton) {
        activeMoreButton.setAttribute('aria-expanded', 'false');
        activeMoreButton.classList.remove('active');
        activeMoreButton = null;
    }
}

function getMoreMenu() {
    if (!moreMenuEl) {
        moreMenuEl = document.createElement('div');
        moreMenuEl.id = 'employee-more-menu';
        moreMenuEl.className = 'action-dropdown-menu';
        moreMenuEl.hidden = true;
        document.body.appendChild(moreMenuEl);

        moreMenuEl.addEventListener('click', (event) => {
            const item = event.target.closest('[data-action]');
            if (!item) return;
            const action = item.dataset.action;
            const employee = rows.find((e) => String(e.id) === item.dataset.id);
            closeMoreMenu();
            if (employee) runAction(action, employee);
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('#employee-more-menu') && !event.target.closest('[data-action="more"]')) {
                closeMoreMenu();
            }
        });

        window.addEventListener('scroll', closeMoreMenu, true);
        window.addEventListener('resize', closeMoreMenu);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMoreMenu();
        });
    }
    return moreMenuEl;
}

function toggleMoreMenu(button, employee) {
    if (activeMoreButton === button && moreMenuEl && !moreMenuEl.hidden) {
        closeMoreMenu();
        return;
    }

    closeMoreMenu();
    activeMoreButton = button;
    button.setAttribute('aria-expanded', 'true');
    button.classList.add('active');

    const menu = getMoreMenu();
    const items = [];

    items.push(`
        <button type="button" class="action-dropdown-item" data-action="level" data-id="${employee.id}">
            ${icon('award', 15)} <span>Ubah level tunjangan</span>
        </button>
    `);

    if (employee.user) {
        items.push(`
            <button type="button" class="action-dropdown-item" data-action="unlink" data-id="${employee.id}">
                ${icon('link-slash', 15)} <span>Putuskan tautan akun</span>
            </button>
        `);
    }

    if (employee.user && employee.devices_count) {
        items.push(`
            <button type="button" class="action-dropdown-item" data-action="revoke" data-id="${employee.id}">
                ${icon('device-logout', 15)} <span>Keluarkan perangkat (${employee.devices_count})</span>
            </button>
        `);
    }

    if (employee.status === 'active') {
        items.push('<div class="action-dropdown-divider" role="separator"></div>');
        items.push(`
            <button type="button" class="action-dropdown-item danger" data-action="deactivate" data-id="${employee.id}">
                ${icon('user-x', 15)} <span>Nonaktifkan karyawan</span>
            </button>
        `);
    }

    menu.innerHTML = items.join('');
    menu.hidden = false;

    const rect = button.getBoundingClientRect();
    const menuWidth = menu.offsetWidth || 210;
    const menuHeight = menu.offsetHeight || 160;

    let left = rect.right - menuWidth;
    if (left < 10) left = 10;
    if (left + menuWidth > window.innerWidth - 10) {
        left = window.innerWidth - menuWidth - 10;
    }

    let top = rect.bottom + 4;
    if (window.innerHeight - rect.bottom < menuHeight + 10 && rect.top > menuHeight) {
        top = rect.top - menuHeight - 4;
    }

    menu.style.top = `${top}px`;
    menu.style.left = `${left}px`;
}

async function runAction(action, employee) {
    if (action === 'edit') openEmployee(employee);
    if (action === 'employment') openEmployment(employee);
    if (action === 'level') openLevel(employee);
    if (action === 'account') openAccount(employee);
    if (action === 'unlink' && await confirmDialog({
        title: 'Putuskan tautan akun?',
        message: `Putuskan hubungan antara karyawan ${employee.name} dengan akun ${employee.user?.email}? Karyawan tidak akan dapat login ke mobile app sampai ditautkan kembali.`,
        confirmLabel: 'Putuskan tautan',
        danger: true,
    })) {
        act(`employees/${employee.id}/unlink-user`, 'Tautan akun berhasil diputuskan.');
    }
    if (action === 'revoke') {
        try {
            const { data: devices } = await api(`employees/${employee.id}/devices`);
            if (!devices || devices.length === 0) {
                toast('Tidak ada perangkat aktif untuk karyawan ini.');
                return;
            }

            const isMultiple = devices.length > 1;
            const title = isMultiple ? `Keluarkan Semua Perangkat (${devices.length})` : 'Keluarkan Perangkat';
            const confirmLabel = isMultiple ? `Ya, Keluarkan Semua (${devices.length})` : 'Keluarkan Perangkat';

            const html = isMultiple ? `
                <p style="margin-bottom: 12px; font-size: 13.5px; color: var(--ink);">
                    Karyawan <b>${esc(employee.name)}</b> saat ini terhubung di <b>${devices.length} perangkat aktif</b>:
                </p>
                <div style="display: grid; gap: 8px; margin: 12px 0 16px; max-height: 240px; overflow-y: auto; padding-right: 4px;">
                    ${devices.map((d, idx) => `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--canvas, #f2f5f1); border: 1px solid var(--line, #dfe5df); border-radius: 8px;">
                            <div>
                                <div style="font-weight: 700; font-size: 13px; color: var(--ink); display: flex; align-items: center; gap: 6px;">
                                    ${icon('smartphone', 15)} <span>${esc(d.name || 'Perangkat Seluler')}</span>
                                    <small style="color: var(--muted); font-weight: normal; font-size: 11px;">#${idx + 1}</small>
                                </div>
                                <div style="font-size: 11.5px; color: var(--muted); margin-top: 3px;">
                                    Terakhir aktif: <strong>${esc(formatDateTime(d.last_seen_at))}</strong>
                                </div>
                                <div style="font-size: 10.5px; color: #88958c; font-family: monospace; margin-top: 1px;">
                                    UUID: ${esc(d.device_uuid ? d.device_uuid.slice(0, 16) + '…' : '-')}
                                </div>
                            </div>
                            <span class="status success" style="font-size: 10.5px; padding: 2px 7px;">Aktif</span>
                        </div>
                    `).join('')}
                </div>
                <p style="margin: 0; font-size: 12.5px; color: var(--danger); font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    ${icon('alert-circle', 15)} Semua sesi login pada ${devices.length} perangkat di atas akan dibatalkan dan karyawan harus login ulang.
                </p>
            ` : `
                <p style="margin-bottom: 12px; font-size: 13.5px; color: var(--ink);">
                    Keluarkan perangkat untuk karyawan <b>${esc(employee.name)}</b>?
                </p>
                <div style="margin: 12px 0 16px; padding: 10px 14px; background: var(--canvas, #f2f5f1); border: 1px solid var(--line, #dfe5df); border-radius: 8px;">
                    <div style="font-weight: 700; font-size: 13px; color: var(--ink); display: flex; align-items: center; gap: 6px;">
                        ${icon('smartphone', 15)} <span>${esc(devices[0].name || 'Perangkat Seluler')}</span>
                    </div>
                    <div style="font-size: 11.5px; color: var(--muted); margin-top: 3px;">
                        Terakhir aktif: <strong>${esc(formatDateTime(devices[0].last_seen_at))}</strong>
                    </div>
                    <div style="font-size: 10.5px; color: #88958c; font-family: monospace; margin-top: 1px;">
                        UUID: ${esc(devices[0].device_uuid ? devices[0].device_uuid.slice(0, 16) + '…' : '-')}
                    </div>
                </div>
                <p style="margin: 0; font-size: 12.5px; color: var(--muted);">
                    Karyawan harus login ulang untuk dapat mengakses aplikasi kembali.
                </p>
            `;

            if (await confirmDialog({ title, html, confirmLabel, danger: true })) {
                await act(`employees/${employee.id}/revoke-devices`, isMultiple ? `Semua (${devices.length}) perangkat berhasil dikeluarkan.` : 'Perangkat berhasil dikeluarkan.');
            }
        } catch (error) {
            toast(error.message, 'error');
        }
    }
    if (action === 'deactivate' && await confirmDialog({
        title: 'Nonaktifkan karyawan?',
        message: `Nonaktifkan ${employee.name}? Akun app ikut nonaktif.`,
        confirmLabel: 'Nonaktifkan',
        danger: true,
    })) {
        act(`employees/${employee.id}/deactivate`, 'Karyawan dinonaktifkan.');
    }
}

table.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const employee = button && rows.find((e) => String(e.id) === button.dataset.id);
    if (!employee) return;
    const action = button.dataset.action;
    if (action === 'more') {
        toggleMoreMenu(button, employee);
        return;
    }
    closeMoreMenu();
    runAction(action, employee);
});

$('#employee-add').addEventListener('click', () => openEmployee(null));
filters.q.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
filters.code?.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(() => load(1), 250); });
filters.department_id.addEventListener('change', () => load(1));
filters.level_id?.addEventListener('change', () => load(1));
filters.status.addEventListener('change', () => load(1));

const importModal = $('#employee-import-modal');
const importBtn = $('#employee-import-btn');
const importForm = $('#employee-import-form');
const importFile = $('#employee-import-file');
const importDropzone = $('#employee-import-dropzone');
const importDropzoneIdle = $('#employee-import-dropzone-idle');
const importDropzoneSelected = $('#employee-import-dropzone-selected');
const importFileName = $('#employee-import-file-name');
const importFileSize = $('#employee-import-file-size');
const importFileClear = $('#employee-import-file-clear');

const importContainer = $('#employee-import-preview-container');
const importStatsSummary = $('#employee-import-stats-summary');
const importPillTotal = $('#import-pill-total');
const importPillValid = $('#import-pill-valid');
const importPillError = $('#import-pill-error');

const progressWrap = $('#employee-import-progress-wrap');
const progressStatus = $('#employee-import-progress-status');
const progressPct = $('#employee-import-progress-pct');
const progressFill = $('#employee-import-progress-fill');
const progressDetail = $('#employee-import-progress-detail');

const filterAllBtn = $('#import-filter-all');
const filterValidBtn = $('#import-filter-valid');
const filterErrorBtn = $('#import-filter-error');
const countFilterAll = $('#count-filter-all');
const countFilterValid = $('#count-filter-valid');
const countFilterError = $('#count-filter-error');

const importTbody = $('#employee-import-table-body');
const importError = $('#employee-import-error');
const importSubmit = $('#employee-import-submit');
const importNotice = $('#employee-import-notice');
const templateBtn = $('#employee-template-btn');
const templateModalBtn = $('#employee-template-modal-btn');

const FALLBACK_CSV = '\uFEFF' + [
    'kode_karyawan,nama_lengkap,departemen,jabatan,tanggal_mulai_kerja,status_kerja,tanggal_selesai_kontrak,email,no_telepon,gaji_pokok,agama,tempat_lahir,tanggal_lahir,jenis_kelamin,status_pernikahan,alamat_asal,alamat_domisili,level,lokasi_kerja,status,buat_akun,password_awal',
    'FO-001,Nadia Anjani,Front Office,Receptionist,2026-01-01,tetap,,nadia@deresort.test,081234567801,,islam,,,,,,,Staff,Resort Utama,aktif,ya,password123',
    'EN-001,Dimas Saputra,Engineering,Technician,2026-01-15,kontrak,2026-12-31,dimas@deresort.test,081234567802,,islam,,,,,,,Staff,,aktif,ya,password123',
].join('\r\n');

function triggerDownload(content, filename = 'template_import_karyawan.csv') {
    const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    toast('Template CSV berhasil diunduh.');
}

async function handleDownloadTemplate(btn) {
    if (btn) btn.disabled = true;
    try {
        const response = await fetch('/admin/api/employees/import-template', {
            headers: { Accept: 'text/csv' },
            credentials: 'same-origin',
        });
        if (response.ok) {
            const csvText = await response.text();
            triggerDownload(csvText);
        } else {
            triggerDownload(FALLBACK_CSV);
        }
    } catch (_) {
        triggerDownload(FALLBACK_CSV);
    } finally {
        if (btn) btn.disabled = false;
    }
}

if (templateBtn) templateBtn.addEventListener('click', () => handleDownloadTemplate(templateBtn));
if (templateModalBtn) templateModalBtn.addEventListener('click', () => handleDownloadTemplate(templateModalBtn));

let currentValidatedRows = [];
let currentFilter = 'all';

function formatFileSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
}

function renderFilteredRows() {
    if (!importTbody) return;
    const rows = currentValidatedRows.filter((r) => {
        if (currentFilter === 'valid') return r._valid !== false;
        if (currentFilter === 'error') return r._valid === false;
        return true;
    });

    if (!rows.length) {
        const msg = currentFilter === 'error'
            ? 'Tidak ada baris dengan kesalahan format.'
            : currentFilter === 'valid'
                ? 'Tidak ada baris valid.'
                : 'Berkas tidak memiliki baris data.';
        importTbody.innerHTML = `<tr><td colspan="8" style="padding: 24px 12px; text-align: center; color: var(--muted); font-size: 13px;">${msg}</td></tr>`;
        return;
    }

    importTbody.innerHTML = rows.map((r) => {
        const isValid = r._valid !== false;
        const statusBadge = isValid
            ? '<span class="status success" style="font-size: 11px; padding: 2px 8px;">Valid</span>'
            : '<span class="status danger" style="font-size: 11px; padding: 2px 8px; color: #991b1b; background: #fee2e2;">Error</span>';
        const empType = r.employment_type === 'contract'
            ? `Kontrak${r.ends_on ? ` (${esc(r.ends_on)})` : ''}`
            : 'Tetap';
        const detail = isValid
            ? `<div style="display: flex; align-items: center; gap: 4px; color: var(--muted); font-size: 11.5px;">${r.make_account ? `<span>Akun: <b>${esc(r.email)}</b></span>` : '<span>Tanpa pembuatan akun</span>'}</div>`
            : `<div style="display: flex; flex-direction: column; gap: 3px;">${(r._errors || []).map((err) => `<span class="import-error-tag">${icon('alert-circle', 12)} ${esc(err)}</span>`).join('')}</div>`;

        return `
            <tr class="${isValid ? '' : 'row-invalid'}">
                <td style="text-align: center; font-weight: 700; color: var(--muted);">${r._line}</td>
                <td>${statusBadge}</td>
                <td><span class="import-code-badge">${esc(r.code)}</span></td>
                <td><b>${esc(r.name)}</b></td>
                <td>${esc(r.department)}</td>
                <td>${esc(r.position)}</td>
                <td>${esc(empType)}</td>
                <td>${detail}</td>
            </tr>
        `;
    }).join('');
}

function setFilter(filter) {
    currentFilter = filter;
    [filterAllBtn, filterValidBtn, filterErrorBtn].forEach((btn) => {
        if (btn) btn.classList.toggle('active', btn.dataset.filter === filter);
    });
    renderFilteredRows();
}

[filterAllBtn, filterValidBtn, filterErrorBtn].forEach((btn) => {
    if (btn) btn.addEventListener('click', () => setFilter(btn.dataset.filter));
});

function setImportProgress(current, total, statusText, detailText) {
    if (!progressWrap) return;
    progressWrap.hidden = false;
    const pct = total > 0 ? Math.round((current / total) * 100) : 0;
    if (progressPct) progressPct.textContent = `${pct}%`;
    if (progressFill) progressFill.style.width = `${pct}%`;
    if (progressStatus) progressStatus.textContent = statusText;
    if (progressDetail) progressDetail.textContent = detailText ?? `${current} dari ${total} data karyawan diproses (${pct}%)`;
}

function resetImportDialog() {
    if (importFile) importFile.value = '';
    if (importDropzoneIdle) importDropzoneIdle.hidden = false;
    if (importDropzoneSelected) importDropzoneSelected.hidden = true;
    if (importContainer) importContainer.hidden = true;
    if (progressWrap) {
        progressWrap.hidden = true;
        if (progressFill) progressFill.style.width = '0%';
        if (progressPct) progressPct.textContent = '0%';
    }
    if (importError) {
        importError.hidden = true;
        importError.textContent = '';
    }
    if (importSubmit) {
        importSubmit.disabled = true;
        importSubmit.textContent = 'Simpan & Impor Karyawan';
    }
    if (importNotice) {
        importNotice.textContent = 'Baris dengan kesalahan format akan dilewati secara otomatis.';
    }
    currentValidatedRows = [];
    currentFilter = 'all';
    setFilter('all');
}

if (importBtn && importModal) {
    importBtn.addEventListener('click', () => {
        resetImportDialog();
        importModal.showModal();
    });
}

if (importFileClear) {
    importFileClear.addEventListener('click', (e) => {
        e.stopPropagation();
        resetImportDialog();
    });
}

if (importDropzone) {
    ['dragenter', 'dragover'].forEach((eventName) => {
        importDropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            importDropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        importDropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            importDropzone.classList.remove('dragover');
        });
    });
    importDropzone.addEventListener('drop', (e) => {
        const droppedFiles = e.dataTransfer?.files;
        if (droppedFiles && droppedFiles.length) {
            importFile.files = droppedFiles;
            handleFileSelection(droppedFiles[0]);
        }
    });
}

async function handleFileSelection(file) {
    if (!file) {
        resetImportDialog();
        return;
    }

    if (importDropzoneIdle) importDropzoneIdle.hidden = true;
    if (importDropzoneSelected) importDropzoneSelected.hidden = false;
    if (importFileName) importFileName.textContent = file.name;
    if (importFileSize) importFileSize.textContent = formatFileSize(file.size);

    importError.hidden = true;
    importContainer.hidden = false;
    importSubmit.disabled = true;
    importSubmit.textContent = 'Memvalidasi...';
    if (importStatsSummary) importStatsSummary.innerHTML = '<span>Memverifikasi baris data berkas CSV…</span>';
    if (importTbody) importTbody.innerHTML = '<tr><td colspan="8" style="padding: 24px 12px; text-align: center; color: var(--muted);">Sedang membaca dan memvalidasi berkas CSV…</td></tr>';

    try {
        const formData = new FormData();
        formData.append('file', file);

        const result = await api('employees/import-preview', {
            method: 'POST',
            body: formData,
        });

        currentValidatedRows = result.rows || [];
        const validRows = currentValidatedRows.filter((r) => r._valid !== false);
        const errorRows = currentValidatedRows.filter((r) => r._valid === false);

        if (countFilterAll) countFilterAll.textContent = currentValidatedRows.length;
        if (countFilterValid) countFilterValid.textContent = validRows.length;
        if (countFilterError) countFilterError.textContent = errorRows.length;

        if (importPillTotal) importPillTotal.textContent = `Total: ${currentValidatedRows.length}`;
        if (importPillValid) importPillValid.textContent = `Valid: ${validRows.length}`;
        if (importPillError) {
            importPillError.textContent = `Error: ${errorRows.length}`;
            importPillError.hidden = errorRows.length === 0;
        }

        if (result.error_count === 0 && validRows.length > 0) {
            importStatsSummary.innerHTML = `<span style="color: var(--primary, #15803d); display: flex; align-items: center; gap: 6px;">${icon('check-circle', 18)} <b>Semua baris valid!</b> Siap diimpor ke sistem.</span>`;
            importSubmit.disabled = false;
            importSubmit.textContent = `Simpan & Impor (${validRows.length}) Karyawan`;
            if (importNotice) importNotice.textContent = `Semua ${validRows.length} data karyawan siap didaftarkan.`;
        } else if (validRows.length > 0) {
            importStatsSummary.innerHTML = `<span style="color: var(--warning, #b45309); display: flex; align-items: center; gap: 6px;">${icon('alert-circle', 18)} <b>Perhatian:</b> Ditemukan ${result.error_count} baris bermasalah.</span>`;
            importSubmit.disabled = false;
            importSubmit.textContent = `Simpan & Impor (${validRows.length}) Karyawan Valid`;
            if (importNotice) importNotice.textContent = `${validRows.length} baris valid akan diimpor. ${result.error_count} baris bermasalah akan dilewati.`;
        } else {
            importStatsSummary.innerHTML = `<span style="color: var(--danger, #dc2626); display: flex; align-items: center; gap: 6px;">${icon('alert-circle', 18)} <b>Tidak dapat diimpor:</b> Seluruh baris data memiliki kesalahan.</span>`;
            importSubmit.disabled = true;
            importSubmit.textContent = 'Perbaiki Berkas CSV';
            if (importNotice) importNotice.textContent = 'Silakan perbaiki kesalahan kolom pada berkas CSV Anda lalu unggah ulang.';
        }

        setFilter('all');
    } catch (err) {
        importError.textContent = err.message || 'Gagal memproses file CSV.';
        importError.hidden = false;
        importContainer.hidden = true;
        importSubmit.disabled = true;
        importSubmit.textContent = 'Simpan & Impor Karyawan';
    }
}

if (importFile) {
    importFile.addEventListener('change', () => {
        const file = importFile.files?.[0];
        handleFileSelection(file);
    });
}

if (importForm) {
    importForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const validRows = currentValidatedRows.filter((r) => r._valid !== false);
        if (!validRows.length) {
            importError.textContent = 'Tidak ada baris valid untuk diimpor.';
            importError.hidden = false;
            return;
        }

        importSubmit.disabled = true;
        importSubmit.textContent = 'Mengimpor…';
        importError.hidden = true;
        if (importDropzone) importDropzone.style.pointerEvents = 'none';

        const CHUNK_SIZE = 25;
        const totalRows = validRows.length;
        let totalImported = 0;

        setImportProgress(0, totalRows, 'Menyiapkan database dan memproses baris data…', `0 dari ${totalRows} data karyawan diproses`);

        try {
            for (let i = 0; i < totalRows; i += CHUNK_SIZE) {
                const chunk = validRows.slice(i, i + CHUNK_SIZE);
                const chunkStart = i + 1;
                const chunkEnd = Math.min(i + CHUNK_SIZE, totalRows);

                setImportProgress(
                    i,
                    totalRows,
                    `Mengimpor data karyawan baris ${chunkStart}–${chunkEnd}…`,
                    `${i} dari ${totalRows} data karyawan diproses (${Math.round((i / totalRows) * 100)}%)`
                );

                const res = await api('employees/import', {
                    method: 'POST',
                    body: { rows: chunk },
                });

                totalImported += (res.imported_count || chunk.length);

                setImportProgress(
                    chunkEnd,
                    totalRows,
                    chunkEnd === totalRows ? 'Menyelesaikan penyimpanan…' : 'Mengimpor data karyawan…',
                    `${chunkEnd} dari ${totalRows} data karyawan diproses (${Math.round((chunkEnd / totalRows) * 100)}%)`
                );
            }

            setImportProgress(totalRows, totalRows, 'Impor data selesai!', `Berhasil mendaftarkan ${totalImported} karyawan baru.`);

            await new Promise((resolve) => setTimeout(resolve, 600));

            toast(`Berhasil mengimpor ${totalImported} karyawan.`);
            importModal.close();
            resetImportDialog();
            refreshEmployeeLookups();
            load();
        } catch (err) {
            importError.textContent = err.message || 'Gagal menyimpan data import.';
            importError.hidden = false;
            importSubmit.disabled = false;
            importSubmit.textContent = `Coba Lagi (${validRows.length} Karyawan)`;
            if (progressWrap) progressWrap.hidden = true;
        } finally {
            if (importDropzone) importDropzone.style.pointerEvents = '';
        }
    });
}

let started = false;
export async function showEmployees() {
    if (!started) {
        started = true;
        try {
            const [departments, levels] = await Promise.all([
                lookup('departments'),
                lookup('employee-levels'),
            ]);
            filters.department_id.insertAdjacentHTML('beforeend', departments.map((d) => `<option value="${d.id}">${esc(d.name)}</option>`).join(''));
            if (filters.level_id && filters.level_id.options.length <= 2) {
                filters.level_id.insertAdjacentHTML('beforeend', (levels ?? []).map((l) => `<option value="${l.id}">${esc(l.name)} (${esc(l.code)})</option>`).join(''));
            }
        } catch (error) {
            toast(error.message, 'error');
        }
    }
    load();
}

export const refreshEmployeeLookups = () => ['departments', 'positions', 'locations', 'employee-levels'].forEach(invalidate);
