@php($siteSettings = \App\Models\SiteSetting::current())
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $siteSettings->site_name }}</title>
    @if ($siteSettings->favicon_url)
        <link rel="icon" href="{{ $siteSettings->favicon_url }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php($me = auth()->user())
<body data-role="{{ $me->role }}" data-user-id="{{ $me->id }}" data-today="{{ \App\Support\WorkTime::today() }}">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
        <symbol id="i-dashboard" viewBox="0 0 24 24"><path d="M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><path d="M4 6h16v14H4zM4 10h16M8 3v6M16 3v6"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 7"/></symbol>
        <symbol id="i-inbox" viewBox="0 0 24 24"><path d="M4 13l3-8h10l3 8v6H4zM4 13h5l1 2h4l1-2h5"/></symbol>
        <symbol id="i-people" viewBox="0 0 24 24"><path d="M9 11a4 4 0 100-8 4 4 0 000 8zM2 21v-2a5 5 0 015-5h4a5 5 0 015 5v2M17 4a4 4 0 010 7M22 21v-2a5 5 0 00-3-4.6"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7"/></symbol>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h10M18 7h2M4 17h2M10 17h10M16 4v6M8 14v6"/></symbol>
        <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 17v-6a6 6 0 0112 0v6l2 2H4zM10 21h4"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path d="M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15zM16 16l5 5"/></symbol>
    </svg>

    <a class="skip-link" href="#main-content">Lewati ke konten</a>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
            <div class="brand">
                <img class="brand-logo" data-site-logo src="{{ $siteSettings->logo_url ?? '' }}" alt="" @if (! $siteSettings->logo_url) hidden @endif>
                <span><strong data-site-name>{{ $siteSettings->site_name }}</strong></span>
            </div>
            <nav class="nav-list">
                <button type="button" class="nav-item active" data-view="dashboard" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#i-dashboard"/></svg>Dashboard</button>
                <button type="button" class="nav-item" data-view="schedule"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg>Jadwal &amp; Shift</button>
                <button type="button" class="nav-item" data-view="attendance"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>Monitoring Absensi</button>
                <button type="button" class="nav-item" data-view="requests"><svg class="icon" aria-hidden="true"><use href="#i-inbox"/></svg>Persetujuan</button>
                <button type="button" class="nav-item" data-view="employees"><svg class="icon" aria-hidden="true"><use href="#i-people"/></svg>Karyawan</button>
                <button type="button" class="nav-item" data-view="reports"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg>Laporan</button>
            </nav>
            <div class="sidebar-bottom">
                <button type="button" class="nav-item" data-view="settings" data-requires="web_admin"><svg class="icon" aria-hidden="true"><use href="#i-sliders"/></svg>Pengaturan</button>
                <div class="operator">
                    <span class="avatar">{{ \Illuminate\Support\Str::of($me->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
                    <span><strong>{{ $me->name }}</strong><small>{{ ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'supervisor' => 'Supervisor'][$me->role] ?? $me->role }}</small></span>
                </div>
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-item logout-button">Keluar</button></form>
            </div>
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <button type="button" class="icon-button menu-button" id="menu-button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="sidebar">☰</button>
                <div class="search-wrap">
                    <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                    <label class="sr-only" for="global-search">Cari karyawan</label>
                    <input id="global-search" type="search" list="employee-list" placeholder="Cari karyawan, lihat jadwalnya" autocomplete="off">
                    <datalist id="employee-list"></datalist>
                    <kbd>⌘ K</kbd>
                </div>
                <div class="top-actions">
                    <span class="server-state"><i></i>Server lokal aktif</span>
                    <button type="button" class="icon-button notification-button" data-view-link="requests" aria-label="Buka persetujuan"><svg class="icon" aria-hidden="true"><use href="#i-bell"/></svg></button>
                </div>
            </header>

            <main id="main-content" tabindex="-1">
                <section class="view active" data-view-panel="dashboard">
                    <div class="page-heading">
                        <div><p data-site-name>{{ $siteSettings->site_name }}</p><h1>Dashboard</h1><span id="dashboard-finding">Ringkasan kehadiran dan sesi yang perlu ditinjau.</span></div>
                    </div>
                    <form class="attendance-filters panel" id="dashboard-filters">
                        <label>Dari<input type="date" name="from" required></label>
                        <label>Sampai<input type="date" name="to" required></label>
                        <label>Departemen<select name="department_id"><option value="">Semua departemen</option></select></label>
                        <label>Karyawan<select name="employee_id"><option value="">Semua karyawan</option></select></label>
                        <button type="submit" class="secondary-button">Terapkan</button>
                    </form>
                    <p class="form-error" id="dashboard-error" role="alert" hidden></p>
                    <section class="metric-grid" aria-label="Ringkasan kehadiran" aria-live="polite">
                        <div class="metric-card"><span>Karyawan dijadwalkan</span><strong id="metric-employees">—</strong><small>unik dalam rentang</small></div>
                        <div class="metric-card"><span>Sesi check-in</span><strong id="metric-checkins">—</strong><small id="metric-sessions">— sesi dijadwalkan</small></div>
                        <div class="metric-card warning"><span>Perlu review</span><strong id="metric-review">—</strong><small>sesi atau event ditandai</small></div>
                        <div class="metric-card"><span>Menit tervalidasi</span><strong id="metric-minutes">—</strong><small>jam kerja yang dihitung</small></div>
                    </section>
                    <section class="panel attendance-panel" aria-labelledby="activity-title">
                        <div class="panel-heading"><div><h2 id="activity-title">Aktivitas absensi terbaru</h2><p>Perubahan dari event absensi yang tercatat.</p></div><button type="button" class="text-button" data-view-link="attendance">Buka monitoring</button></div>
                        <div id="dashboard-activity" aria-live="polite"><p class="list-empty">Memuat data…</p></div>
                    </section>
                </section>

                <section class="view schedule-view" data-view-panel="schedule">
                    <div class="page-heading">
                        <div><p>Perencanaan shift</p><h1>Jadwal &amp; Shift</h1><span>Pilih tampilan mingguan atau bulanan. Pilih karyawan untuk melihat jadwalnya saja.</span></div>
                        <div class="heading-actions" data-requires="admin">
                            <button type="button" class="secondary-button" id="schedule-copy">Salin ke minggu depan</button>
                            <button type="button" class="secondary-button" id="schedule-publish">Terbitkan draft</button>
                            <button type="button" class="primary-button" data-open-modal="schedule-modal">+ Buat jadwal</button>
                        </div>
                    </div>
                    <p class="form-error" id="schedule-load-error" role="alert" hidden></p>
                    <div class="calendar-layout">
                        <section class="panel calendar-panel" aria-label="Jadwal karyawan">
                            <div class="schedule-toolbar">
                                <div class="range"><h2 id="range-label" aria-live="polite"></h2><span id="range-count"></span></div>
                                <div class="toolbar-controls">
                                    <label class="person-select"><span>Karyawan</span><select id="person-filter"></select></label>
                                    <div class="view-toggle" role="group" aria-label="Tampilan kalender">
                                        <button type="button" data-cal-view="week" aria-pressed="true">Mingguan</button>
                                        <button type="button" data-cal-view="month" aria-pressed="false">Bulanan</button>
                                    </div>
                                    <div class="calendar-controls">
                                        <button type="button" class="secondary-button today-button" id="calendar-today">Hari ini</button>
                                        <button type="button" class="icon-button calendar-nav" id="calendar-prev" aria-label="Minggu sebelumnya">‹</button>
                                        <button type="button" class="icon-button calendar-nav" id="calendar-next" aria-label="Minggu berikutnya">›</button>
                                    </div>
                                </div>
                            </div>
                            <div id="calendar-body"></div>
                            <div class="calendar-legend"><span><i class="legend-dot regular"></i>Reguler</span><span><i class="legend-dot special"></i>Khusus</span><span><i class="legend-dot night"></i>Malam</span></div>
                        </section>
                        <aside class="panel selected-day-panel" aria-live="polite">
                            <div class="selected-day-heading"><div><span id="selected-person-label"></span><h2 id="selected-date-label"></h2></div></div>
                            <div id="selected-day-list" class="selected-day-list"></div>
                            <button type="button" class="secondary-button selected-add-button" id="add-selected-date" data-requires="admin">+ Tambah jadwal</button>
                        </aside>
                    </div>
                    <p class="calendar-help">Jadwal bisa diubah sampai sesi pertama mulai. Setelah itu, ubah lewat koreksi.</p>
                </section>
                <section class="view attendance-view" data-view-panel="attendance">
                    <div class="page-heading"><div><p data-site-name>{{ $siteSettings->site_name }}</p><h1>Monitoring Absensi</h1><span>Catatan per sesi, termasuk event offline dan checkout provisional.</span></div></div>
                    <form class="attendance-filters panel" id="monitoring-filters">
                        <label>Dari<input type="date" name="from" required></label>
                        <label>Sampai<input type="date" name="to" required></label>
                        <label>Departemen<select name="department_id"><option value="">Semua departemen</option></select></label>
                        <label>Karyawan<select name="employee_id"><option value="">Semua karyawan</option></select></label>
                        <button type="submit" class="secondary-button">Terapkan</button>
                    </form>
                    <p class="form-error" id="monitoring-error" role="alert" hidden></p>
                    <section class="panel monitoring-panel" aria-label="Daftar absensi"><div class="panel-heading"><div><h2>Absensi per sesi</h2><p id="monitoring-count" aria-live="polite">Memuat data…</p></div></div><div id="monitoring-table" aria-live="polite"><p class="list-empty">Memuat data…</p></div><div id="monitoring-pagination" class="table-pagination"></div></section>
                </section>
                <section class="view workflow-view" data-view-panel="requests">
                    <div class="page-heading"><div><p data-site-name>{{ $siteSettings->site_name }}</p><h1>Persetujuan</h1><span>Permintaan izin dan koreksi menunggu keputusan.</span></div><button type="button" class="secondary-button" id="workflow-refresh">Muat ulang</button></div>
                    <p class="form-error" id="workflow-error" role="alert" hidden></p>
                    <section class="panel" id="direct-late-panel" data-requires="super_admin">
                        <div class="panel-heading"><div><h2>Izin terlambat langsung</h2><p>Hanya Super Admin. Timestamp absensi tetap disimpan apa adanya.</p></div></div>
                        <form class="attendance-filters" id="direct-late-form">
                            <label>Tanggal kerja<input type="date" name="work_date" required></label>
                            <label>Sesi<select name="shift_session_id" required><option value="">Pilih sesi</option></select></label>
                            <label>Target jam hadir<input type="datetime-local" name="allowed_until"></label>
                            <label>Durasi izin (menit)<input type="number" name="allowed_minutes" min="0" max="1440"></label>
                            <label class="workflow-reason">Alasan<textarea name="reason" rows="2" maxlength="2000" required></textarea></label>
                            <label>Bukti (JPG, PNG, PDF)<input type="file" name="evidence" accept="image/jpeg,image/png,application/pdf"></label>
                            <button type="submit" class="secondary-button">Tetapkan izin</button>
                        </form>
                    </section>
                    <div class="workflow-grid">
                        <section class="panel"><div class="panel-heading"><div><h2>Izin terlambat</h2><p id="late-permission-count">Memuat…</p></div></div><div id="late-permission-queue" aria-live="polite"><p class="list-empty">Memuat data…</p></div></section>
                        <section class="panel"><div class="panel-heading"><div><h2>Koreksi absensi</h2><p id="correction-count">Memuat…</p></div></div><div id="correction-queue" aria-live="polite"><p class="list-empty">Memuat data…</p></div></section>
                    </div>
                </section>
                <section class="view" data-view-panel="employees">
                    <div class="page-heading">
                        <div><h1>Karyawan</h1><span>Data pegawai, akun app, dan status aktif.</span></div>
                        <button type="button" class="primary-button" id="employee-add" data-requires="admin">+ Tambah karyawan</button>
                    </div>
                    <div class="panel">
                        <div class="toolbar">
                            <label class="sr-only" for="employee-q">Cari nama atau kode</label>
                            <input id="employee-q" type="search" placeholder="Cari nama atau kode" autocomplete="off">
                            <label class="sr-only" for="employee-department">Departemen</label>
                            <select id="employee-department"><option value="">Semua departemen</option></select>
                            <label class="sr-only" for="employee-status">Status</label>
                            <select id="employee-status"><option value="active">Aktif</option><option value="inactive">Nonaktif</option><option value="">Semua status</option></select>
                        </div>
                        <div id="employee-table" aria-live="polite"></div>
                    </div>
                </section>
                <section class="view placeholder-view" data-view-panel="reports"><h1>Laporan</h1><p>Rekap kehadiran, lembur, tunjangan, dan periode.</p><div class="empty-state"><strong>Belum tersedia</strong><span>Laporan dan ekspor akan ditambahkan setelah perhitungan kehadiran selesai.</span></div></section>
                <section class="view" data-view-panel="settings">
                    <div class="page-heading"><div><h1>Pengaturan</h1><span>Data dasar yang dipakai jadwal dan absensi.</span></div></div>
                    <div class="tabs" role="tablist" aria-label="Bagian pengaturan">
                        <button type="button" role="tab" aria-selected="true" data-settings-tab="general">Umum</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="templates" data-requires="admin">Template shift</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="policies" data-requires="admin">Aturan kehadiran</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="departments" data-requires="admin">Departemen</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="positions" data-requires="admin">Jabatan</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="locations" data-requires="admin">Lokasi kerja</button>
                        <button type="button" role="tab" aria-selected="false" data-settings-tab="users" data-requires="super_admin">Akun admin</button>
                    </div>
                    <div class="panel">
                        <div class="panel-heading"><div><h2 id="settings-title"></h2><p id="settings-hint"></p></div><button type="button" class="secondary-button" id="settings-add">+ Tambah</button></div>
                        <form id="site-settings-form" class="site-settings-form" hidden>
                            <p class="form-error" id="site-settings-error" role="alert" hidden></p>
                            <div class="site-settings-layout">
                                <div class="site-settings-fields">
                                    <section class="site-settings-section">
                                        <h3>Identitas website</h3>
                                        <label>Nama website<input name="site_name" maxlength="120" required><small class="field-error" id="site-site-name-error" hidden></small></label>
                                        <label>Logo untuk halaman login dan sidebar<input id="site-logo-file" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><small class="field-hint">PNG, JPG, atau WebP · Maksimal 2 MB</small></label>
                                        <div class="site-asset-preview"><img id="site-logo-preview" alt="Pratinjau logo" hidden><span id="site-logo-empty">Belum ada logo</span><label class="check-field" id="site-logo-remove-wrap" hidden><input name="remove_logo" type="checkbox" value="1"><span>Hapus logo tersimpan</span></label><small class="field-error" id="site-logo-error" hidden></small></div>
                                        <label>Favicon tab browser<input id="site-favicon-file" name="favicon" type="file" accept="image/png,.ico"><small class="field-hint">PNG atau ICO · Maksimal 2 MB</small></label>
                                        <div class="site-asset-preview favicon-preview"><img id="site-favicon-preview" alt="Pratinjau favicon" hidden><span id="site-favicon-empty">Belum ada favicon</span><label class="check-field" id="site-favicon-remove-wrap" hidden><input name="remove_favicon" type="checkbox" value="1"><span>Hapus favicon tersimpan</span></label><small class="field-error" id="site-favicon-error" hidden></small></div>
                                    </section>
                                    <section class="site-settings-section">
                                        <h3>Kontak website</h3>
                                        <label>Email<input name="contact_email" type="email" maxlength="255" autocomplete="email"><small class="field-error" id="site-contact-email-error" hidden></small></label>
                                        <label>Telepon / WhatsApp<input name="contact_phone" type="tel" maxlength="40" autocomplete="tel"><small class="field-error" id="site-contact-phone-error" hidden></small></label>
                                        <label>Alamat<textarea name="contact_address" rows="3" maxlength="500" autocomplete="street-address"></textarea><small class="field-error" id="site-contact-address-error" hidden></small></label>
                                        <p class="field-hint">Kontak yang diisi akan tampil di halaman login.</p>
                                    </section>
                                </div>
                                <aside class="site-preview" aria-live="polite" aria-label="Pratinjau halaman login">
                                    <h3>Pratinjau login</h3>
                                    <div class="site-preview-card">
                                        <img id="site-preview-logo" alt="" hidden>
                                        <span id="site-preview-globe" class="auth-mark" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><ellipse cx="12" cy="12" rx="4" ry="9"></ellipse><path d="M3.5 9h17M3.5 15h17"></path></svg>
                                        </span>
                                        <strong id="site-preview-name">Deresort Attendance</strong>
                                        <div id="site-preview-contact" class="site-preview-contact"></div>
                                    </div>
                                </aside>
                            </div>
                            <div class="site-settings-actions"><button type="submit" class="primary-button" id="site-settings-save">Simpan perubahan</button></div>
                        </form>
                        <div id="settings-table" aria-live="polite"></div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <dialog class="app-dialog" id="schedule-modal" aria-labelledby="schedule-modal-title">
        <form method="dialog" id="schedule-form">
            <div class="modal-heading"><div><h2 id="schedule-modal-title">Buat jadwal</h2><p>Jadwal baru tersimpan sebagai draft. Karyawan melihatnya setelah diterbitkan.</p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body">
                <label>Karyawan<select id="schedule-employee" required></select></label>
                <label>Tanggal kerja<input type="date" id="schedule-date" required></label>
                <label>Template shift<select id="schedule-template" required></select></label>
                <p class="form-error" id="schedule-error" role="alert" hidden></p>
            </div>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button">Simpan draft</button></div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="form-modal" aria-labelledby="form-modal-title">
        <form method="dialog" id="form-modal-form" novalidate>
            <div class="modal-heading"><div><h2 id="form-modal-title"></h2><p id="form-modal-hint"></p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body"><div id="form-modal-fields" class="form-fields"></div><p class="form-error" id="form-modal-error" role="alert" hidden></p></div>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button" id="form-modal-submit">Simpan</button></div>
        </form>
    </dialog>
    <dialog class="app-dialog" id="workflow-modal" aria-labelledby="workflow-modal-title"><form id="workflow-modal-form"><div class="modal-heading"><div><h2 id="workflow-modal-title"></h2><p id="workflow-modal-hint"></p></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div><div class="modal-body"><div id="workflow-modal-fields" class="form-fields"></div><p class="form-error" id="workflow-modal-error" role="alert" hidden></p></div><div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Batal</button><button type="submit" class="primary-button" id="workflow-modal-submit">Simpan</button></div></form></dialog>
    <dialog class="app-dialog confirm-dialog" id="confirm-modal" aria-labelledby="confirm-modal-title" aria-describedby="confirm-modal-message">
        <form method="dialog">
            <div class="modal-heading"><div><h2 id="confirm-modal-title">Konfirmasi</h2></div><button type="button" class="icon-button" data-close-modal aria-label="Tutup dialog">×</button></div>
            <div class="modal-body"><p id="confirm-modal-message"></p></div>
            <div class="modal-actions"><button type="submit" class="secondary-button" value="cancel" autofocus>Batal</button><button type="submit" class="primary-button" id="confirm-modal-submit" value="confirm">Lanjutkan</button></div>
        </form>
    </dialog>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
