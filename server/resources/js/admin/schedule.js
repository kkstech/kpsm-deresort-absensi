import { api, isAdmin, lookup } from './api';
import { $, $$, confirmDialog, esc, toast } from './ui';

/* ---------- Tanggal ---------- */
const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const DAYS_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

const pad = (n) => String(n).padStart(2, '0');
const keyOf = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseKey = (key) => { const [y, m, d] = key.split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const weekStart = (d) => addDays(d, -((d.getDay() + 6) % 7));
const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const isToday = (d) => keyOf(d) === todayKey();
const longDate = (key) => { const d = parseKey(key); return `${DAYS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`; };

function addMonths(d, n) {
    const lastDay = new Date(d.getFullYear(), d.getMonth() + n + 1, 0).getDate();
    return new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), lastDay));
}

/* ---------- Data ---------- */
let employees = [];
let templates = [];
let entries = [];
const STATUS = { DRAFT: ['warning', 'Draft'], PUBLISHED: ['success', 'Terbit'], IN_PROGRESS: ['blue', 'Berjalan'], COMPLETED: ['neutral', 'Selesai'] };

const kind = (e) => (e.template_type === 'special' ? 'special' : e.sessions.some((s) => s.local_end.includes('+1')) ? 'night' : 'regular');
const summary = (e) => (e.sessions.length > 1 ? `${e.sessions.length} sesi` : `${e.sessions[0].local_start}–${e.sessions[0].local_end.replace(' (+1)', '')}`);
const nameOf = (id) => employees.find((e) => e.id === id)?.name ?? 'Karyawan';
const templateText = (t) => (t.sessions.length > 1 ? `${t.sessions.length} sesi` : `${t.sessions[0].local_start.slice(0, 5).replace(':', '.')}–${t.sessions[0].local_end.slice(0, 5).replace(':', '.')}`);

const state = { view: 'week', anchor: parseKey(todayKey()), filter: 'all', selected: { date: todayKey(), emp: null } };
const personFilter = () => (state.filter === 'all' ? null : Number(state.filter));
const visiblePeople = () => employees.filter((e) => !personFilter() || e.id === personFilter());
const entriesOn = (key, emp) => entries
    .filter((e) => e.work_date === key && (!emp || e.employee_id === emp))
    .sort((a, b) => a.sessions[0].start_at.localeCompare(b.sessions[0].start_at) || nameOf(a.employee_id).localeCompare(nameOf(b.employee_id)));

function visibleRange() {
    if (state.view === 'week') {
        const start = weekStart(state.anchor);
        return [start, addDays(start, 6)];
    }
    return [new Date(state.anchor.getFullYear(), state.anchor.getMonth(), 1), new Date(state.anchor.getFullYear(), state.anchor.getMonth() + 1, 0)];
}

function fetchRange() {
    if (state.view === 'week') return visibleRange();
    const start = weekStart(new Date(state.anchor.getFullYear(), state.anchor.getMonth(), 1));
    return [start, addDays(start, 41)];
}

async function load() {
    const [from, to] = fetchRange().map(keyOf);
    const errorBox = $('#schedule-load-error');
    try {
        entries = (await api('schedule', { query: { from, to } })).data;
        errorBox.hidden = true;
    } catch (error) {
        entries = [];
        errorBox.textContent = error.message;
        errorBox.hidden = false;
    }
    render();
}

/* ---------- Render ---------- */
function rangeLabel([from, to]) {
    if (state.view === 'month') return `${MONTHS[from.getMonth()]} ${from.getFullYear()}`;
    const short = (d) => `${d.getDate()} ${MONTHS[d.getMonth()].slice(0, 3)}`;
    if (from.getMonth() === to.getMonth()) return `${from.getDate()}–${to.getDate()} ${MONTHS[to.getMonth()]} ${to.getFullYear()}`;
    return `${short(from)} – ${short(to)} ${to.getFullYear()}`;
}

function renderToolbar() {
    const range = visibleRange();
    const [from, to] = range.map(keyOf);
    const inRange = entries.filter((e) => e.work_date >= from && e.work_date <= to && (!personFilter() || e.employee_id === personFilter()));
    const drafts = inRange.filter((e) => e.status === 'DRAFT').length;
    $('#range-label').textContent = rangeLabel(range);
    $('#range-count').textContent = `${inRange.length} jadwal${drafts ? `, ${drafts} draft` : ''}${personFilter() ? ` untuk ${nameOf(personFilter())}` : ''}`;
    $$('[data-cal-view]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.calView === state.view)));
    const unit = state.view === 'week' ? 'Minggu' : 'Bulan';
    $('#calendar-prev').setAttribute('aria-label', `${unit} sebelumnya`);
    $('#calendar-next').setAttribute('aria-label', `${unit} berikutnya`);
    const copy = $('#schedule-copy');
    if (copy) copy.hidden = state.view !== 'week';
}

const chip = (e) => `<span class="shift-chip ${kind(e)}${e.status === 'DRAFT' ? ' draft' : ''}"><span>${esc(e.template_name)}</span><small>${esc(summary(e))}</small></span>`;

function weekCell(person, day) {
    const key = keyOf(day);
    const items = entriesOn(key, person.id);
    const selected = state.selected.date === key && state.selected.emp === person.id;
    const what = items.length ? items.map((e) => `${e.template_name} ${STATUS[e.status]?.[1] ?? ''}`).join(', ') : key >= todayKey() && isAdmin ? 'kosong, tambah jadwal' : 'kosong';
    const label = `${person.name}, ${longDate(key)}: ${what}`;
    const classes = `week-cell${selected ? ' selected' : ''}${items.length ? '' : ' is-empty'}`;
    return `<td class="${isToday(day) ? 'today' : ''}" data-label="${DAYS_SHORT[day.getDay()]} ${day.getDate()}"><button type="button" class="${classes}" data-cell data-date="${key}" data-emp="${person.id}" aria-pressed="${selected}" aria-label="${esc(label)}">${items.map(chip).join('') || (isAdmin && key >= todayKey() ? '<span class="cell-add" aria-hidden="true">+</span>' : '')}</button></td>`;
}

function renderWeek() {
    const start = weekStart(state.anchor);
    const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));
    const head = days.map((d) => `<th scope="col" class="${isToday(d) ? 'today' : ''}"${isToday(d) ? ' aria-current="date"' : ''}><span>${DAYS_SHORT[d.getDay()]}</span><b>${d.getDate()}</b></th>`).join('');
    const people = visiblePeople();
    const rows = people.map((p) => `<tr><th scope="row"><strong>${esc(p.name)}</strong><small>${esc(p.dept)}</small></th>${days.map((d) => weekCell(p, d)).join('')}</tr>`).join('');
    $('#calendar-body').innerHTML = people.length
        ? `<div class="week-scroll"><table class="week-table"><thead><tr><th scope="col">Karyawan</th>${head}</tr></thead><tbody>${rows}</tbody></table></div>`
        : '<p class="list-empty calendar-empty">Belum ada karyawan aktif. Tambahkan di menu Karyawan.</p>';
}

function monthCell(day, month) {
    const key = keyOf(day);
    const who = personFilter();
    const items = entriesOn(key, who);
    const text = (e) => (who ? `${e.template_name} ${e.sessions[0].local_start}` : `${nameOf(e.employee_id).split(' ')[0]} · ${e.template_name}`);
    const events = items.slice(0, 2).map((e) => `<span class="calendar-event ${kind(e)}">${esc(text(e))}</span>`).join('')
        + (items.length > 2 ? `<span class="calendar-event more">+${items.length - 2} lainnya</span>` : '');
    const selected = state.selected.date === key;
    const classes = ['calendar-day', day.getMonth() !== month ? 'outside' : '', isToday(day) ? 'today' : '', selected ? 'selected' : ''].filter(Boolean).join(' ');
    const label = `${longDate(key)}, ${items.length ? `${items.length} jadwal` : 'tanpa jadwal'}`;
    return `<button type="button" class="${classes}" data-cell data-date="${key}" aria-pressed="${selected}" aria-label="${label}"><span class="day-number">${day.getDate()}</span><span class="calendar-events">${events}</span>${items.length ? `<span class="day-count" aria-hidden="true">${items.length}</span>` : ''}</button>`;
}

function renderMonth() {
    const year = state.anchor.getFullYear();
    const month = state.anchor.getMonth();
    const gridStart = weekStart(new Date(year, month, 1));
    const weekdays = [1, 2, 3, 4, 5, 6, 0].map((i) => `<div class="calendar-weekday${i === 0 || i === 6 ? ' weekend' : ''}">${DAYS_SHORT[i]}</div>`).join('');
    const days = Array.from({ length: 42 }, (_, i) => monthCell(addDays(gridStart, i), month)).join('');
    $('#calendar-body').innerHTML = `<div class="calendar-grid"><div class="calendar-weekdays" aria-hidden="true">${weekdays}</div><div class="calendar-days">${days}</div></div>`;
}

function panelItem(e) {
    const name = nameOf(e.employee_id);
    const [tone, label] = STATUS[e.status] ?? ['neutral', e.status];
    const remove = isAdmin && e.editable ? `<button type="button" class="text-button danger-text" data-delete="${e.id}" aria-label="Batalkan jadwal ${esc(e.template_name)} ${esc(name)}">Batalkan</button>` : '';
    const locked = e.editable ? '' : '<small>Sudah mulai. Perubahan lewat koreksi.</small>';
    const sessions = e.sessions.map((s) => `<li>${esc(s.local_start)}–${esc(s.local_end)}</li>`).join('');
    return `<article class="selected-shift"><i class="shift-dot ${kind(e)}" aria-hidden="true"></i><div><strong>${esc(name)}</strong><small>${esc(e.template_name)} · ${e.sessions.length} sesi</small><ul class="session-list">${sessions}</ul><div class="shift-actions"><span class="status ${tone}">${label}</span>${remove}</div>${locked}</div></article>`;
}

function renderPanel() {
    const { date, emp } = state.selected;
    const who = emp ?? personFilter();
    const items = entriesOn(date, who);
    $('#selected-person-label').textContent = who ? nameOf(who) : 'Semua karyawan';
    $('#selected-date-label').textContent = longDate(date);
    $('#selected-day-list').innerHTML = items.length
        ? items.map(panelItem).join('')
        : `<div class="selected-empty">Belum ada jadwal${isAdmin ? '. Tambahkan shift untuk tanggal ini.' : '.'}</div>`;
}

function render() {
    renderToolbar();
    if (state.view === 'week') renderWeek();
    else renderMonth();
    renderPanel();
}

function focusCell({ date, emp }) {
    $(`[data-cell][data-date="${date}"]${emp ? `[data-emp="${emp}"]` : ''}`)?.focus();
}

function moveTo(anchor, selected) {
    const before = fetchRange().map(keyOf).join();
    state.anchor = anchor;
    state.selected = selected;
    if (fetchRange().map(keyOf).join() !== before) load();
    else render();
}

/* ---------- Dialog jadwal ---------- */
const modal = $('#schedule-modal');
const errorBox = $('#schedule-error');

function openScheduleModal() {
    $('#schedule-employee').value = state.selected.emp ?? personFilter() ?? '';
    $('#schedule-date').value = state.selected.date;
    errorBox.hidden = true;
    modal.showModal();
}

async function saveSchedule() {
    const body = {
        employee_id: Number($('#schedule-employee').value) || null,
        work_date: $('#schedule-date').value || null,
        shift_template_id: Number($('#schedule-template').value) || null,
    };
    errorBox.hidden = true;
    const submit = $('#schedule-form [type="submit"]');
    submit.disabled = true;
    try {
        await api('schedule', { method: 'POST', body });
    } catch (error) {
        errorBox.textContent = Object.values(error.details ?? {})[0]?.[0] ?? error.message;
        errorBox.hidden = false;
        return;
    } finally {
        submit.disabled = false;
    }
    modal.close();
    if (personFilter() && personFilter() !== body.employee_id) {
        state.filter = 'all';
        $('#person-filter').value = 'all';
    }
    toast(`Jadwal ${nameOf(body.employee_id)} disimpan sebagai draft.`);
    state.anchor = parseKey(body.work_date);
    state.selected = { date: body.work_date, emp: state.view === 'week' ? body.employee_id : personFilter() };
    load();
}

async function runAction(fn) {
    try {
        toast(await fn());
        load();
    } catch (error) {
        toast(error.message);
    }
}

/* ---------- Event ---------- */
$('#person-filter').addEventListener('change', (event) => {
    state.filter = event.target.value;
    state.selected = { date: state.selected.date, emp: null };
    render();
});
$$('[data-cal-view]').forEach((button) => button.addEventListener('click', () => {
    state.view = button.dataset.calView;
    state.anchor = parseKey(state.selected.date);
    state.selected = { date: state.selected.date, emp: personFilter() };
    load();
}));
const step = (dir) => (state.view === 'week' ? addDays(state.anchor, 7 * dir) : addMonths(state.anchor, dir));
$('#calendar-prev').addEventListener('click', () => { const a = step(-1); moveTo(a, { date: keyOf(a), emp: state.selected.emp }); });
$('#calendar-next').addEventListener('click', () => { const a = step(1); moveTo(a, { date: keyOf(a), emp: state.selected.emp }); });
$('#calendar-today').addEventListener('click', () => moveTo(parseKey(todayKey()), { date: todayKey(), emp: state.selected.emp }));

$('#calendar-body').addEventListener('click', (event) => {
    const cell = event.target.closest('[data-cell]');
    if (!cell) return;
    const emp = cell.dataset.emp ? Number(cell.dataset.emp) : personFilter();
    moveTo(parseKey(cell.dataset.date), { date: cell.dataset.date, emp });
    focusCell({ date: cell.dataset.date, emp: cell.dataset.emp });
    if (isAdmin && cell.classList.contains('is-empty') && cell.dataset.emp && cell.dataset.date >= todayKey()) openScheduleModal();
    else if (window.matchMedia('(max-width: 1499px)').matches) $('.selected-day-panel').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});

$('#selected-day-list').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete]');
    if (!button || !await confirmDialog({ title: 'Batalkan jadwal?', message: 'Jadwal ini akan dibatalkan.', confirmLabel: 'Batalkan', danger: true })) return;
    runAction(async () => {
        await api(`schedule/${button.dataset.delete}`, { method: 'DELETE' });
        return 'Jadwal dibatalkan.';
    });
});

$$('[data-open-modal]').forEach((button) => button.addEventListener('click', openScheduleModal));
$('#add-selected-date')?.addEventListener('click', openScheduleModal);
$('#schedule-form').addEventListener('submit', (event) => {
    event.preventDefault();
    saveSchedule();
});

$('#schedule-publish')?.addEventListener('click', async () => {
    const [from, to] = visibleRange().map(keyOf);
    const employee_id = personFilter();
    if (!await confirmDialog({ title: 'Terbitkan jadwal?', message: `Terbitkan semua draft ${rangeLabel(visibleRange())}${employee_id ? ` untuk ${nameOf(employee_id)}` : ''}? Karyawan bisa melihatnya di app.`, confirmLabel: 'Terbitkan' })) return;
    runAction(async () => {
        const { published } = await api('schedule/publish', { method: 'POST', body: { from, to, employee_id } });
        return published ? `${published} jadwal diterbitkan.` : 'Tidak ada draft di rentang ini.';
    });
});

$('#schedule-copy')?.addEventListener('click', async () => {
    const from = keyOf(weekStart(state.anchor));
    const to = keyOf(addDays(weekStart(state.anchor), 7));
    const employee_id = personFilter();
    if (!await confirmDialog({ title: 'Salin jadwal minggu?', message: `Salin jadwal minggu ini ke minggu ${longDate(to)}? Tanggal yang sudah terisi dilewati.`, confirmLabel: 'Salin jadwal' })) return;
    runAction(async () => {
        const { created, skipped } = await api('schedule/copy-week', { method: 'POST', body: { from, to, employee_id } });
        state.anchor = parseKey(to);
        state.selected = { date: to, emp: state.selected.emp };
        return `${created} jadwal disalin${skipped.length ? `, ${skipped.length} dilewati` : ''}.`;
    });
});

/** Pencarian global: pilih karyawan lalu tampilkan jadwalnya. */
export function focusEmployee(query) {
    const q = query.trim().toLowerCase();
    const match = employees.find((e) => e.name.toLowerCase() === q) ?? employees.find((e) => e.name.toLowerCase().includes(q));
    if (!match) return false;
    state.filter = String(match.id);
    state.selected = { date: state.selected.date, emp: null };
    $('#person-filter').value = match.id;
    render();
    return true;
}

let ready;
export function initSchedule() {
    ready ??= Promise.all([api('employees', { query: { status: 'active' } }), lookup('templates')]).then(([emp, tpl]) => {
        employees = emp.data.map((e) => ({ id: e.id, name: e.name, dept: e.department?.name ?? '' }));
        templates = tpl;
        const options = employees.map((e) => `<option value="${e.id}">${esc(e.name)}</option>`).join('');
        $('#person-filter').innerHTML = `<option value="all">Semua karyawan</option>${options}`;
        $('#schedule-employee').innerHTML = `<option value="">Pilih karyawan</option>${options}`;
        $('#schedule-template').innerHTML = templates.map((t) => `<option value="${t.id}">${esc(t.name)} · ${esc(templateText(t))}</option>`).join('');
        $('#employee-list').innerHTML = employees.map((e) => `<option value="${esc(e.name)}"></option>`).join('');
    }).catch((error) => { ready = null; throw error; });
    return ready;
}

export async function showSchedule() {
    try {
        await initSchedule();
    } catch (error) {
        $('#schedule-load-error').textContent = error.message;
        $('#schedule-load-error').hidden = false;
        return;
    }
    load();
}
