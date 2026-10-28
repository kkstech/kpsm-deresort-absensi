export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const ICONS = {
    edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
    contract: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
    award: '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
    key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3M18.5 4.5l3 3"/>',
    'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
    'device-logout': '<rect x="3" y="4" width="10" height="16" rx="2"/><path d="M15 8h4a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-4"/><polyline points="16 10 18 12 16 14"/><line x1="12" y1="12" x2="18" y2="12"/>',
    'user-x': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="17" y1="8" x2="22" y2="13"/><line x1="22" y1="8" x2="17" y2="13"/>',
    check: '<polyline points="20 6 9 17 4 12"/>',
    x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    undo: '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
    adjust: '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'shield-check': '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><polyline points="9 12 11 14 15 10"/>',
    swap: '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'link-slash': '<path d="m18.84 12.25 1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="m5.16 11.75-1.72 1.71a5 5 0 0 0 7.07 7.07l1.72-1.71"/><line x1="2" y1="2" x2="22" y2="22"/>',
    clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'upload-cloud': '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/><polyline points="16 16 12 12 8 16"/>',
    'file-text': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
    'alert-circle': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'more-horizontal': '<circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/><circle cx="5" cy="12" r="1.5" fill="currentColor"/>',
    'more-vertical': '<circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="5" r="1.5" fill="currentColor"/><circle cx="12" cy="19" r="1.5" fill="currentColor"/>',
};

export function icon(name, size = 16) {
    const body = ICONS[name] ?? '';
    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${body}</svg>`;
}

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

export function confirmDialog({ title = 'Konfirmasi', message, html, confirmLabel = 'Lanjutkan', danger = false }) {
    const dialog = $('#confirm-modal');
    const submit = $('#confirm-modal-submit');
    const messageEl = $('#confirm-modal-message');
    $('#confirm-modal-title').textContent = title;
    if (html != null) {
        messageEl.innerHTML = html;
    } else {
        messageEl.textContent = message ?? '';
    }
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
    if (f.type === 'file') {
        const accept = f.accept ? ` accept="${esc(f.accept)}"` : '';
        const preview = value ? `<div style="margin-top:8px;display:flex;align-items:center;gap:10px;"><img src="${esc(value)}" alt="Preview" style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--line);"><small style="color:var(--muted)">Foto profil saat ini</small></div>` : '';
        return `<label data-field="${esc(f.name)}">${esc(f.label)}<input type="file" id="${id}" name="${esc(f.name)}"${req}${accept}>${preview}${hint}${err}</label>`;
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
        <button type="button" class="action-btn danger" data-remove-session aria-label="Hapus sesi" title="Hapus sesi">${icon('trash', 14)}</button>
    </div>`;
}

function syncVisibility() {
    const values = collect();
    current.fields.forEach((f) => {
        const el = $(`[data-field="${f.name}"]`, fieldsBox);
        if (el && f.showIf) {
            const isHidden = !f.showIf(values);
            el.hidden = isHidden;
            const input = el.querySelector('input, select, textarea');
            if (input) input.disabled = isHidden;
        }
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
        else if (f.type === 'file') out[f.name] = (input.files && input.files[0]) ? input.files[0] : null;
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
