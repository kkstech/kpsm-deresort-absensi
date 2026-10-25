import { role } from './admin/api';
import { $, $$, toast } from './admin/ui';
import { showEmployees } from './admin/employees';
import { focusEmployee, initSchedule, showSchedule } from './admin/schedule';
import { showSettings } from './admin/settings';
import { initAttendance, showApprovals, showDashboard, showMonitoring } from './admin/attendance';
import { showLeaves } from './admin/leaves';
import { showAllowances } from './admin/allowances';

const loaders = { dashboard: showDashboard, schedule: showSchedule, attendance: showMonitoring, requests: showApprovals, employees: showEmployees, leaves: showLeaves, allowances: showAllowances, settings: showSettings };
const canSee = (el) => ({
    web_admin: ['super_admin', 'admin', 'supervisor'].includes(role),
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

function showView(view) {
    $$('[data-view-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.viewPanel === view));
    $$('[data-view]').forEach((button) => {
        const active = button.dataset.view === view;
        button.classList.toggle('active', active);
        if (active) button.setAttribute('aria-current', 'page');
        else button.removeAttribute('aria-current');
    });
    closeSidebar();
    loaders[view]?.();
    $('#main-content').focus({ preventScroll: true });
    window.scrollTo({ top: 0 });
}

$$('[data-view]').forEach((button) => button.addEventListener('click', () => showView(button.dataset.view)));
$$('[data-view-link]').forEach((button) => button.addEventListener('click', () => showView(button.dataset.viewLink)));
menuButton.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
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
