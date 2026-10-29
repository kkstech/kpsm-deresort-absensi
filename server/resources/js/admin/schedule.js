import { api, isAdmin, lookup } from './api';
import { $, $$, confirmDialog, esc, icon, toast } from './ui';

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

const savedCalView = (() => {
    try { return localStorage.getItem('deresort_cal_view'); } catch (_) { return null; }
})();
const state = { view: ['week', 'month'].includes(savedCalView) ? savedCalView : 'week', anchor: parseKey(todayKey()), filter: 'all', selected: { date: todayKey(), emp: null } };
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
    if (copy) {
        copy.hidden = false;
        copy.textContent = state.view === 'week' ? 'Salin ke minggu depan' : 'Salin ke bulan depan';
    }
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
    const edit = isAdmin && e.sessions_editable ? `<button type="button" class="action-btn" data-edit-sessions="${e.id}" title="Atur sesi shift" aria-label="Atur sesi ${esc(e.template_name)} ${esc(name)}">${icon('edit')}</button>` : '';
    const swap = isAdmin && e.editable ? `<button type="button" class="action-btn" data-swap="${e.id}" title="Tukar jadwal" aria-label="Tukar jadwal ${esc(e.template_name)} ${esc(name)}">${icon('swap')}</button>` : '';
    const remove = isAdmin && e.editable ? `<button type="button" class="action-btn danger" data-delete="${e.id}" title="Batalkan jadwal" aria-label="Batalkan jadwal ${esc(e.template_name)} ${esc(name)}">${icon('trash')}</button>` : '';
    const locked = e.editable || e.sessions_editable ? '' : '<small>Jadwal sudah selesai.</small>';
    const sessions = e.sessions.map((s) => `<li>${esc(s.local_start)}–${esc(s.local_end)}</li>`).join('');
    return `<article class="selected-shift"><i class="shift-dot ${kind(e)}" aria-hidden="true"></i><div><strong>${esc(name)}</strong><small>${esc(e.template_name)} · ${e.sessions.length} sesi</small><ul class="session-list">${sessions}</ul><div class="shift-actions"><span class="status ${tone}">${label}</span><div class="shift-action-btns">${edit}${swap}${remove}</div></div>${locked}</div></article>`;
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
let editingSchedule = null;

function scheduleSessionRow(session = {}, index = 0) {
    const locked = editingSchedule && new Date(session.end_at).getTime() <= Date.now();
    return `<div class="schedule-session-card schedule-session-row ${locked ? 'session-locked' : ''}">
        <input type="hidden" data-s="id" value="${esc(session.id ?? '')}">
        <div class="session-card-header">
            <div class="session-title-wrap">
                <span class="session-num-badge">Sesi ${index + 1}</span>
                ${locked ? '<span class="session-status-badge locked">Terkunci (Selesai)</span>' : '<span class="session-status-badge editable">Dapat Diedit</span>'}
            </div>
            ${locked ? '' : `<button type="button" class="action-btn danger session-remove-btn" data-remove-session aria-label="Hapus sesi ${index + 1}" title="Hapus sesi ini">${icon('trash', 14)}</button>`}
        </div>
        <div class="session-fields-grid">
            <label class="session-field">
                <span>Tanggal mulai</span>
                <input type="date" data-s="start_date" value="${esc(session.local_start_date ?? $('#schedule-date').value)}" required${locked ? ' readonly' : ''}>
            </label>
            <label class="session-field">
                <span>Jam mulai</span>
                <input type="time" data-s="start_time" value="${esc(session.local_start_time ?? '')}" required${locked ? ' readonly' : ''}>
            </label>
            <label class="session-field">
                <span>Tanggal selesai</span>
                <input type="date" data-s="end_date" value="${esc(session.local_end_date ?? $('#schedule-date').value)}" required${locked ? ' readonly' : ''}>
            </label>
            <label class="session-field">
                <span>Jam selesai</span>
                <input type="time" data-s="end_time" value="${esc(session.local_end_time ?? '')}" required${locked ? ' readonly' : ''}>
            </label>
            <label class="session-field field-break">
                <span>Istirahat (menit)</span>
                <input type="number" data-s="unpaid_break_minutes" min="0" max="720" value="${esc(session.unpaid_break_minutes ?? 0)}"${locked ? ' readonly' : ''}>
            </label>
        </div>
        ${locked ? '<p class="session-locked-notice">Sesi ini sudah berjalan atau selesai, sehingga jamnya dikunci untuk melindungi riwayat absensi.</p>' : ''}
    </div>`;
}

function setScheduleMode(mode) {
    const custom = mode === 'custom';
    $('#schedule-template-wrap').hidden = custom;
    $('#schedule-custom-wrap').hidden = !custom;
    $('#schedule-template').required = !custom;
}

function fillScheduleSessions(sessions = [{}, {}]) {
    $('#schedule-session-rows').innerHTML = sessions.map(scheduleSessionRow).join('');
    $('#schedule-add-session').hidden = sessions.length >= 4;
}

function openScheduleModal() {
    editingSchedule = null;
    $('#schedule-modal-title').textContent = 'Buat jadwal';
    $('#schedule-form').querySelector('.modal-heading p').textContent = 'Jadwal baru tersimpan sebagai draft. Karyawan melihatnya setelah diterbitkan.';
    $('#schedule-form [type="submit"]').textContent = 'Simpan draft';
    $('#schedule-employee').value = state.selected.emp ?? personFilter() ?? '';
    $('#schedule-employee').disabled = false;
    $('#schedule-date').value = state.selected.date;
    $('#schedule-date').disabled = false;
    $('#schedule-mode').value = 'template';
    $('#schedule-name').value = 'Shift khusus';
    $('#schedule-reason-wrap').hidden = true;
    $('#schedule-reason').value = '';
    fillScheduleSessions();
    setScheduleMode('template');
    errorBox.hidden = true;
    modal.showModal();
}

function openScheduleEdit(assignment) {
    editingSchedule = assignment;
    const isDraft = assignment.status === 'DRAFT';
    $('#schedule-modal-title').textContent = isDraft
        ? `Edit jadwal draft · ${assignment.template_name}`
        : `Atur sesi · ${assignment.template_name}`;
    $('#schedule-form').querySelector('.modal-heading p').textContent = isDraft
        ? 'Sesuaikan jam kerja, sesi shift, atau durasi istirahat sebelum jadwal diterbitkan ke karyawan.'
        : 'Sesi berjalan dan yang sudah selesai tetap tercatat; sesi mendatang bisa disesuaikan.';
    $('#schedule-form [type="submit"]').textContent = 'Simpan perubahan';
    $('#schedule-employee').value = assignment.employee_id;
    $('#schedule-employee').disabled = true;
    $('#schedule-date').value = assignment.work_date;
    $('#schedule-date').disabled = true;
    $('#schedule-mode').value = 'custom';
    $('#schedule-name').value = assignment.template_name;
    $('#schedule-reason-wrap').hidden = isDraft;
    $('#schedule-reason').required = !isDraft;
    $('#schedule-reason').value = isDraft ? 'Penyesuaian jadwal draft' : '';
    fillScheduleSessions(assignment.sessions);
    setScheduleMode('custom');
    errorBox.hidden = true;
    modal.showModal();
}

function collectScheduleSessions() {
    return $$('.schedule-session-row', $('#schedule-session-rows')).map((row, index) => {
        const value = (name) => $('[data-s="' + name + '"]', row).value;
        return {
            ...(value('id') ? { id: Number(value('id')) } : {}),
            seq: index + 1,
            start_date: value('start_date'),
            start_time: value('start_time'),
            end_date: value('end_date'),
            end_time: value('end_time'),
            unpaid_break_minutes: Number(value('unpaid_break_minutes') || 0),
        };
    });
}

async function saveSchedule() {
    const custom = $('#schedule-mode').value === 'custom';
    const body = {
        employee_id: Number($('#schedule-employee').value) || null,
        work_date: $('#schedule-date').value || null,
        ...(custom ? {
            name: $('#schedule-name').value.trim() || 'Shift khusus',
            sessions: collectScheduleSessions(),
            ...(editingSchedule ? { expected_revision: editingSchedule.revision, reason: $('#schedule-reason').value.trim() } : {}),
        } : { shift_template_id: Number($('#schedule-template').value) || null }),
    };
    errorBox.hidden = true;
    const submit = $('#schedule-form [type="submit"]');
    submit.disabled = true;
    try {
        if (editingSchedule && custom) await api(`schedule/${editingSchedule.id}/sessions`, { method: 'PATCH', body });
        else await api('schedule', { method: 'POST', body });
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
    toast(editingSchedule ? 'Sesi jadwal diperbarui.' : `Jadwal ${nameOf(body.employee_id)} disimpan sebagai draft.`);
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

/* ---------- Penugasan Massal ---------- */
const bulkModal = $('#schedule-bulk-modal');
const bulkError = $('#bulk-modal-error');

function updateBulkCounter() {
    const checked = $$('input[name="bulk_emp_id"]:checked', $('#bulk-emp-list')).length;
    const counter = $('#bulk-emp-counter');
    if (counter) counter.textContent = `${checked} dipilih`;
}

function renderBulkEmployeeList(selectedDept = 'all', searchQuery = '') {
    const container = $('#bulk-emp-list');
    if (!container) return;
    const q = searchQuery.toLowerCase().trim();
    const list = employees.filter((e) => {
        const matchDept = selectedDept === 'all' || e.dept === selectedDept;
        const matchSearch = !q || e.name.toLowerCase().includes(q) || (e.dept && e.dept.toLowerCase().includes(q));
        return matchDept && matchSearch;
    });

    if (!list.length) {
        container.innerHTML = '<p class="list-empty" style="grid-column: 1 / -1; padding: 12px; margin: 0; text-align: center;">Tidak ada karyawan yang cocok.</p>';
        updateBulkCounter();
        return;
    }

    container.innerHTML = list.map((e) => `
        <label class="bulk-emp-item">
            <input type="checkbox" name="bulk_emp_id" value="${e.id}" data-dept="${esc(e.dept)}" checked>
            <div class="bulk-emp-info">
                <strong>${esc(e.name)}</strong>
                <small>${esc(e.dept || 'Tanpa Departemen')}${e.position && e.position !== 'Tanpa Jabatan' ? ` · ${esc(e.position)}` : ''}</small>
            </div>
        </label>
    `).join('');
    updateBulkCounter();
}

function setDayPreset(preset) {
    if (!bulkModal) return;
    $$('.preset-pill', bulkModal).forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.preset === preset);
    });
    const checkboxes = $$('input[name="bulk_day"]', $('#bulk-days-group'));
    checkboxes.forEach((cb) => {
        const val = Number(cb.value);
        if (preset === 'all') cb.checked = true;
        else if (preset === 'weekdays') cb.checked = val >= 1 && val <= 5;
        else if (preset === 'weekend') cb.checked = val === 0 || val === 6;
    });
}

function openBulkModal() {
    if (!bulkModal) return;
    bulkError.hidden = true;

    const start = state.view === 'week' ? keyOf(weekStart(state.anchor)) : todayKey();
    const end = keyOf(addDays(parseKey(start), 6));
    $('#bulk-start-date').value = start;
    $('#bulk-end-date').value = end;

    const depts = [...new Set(employees.map((e) => e.dept).filter(Boolean))].sort();
    $('#bulk-dept-filter').innerHTML = '<option value="all">Semua Departemen</option>' + depts.map((d) => `<option value="${esc(d)}">${esc(d)}</option>`).join('');
    $('#bulk-dept-filter').value = 'all';
    $('#bulk-emp-search').value = '';

    $('#bulk-template-select').innerHTML = templates.map((t) => `<option value="${t.id}">${esc(t.name)} · ${esc(templateText(t))}</option>`).join('');

    setDayPreset('weekdays');
    $('#bulk-overwrite-checkbox').checked = false;

    renderBulkEmployeeList();
    bulkModal.showModal();
}

async function submitBulkSchedule(event) {
    event.preventDefault();
    bulkError.hidden = true;

    const employee_ids = $$('input[name="bulk_emp_id"]:checked', $('#bulk-emp-list')).map((cb) => Number(cb.value));
    if (!employee_ids.length) {
        bulkError.textContent = 'Pilih minimal satu karyawan.';
        bulkError.hidden = false;
        return;
    }

    const from = $('#bulk-start-date').value;
    const to = $('#bulk-end-date').value;
    if (!from || !to) {
        bulkError.textContent = 'Isi tanggal mulai dan tanggal selesai.';
        bulkError.hidden = false;
        return;
    }
    if (from > to) {
        bulkError.textContent = 'Tanggal mulai tidak boleh lebih besar dari tanggal selesai.';
        bulkError.hidden = false;
        return;
    }

    const days_of_week = $$('input[name="bulk_day"]:checked', $('#bulk-days-group')).map((cb) => Number(cb.value));
    if (!days_of_week.length) {
        bulkError.textContent = 'Pilih minimal satu hari kerja.';
        bulkError.hidden = false;
        return;
    }

    const shift_template_id = Number($('#bulk-template-select').value);
    if (!shift_template_id) {
        bulkError.textContent = 'Pilih shift reguler.';
        bulkError.hidden = false;
        return;
    }

    const overwrite = $('#bulk-overwrite-checkbox').checked;
    const submitBtn = $('#bulk-submit-btn');
    submitBtn.disabled = true;
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Menugaskan...';

    try {
        const result = await api('schedule/bulk', {
            method: 'POST',
            body: { employee_ids, from, to, days_of_week, shift_template_id, overwrite },
        });
        bulkModal.close();
        const created = result.created ?? 0;
        const skipped = result.skipped?.length ?? 0;
        if (created > 0 && skipped > 0) {
            toast(`Berhasil membuat ${created} jadwal draft (${skipped} dilewati).`);
        } else if (created > 0) {
            toast(`Berhasil membuat ${created} jadwal draft.`);
        } else if (skipped > 0) {
            toast(`Tidak ada jadwal baru dibuat (${skipped} tanggal dilewati).`);
        } else {
            toast('Tidak ada tanggal yang cocok dengan hari yang dipilih.');
        }
        load();
    } catch (error) {
        bulkError.textContent = Object.values(error.details ?? {})[0]?.[0] ?? error.message;
        bulkError.hidden = false;
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
}

/* ---------- Tukar rantai jadwal ---------- */
const swapModal = $('#schedule-swap-modal');
let swapPreview = null;

const scheduleLabel = (item) => `${longDate(item.work_date)} · ${nameOf(item.employee_id)} · ${item.template_name} · ${summary(item)}`;

function swapAssignmentOptions(selected = '') {
    return `<option value="">Pilih jadwal</option>${entries.filter((item) => item.editable).map((item) => `<option value="${item.id}"${String(item.id) === String(selected) ? ' selected' : ''}>${esc(scheduleLabel(item))}</option>`).join('')}`;
}

function swapTargetOptions(sourceId = null, selected = '') {
    const available = sourceId ? employees.filter((item) => item.id !== Number(sourceId)) : employees;
    const groups = new Map();
    for (const item of available) {
        const pos = item.position || 'Tanpa Jabatan';
        if (!groups.has(pos)) groups.set(pos, []);
        groups.get(pos).push(item);
    }
    const sortedPositions = [...groups.keys()].sort((a, b) => {
        if (a === 'Tanpa Jabatan') return 1;
        if (b === 'Tanpa Jabatan') return -1;
        return a.localeCompare(b);
    });
    let html = '<option value="">Pilih karyawan pengganti</option>';
    for (const pos of sortedPositions) {
        const members = groups.get(pos).sort((a, b) => a.name.localeCompare(b.name));
        html += `<optgroup label="${esc(pos)}">`;
        for (const item of members) {
            const isSel = String(item.id) === String(selected) ? ' selected' : '';
            const dept = item.dept ? ` · ${esc(item.dept)}` : '';
            html += `<option value="${item.id}"${isSel}>${esc(item.name)}${dept}</option>`;
        }
        html += `</optgroup>`;
    }
    return html;
}

async function ensureEmployeeSchedules(employeeId) {
    if (!employeeId) return;
    try {
        const from = todayKey();
        const to = keyOf(addDays(parseKey(todayKey()), 35));
        const res = await api('schedule', { query: { from, to, employee_id: employeeId } });
        if (Array.isArray(res?.data)) {
            for (const item of res.data) {
                const idx = entries.findIndex((e) => e.id === item.id);
                if (idx >= 0) entries[idx] = item;
                else entries.push(item);
            }
        }
    } catch {
        // Fallback to loaded entries
    }
}

async function onTargetEmployeeChange() {
    const targetEmpId = Number($('#schedule-swap-target-employee').value);
    const sourceAssId = Number($('#schedule-swap-source-assignment').value);
    const sourceAss = entries.find((e) => e.id === sourceAssId);
    const initiatorEmp = sourceAss ? employees.find((e) => e.id === sourceAss.employee_id) : null;
    const targetEmp = employees.find((e) => e.id === targetEmpId);

    const section = $('#schedule-swap-options-section');
    if (!targetEmpId || !targetEmp) {
        section.hidden = true;
        invalidateSwapPreview();
        return;
    }

    section.hidden = false;
    $('#schedule-swap-options-title').textContent = `Pilihan pertukaran untuk jadwal ${targetEmp.name}`;
    $('#swap-mode-direct-title').textContent = `Tukar langsung dengan ${initiatorEmp ? initiatorEmp.name : 'karyawan pengaju'}`;
    $('#schedule-swap-direct-target-hint').textContent = initiatorEmp
        ? `Jadwal ini akan diberikan kepada ${initiatorEmp.name} (${initiatorEmp.position || initiatorEmp.dept || 'Karyawan Pengaju'}).`
        : '';

    await ensureEmployeeSchedules(targetEmpId);

    const targetSchedules = entries.filter((e) => e.employee_id === targetEmpId && e.editable && e.id !== sourceAssId);
    const directRadio = $('#swap-mode-direct');
    const chainRadio = $('#swap-mode-chain');
    const noneRadio = $('#swap-mode-none');

    if (targetSchedules.length === 0) {
        $('#schedule-swap-options-hint').textContent = `${targetEmp.name} tidak memiliki jadwal aktif lain di periode ini untuk ditukarkan.`;
        directRadio.disabled = true;
        chainRadio.disabled = true;
        noneRadio.checked = true;
    } else {
        $('#schedule-swap-options-hint').textContent = `Pilih perlakuan untuk jadwal milik ${targetEmp.name}.`;
        directRadio.disabled = false;
        chainRadio.disabled = false;
        if (noneRadio.checked && !noneRadio.dataset.userChosen) {
            directRadio.checked = true;
        }

        const sameDateAss = sourceAss ? targetSchedules.find((e) => e.work_date === sourceAss.work_date) : null;
        const defaultAssId = sameDateAss ? sameDateAss.id : targetSchedules[0].id;

        const scheduleOptionsHtml = targetSchedules.map((item) =>
            `<option value="${item.id}"${item.id === defaultAssId ? ' selected' : ''}>${longDate(item.work_date)} · ${esc(item.template_name)} (${esc(summary(item))})</option>`
        ).join('');

        $('#schedule-swap-target-schedule').innerHTML = scheduleOptionsHtml;
        $('#schedule-swap-chain-schedule').innerHTML = scheduleOptionsHtml;
        $('#schedule-swap-chain-target').innerHTML = swapTargetOptions(targetEmpId);
    }
    updateSwapModeUi();
}

function updateSwapModeUi() {
    const mode = $('input[name="swap_mode"]:checked')?.value || 'direct';
    $('#schedule-swap-direct-config').hidden = mode !== 'direct';
    $('#schedule-swap-chain-config').hidden = mode !== 'chain';
    invalidateSwapPreview();
}

function addExtraSwapRow() {
    const row = document.createElement('div');
    row.className = 'form-fields schedule-swap-row';
    row.innerHTML = `<label>Jadwal lanjutan<select data-swap-assignment required>${swapAssignmentOptions()}</select></label><label>Karyawan pengganti<select data-swap-target required>${swapTargetOptions()}</select></label><button type="button" class="action-btn danger" data-swap-remove title="Hapus baris rantai tukar" aria-label="Hapus baris rantai tukar">${icon('trash')}</button>`;
    $('#schedule-swap-extra-moves').append(row);
    invalidateSwapPreview();
}

function swapPayload() {
    const moves = [];

    const sourceAssId = Number($('#schedule-swap-source-assignment').value);
    const targetEmpId = Number($('#schedule-swap-target-employee').value);
    const sourceAss = entries.find((item) => item.id === sourceAssId);
    if (sourceAss && targetEmpId) {
        moves.push({
            assignment_id: sourceAss.id,
            expected_revision: sourceAss.revision,
            to_employee_id: targetEmpId,
        });
    }

    const mode = $('input[name="swap_mode"]:checked')?.value;
    if (mode === 'direct') {
        const targetAssId = Number($('#schedule-swap-target-schedule').value);
        const targetAss = entries.find((item) => item.id === targetAssId);
        if (targetAss && sourceAss) {
            moves.push({
                assignment_id: targetAss.id,
                expected_revision: targetAss.revision,
                to_employee_id: sourceAss.employee_id,
            });
        }
    } else if (mode === 'chain') {
        const chainAssId = Number($('#schedule-swap-chain-schedule').value);
        const chainTargetEmpId = Number($('#schedule-swap-chain-target').value);
        const chainAss = entries.find((item) => item.id === chainAssId);
        if (chainAss && chainTargetEmpId) {
            moves.push({
                assignment_id: chainAss.id,
                expected_revision: chainAss.revision,
                to_employee_id: chainTargetEmpId,
            });
        }
    }

    $$('.schedule-swap-row', $('#schedule-swap-extra-moves')).forEach((row) => {
        const assId = Number(row.querySelector('[data-swap-assignment]').value);
        const toId = Number(row.querySelector('[data-swap-target]').value);
        const ass = entries.find((item) => item.id === assId);
        if (ass && toId) {
            moves.push({
                assignment_id: ass.id,
                expected_revision: ass.revision,
                to_employee_id: toId,
            });
        }
    });

    const vacancy_decisions = $$('.schedule-swap-vacancy', $('#schedule-swap-result')).map((row) => ({
        employee_id: Number(row.dataset.employeeId),
        work_date: row.dataset.workDate,
        action: row.querySelector('[data-vacancy-action]').value,
        reason: row.querySelector('[data-vacancy-reason]').value.trim(),
    }));

    return {
        moves,
        vacancy_decisions,
        confirm_cross_unit: $('#schedule-swap-cross-unit').checked,
    };
}

function invalidateSwapPreview(clearResult = true) {
    swapPreview = null;
    $('#schedule-swap-save').disabled = true;
    if (clearResult) $('#schedule-swap-result').replaceChildren();
}

function openSwap(assignment) {
    swapPreview = null;
    $('#schedule-swap-result').replaceChildren();
    $('#schedule-swap-extra-moves').replaceChildren();
    $('#schedule-swap-cross-unit').checked = false;
    $('#schedule-swap-reason').value = '';
    $('#schedule-swap-error').hidden = true;
    $('#schedule-swap-save').disabled = true;
    delete $('#swap-mode-none').dataset.userChosen;

    $('#schedule-swap-source-assignment').innerHTML = swapAssignmentOptions(assignment.id);
    $('#schedule-swap-target-employee').innerHTML = swapTargetOptions(assignment.employee_id);
    $('#schedule-swap-target-employee').value = '';

    $('#schedule-swap-options-section').hidden = true;
    $('#swap-mode-direct').checked = true;
    $('#schedule-swap-direct-config').hidden = false;
    $('#schedule-swap-chain-config').hidden = true;

    swapModal.showModal();
}

function renderSwapPreview(result) {
    const root = $('#schedule-swap-result');
    const blockers = result.blockers.map((item) => `<li>${esc(item.message)}</li>`).join('');
    const moves = result.moves.map((item) => `<li>${esc(item.work_date)} · ${esc(item.shift)}: ${esc(item.from_employee)} → ${esc(item.to_employee)}</li>`).join('');
    const vacancies = result.vacancies.map((item) => `<div class="schedule-swap-vacancy" data-employee-id="${item.employee_id}" data-work-date="${esc(item.work_date)}"><strong>${esc(item.name)} · ${esc(item.work_date)}</strong><label>Tindak lanjut<select data-vacancy-action required><option value="">Pilih roster</option><option value="roster_off">Roster off</option><option value="regular">Potong cuti reguler</option><option value="day_off">Potong day off</option></select></label><label>Alasan<input data-vacancy-reason maxlength="1000" required></label></div>`).join('');
    root.innerHTML = `<section><strong>Jadwal yang ditukar</strong><ul>${moves}</ul></section>${vacancies ? `<section><strong>Jadwal yang kosong</strong>${vacancies}</section>` : ''}${blockers ? `<div class="form-error"><strong>Belum bisa diterapkan</strong><ul>${blockers}</ul></div>` : '<p class="field-hint">Konflik sudah diperiksa. Setelah semua pilihan roster valid, jalankan pratinjau sekali lagi.</p>'}`;
    $('#schedule-swap-save').disabled = !result.can_commit;
}

async function previewSwap() {
    const error = $('#schedule-swap-error');
    error.hidden = true;
    try {
        const payload = swapPayload();
        if (!payload.moves.length) throw new Error('Pilih jadwal dan karyawan pengganti.');
        const mode = $('input[name="swap_mode"]:checked')?.value;
        if (mode === 'direct' && !$('#schedule-swap-target-schedule').value) {
            throw new Error('Pilih jadwal karyawan pengganti untuk ditukar balik.');
        }
        if (mode === 'chain' && (!$('#schedule-swap-chain-schedule').value || !$('#schedule-swap-chain-target').value)) {
            throw new Error('Pilih jadwal yang dialihkan dan karyawan pengganti berikutnya.');
        }
        if (payload.moves.some((item) => !item.assignment_id || !item.to_employee_id)) {
            throw new Error('Lengkapi seluruh jadwal dan karyawan pengganti sebelum pratinjau.');
        }
        const result = await api('schedule/swap/preview', { method: 'POST', body: payload });
        swapPreview = result;
        renderSwapPreview(result);
    } catch (reason) {
        error.textContent = reason.message;
        error.hidden = false;
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
    try { localStorage.setItem('deresort_cal_view', state.view); } catch (_) {}
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
    const edit = event.target.closest('[data-edit-sessions]');
    if (edit) {
        const assignment = entries.find((entry) => entry.id === Number(edit.dataset.editSessions));
        if (assignment) openScheduleEdit(assignment);
        return;
    }
    const swap = event.target.closest('[data-swap]');
    if (swap) {
        const assignment = entries.find((entry) => entry.id === Number(swap.dataset.swap));
        if (assignment) openSwap(assignment);
        return;
    }
    const button = event.target.closest('[data-delete]');
    if (!button || !await confirmDialog({ title: 'Batalkan jadwal?', message: 'Jadwal ini akan dibatalkan.', confirmLabel: 'Batalkan', danger: true })) return;
    runAction(async () => {
        await api(`schedule/${button.dataset.delete}`, { method: 'DELETE' });
        return 'Jadwal dibatalkan.';
    });
});

$('#schedule-swap-source-assignment').addEventListener('change', async () => {
    const sourceAssId = Number($('#schedule-swap-source-assignment').value);
    const sourceAss = entries.find((e) => e.id === sourceAssId);
    const currentTarget = $('#schedule-swap-target-employee').value;
    $('#schedule-swap-target-employee').innerHTML = swapTargetOptions(sourceAss?.employee_id ?? '', currentTarget);
    await onTargetEmployeeChange();
});
$('#schedule-swap-target-employee').addEventListener('change', onTargetEmployeeChange);

$$('input[name="swap_mode"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        if (radio.value === 'none') radio.dataset.userChosen = 'true';
        else delete $('#swap-mode-none').dataset.userChosen;
        updateSwapModeUi();
    });
});

$('#schedule-swap-target-schedule').addEventListener('change', () => invalidateSwapPreview());
$('#schedule-swap-chain-schedule').addEventListener('change', () => invalidateSwapPreview());
$('#schedule-swap-chain-target').addEventListener('change', () => invalidateSwapPreview());

$('#schedule-swap-add').addEventListener('click', () => { addExtraSwapRow(); });
$('#schedule-swap-extra-moves').addEventListener('change', (event) => {
    const row = event.target.closest('.schedule-swap-row');
    if (event.target.matches('[data-swap-assignment]')) {
        const assId = Number(event.target.value);
        const ass = entries.find((e) => e.id === assId);
        const targetSelect = row?.querySelector('[data-swap-target]');
        if (targetSelect) {
            targetSelect.innerHTML = swapTargetOptions(ass?.employee_id ?? '', targetSelect.value);
        }
    }
    invalidateSwapPreview();
});
$('#schedule-swap-extra-moves').addEventListener('click', (event) => {
    if (!event.target.closest('[data-swap-remove]')) return;
    event.target.closest('.schedule-swap-row').remove();
    invalidateSwapPreview();
});

$('#schedule-swap-cross-unit').addEventListener('change', () => invalidateSwapPreview());
$('#schedule-swap-result').addEventListener('input', () => invalidateSwapPreview(false));
$('#schedule-swap-result').addEventListener('change', () => invalidateSwapPreview(false));
$('#schedule-swap-preview').addEventListener('click', previewSwap);
$('#schedule-swap-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!swapPreview?.can_commit || !swapPreview.preview_token) return;
    const error = $('#schedule-swap-error');
    error.hidden = true;
    try {
        const payload = swapPayload();
        if (payload.vacancy_decisions.some((decision) => !decision.action || decision.reason.length < 3)) throw new Error('Lengkapi pilihan roster dan alasan pada semua jadwal yang kosong.');
        await api('schedule/swap', { method: 'POST', body: { ...payload, preview_token: swapPreview.preview_token, reason: $('#schedule-swap-reason').value.trim() } });
        swapModal.close();
        toast('Rantai jadwal ditukar dan dicatat.');
        load();
    } catch (reason) {
        error.textContent = reason.message;
        error.hidden = false;
        invalidateSwapPreview();
    }
});

$$('[data-open-modal]').forEach((button) => button.addEventListener('click', openScheduleModal));
$('#schedule-bulk-btn')?.addEventListener('click', openBulkModal);
$('#bulk-dept-filter')?.addEventListener('change', () => {
    renderBulkEmployeeList($('#bulk-dept-filter').value, $('#bulk-emp-search').value);
});
$('#bulk-emp-search')?.addEventListener('input', () => {
    renderBulkEmployeeList($('#bulk-dept-filter').value, $('#bulk-emp-search').value);
});
$('#bulk-select-all')?.addEventListener('click', () => {
    $$('input[name="bulk_emp_id"]', $('#bulk-emp-list')).forEach((cb) => { cb.checked = true; });
    updateBulkCounter();
});
$('#bulk-clear-all')?.addEventListener('click', () => {
    $$('input[name="bulk_emp_id"]', $('#bulk-emp-list')).forEach((cb) => { cb.checked = false; });
    updateBulkCounter();
});
$('#bulk-emp-list')?.addEventListener('change', (event) => {
    if (event.target.matches('input[name="bulk_emp_id"]')) updateBulkCounter();
});
if (bulkModal) {
    $$('.preset-pill', bulkModal).forEach((btn) => {
        btn.addEventListener('click', () => setDayPreset(btn.dataset.preset));
    });
}
$('#bulk-days-group')?.addEventListener('change', () => {
    if (bulkModal) $$('.preset-pill', bulkModal).forEach((btn) => btn.classList.remove('active'));
});
$('#schedule-bulk-form')?.addEventListener('submit', submitBulkSchedule);
$('#add-selected-date')?.addEventListener('click', openScheduleModal);
$('#schedule-form').addEventListener('submit', (event) => {
    event.preventDefault();
    saveSchedule();
});

$('#schedule-mode').addEventListener('change', (event) => setScheduleMode(event.target.value));
$('#schedule-date').addEventListener('change', () => {
    if (!editingSchedule) $$('.schedule-session-row [data-s="start_date"], .schedule-session-row [data-s="end_date"]', $('#schedule-session-rows')).forEach((input) => { if (!input.value) input.value = $('#schedule-date').value; });
});
$('#schedule-add-session').addEventListener('click', () => {
    const rows = $('#schedule-session-rows');
    rows.insertAdjacentHTML('beforeend', scheduleSessionRow({}, rows.children.length));
    $('#schedule-add-session').hidden = rows.children.length >= 4;
});
$('#schedule-session-rows').addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-session]');
    if (!remove) return;
    const rows = $('#schedule-session-rows');
    const minimum = editingSchedule ? 1 : 2;
    if (rows.children.length <= minimum) return;
    remove.closest('.schedule-session-row').remove();
    $$('.schedule-session-row', rows).forEach((row, index) => {
        const badge = $('.session-num-badge', row);
        if (badge) badge.textContent = `Sesi ${index + 1}`;
        const button = $('[data-remove-session]', row);
        if (button) button.setAttribute('aria-label', `Hapus sesi ${index + 1}`);
    });
    $('#schedule-add-session').hidden = rows.children.length >= 4;
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
    const employee_id = personFilter();
    if (state.view === 'week') {
        const from = keyOf(weekStart(state.anchor));
        const to = keyOf(addDays(weekStart(state.anchor), 7));
        if (!await confirmDialog({
            title: 'Salin jadwal minggu?',
            message: `Salin jadwal minggu ini ke minggu ${longDate(to)}${employee_id ? ` untuk ${nameOf(employee_id)}` : ''}? Tanggal yang sudah terisi dilewati.`,
            confirmLabel: 'Salin jadwal',
        })) return;
        runAction(async () => {
            const { created, skipped } = await api('schedule/copy-week', { method: 'POST', body: { from, to, employee_id } });
            state.anchor = parseKey(to);
            state.selected = { date: to, emp: state.selected.emp };
            return `${created} jadwal disalin${skipped.length ? `, ${skipped.length} dilewati` : ''}.`;
        });
    } else {
        const fromDate = new Date(state.anchor.getFullYear(), state.anchor.getMonth(), 1);
        const toDate = addMonths(fromDate, 1);
        const from = keyOf(fromDate);
        const to = keyOf(toDate);
        const fromLabel = `${MONTHS[fromDate.getMonth()]} ${fromDate.getFullYear()}`;
        const toLabel = `${MONTHS[toDate.getMonth()]} ${toDate.getFullYear()}`;
        if (!await confirmDialog({
            title: 'Salin jadwal bulan?',
            message: `Salin semua jadwal shift bulan ${fromLabel} ke bulan ${toLabel}${employee_id ? ` untuk ${nameOf(employee_id)}` : ''}? Tanggal yang sudah terisi dilewati.`,
            confirmLabel: 'Salin jadwal',
        })) return;
        runAction(async () => {
            const { created, skipped } = await api('schedule/copy-month', { method: 'POST', body: { from, to, employee_id } });
            state.anchor = toDate;
            state.selected = { date: to, emp: state.selected.emp };
            return `${created} jadwal disalin${skipped.length ? `, ${skipped.length} dilewati` : ''}.`;
        });
    }
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
        employees = emp.data.map((e) => ({
            id: e.id,
            name: e.name,
            dept: e.department?.name ?? '',
            position: e.position?.name ?? 'Tanpa Jabatan',
        }));
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
