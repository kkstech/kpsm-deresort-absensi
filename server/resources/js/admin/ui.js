export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

let toastTimer;
export function toast(message) {
    const el = $('#toast');
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

/** columns: [{ label, cell(row) => html yang sudah di-escape }] */
export function renderTable(container, { columns, rows, empty, actions }) {
    if (!rows.length) {
        container.innerHTML = `<p class="list-empty">${esc(empty)}</p>`;
        return;
    }
    const head = columns.map((c) => `<th scope="col">${esc(c.label)}</th>`).join('') + (actions ? '<th scope="col"><span class="sr-only">Aksi</span></th>' : '');
    const body = rows.map((row) => `<tr>${columns.map((c) => `<td data-label="${esc(c.label)}">${c.cell(row)}</td>`).join('')}${actions ? `<td class="row-actions">${actions(row)}</td>` : ''}</tr>`).join('');
    container.innerHTML = `<div class="table-scroll"><table class="data-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

export const loadingInto = (container) => { container.innerHTML = '<p class="list-empty">Memuat data…</p>'; };
export const errorInto = (container, error) => { container.innerHTML = `<p class="form-error list-error" role="alert">${esc(error.message)}</p>`; };

export function confirmDialog({ title = 'Konfirmasi', message, confirmLabel = 'Lanjutkan', danger = false }) {
    const dialog = $('#confirm-modal');
    const submit = $('#confirm-modal-submit');
    $('#confirm-modal-title').textContent = title;
    $('#confirm-modal-message').textContent = message;
    submit.textContent = confirmLabel;
    submit.classList.toggle('danger', danger);
    dialog.returnValue = 'cancel';

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
        dialog.showModal();
    });
}

/* ---------- Dialog form generik ---------- */
const modal = $('#form-modal');
const fieldsBox = $('#form-modal-fields');
const errorBox = $('#form-modal-error');
let current = null;

function fieldHtml(f, value) {
    const id = `ff-${f.name}`;
    const req = f.required ? ' required' : '';
    const hint = f.hint ? `<small class="field-hint">${esc(f.hint)}</small>` : '';
    const err = `<small class="field-error" data-error-for="${esc(f.name)}" hidden></small>`;
    if (f.type === 'checkbox') {
        return `<label class="check-field" data-field="${esc(f.name)}"><input type="checkbox" id="${id}" name="${esc(f.name)}"${value ? ' checked' : ''}><span>${esc(f.label)}</span>${hint}${err}</label>`;
    }
    if (f.type === 'select') {
        const options = (f.blank !== undefined ? [{ value: '', label: f.blank }] : []).concat(f.options)
            .map((o) => `<option value="${esc(o.value)}"${String(o.value) === String(value ?? '') ? ' selected' : ''}>${esc(o.label)}</option>`).join('');
        return `<label data-field="${esc(f.name)}">${esc(f.label)}<select id="${id}" name="${esc(f.name)}"${req}>${options}</select>${hint}${err}</label>`;
    }
    if (f.type === 'sessions') {
        return `<fieldset class="sessions-field" data-field="${esc(f.name)}"><legend>${esc(f.label)}</legend><div class="session-rows"></div><button type="button" class="text-button" data-add-session>+ Tambah sesi</button>${hint}${err}</fieldset>`;
    }
    const attrs = ['min', 'max', 'step', 'maxlength', 'autocomplete', 'inputmode'].filter((a) => f[a] != null).map((a) => ` ${a}="${esc(f[a])}"`).join('');
    return `<label data-field="${esc(f.name)}">${esc(f.label)}<input type="${f.type ?? 'text'}" id="${id}" name="${esc(f.name)}" value="${esc(value)}"${req}${attrs}>${hint}${err}</label>`;
}

function sessionRow(s = {}) {
    const startOffset = Number(s.start_day_offset ?? 0);
    const endOffset = Number(s.day_offset ?? (startOffset + (String(s.local_end ?? '') <= String(s.local_start ?? '') ? 1 : 0)));
    return `<div class="session-row">
        <label>Hari mulai<select data-s="start_day_offset"><option value="0"${startOffset === 0 ? ' selected' : ''}>Tanggal kerja</option><option value="1"${startOffset === 1 ? ' selected' : ''}>Hari berikutnya</option></select></label>
        <label>Mulai<input type="time" data-s="local_start" value="${esc(String(s.local_start ?? '').slice(0, 5))}" required></label>
        <label>Hari selesai<select data-s="day_offset"><option value="0"${endOffset === 0 ? ' selected' : ''}>Tanggal kerja</option><option value="1"${endOffset === 1 ? ' selected' : ''}>Hari berikutnya</option><option value="2"${endOffset === 2 ? ' selected' : ''}>2 hari berikutnya</option></select></label>
        <label>Selesai<input type="time" data-s="local_end" value="${esc(String(s.local_end ?? '').slice(0, 5))}" required></label>
        <label>Istirahat (menit)<input type="number" data-s="unpaid_break_minutes" min="0" max="240" value="${esc(s.unpaid_break_minutes ?? 0)}"></label>
        <button type="button" class="icon-button" data-remove-session aria-label="Hapus sesi">×</button>
    </div>`;
}

function syncVisibility() {
    const values = collect();
    current.fields.forEach((f) => {
        const el = $(`[data-field="${f.name}"]`, fieldsBox);
        if (el && f.showIf) el.hidden = !f.showIf(values);
    });
    const rows = $('.session-rows', fieldsBox);
    if (rows) $('[data-add-session]', fieldsBox).hidden = rows.children.length >= 4;
}

function collect() {
    const out = {};
    current.fields.forEach((f) => {
        const wrap = $(`[data-field="${f.name}"]`, fieldsBox);
        if (!wrap || (wrap.hidden && f.showIf)) return;
        if (f.type === 'sessions') {
            out[f.name] = $$('.session-row', wrap).map((row) => Object.fromEntries($$('[data-s]', row).map((i) => [i.dataset.s, i.type === 'number' || ['start_day_offset', 'day_offset'].includes(i.dataset.s) ? Number(i.value || 0) : i.value])));
            return;
        }
        const input = $(`[name="${f.name}"]`, wrap);
        if (f.type === 'checkbox') out[f.name] = input.checked;
        else if (input.value === '') out[f.name] = null;
        else out[f.name] = f.type === 'number' || f.numeric ? Number(input.value) : input.value;
    });
    return out;
}

function showErrors(error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
    Object.entries(error.details ?? {}).forEach(([key, messages]) => {
        const base = key.split('.')[0];
        const slot = $(`[data-error-for="${base}"]`, fieldsBox);
        if (slot) {
            slot.textContent = Array.isArray(messages) ? messages[0] : String(messages);
            slot.hidden = false;
        }
    });
    ($('[data-error-for]:not([hidden])', fieldsBox)?.closest('[data-field]')?.querySelector('input, select') ?? errorBox).focus?.();
}

export function openForm({ title, hint = '', fields, values = {}, submitLabel = 'Simpan', onSubmit }) {
    current = { fields, onSubmit };
    $('#form-modal-title').textContent = title;
    $('#form-modal-hint').textContent = hint;
    $('#form-modal-submit').textContent = submitLabel;
    errorBox.hidden = true;
    fieldsBox.innerHTML = fields.map((f) => fieldHtml(f, values[f.name] ?? f.default ?? '')).join('');
    const rows = $('.session-rows', fieldsBox);
    if (rows) rows.innerHTML = (values.sessions?.length ? values.sessions : [{}]).map(sessionRow).join('');
    syncVisibility();
    modal.showModal();
    $('input, select', fieldsBox)?.focus();
}

fieldsBox.addEventListener('change', () => current && syncVisibility());
fieldsBox.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-session]')) {
        $('.session-rows', fieldsBox).insertAdjacentHTML('beforeend', sessionRow());
        syncVisibility();
    }
    const remove = event.target.closest('[data-remove-session]');
    if (remove && $$('.session-row', fieldsBox).length > 1) {
        remove.closest('.session-row').remove();
        syncVisibility();
    }
});

$('#form-modal-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.hidden = true;
    $$('.field-error', fieldsBox).forEach((el) => { el.hidden = true; });
    const submit = $('#form-modal-submit');
    submit.disabled = true;
    try {
        const result = await current.onSubmit(collect());
        if (result !== false) modal.close();
    } catch (error) {
        showErrors(error);
    } finally {
        submit.disabled = false;
    }
});
