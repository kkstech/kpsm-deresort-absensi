import { role } from './admin/api';
import { $, $$, toast } from './admin/ui';
import { showEmployees } from './admin/employees';
import { focusEmployee, initSchedule, showSchedule } from './admin/schedule';
import { showSettings } from './admin/settings';
import { initAttendance, showApprovals, showDashboard, showMonitoring } from './admin/attendance';
import { showLeaves } from './admin/leaves';
import { showAllowances } from './admin/allowances';
import { showReports } from './admin/reports';
import { initNotifications } from './admin/notifications';

const loaders = { dashboard: showDashboard, schedule: showSchedule, attendance: showMonitoring, requests: showApprovals, employees: showEmployees, leaves: showLeaves, allowances: showAllowances, reports: showReports, settings: showSettings };
const canSee = (el) => ({
    web_admin: ['super_admin', 'admin'].includes(role),
    admin: role === 'admin' || role === 'super_admin',
    super_admin: role === 'super_admin',
})[el.dataset.requires] ?? false;
$$('[data-requires]').forEach((el) => { if (!canSee(el)) el.remove(); });

/* ---------- Navigasi ---------- */
const sidebar = $('#sidebar');
const menuButton = $('#menu-button');

function closeSidebar() {
    sidebar.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
}

function parseHash() {
    const raw = window.location.hash.replace(/^#\/?/, '').trim();
    if (!raw) return { view: null, sub: null };
    const parts = raw.split('/');
    return { view: parts[0] || null, sub: parts[1] || null };
}

function resolveCurrentView() {
    const { view: hashView, sub: hashSub } = parseHash();
    if (hashView && loaders[hashView] && (hashView === 'dashboard' || $(`[data-view="${hashView}"]`))) {
        if (hashView === 'settings' && hashSub) {
            try { localStorage.setItem('deresort_settings_tab', hashSub); } catch (_) {}
        }
        return hashView;
    }

    try {
        const savedView = localStorage.getItem('deresort_active_view');
        if (savedView && loaders[savedView] && (savedView === 'dashboard' || $(`[data-view="${savedView}"]`))) {
            return savedView;
        }
    } catch (_) {}

    return 'dashboard';
}

function showView(view, updateHash = true, isInit = false) {
    const targetButton = $(`[data-view="${view}"]`);
    if (!loaders[view] || (!targetButton && view !== 'dashboard')) {
        view = 'dashboard';
    }

    $$('[data-view-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.viewPanel === view));
    $$('[data-view]').forEach((button) => {
        const active = button.dataset.view === view;
        button.classList.toggle('active', active);
        if (active) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
    });
    closeSidebar();
    loaders[view]?.();

    try {
        localStorage.setItem('deresort_active_view', view);
    } catch (_) {}

    if (updateHash) {
        let hash = `#${view}`;
        if (view === 'settings') {
            const sub = localStorage.getItem('deresort_settings_tab');
            if (sub && sub !== 'general') hash = `#settings/${sub}`;
        }
        if (window.location.hash !== hash) {
            history.replaceState(null, '', hash);
        }
    }

    if (!isInit) {
        $('#main-content').focus({ preventScroll: true });
        window.scrollTo({ top: 0 });
    }
}

$$('[data-view]').forEach((button) => button.addEventListener('click', () => showView(button.dataset.view)));
$$('[data-view-link]').forEach((button) => button.addEventListener('click', () => showView(button.dataset.viewLink)));
menuButton.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
});

document.addEventListener('click', (event) => {
    if (sidebar.classList.contains('open') && !sidebar.contains(event.target) && !menuButton.contains(event.target)) {
        closeSidebar();
    }
});

window.addEventListener('hashchange', () => {
    const current = resolveCurrentView();
    showView(current, false);
});

document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        $('#global-search').focus();
    }
    if (event.key === 'Escape' && sidebar.classList.contains('open')) {
        closeSidebar();
        menuButton.focus();
    }
});

/* ---------- Dashboard: filter absensi ---------- */
const activityRows = $$('.activity-row');

function filterActivity(filter) {
    let visible = 0;
    activityRows.forEach((row) => {
        row.hidden = filter !== 'all' && row.dataset.status !== filter;
        if (!row.hidden) visible += 1;
    });
    $('#activity-empty').hidden = visible > 0;
}

$$('[data-count]').forEach((badge) => {
    const filter = badge.dataset.count;
    badge.textContent = activityRows.filter((row) => filter === 'all' || row.dataset.status === filter).length;
});
$$('[data-filter]').forEach((button) => button.addEventListener('click', () => {
    $$('[data-filter]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    filterActivity(button.dataset.filter);
}));

$$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));

initAttendance();
initNotifications((requestType) => showView(requestType === 'leave' ? 'leaves' : 'requests'));

$('#global-search').addEventListener('focus', () => initSchedule().catch(() => {}), { once: true });
$('#global-search').addEventListener('change', async (event) => {
    const query = event.target.value;
    if (!query.trim()) return;
    try {
        await initSchedule();
    } catch (error) {
        toast(error.message);
        return;
    }
    if (!focusEmployee(query)) {
        toast('Karyawan tidak ditemukan.');
        return;
    }
    event.target.value = '';
    showView('schedule');
});

const logoutForm = document.querySelector('form[action*="logout"]');
if (logoutForm) {
    logoutForm.addEventListener('submit', () => {
        try {
            localStorage.removeItem('deresort_active_view');
            localStorage.removeItem('deresort_settings_tab');
        } catch (_) {}
        history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
    });
}

const initialView = resolveCurrentView();
showView(initialView, true, true);
