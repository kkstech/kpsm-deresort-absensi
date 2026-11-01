import { api } from './api';
import { $, esc } from './ui';

const button = $('#notification-button');
const badge = $('#notification-unread-count');
const modal = $('#notification-modal');
const list = $('#notification-list');
const error = $('#notification-error');

function render(feed) {
    const count = Number(feed.unread_count || 0);
    badge.hidden = count < 1;
    badge.textContent = count > 99 ? '99+' : String(count);
    if (!feed.data?.length) {
        list.innerHTML = '<p class="list-empty">Belum ada notifikasi.</p>';
        return;
    }

    list.innerHTML = feed.data.map((item) => {
        const data = item.data ?? {};
        const requestType = data.context?.request_type ?? '';
        const timestamp = item.created_at ? new Date(item.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '';
        return `<article class="notification-item${item.read_at ? '' : ' unread'}">
            <div><h3>${esc(data.title || 'Notifikasi')}</h3><p>${esc(data.body || '')}</p><time>${esc(timestamp)}</time></div>
            <div class="notification-actions">
                <button type="button" data-open-request="${esc(requestType)}" data-notification-id="${esc(item.id)}">Buka</button>
                ${item.read_at ? '' : `<button type="button" data-mark-read="${esc(item.id)}">Tandai dibaca</button>`}
            </div>
        </article>`;
    }).join('');
}

async function refresh() {
    try {
        const feed = await api('notifications');
        render(feed);
        error.hidden = true;
        return feed;
    } catch (failure) {
        error.textContent = failure.message || 'Notifikasi belum bisa dimuat.';
        error.hidden = false;
        return null;
    }
}

export function initNotifications(openApprovals) {
    if (!button || !badge || !modal || !list || !error) return;

    button.addEventListener('click', async () => {
        modal.showModal();
        await refresh();
    });
    list.addEventListener('click', async (event) => {
        const openTarget = event.target.closest('[data-open-request]');
        const readTarget = event.target.closest('[data-mark-read]');
        const target = openTarget || readTarget;
        if (!target) return;
        target.disabled = true;
        try {
            const id = openTarget ? openTarget.dataset.notificationId : readTarget.dataset.markRead;
            const feed = await api(`notifications/${encodeURIComponent(id)}/read`, { method: 'POST' });
            render(feed);
            if (openTarget) {
                const requestType = openTarget.dataset.openRequest;
                modal.close();
                openApprovals(requestType);
            }
        } catch (failure) {
            target.disabled = false;
            error.textContent = failure.message || 'Notifikasi belum bisa diperbarui.';
            error.hidden = false;
        }
    });
    modal.querySelector('[data-open-approvals]')?.addEventListener('click', () => {
        modal.close();
        openApprovals('permission');
    });
    refresh();
    window.setInterval(refresh, 60_000);
}
