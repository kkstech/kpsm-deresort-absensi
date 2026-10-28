import { api, lookup } from './api';
import { $, $$, esc } from './ui';

const form = $('#allowance-filters');
const table = $('#allowance-table');
const searchInput = $('#allowance-search');
const statusSelect = $('#allowance-status-filter');
const deptSelect = $('#allowance-dept-filter');
const levelSelect = $('#allowance-level-filter');
const presetButtons = $$('.preset-chip');

const money = (amount) => `Rp ${Number(amount ?? 0).toLocaleString('id-ID')}`;

const state = {
    ELIGIBLE: ['success', 'Memenuhi syarat'],
    PENDING_VERIFICATION: ['warning', 'Menunggu verifikasi'],
    NO_VERIFIED_ATTENDANCE: ['neutral', 'Tidak ada menit valid'],
    CONFIGURATION_REQUIRED: ['warning', 'Level/tarif belum diatur'],
};

let allRows = [];
let currentSummary = null;

function formatDateIndo(dateStr) {
    if (!dateStr) return { date: '-', day: '-' };
    try {
        const [y, m, d] = dateStr.split('-');
        const date = new Date(Number(y), Number(m) - 1, Number(d));
        const day = new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(date);
        const formatted = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
        return { date: formatted, day };
    } catch (_) {
        return { date: dateStr, day: '' };
    }
}

function formatDuration(minutes) {
    const m = Number(minutes ?? 0);
    if (m <= 0) return '<span style="color:var(--muted)">0 mnt</span>';
    const hours = Math.floor(m / 60);
    const remMins = m % 60;
    if (hours > 0 && remMins > 0) return `<b>${m}</b> mnt <small style="color:var(--muted)">(${hours}j ${remMins}m)</small>`;
    if (hours > 0) return `<b>${m}</b> mnt <small style="color:var(--muted)">(${hours} jam)</small>`;
    return `<b>${m}</b> mnt`;
}

function getRange(preset) {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const fmt = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    if (preset === 'today') {
        const today = fmt(d);
        return { from: today, to: today };
    }
    if (preset === 'week') {
        const past = new Date(d);
        past.setDate(d.getDate() - 6);
        return { from: fmt(past), to: fmt(d) };
    }
    if (preset === 'month') {
        const first = new Date(d.getFullYear(), d.getMonth(), 1);
        return { from: fmt(first), to: fmt(d) };
    }
    if (preset === 'last-month') {
        const first = new Date(d.getFullYear(), d.getMonth() - 1, 1);
        const last = new Date(d.getFullYear(), d.getMonth(), 0);
        return { from: fmt(first), to: fmt(last) };
    }
    return { from: `${fmt(d).slice(0, 7)}-01`, to: fmt(d) };
}

async function initDepartments() {
    if (!deptSelect || deptSelect.options.length > 1) return;
    try {
        const depts = await lookup('departments');
        (depts ?? []).forEach((d) => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            deptSelect.appendChild(opt);
        });
    } catch (_) {}
}

async function initLevels() {
    if (!levelSelect || levelSelect.options.length > 2) return;
    try {
        const levels = await lookup('employee-levels');
        (levels ?? []).forEach((l) => {
            const opt = document.createElement('option');
            opt.value = l.id;
            opt.textContent = `${l.name} (${l.code})`;
            levelSelect.appendChild(opt);
        });
    } catch (_) {}
}

function applyFilters() {
    const q = (searchInput?.value ?? '').trim().toLowerCase();
    const status = statusSelect?.value ?? '';
    const deptId = deptSelect?.value ?? '';
    const levelId = levelSelect?.value ?? '';

    return allRows.filter((row) => {
        if (status && row.status !== status) return false;
        if (deptId && String(row.employee?.department_id) !== String(deptId)) return false;
        if (levelId === 'none') {
            const rowLevel = row.level_id ?? row.employee?.level_id;
            if (rowLevel) return false;
        } else if (levelId) {
            const rowLevel = row.level_id ?? row.employee?.level_id;
            if (String(rowLevel) !== String(levelId)) return false;
        }
        if (q) {
            const name = (row.employee?.name ?? '').toLowerCase();
            const code = (row.employee?.code ?? '').toLowerCase();
            const dept = (row.employee?.department?.name ?? '').toLowerCase();
            if (!name.includes(q) && !code.includes(q) && !dept.includes(q)) return false;
        }
        return true;
    });
}

function renderTable() {
    const rows = applyFilters();
    const countEl = $('#allowance-count');

    if (countEl) {
        countEl.textContent = rows.length === allRows.length
            ? `${rows.length} siklus kerja ditemukan`
            : `Menampilkan ${rows.length} dari total ${allRows.length} siklus kerja`;
    }

    if (!rows.length) {
        const emptyMsg = allRows.length
            ? 'Tidak ada data tunjangan yang sesuai dengan filter pencarian.'
            : 'Belum ada data siklus absensi dalam periode tanggal ini.';
        table.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: var(--muted);">
                <div style="font-size: 32px; margin-bottom: 8px;">📊</div>
                <p style="margin: 0; font-size: 14px; font-weight: 600; color: var(--ink);">${esc(emptyMsg)}</p>
                <small style="display: block; margin-top: 4px;">Ubah filter atau rentang tanggal untuk melihat data lainnya.</small>
            </div>
        `;
        return;
    }

    let totalMeal = 0;
    let totalTransport = 0;

    const bodyHtml = rows.map((row) => {
        totalMeal += Number(row.meal_amount ?? 0);
        totalTransport += Number(row.transport_amount ?? 0);

        const [tone, label] = state[row.status] ?? ['neutral', row.status];
        const { date, day } = formatDateIndo(row.work_date);
        const levelName = row.rate_snapshot?.level_name ?? row.employee?.level?.name ?? (row.level_id || row.employee?.level_id ? 'Level diatur' : 'Tanpa level');

        const initial = (row.employee?.name || 'K').split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
        const avatar = row.employee?.avatar_url
            ? `<img src="${esc(row.employee.avatar_url)}" alt="${esc(row.employee.name)}" class="avatar" style="width:32px;height:32px;border-radius:6px;object-fit:cover;margin-right:10px;flex-shrink:0;">`
            : `<span class="avatar" style="width:32px;height:32px;border-radius:6px;display:inline-grid;place-items:center;margin-right:10px;flex-shrink:0;font-size:11px;background:#e2ece4;color:var(--primary);">${esc(initial)}</span>`;

        const rowTotal = Number(row.meal_amount ?? 0) + Number(row.transport_amount ?? 0);

        return `
            <tr>
                <td data-label="Tanggal">
                    <b>${esc(date)}</b>
                    <small style="color:var(--muted);">${esc(day)}</small>
                </td>
                <td data-label="Karyawan">
                    <div style="display:flex;align-items:center;">
                        ${avatar}
                        <div>
                            <b>${esc(row.employee?.name ?? 'Karyawan')}</b>
                            <small style="color:var(--muted);">${esc(row.employee?.code ?? '-')} · ${esc(row.employee?.department?.name ?? 'Tanpa Departemen')} · <span style="display:inline-block;padding:0 5px;border-radius:4px;font-size:10px;font-weight:600;background:var(--subtle,#eef2f6);color:var(--ink,#1e293b);">${esc(levelName)}</span></small>
                        </div>
                    </div>
                </td>
                <td data-label="Shift">
                    <span class="status neutral" style="font-size:11px;font-weight:600;padding:2px 8px;">${esc(row.assignment?.template_name ?? 'Siklus Reguler')}</span>
                </td>
                <td data-label="Durasi Hadir">${formatDuration(row.verified_minutes)}</td>
                <td data-label="Status"><span class="status ${tone}">${label}</span></td>
                <td data-label="Makan" class="number-cell">${money(row.meal_amount)}</td>
                <td data-label="Transport" class="number-cell">${money(row.transport_amount)}</td>
                <td data-label="Total" class="number-cell" style="font-weight:700;color:var(--ink);">${money(rowTotal)}</td>
            </tr>
        `;
    }).join('');

    const grandTotal = totalMeal + totalTransport;

    table.innerHTML = `
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col" style="min-width:110px;">Tanggal</th>
                        <th scope="col" style="min-width:200px;">Karyawan</th>
                        <th scope="col" style="min-width:120px;">Shift</th>
                        <th scope="col" style="min-width:130px;">Durasi Hadir</th>
                        <th scope="col" style="min-width:140px;">Status</th>
                        <th scope="col" class="number-cell" style="min-width:110px;">Uang Makan</th>
                        <th scope="col" class="number-cell" style="min-width:110px;">Transport</th>
                        <th scope="col" class="number-cell" style="min-width:120px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${bodyHtml}
                </tbody>
                <tfoot class="allowance-table-footer">
                    <tr>
                        <td colspan="5" style="text-align:right;font-weight:700;padding-right:16px;">
                            Total (${rows.length} Siklus):
                        </td>
                        <td class="number-cell" style="font-weight:700;">${money(totalMeal)}</td>
                        <td class="number-cell" style="font-weight:700;">${money(totalTransport)}</td>
                        <td class="number-cell" style="font-weight:700;color:var(--primary);font-size:14px;">${money(grandTotal)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;
}

async function load() {
    const error = $('#allowance-error');
    if (error) error.hidden = true;
    table.setAttribute('aria-busy', 'true');

    try {
        await Promise.all([initDepartments(), initLevels()]);
        const fromVal = form.elements.from.value;
        const toVal = form.elements.to.value;

        const { data, summary } = await api('allowances', { query: { from: fromVal, to: toVal } });
        allRows = data ?? [];
        currentSummary = summary ?? {};

        $('#allowance-eligible').textContent = summary?.eligible_cycles ?? 0;
        $('#allowance-pending').textContent = summary?.pending_cycles ?? 0;
        $('#allowance-unconfigured').textContent = summary?.configuration_required ?? 0;

        const totalAmount = Number(summary?.meal_amount ?? 0) + Number(summary?.transport_amount ?? 0);
        $('#allowance-total').textContent = money(totalAmount);

        const subtotalEl = $('#allowance-subtotal');
        if (subtotalEl) {
            subtotalEl.textContent = `Makan: ${money(summary?.meal_amount)} · Transport: ${money(summary?.transport_amount)}`;
        }

        const hintEl = $('#allowance-unconfigured-hint');
        if (hintEl) {
            if (summary?.configuration_required > 0) {
                hintEl.innerHTML = '<a href="#settings/allowances" style="color:var(--danger);font-weight:600;text-decoration:underline;">Atur tarif level di Pengaturan</a>';
            } else {
                hintEl.textContent = 'Semua level & tarif terkonfigurasi';
            }
        }

        renderTable();
    } catch (failure) {
        if (error) {
            error.textContent = failure.message;
            error.hidden = false;
        }
        table.innerHTML = '<p class="list-empty">Rekap tunjangan gagal dimuat.</p>';
    } finally {
        table.removeAttribute('aria-busy');
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
});

presetButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
        presetButtons.forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        const { from, to } = getRange(btn.dataset.preset);
        form.elements.from.value = from;
        form.elements.to.value = to;
        load();
    });
});

let searchDebounce;
searchInput?.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(renderTable, 200);
});

statusSelect?.addEventListener('change', renderTable);
deptSelect?.addEventListener('change', renderTable);
levelSelect?.addEventListener('change', renderTable);

const defaultRange = getRange('month');
if (form.elements.from) form.elements.from.value = defaultRange.from;
if (form.elements.to) form.elements.to.value = defaultRange.to;

export async function showAllowances() {
    await load();
}
