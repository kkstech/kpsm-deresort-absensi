import { api } from './api';
import { $, esc } from './ui';

const form = $('#allowance-filters');
const table = $('#allowance-table');
const money = (amount) => `Rp ${Number(amount ?? 0).toLocaleString('id-ID')}`;
const state = {
    ELIGIBLE: ['success', 'Memenuhi syarat'],
    PENDING_VERIFICATION: ['warning', 'Menunggu verifikasi'],
    NO_VERIFIED_ATTENDANCE: ['neutral', 'Tidak ada menit valid'],
    CONFIGURATION_REQUIRED: ['warning', 'Level/tarif belum diatur'],
};

function currentRange() {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
    return { from: `${today.slice(0, 7)}-01`, to: today };
}

async function load() {
    const error = $('#allowance-error');
    error.hidden = true;
    table.setAttribute('aria-busy', 'true');
    try {
        const { data, summary } = await api('allowances', { query: { from: form.elements.from.value, to: form.elements.to.value } });
        $('#allowance-eligible').textContent = summary.eligible_cycles;
        $('#allowance-pending').textContent = summary.pending_cycles;
        $('#allowance-unconfigured').textContent = summary.configuration_required;
        $('#allowance-total').textContent = money(summary.meal_amount + summary.transport_amount);
        $('#allowance-count').textContent = `${data.length} siklus`;
        table.innerHTML = data.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Shift</th><th>Menit terverifikasi</th><th>Status</th><th>Makan</th><th>Transport</th></tr></thead><tbody>${data.map((row) => {
            const [tone, label] = state[row.status] ?? ['neutral', row.status];
            return `<tr><td data-label="Tanggal">${esc(row.work_date)}</td><td data-label="Karyawan"><b>${esc(row.employee?.name ?? 'Karyawan')}</b><small>${esc(row.employee?.department?.name ?? '')}</small></td><td data-label="Shift">${esc(row.assignment?.template_name ?? 'Siklus')}</td><td data-label="Menit">${Number(row.verified_minutes ?? 0)}</td><td data-label="Status"><span class="status ${tone}">${label}</span></td><td data-label="Makan">${money(row.meal_amount)}</td><td data-label="Transport">${money(row.transport_amount)}</td></tr>`;
        }).join('')}</tbody></table></div>` : '<p class="list-empty">Belum ada siklus absensi dalam periode ini.</p>';
    } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
        table.innerHTML = '<p class="list-empty">Rekap tunjangan gagal dimuat.</p>';
    } finally {
        table.removeAttribute('aria-busy');
    }
}

form.addEventListener('submit', (event) => { event.preventDefault(); load(); });

const range = currentRange();
form.elements.from.value = range.from;
form.elements.to.value = range.to;

export async function showAllowances() {
    await load();
}
