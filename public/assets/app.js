const app = document.querySelector('#app');
const toastRegion = document.querySelector('#toast-region');
const routeLabels = {
    dashboard: ['Vista general', 'Operación TI'],
    assets: ['Inventario', 'Equipos'],
    users: ['Directorio', 'Usuarios'],
    maintenance: ['Servicio', 'Mantenimientos'],
    documents: ['Resguardo', 'Documentos'],
};
let csrfToken = '';
let sessionUser = null;
let currentRoute = 'dashboard';
let peopleCache = [];
let assetsCache = [];
let dashboardChartInstances = [];
let dashboardChartData = null;
let dashboardCounts = null;
let assetTab = 'all';
let assetPage = 1;
let assetPageSize = 0;
let maintenanceCalendarDate = new Date();
let maintenanceCalendarView = 'month';
let maintenanceCache = [];
let maintenanceDepartmentPlanCache = null;

const escapeHtml = (value = '') => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const formatPersonName = value => String(value || '').toLocaleLowerCase('es-MX').replace(/(^|[\s'-])([a-záéíóúüñ])/g, (_, separator, initial) => separator + initial.toLocaleUpperCase('es-MX'));
const dateTime = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T'))) : 'Sin fecha';
const statusText = status => ({ assigned: 'Activo', available: 'Disponible', maintenance: 'En mantenimiento', retired: 'Dado de baja', active: 'Activa', expiring: 'Por vencer', expired: 'Vencida', inactive: 'Inactiva', pending: 'Por confirmar', confirmed: 'Confirmada', reschedule_requested: 'Cambio solicitado', completed: 'Completado', cancelled: 'Cancelado' })[status] || status;
const phoneFormatText = status => ({ active: 'Activo', formatted: 'Formateado', to_format: 'Por formatear' })[status] || '—';
const fmtDate = value => {
    if (!value) return '--';
    const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
    const date = dateOnly
        ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
        : new Date(String(value).replace(' ', 'T'));
    return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short' }).format(date);
};
const fmtAssignedDate = value => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) return '--';
    const [, year, month, day] = match;
    return `${day}/${month}/${year}`;
};

document.addEventListener('input', event => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || field.readOnly) return;
    if (field instanceof HTMLInputElement && !['text', 'search', 'tel', 'url'].includes(field.type)) return;
    const start = field.selectionStart;
    const end = field.selectionEnd;
    field.value = field.value.toLocaleUpperCase('es-MX');
    if (start !== null && end !== null) field.setSelectionRange(start, end);
});

async function api(action, options = {}) {
    const method = options.method || 'GET';
    const url = new URL(`api.php?action=${encodeURIComponent(action)}`, window.location.href);
    for (const [key, value] of Object.entries(options.query || {})) {
        url.searchParams.set(key, value);
    }
    const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', ...(method !== 'GET' ? { 'X-CSRF-Token': csrfToken } : {}) },
        body: method === 'GET' ? undefined : JSON.stringify(options.body || {}),
        credentials: 'same-origin',
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || 'No se pudo completar la solicitud.');
    return result;
}

function toast(message, type = '') {
    const node = document.createElement('div');
    node.className = `toast ${type}`;
    node.textContent = message;
    toastRegion.append(node);
    window.setTimeout(() => node.remove(), 4400);
}

function brand(extra = '') {
    return `<div class="brand-lockup ${extra}"><span class="brand-image-frame"><img class="brand-logo" src="../Logo-Grupo-RAME-3.png" alt="Grupo Rame"></span></div>`;
}

function authScreen(setup = false, connectionError = false) {
    app.innerHTML = `
        <section class="login-wrap">
            <div class="login-art">${brand('brand-on-blue')}<div class="login-message"><span class="eyebrow">Operación tecnológica</span><h1>Todo tu equipo.<br>En orden.</h1><p>Activos, responsivas y mantenimientos preventivos en un solo lugar, con seguimiento hasta la confirmación.</p></div><div class="login-foot">Rame TI · Gestión interna de activos y servicios</div></div>
            <div class="login-panel">${brand('brand-on-light')}<h2>${connectionError ? 'Conecta tu base de datos' : setup ? 'Configura tu espacio' : 'Qué gusto verte'}</h2><p>${connectionError ? 'No se pudo conectar. Importa database/schema.sql en phpMyAdmin y revisa los valores de app/config.php.' : setup ? 'Crea la cuenta administradora para empezar a registrar tus equipos.' : 'Ingresa con tu cuenta para continuar.'}</p>
                ${connectionError ? '<div class="notice error"><span>!</span><div><strong>Base de datos no disponible</strong>Abre phpMyAdmin, importa el esquema y verifica que MySQL esté activo en XAMPP.</div></div><button class="secondary-button" id="retry-connection">Reintentar conexión</button>' : `<form id="auth-form"><div class="form-grid">${setup ? '<div class="field full"><label for="auth-name">Nombre completo</label><input id="auth-name" name="name" required autocomplete="name" maxlength="140"></div>' : ''}<div class="field full"><label for="auth-email">Correo electrónico</label><input id="auth-email" name="email" type="email" required autocomplete="email"></div><div class="field full"><label for="auth-password">${setup ? 'Contraseña (8 caracteres mínimo)' : 'Contraseña'}</label><input id="auth-password" name="password" type="password" required ${setup ? 'minlength="8"' : ''} autocomplete="${setup ? 'new-password' : 'current-password'}"></div></div><div class="form-actions"><button class="primary-button" type="submit">${setup ? 'Crear administrador' : 'Entrar'} <span class="button-icon">→</span></button></div><div id="auth-error" class="form-error" role="alert"></div></form>`}
            </div>
        </section>`;
    document.querySelector('#retry-connection')?.addEventListener('click', start);
    document.querySelector('#auth-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        try {
            const result = await api(setup ? 'setup' : 'login', { method: 'POST', body: Object.fromEntries(form.entries()) });
            sessionUser = result.user;
            currentRoute = 'dashboard';
            await renderApp();
        } catch (error) {
            document.querySelector('#auth-error').textContent = error.message;
        }
    });
}

async function start() {
    try {
        const state = await api('state');
        csrfToken = state.csrfToken;
        sessionUser = state.user;
        if (!sessionUser) authScreen(state.setupRequired);
        else await renderApp();
    } catch {
        authScreen(false, true);
    }
}

function navItems() {
    const all = sessionUser.role === 'user'
        ? [['dashboard', '◫', 'Resumen'], ['assets', '▣', 'Mis equipos'], ['maintenance', '◷', 'Mantenimientos'], ['documents', '▧', 'Documentos']]
        : [['dashboard', '◫', 'Resumen'], ['assets', '▣', 'Activos'], ['maintenance', '◷', 'Mantenimientos'], ['documents', '▧', 'Documentos']];
    if (sessionUser.role === 'admin') all.splice(2, 0, ['users', '♙', 'Usuarios']);
    return all;
}

async function renderApp() {
    const [peopleResult, assetsResult] = await Promise.all([api('people'), api('assets')]);
    peopleCache = peopleResult.people;
    assetsCache = assetsResult.assets;
    const [eyebrow, heading] = routeLabels[currentRoute];
    app.innerHTML = `<div class="layout"><aside class="sidebar">${brand('brand-on-blue')}<div class="nav-label">Espacio de trabajo</div><nav class="nav-list" aria-label="Secciones">${navItems().map(([route, glyph, title]) => `<button class="nav-link ${route === currentRoute ? 'active' : ''}" data-route="${route}"><span class="nav-glyph">${glyph}</span><span>${title}</span></button>`).join('')}</nav><div class="sidebar-bottom"><div class="sidebar-status"><span class="status-dot"></span>Espacio operativo<br>Datos en MySQL</div><div class="user-chip"><span class="avatar">${escapeHtml(sessionUser.name.split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase())}</span><div class="user-info"><strong>${escapeHtml(sessionUser.name)}</strong><span>${escapeHtml(sessionUser.role)}</span></div><button class="icon-button" id="logout" title="Cerrar sesión" aria-label="Cerrar sesión">↗</button></div></div></aside><div class="main-column"><header class="topbar"><div class="breadcrumbs"><span>Rame TI</span><span>/</span><strong>${heading}</strong></div><div class="topbar-actions"><span class="system-state"><span class="status-dot"></span>Base conectada</span><button class="icon-button" id="refresh" title="Actualizar datos" aria-label="Actualizar datos">↻</button></div></header><main class="content"><div class="page-heading"><div><span class="eyebrow">${eyebrow}</span><h1>${heading}</h1><p>${pageDescription(currentRoute)}</p></div>${pageAction(currentRoute)}</div><div id="page-content"><div class="loading-screen" style="min-height:180px"><span class="loader"></span></div></div></main></div></div>`;
    document.querySelectorAll('[data-route]').forEach(button => button.addEventListener('click', async () => { currentRoute = button.dataset.route; await renderApp(); }));
    document.querySelector('#logout').addEventListener('click', async () => { try { await api('logout', { method: 'POST' }); location.reload(); } catch (error) { toast(error.message, 'error'); } });
    document.querySelector('#refresh').addEventListener('click', () => renderApp());
    document.querySelector('#page-action')?.addEventListener('click', () => openCreateForm(currentRoute));
    await renderPage();
}

function pageDescription(route) {
    return ({ dashboard: 'El estado del inventario y lo que requiere atención.', assets: 'PC y laptops asignadas, disponibles y en servicio.', users: 'Personas con acceso al sistema y sus permisos.', maintenance: 'Agenda preventiva, confirmaciones y evidencia de cumplimiento.', documents: 'Órdenes de Servicio y Solicitudes Correctivas con registro de firmas.' })[route];
}

function pageAction(route) {
    if (route === 'assets' && ['admin', 'technician'].includes(sessionUser.role)) {
        return '<button class="primary-button" id="page-action"><span class="button-icon">+</span>Nuevo PC o laptop</button>';
    }
    if (['assets', 'maintenance'].includes(route) && ['admin', 'technician'].includes(sessionUser.role)) {
        const labels = { assets: 'Nuevo activo', maintenance: 'Agendar servicio' };
        return `<button class="primary-button" id="page-action"><span class="button-icon">+</span>${labels[route]}</button>`;
    }
    if (route === 'users' && sessionUser.role === 'admin') return '<button class="primary-button" id="page-action"><span class="button-icon">+</span>Agregar usuario</button>';
    return '';
}

async function renderPage() {
    const target = document.querySelector('#page-content');
    try {
        if (currentRoute === 'dashboard') {
            target.innerHTML = await dashboardView();
            updateDashboardSummary(target, dashboardCounts);
        }
        if (currentRoute === 'assets') {
            target.innerHTML = await assetsView();
            target.querySelectorAll('.asset-group').forEach(group => {
                if (!group.querySelector('tbody tr')) group.remove();
            });
            const assetGroup = target.querySelector('.asset-group');
            if (assetGroup) {
                assetGroup.querySelector('h2').textContent = 'Inventario de PC y laptops';
                assetGroup.querySelector('.panel-head p').textContent = `${assetsCache.length} equipos registrados`;
            }
            updateAssetTableColumns(target);
            addAssetFilters(target);
        }
        if (currentRoute === 'users') target.innerHTML = await usersView();
        if (currentRoute === 'maintenance') target.innerHTML = await maintenanceView();
        if (currentRoute === 'documents') target.innerHTML = await documentsView();
        bindPageActions();
        if (currentRoute === 'dashboard') renderDashboardCharts();
    } catch (error) {
        target.innerHTML = `<div class="notice error"><span>!</span><div><strong>No se cargaron los datos</strong>${escapeHtml(error.message)}</div></div>`;
    }
}

function updateDashboardSummary(target, counts) {
    const kpis = [...target.querySelectorAll('.kpi')];
    kpis[1]?.remove();
    kpis[2]?.remove();
    target.querySelector('.kpi-grid')?.classList.add('kpi-grid-compact');
    const updateKpi = (labelMatch, label, value, glyph, note) => {
        const card = [...target.querySelectorAll('.kpi')].find(item => item.querySelector('.kpi-top > span:first-child')?.textContent === labelMatch);
        if (!card) return;
        card.querySelector('.kpi-top > span:first-child').textContent = label;
        card.querySelector('.kpi-glyph').textContent = glyph;
        card.querySelector('.kpi-value').textContent = value;
        card.querySelector('.kpi-note').textContent = note;
    };
    updateKpi('Activos en operación', 'Activos en operación', counts.assets, '▣', 'PC y laptops');
    updateKpi('Licencias vigentes', 'Mantenimientos en curso', counts.maintenance, '◷', 'Pendientes y confirmados');
    const assetAction = target.querySelector('[data-create="assets"]');
    if (assetAction) {
        assetAction.querySelector('strong').textContent = 'Registrar PC o laptop';
        assetAction.querySelector('strong + span').textContent = 'Alta de equipo de cómputo';
    }
}

async function dashboardView() {
    const { counts, upcoming, charts } = await api('dashboard');
    dashboardChartData = charts;
    dashboardCounts = counts;
    const quickActions = sessionUser.role === 'user' ? '' : `<section class="section-panel"><div class="panel-head"><div><h2>Accesos rápidos</h2><p>Atajos para tareas frecuentes</p></div></div><div class="quick-list"><button class="quick-action" data-create="assets"><span class="quick-glyph">+</span><span><strong>Registrar equipo</strong><span>Alta de computadora o celular</span></span><span class="quick-arrow">›</span></button><button class="quick-action" data-create="maintenance"><span class="quick-glyph">◷</span><span><strong>Agendar mantenimiento</strong><span>Enviar invitación al usuario</span></span><span class="quick-arrow">›</span></button><button class="quick-action" data-route="documents"><span class="quick-glyph">▧</span><span><strong>Gestionar responsivas</strong><span>Entrega y cumplimiento firmado</span></span><span class="quick-arrow">›</span></button></div></section>`;
    const chartPanels = [
        ['asset-status-chart', 'Estado de activos', 'Incluye equipos dados de baja'],
        ['asset-type-chart', 'Equipos por tipo', 'PC y laptops en operación'],
        ['antivirus-status-chart', 'Vigencia del antivirus', 'Incluye antivirus sin fecha y equipos sin antivirus'],
        ...(sessionUser.role === 'user' ? [['maintenance-status-chart', 'Tus mantenimientos', 'Seguimiento de tus servicios']] : []),
        ['maintenance-month-chart', 'Mantenimientos por mes', 'Actividad de los últimos seis meses'],
    ].map(([id, title, subtitle]) => `<section class="section-panel chart-panel ${id === 'antivirus-status-chart' ? 'chart-panel-antivirus' : ''}"><div class="panel-head"><div><h2>${title}</h2><p>${subtitle}</p></div></div><div class="chart-stage"><canvas id="${id}" role="img" aria-label="${title}"></canvas><div class="chart-empty hidden" id="${id}-empty">Aún no hay datos para mostrar.</div></div></section>`).join('');
    return `<div class="kpi-grid">${[['Activos en operación', counts.assets, '▣', 'Computadoras y móviles'], ['Computadoras', counts.computers, '⌘', 'Equipos de escritorio y portátiles'], ['Celulares', counts.phones, '▯', 'Líneas y dispositivos'], ['Licencias vigentes', counts.licenses, '▤', `${counts.maintenance} mantenimientos en curso`]].map(([label, value, glyph, note]) => `<article class="kpi"><div class="kpi-top"><span>${label}</span><span class="kpi-glyph">${glyph}</span></div><div><div class="kpi-value">${value}</div><div class="kpi-note">${note}</div></div></article>`).join('')}</div><div class="chart-grid">${chartPanels}</div><div class="dashboard-grid"><section class="section-panel"><div class="panel-head"><div><h2>Próximos mantenimientos</h2><p>Agenda de servicio preventivo</p></div><button class="text-button" data-route="maintenance">Ver agenda →</button></div>${upcoming.length ? `<div class="schedule-list">${upcoming.map(item => `<div class="schedule-item"><div class="date-block"><strong>${fmtDate(item.scheduled_at).split(' ')[0]}</strong><span>${fmtDate(item.scheduled_at).split(' ')[1] || ''}</span></div><div class="schedule-main"><strong>${escapeHtml(item.asset_tag)} · ${escapeHtml(item.brand)} ${escapeHtml(item.model)}</strong><span>${sessionUser.role === 'user' ? '' : `${escapeHtml(item.user_name)} · `}<span class="status-pill status-${item.status}">${statusText(item.status)}</span></span></div><div class="schedule-time">${dateTime(item.scheduled_at).split(', ').pop()}</div></div>`).join('')}</div>` : emptyState('No hay servicios agendados', 'Cuando programes un mantenimiento, aparecerá aquí.')}</section>${quickActions}</div>`;
}

function renderDashboardCharts() {
    dashboardChartInstances.forEach(chart => chart.destroy());
    dashboardChartInstances = [];
    const ChartLibrary = window.Chart;
    if (!ChartLibrary || !dashboardChartData) {
        document.querySelectorAll('.chart-empty').forEach(node => { node.textContent = 'No se pudo cargar la librería de gráficas.'; node.classList.remove('hidden'); });
        return;
    }
    ChartLibrary.defaults.font.family = "'DM Sans', 'Trebuchet MS', sans-serif";
    ChartLibrary.defaults.color = '#718078';
    const series = [
        { id: 'asset-status-chart', data: dashboardChartData.assetStatus, type: 'doughnut', names: { assigned: 'Asignado', available: 'Disponible', maintenance: 'En mantenimiento', retired: 'Baja' }, colors: ['#26745b', '#56a890', '#e3b64c', '#dc6745'] },
        { id: 'asset-type-chart', data: dashboardChartData.assetType, type: 'bar', names: { pc: 'PC', laptop: 'Laptop', computer: 'Computadora', phone: 'Celular', tablet: 'Tableta', other: 'Otro' }, colors: ['#26745b', '#4f7889', '#dc6745', '#e3b64c'] },
        { id: 'antivirus-status-chart', data: dashboardChartData.antivirusStatus, type: 'doughnut', names: { no_antivirus: 'Sin antivirus', no_expiry: 'Sin fecha de vencimiento', expired: 'Vencido', expiring: 'Por vencer', active: 'Vigente' }, colors: ['#84918a', '#4f7889', '#dc6745', '#e3b64c', '#26745b'] },
        ...(sessionUser.role === 'user' ? [{ id: 'maintenance-status-chart', data: dashboardChartData.maintenanceStatus, type: 'doughnut', names: { pending: 'Por confirmar', confirmed: 'Confirmado', reschedule_requested: 'Cambio solicitado', completed: 'Completado', cancelled: 'Cancelado' }, colors: ['#e3b64c', '#26745b', '#4f7889', '#56a890', '#dc6745'] }] : []),
        { id: 'maintenance-month-chart', data: dashboardChartData.maintenanceMonthly, type: 'line', colors: ['#4f7889'] },
    ];
    for (const item of series) {
        const canvas = document.getElementById(item.id);
        if (!canvas) continue;
        const values = item.data.values || [];
        const rawLabels = item.data.labels || [];
        const labels = rawLabels.map((label, index) => item.id === 'antivirus-status-chart'
            ? `${label} (${values[index]})`
            : item.names?.[label] || label);
        const colors = item.id === 'antivirus-status-chart'
            ? rawLabels.map(label => label.endsWith('Vigente') ? '#26745b' : label.endsWith('Por vencer') ? '#e3b64c' : label.endsWith('Vencido') ? '#dc6745' : '#84918a')
            : item.colors;
        const empty = document.getElementById(`${item.id}-empty`);
        const isEmpty = !values.some(value => Number(value) > 0);
        empty?.classList.toggle('hidden', !isEmpty);
        const dataset = { data: values, backgroundColor: item.type === 'line' ? 'rgba(79, 120, 137, .16)' : colors, borderColor: item.type === 'line' ? item.colors[0] : '#ffffff', borderWidth: item.type === 'line' ? 2 : 2, borderRadius: item.type === 'bar' ? 4 : 0, tension: .35, fill: item.type === 'line', pointRadius: item.type === 'line' ? 3 : 0, pointBackgroundColor: item.colors[0] };
        dashboardChartInstances.push(new ChartLibrary(canvas, {
            type: item.type,
            data: { labels, datasets: [dataset] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: item.type === 'doughnut' ? '68%' : undefined,
                plugins: {
                    legend: { display: item.type !== 'bar' && item.type !== 'line', position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, padding: 15, font: { size: 10 } } },
                    tooltip: { callbacks: { label: context => ` ${context.label}: ${context.parsed.y ?? context.parsed}` } },
                },
                scales: item.type === 'bar' || item.type === 'line' ? {
                    x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 9 }, maxRotation: 0 } },
                    y: { beginAtZero: true, grid: { color: '#edf0eb' }, border: { display: false }, ticks: { precision: 0, font: { size: 9 } } },
                } : {},
            },
        }));
    }
}

async function assetsView() {
    const { assets } = await api('assets');
    assetsCache = assets;
    const canEdit = ['admin', 'technician'].includes(sessionUser.role);
    const computers = assets.filter(asset => asset.type !== 'phone');
    const adminPhones = assets.filter(asset => asset.type === 'phone' && asset.phone_category === 'administrative');
    const opsPhones = assets.filter(asset => asset.type === 'phone' && asset.phone_category === 'operational');
    const unclassifiedPhones = assets.filter(asset => asset.type === 'phone' && !['administrative', 'operational'].includes(asset.phone_category));
    const typeLabels = { computer: 'Computadora', phone: 'Celular', tablet: 'Tableta', other: 'Otro' };
    const historyAccess = canEdit || sessionUser.role === 'user';
    const table = (rows, phoneTable = false) => `<div class="table-wrap"><table class="data-table"><thead><tr><th>Etiqueta / equipo</th><th>Tipo</th>${phoneTable ? '<th>Estado celular</th>' : ''}<th>Usuario de Windows</th><th>Persona asignada</th><th>Departamento</th><th>Puesto</th><th>Vencimiento garantía</th><th>Terminal</th><th>Monitor</th><th>Mouse</th><th>RDP</th><th>Contraseña</th><th>Estado inventario</th><th>Fecha de asignación</th>${canEdit || historyAccess ? '<th>Acciones</th>' : ''}</tr></thead><tbody>${rows.map(asset => `<tr class="${Number(asset.has_all_responsibility_letters) ? 'has-all-responsibility-letters' : ''}" data-search="${escapeHtml([asset.asset_tag, asset.brand, asset.model, asset.assigned_name || '', asset.windows_username || '', asset.department || '', asset.job_title || '', asset.terminal || ''].join(' ').toLowerCase())}" data-status="${asset.status}"><td><span class="table-primary">${escapeHtml(asset.asset_tag)}</span><span class="table-secondary">${escapeHtml(asset.brand)} ${escapeHtml(asset.model)} · ${escapeHtml(asset.serial_number || 'Sin número de serie')}</span></td><td>${typeLabels[asset.type] || 'Otro'}</td>${phoneTable ? `<td><span class="status-pill status-${asset.phone_format_status || 'pending'}">${phoneFormatText(asset.phone_format_status)}</span></td>` : ''}<td>${escapeHtml(asset.windows_username || '—')}</td><td class="assigned-name-cell">${escapeHtml(asset.assigned_name || 'Sin asignar')}</td><td>${escapeHtml(asset.department || '—')}</td><td>${escapeHtml(asset.job_title || '—')}</td><td>${fmtAssignedDate(asset.warranty_until)}</td><td>${escapeHtml(asset.terminal || '—')}</td><td>${escapeHtml(asset.monitor || '—')}</td><td>${escapeHtml(asset.mouse || '—')}</td><td>${escapeHtml(asset.rdp_access || '—')}</td><td>${Number(asset.has_windows_password) ? (sessionUser.role === 'admin' ? `<button class="small-button" data-reveal-asset-password="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}">Revelar</button>` : 'Protegida') : '—'}</td><td><span class="status-pill status-${asset.status}">${statusText(asset.status)}</span></td><td>${fmtAssignedDate(asset.assigned_date)}</td>${canEdit || historyAccess ? `<td class="table-actions">${asset.type === 'computer' || asset.type === 'phone' ? `<button class="small-button" data-asset-letters="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}" data-assigned-user="${asset.assigned_user_id || ''}">Carta responsiva</button>` : ''}${canEdit ? `<button class="small-button" data-edit-asset="${asset.id}">Editar</button>` : ''}${historyAccess ? `<button class="small-button" data-asset-history="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}">Historial</button>` : ''}</td>` : ''}</tr>`).join('')}</tbody></table>${rows.length ? '' : emptyState('Sin equipos en esta categoría', 'Los equipos que registres aparecerán aquí.')}</div>`;
    return `<div class="asset-toolbar"><input class="search-input" id="table-search" placeholder="Buscar etiqueta, equipo o usuario…"><span class="mono">${assets.length} activos</span></div><section class="section-panel asset-group"><div class="panel-head"><div><h2>Activos de computadoras</h2><p>Computadoras y otros equipos no celulares · ${computers.length}</p></div></div>${table(computers)}</section><section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares administrativos</h2><p>${adminPhones.length} celulares</p></div></div>${table(adminPhones, historyAccess)}</section><section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares operacionales</h2><p>${opsPhones.length} celulares</p></div></div>${table(opsPhones, historyAccess)}</section>${unclassifiedPhones.length ? `<section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares por clasificar</h2><p>Selecciona Administrativo u Operacional al editar · ${unclassifiedPhones.length}</p></div></div>${table(unclassifiedPhones, historyAccess)}</section>` : ''}`;
    return `<div class="asset-toolbar"><input class="search-input" id="table-search" placeholder="Buscar etiqueta, equipo o usuario…"><span class="mono">${assets.length} activos</span></div><section class="section-panel asset-group"><div class="panel-head"><div><h2>Activos de computadoras</h2><p>Computadoras y otros equipos no celulares · ${computers.length}</p></div></div>${table(computers)}</section><section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares administrativos</h2><p>${adminPhones.length} celulares</p></div></div>${table(adminPhones, true)}</section><section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares operacionales</h2><p>${opsPhones.length} celulares</p></div></div>${table(opsPhones, true)}</section>${unclassifiedPhones.length ? `<section class="section-panel asset-group"><div class="panel-head"><div><h2>Celulares por clasificar</h2><p>Selecciona Administrativo u Operacional al editar · ${unclassifiedPhones.length}</p></div></div>${table(unclassifiedPhones, true)}</section>` : ''}`;
}

function updateAssetTableColumns(target) {
    target.querySelectorAll('.asset-group .data-table').forEach(table => {
        let headers = [...table.querySelectorAll('thead th')];
        const puestoHeader = headers.find(header => header.textContent.trim() === 'Puesto');
        if (puestoHeader && !headers.some(header => header.textContent.trim() === 'Vencimiento licencia ESET')) {
            const expiryHeader = document.createElement('th');
            expiryHeader.textContent = 'Vencimiento licencia ESET';
            puestoHeader.after(expiryHeader);
            const puestoIndex = [...puestoHeader.parentElement.cells].indexOf(puestoHeader);
            table.querySelectorAll('tbody tr').forEach(row => {
                const expiryCell = document.createElement('td');
                row.cells[puestoIndex].after(expiryCell);
            });
        }
        headers = [...table.querySelectorAll('thead th')];
        const hiddenColumns = headers
            .map((header, index) => ['Tipo', 'Usuario de Windows', 'Vencimiento garantía', 'Fecha de asignación', 'Terminal'].includes(header.textContent.trim()) ? index : -1)
            .filter(index => index >= 0)
            .reverse();
        for (const columnIndex of hiddenColumns) [...table.rows].forEach(row => row.deleteCell(columnIndex));
        headers = [...table.querySelectorAll('thead th')];
        const compactHeaders = {
            'Etiqueta / equipo': 'Etiqueta',
            'Usuario de Windows': 'Usuario Windows',
            'Persona asignada': 'Asignado a',
            'Departamento': 'Depto.',
            'Vencimiento garantía': 'Garantía',
            'Estado inventario': 'Estado',
            'Fecha de asignación': 'Asignado el',
            'Vencimiento licencia ESET': 'ESET',
        };
        table.querySelectorAll('thead th').forEach(header => {
            const fullTitle = header.textContent.trim();
            if (compactHeaders[fullTitle]) {
                header.title = fullTitle;
                header.textContent = compactHeaders[fullTitle];
            }
        });
        const assignedIndex = [...table.querySelectorAll('thead th')].findIndex(header => header.textContent.trim() === 'Asignado a');
        if (assignedIndex >= 0) {
            table.querySelectorAll('tbody tr').forEach(row => {
                const assetTag = row.querySelector('.table-primary')?.textContent.trim();
                const asset = assetsCache.find(item => item.asset_tag === assetTag);
                const assignedNames = asset?.assignees?.length
                    ? asset.assignees.map(person => person.name)
                    : (asset?.assigned_users || []);
                const cell = row.cells[assignedIndex];
                if (!assignedNames.length) {
                    cell.textContent = 'Sin asignar';
                    return;
                }
                const nameStack = document.createElement('div');
                nameStack.className = 'asset-assignee-stack';
                for (const name of assignedNames) {
                    const personName = document.createElement('span');
                    personName.textContent = formatPersonName(name);
                    nameStack.append(personName);
                }
                cell.replaceChildren(nameStack);
            });
        }

        const expiryIndex = [...table.querySelectorAll('thead th')].findIndex(header => header.title === 'Vencimiento licencia ESET');
        if (expiryIndex < 0) return;
        table.querySelectorAll('tbody tr').forEach(row => {
            const assetTag = row.querySelector('.table-primary')?.textContent.trim();
            const asset = assetsCache.find(item => item.asset_tag === assetTag);
            row.cells[expiryIndex].textContent = asset?.antivirus_expires_on ? fmtAssignedDate(asset.antivirus_expires_on) : '—';
        });
    });
}

function addAssetFilters(target) {
    const toolbar = target.querySelector('.asset-toolbar');
    const search = toolbar?.querySelector('#table-search');
    const count = toolbar?.querySelector('.mono');
    if (!toolbar || !search || !count) return;

    const makeFilter = (id, label, options) => {
        const select = document.createElement('select');
        select.id = id;
        select.className = 'filter-select';
        select.setAttribute('aria-label', label);
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = label;
        select.append(defaultOption);
        for (const [value, text] of options) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            select.append(option);
        }
        select.value = '';
        return select;
    };

    const departments = [...new Set(assetsCache.map(asset => (asset.department || '').trim()).filter(Boolean))].sort((left, right) => left.localeCompare(right, 'es-MX'));
    const departmentFilter = makeFilter('asset-department-filter', 'Todos los departamentos', departments.map(department => [department.toLocaleLowerCase('es-MX'), department]));
    departmentFilter.add(new Option('Sin departamento', '__none__'));
    const statusFilter = makeFilter('asset-status-filter', 'Todos los estados', [
        ['assigned', 'Activo'],
        ['available', 'Disponible'],
        ['maintenance', 'En mantenimiento'],
        ['retired', 'Dado de baja'],
    ]);
    const responsibilityFilter = makeFilter('asset-responsibility-filter', 'Todas las cartas', [
        ['missing', 'Personas sin carta responsiva'],
    ]);
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'small-button';
    clearButton.id = 'asset-filter-clear';
    clearButton.textContent = 'Limpiar filtros';
    count.id = 'asset-result-count';

    toolbar.insertBefore(departmentFilter, count);
    toolbar.insertBefore(statusFilter, count);
    toolbar.insertBefore(responsibilityFilter, count);
    toolbar.insertBefore(clearButton, count);

    const pcCount = assetsCache.filter(asset => !asset.asset_tag.toUpperCase().startsWith('RAS-LT')).length;
    const laptopCount = assetsCache.length - pcCount;
    const tabs = document.createElement('div');
    tabs.className = 'asset-tabs';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Filtrar PC y laptops');
    tabs.innerHTML = [['all', 'Todos', assetsCache.length], ['pc', 'PC', pcCount], ['laptop', 'Laptop', laptopCount]]
        .map(([key, label, total]) => `<button type="button" class="asset-tab ${assetTab === key ? 'active' : ''}" role="tab" aria-selected="${assetTab === key}" data-asset-tab="${key}">${label}<span>${total}</span></button>`)
        .join('');
    toolbar.after(tabs);

    const tableWrap = target.querySelector('.asset-group .table-wrap');
    const pagination = document.createElement('div');
    pagination.className = 'asset-pagination';
    pagination.innerHTML = '<label>Mostrar <select id="asset-page-size" class="filter-select" aria-label="Registros por página"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="0" selected>Todos</option></select></label><span id="asset-page-summary" class="field-help"></span><div class="asset-page-actions"><button type="button" class="small-button" id="asset-page-previous">Anterior</button><button type="button" class="small-button" id="asset-page-next">Siguiente</button></div>';
    pagination.querySelector('#asset-page-size').value = String(assetPageSize);
    tableWrap.after(pagination);

    target.querySelectorAll('.asset-group tbody tr').forEach(row => {
        const assetTag = row.querySelector('.table-primary')?.textContent.trim() || '';
        const asset = assetsCache.find(item => item.asset_tag === assetTag);
        row.dataset.department = (asset?.department || '').trim().toLocaleLowerCase('es-MX');
        row.dataset.deviceCategory = assetTag.toUpperCase().startsWith('RAS-LT') ? 'laptop' : 'pc';
        row.dataset.missingResponsibility = String(Boolean(
            (asset?.assignees?.length || asset?.assigned_users?.length)
            && !Number(asset?.has_all_responsibility_letters)
        ));
    });
    filterAssets();
}

async function licensesView() {
    const { licenses } = await api('licenses');
    return `<section class="section-panel"><div class="toolbar"><span class="mono">${licenses.length} licencias registradas</span><span class="toolbar-spacer"></span><span class="field-help">Las claves se cifran; solo administración puede revelarlas.</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Producto</th><th>Proveedor</th><th>Activaciones</th><th>Vigencia</th><th>Estado</th><th></th></tr></thead><tbody>${licenses.map(item => `<tr><td><span class="table-primary">${escapeHtml(item.product)}</span><span class="table-secondary">${escapeHtml(item.assigned_assets || item.notes || 'Sin asignaciones registradas')}</span></td><td>${escapeHtml(item.vendor || '—')}</td><td><span class="mono">${Number(item.seats_confirmed) ? `${item.activations} / ${item.seats}` : `${item.activations} asignaciones · capacidad por confirmar`}</span></td><td>${fmtDate(item.expires_on)}</td><td><span class="status-pill status-${item.status}">${statusText(item.status)}</span></td><td class="table-actions"><button class="small-button" data-edit-license="${item.id}">Actualizar</button>${sessionUser.role === 'admin' && Number(item.has_key) ? `<button class="small-button" data-reveal-license-key="${item.id}">Clave</button>` : ''}</td></tr>`).join('')}</tbody></table>${licenses.length ? '' : emptyState('Sin licencias registradas', 'Agrega licencias para controlar sus asientos y vencimientos.')}</div></section>`;
}

async function usersView() {
    const { users } = await api('users');
    return `<section class="section-panel"><div class="toolbar"><span class="mono">${users.length} cuentas</span><span class="toolbar-spacer"></span><span class="field-help">Las contraseñas se almacenan con hash seguro y no se pueden consultar.</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nombre</th><th>Correo</th><th>Área</th><th>Permiso</th><th>Alta</th></tr></thead><tbody>${users.map(item => `<tr><td><span class="table-primary">${escapeHtml(item.name)}</span></td><td>${escapeHtml(item.email)}</td><td>${escapeHtml(item.department || '—')}</td><td><span class="status-pill ${item.role === 'admin' ? 'status-confirmed' : ''}">${item.role === 'admin' ? 'Administrador' : item.role === 'technician' ? 'Técnico' : 'Usuario'}</span></td><td>${fmtDate(item.created_at)}</td></tr>`).join('')}</tbody></table>${users.length ? '' : emptyState('Sin cuentas adicionales', 'Agrega usuarios para asignarles equipos y documentos.')}</div></section>`;
}

async function maintenanceViewLegacy() { return maintenanceView(); }

async function maintenanceView() {
    const year = maintenanceCalendarDate.getFullYear();
    const [{ maintenances, annualProgress }, departmentPlan] = await Promise.all([
        api('maintenances', { query: { year } }),
        ['admin', 'technician'].includes(sessionUser.role) ? api('maintenance-department-plan', { query: { year } }) : Promise.resolve(null),
    ]);
    maintenanceDepartmentPlanCache = departmentPlan;
    maintenanceCache = maintenances;
    const now = new Date();
    const overdue = item => ['pending', 'confirmed', 'reschedule_requested'].includes(item.status) && new Date(String(item.calendar_at || item.scheduled_at).replace(' ', 'T')) < now;
    const card = (label, value, note, tone) => `<article class="maintenance-kpi maintenance-kpi-${tone}"><span>${label}</span><strong>${value}</strong><small>${note}</small></article>`;
    const currentYearItems = maintenances.filter(item => String(item.calendar_at || item.scheduled_at).slice(0, 4) === String(year));
    const completed = currentYearItems.filter(item => item.status === 'completed').length;
    const pending = currentYearItems.filter(item => ['pending', 'reschedule_requested'].includes(item.status)).length;
    const confirmed = currentYearItems.filter(item => item.status === 'confirmed').length;
    const delayed = currentYearItems.filter(overdue).length;
    const progressCards = (annualProgress || []).map(item => {
        const count = Math.min(2, Number(item.completed_count) || 0);
        return `<article class="maintenance-progress-card ${count === 2 ? 'complete' : ''}"><div><strong>${escapeHtml(item.asset_tag)}</strong><span>${escapeHtml(`${item.brand || ''} ${item.model || ''}`.trim())}</span></div><b>${count}/2</b><div class="maintenance-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="2" aria-valuenow="${count}" aria-label="${escapeHtml(item.asset_tag)}: ${count} de 2 mantenimientos completados"><span style="width:${count * 50}%"></span></div><small>${count === 2 ? 'Meta anual cumplida' : `Faltan ${2 - count} por completar`}</small></article>`;
    }).join('');
    return `<section class="maintenance-overview">${card('Servicios del año', currentYearItems.length, String(year), 'neutral')}${card('Por confirmar', pending, 'Requieren seguimiento', 'warning')}${card('Confirmados', confirmed, 'Horario reservado', 'info')}${card('Vencidos', delayed, 'Pasó su horario', 'danger')}${card('Completados', completed, 'Cierres F-SIS-01', 'success')}</section>
    ${departmentPlan ? maintenanceDepartmentPlanningMarkup(departmentPlan) : ''}
    <section class="section-panel maintenance-progress-panel"><div class="maintenance-section-heading"><div><h2>Avance anual por equipo</h2><p>Solo cuentan los servicios completados durante ${year}.</p></div><strong>${annualProgress?.length || 0} equipos</strong></div><div class="maintenance-progress-grid">${progressCards || emptyState('Sin equipos activos', 'Agrega equipos en Activos para ver su avance anual.')}</div></section>
    <section class="section-panel maintenance-calendar-panel"><div class="maintenance-calendar-toolbar"><div class="maintenance-calendar-nav"><button type="button" class="small-button" id="maintenance-calendar-prev" aria-label="Periodo anterior">‹</button><button type="button" class="small-button" id="maintenance-calendar-today">Hoy</button><button type="button" class="small-button" id="maintenance-calendar-next" aria-label="Periodo siguiente">›</button><h2 id="maintenance-calendar-label"></h2></div><div class="maintenance-calendar-views"><button type="button" data-calendar-view="month" class="${maintenanceCalendarView === 'month' ? 'active' : ''}">Mes</button><button type="button" data-calendar-view="week" class="${maintenanceCalendarView === 'week' ? 'active' : ''}">Semana</button><button type="button" data-calendar-view="day" class="${maintenanceCalendarView === 'day' ? 'active' : ''}">Día</button></div></div><div class="maintenance-calendar-filters"><input class="search-input" id="maintenance-search" type="search" placeholder="Buscar persona, inventario, marca o modelo"><select class="filter-select" id="maintenance-status-filter"><option value="">Todos los estados</option><option value="pending">Por confirmar</option><option value="confirmed">Confirmados</option><option value="reschedule_requested">Cambio solicitado</option><option value="completed">Completados</option><option value="cancelled">Cancelados</option></select><span class="field-help">Atención: 8:30 a. m. a 5:00 p. m. · intervalos de 30 minutos</span></div><div id="maintenance-calendar">${maintenanceCalendarMarkup(maintenances)}</div></section>`;
}

const maintenanceMonthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
function maintenanceDepartmentPlanningMarkup(data) {
    const plannedAssets = data.plans.reduce((sum, plan) => sum + Number(plan.current_assets || 0), 0);
    const completedAssets = data.plans.reduce((sum, plan) => sum + Number(plan.completed_assets || 0), 0);
    const months = data.months.map(entry => {
        const plans = entry.departments || [];
        const teamCount = plans.reduce((sum, plan) => sum + Number(plan.current_assets || 0), 0);
        const rows = plans.map(plan => {
            const progress = Number(plan.progress || 0);
            const state = plan.request_status === 'confirmed' ? (Number(plan.pending_assets) ? 'Horario confirmado' : 'Mantenimiento completado') : plan.request_status === 'email_sent' ? 'Horario pendiente de confirmación' : plan.request_status === 'notification_pending' ? 'Notificación pendiente' : plan.request_status === 'cancelled' ? 'Solicitud cancelada' : plan.request_status === 'expired' ? 'Solicitud vencida' : 'Pendiente de programación';
            return `<article class="department-plan-row"><div class="department-plan-title"><strong>${escapeHtml(plan.department)}</strong><span>${Number(plan.current_assets)} equipos · ${Number(plan.completed_assets)} completados · ${Number(plan.pending_assets)} pendientes</span><small>${state}${plan.scheduled_start ? ` · ${escapeHtml(dateTime(plan.scheduled_start))}` : ''}</small></div><div class="maintenance-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${progress}" aria-label="${escapeHtml(plan.department)} ${progress}%"><span style="width:${progress}%"></span></div><div class="department-plan-actions"><button class="small-button" type="button" data-department-equipment="${plan.id}">Equipos</button>${sessionUser.role === 'admin' ? `<button class="small-button primary-mini" type="button" data-department-notify="${plan.id}">${['email_sent','expired'].includes(plan.request_status) ? 'Revisar / reenviar' : plan.request_status === 'confirmed' ? 'Ver solicitud' : 'Revisar y notificar'}</button>` : ''}</div></article>`;
        }).join('');
        return `<section class="department-month-card"><header><div><h3>${maintenanceMonthNames[entry.month - 1]}</h3><span>${teamCount} equipos · ${plans.length} departamentos</span></div>${sessionUser.role === 'admin' ? `<button class="small-button" type="button" data-department-edit-month="${entry.month}">Editar</button>` : ''}</header><div class="department-month-rows">${rows || '<div class="department-month-empty">Sin departamentos programados. Selecciona “Editar” para preparar la distribución.</div>'}</div></section>`;
    }).join('');
    const unclassified = data.unclassifiedAssets || [];
    return `<section class="section-panel department-planning-panel"><div class="maintenance-section-heading"><div><h2>Programa anual por departamento</h2><p>Equipos y departamentos se leen de Activos. La distribución de ${data.year} es editable.</p></div><label class="department-year-picker">Año<select id="department-plan-year">${data.years.map(value => `<option value="${value}" ${Number(value) === Number(data.year) ? 'selected' : ''}>${value}</option>`).join('')}</select></label></div><div class="department-plan-summary"><strong>${data.departments.filter(dept => dept.eligible && !dept.unclassified).length} departamentos disponibles</strong><span>${plannedAssets} equipos planificados · ${completedAssets} completados</span><span>Gmail ${data.emailReady ? 'configurado' : 'pendiente de configuración'}</span></div><details class="department-directory"><summary>Consultar equipos por departamento (${data.departments.filter(dept => !dept.unclassified).length})</summary><div class="department-directory-grid">${data.departments.filter(dept => !dept.unclassified).map(dept => `<details class="department-directory-item"><summary>${escapeHtml(dept.name || 'Sin departamento')} · ${Number(dept.totalAssets)} equipos (${Number(dept.activeAssets)} activos)</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Inventario</th><th>Equipo</th><th>Responsable / persona(s) asignada(s)</th><th>Estado</th></tr></thead><tbody>${dept.assets.map(asset => `<tr><td>${escapeHtml(asset.asset_tag)}</td><td>${escapeHtml(`${asset.brand || ''} ${asset.model || ''}`.trim())}</td><td>${escapeHtml((asset.assigned_people || []).join(', ') || 'Sin persona asignada')}</td><td>${escapeHtml(statusText(asset.status))}</td></tr>`).join('') || '<tr><td colspan="4">Sin equipos vigentes.</td></tr>'}</tbody></table></div></details>`).join('')}</div></details><div class="department-month-grid">${months}</div><details class="department-unclassified"><summary>Pendientes de clasificación (${unclassified.length})</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Inventario</th><th>Equipo</th><th>Departamento en Activos</th><th>Estado</th></tr></thead><tbody>${unclassified.map(asset => `<tr><td>${escapeHtml(asset.asset_tag)}</td><td>${escapeHtml(`${asset.brand || ''} ${asset.model || ''}`.trim())}</td><td>${escapeHtml(asset.department || 'Sin departamento')}</td><td>${escapeHtml(statusText(asset.status))}</td></tr>`).join('') || '<tr><td colspan="4">No hay equipos sin clasificación.</td></tr>'}</tbody></table></div><p>Estos equipos requieren clasificación en Activos y no se asignan automáticamente.</p></details><details class="department-plan-history"><summary>Historial de cambios (${data.history.length})</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Mes</th><th>Departamento</th><th>Acción</th><th>Destinatario</th></tr></thead><tbody>${data.history.map(row => `<tr><td>${escapeHtml(dateTime(row.changed_at))}</td><td>${maintenanceMonthNames[Number(row.plan_month)-1]}</td><td>${escapeHtml(row.department)}</td><td>${row.action === 'added' ? 'Agregado' : row.action === 'removed' ? 'Retirado' : 'Destinatario actualizado'}</td><td>${escapeHtml(row.recipient_email || '—')}</td></tr>`).join('') || '<tr><td colspan="5">Aún no hay cambios.</td></tr>'}</tbody></table></div></details></section>`;
}

function openDepartmentMonthEditor(month, data) {
    const scheduled = new Set((data.months.find(item => Number(item.month) === Number(month))?.departments || []).map(plan => plan.department));
    const options = data.departments.filter(dept => dept.eligible && !dept.unclassified).map(dept => `<label class="department-check"><input type="checkbox" name="department" value="${escapeHtml(dept.name)}" ${scheduled.has(dept.name) ? 'checked' : ''}><span><strong>${escapeHtml(dept.name)}</strong><small>${Number(dept.activeAssets)} equipos activos</small></span></label>`).join('');
    showModal(`Planificación · ${maintenanceMonthNames[month - 1]} ${data.year}`, `<form id="department-month-form"><p>Selecciona departamentos registrados en Activos. Los equipos y personas asignadas se actualizan desde el inventario.</p><div class="department-check-list">${options || '<p>No hay departamentos con equipos vigentes.</p>'}</div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button type="button" class="secondary-button" data-close>Cancelar</button><button type="submit" class="primary-button">Guardar planificación</button></div></form>`);
    document.querySelector('#department-month-form')?.addEventListener('submit', async event => {
        event.preventDefault(); const button = event.currentTarget.querySelector('[type="submit"]'); button.disabled = true;
        try { await api('maintenance-department-plan-save', { method: 'POST', body: { year: Number(data.year), month: Number(month), departments: [...event.currentTarget.querySelectorAll('input[name="department"]:checked')].map(input => input.value) } }); closeModal(); toast('Planificación guardada con historial.'); await renderApp(); }
        catch (error) { document.querySelector('#modal-error').textContent = error.message; button.disabled = false; }
    });
}

async function openDepartmentDetails(planId, notify = false) {
    try {
        const preview = await api('maintenance-department-preview', { query: { id: planId } });
        const plan = preview.plan; const monthName = maintenanceMonthNames[Number(plan.plan_month) - 1];
        const equipment = preview.assets.map(asset => `<tr><td>${escapeHtml(asset.asset_tag)}</td><td>${escapeHtml(`${asset.brand || ''} ${asset.model || ''}`.trim())}</td><td>${escapeHtml((asset.assigned_people || []).join(', ') || 'Sin persona asignada')}</td></tr>`).join('');
        if (!notify) { showModal(`${plan.department} · ${monthName} ${plan.plan_year}`, `<p>${preview.assets.length} equipos activos en Activos.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Inventario</th><th>Equipo</th><th>Persona(s) asignada(s)</th></tr></thead><tbody>${equipment}</tbody></table></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>`); return; }
        const recipient = plan.request_email || plan.recipient_email || preview.emailSuggestions[0] || '';
        const resend = ['email_sent', 'expired'].includes(plan.request_status);
        showModal(`Revisar notificación · ${plan.department}`, `<p>Periodo: <strong>${monthName} ${plan.plan_year}</strong> · Equipos: <strong>${preview.assets.length}</strong></p><p>Verifica el destinatario y los equipos antes de enviar.</p><div class="field"><label for="department-recipient">Correo del responsable *</label><input id="department-recipient" type="email" maxlength="190" required value="${escapeHtml(recipient)}" placeholder="nombre@empresa.com"></div>${preview.emailSuggestions.length ? `<p class="field-help">Correos vinculados a cuentas de Activos: ${preview.emailSuggestions.map(escapeHtml).join(', ')}. Confirma que sea el contacto correcto.</p>` : '<p class="field-help">No hay correos relacionados en Activos. Ingresa el correo confirmado del responsable.</p>'}<details><summary>Revisar equipos incluidos</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Inventario</th><th>Equipo</th><th>Asignado a</th></tr></thead><tbody>${equipment}</tbody></table></div></details><div class="notice ${preview.emailReady ? '' : 'error'}"><span>!</span><div><strong>${preview.emailReady ? 'Gmail SMTP configurado' : 'Gmail no disponible'}</strong>${preview.emailReady ? 'Se informará si SMTP acepta el correo.' : 'Revisa configuración local y PHPMailer.'}</div></div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cancelar</button><button class="primary-button" type="button" id="department-send" ${preview.emailReady ? '' : 'disabled'}>${resend ? 'Revocar enlace y reenviar' : 'Enviar correo revisado'}</button></div>`);
        document.querySelector('#department-send')?.addEventListener('click', async event => {
            const email = document.querySelector('#department-recipient').value.trim(); if (!email) { document.querySelector('#modal-error').textContent = 'Ingresa el correo confirmado del destinatario.'; return; }
            const button = event.currentTarget; button.disabled = true;
            try { const result = await api('maintenance-department-notify', { method: 'POST', body: { planId: Number(planId), recipientEmail: email, resend } }); closeModal(); toast(result.message, result.emailSent ? '' : 'error'); await renderApp(); }
            catch (error) { document.querySelector('#modal-error').textContent = error.message; button.disabled = false; }
        });
    } catch (error) { toast(error.message, 'error'); }
}

function maintenanceDateKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function maintenanceCalendarMarkup(maintenances) {
    const pivot = new Date(maintenanceCalendarDate.getFullYear(), maintenanceCalendarDate.getMonth(), maintenanceCalendarDate.getDate());
    const dateFor = item => new Date(String(item.calendar_at || item.scheduled_at).replace(' ', 'T'));
    const events = maintenances.map(item => ({ item, date: dateFor(item) }));
    const effectiveStatus = item => ['pending', 'confirmed', 'reschedule_requested', 'completed', 'cancelled'].includes(item.status) ? item.status : 'pending';
    const eventButton = ({ item, date }) => `<button type="button" class="maintenance-calendar-event status-${effectiveStatus(item)}" data-maintenance-event="${item.id}" data-date="${maintenanceDateKey(date)}" data-status="${effectiveStatus(item)}" data-search="${escapeHtml([item.asset_tag, item.brand, item.model, item.user_name, item.service_notes].join(' ').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-MX'))}"><time>${date.toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' })}</time><strong>${escapeHtml(item.asset_tag)}</strong><span>${escapeHtml(item.user_name)}</span></button>`;
    const days = [];
    const firstDay = new Date(pivot.getFullYear(), pivot.getMonth(), 1);
    const mondayOffset = (firstDay.getDay() + 6) % 7;
    const monthStart = new Date(pivot.getFullYear(), pivot.getMonth(), 1 - mondayOffset);
    if (maintenanceCalendarView === 'month') {
        for (let i = 0; i < 42; i++) days.push(new Date(monthStart.getFullYear(), monthStart.getMonth(), monthStart.getDate() + i));
        return `<div class="maintenance-month-grid">${['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'].map(day => `<div class="maintenance-calendar-weekday">${day}</div>`).join('')}${days.map(day => {
            const matches = events.filter(event => maintenanceDateKey(event.date) === maintenanceDateKey(day));
            const outside = day.getMonth() !== pivot.getMonth();
            return `<section class="maintenance-month-day ${outside ? 'outside-month' : ''} ${maintenanceDateKey(day) === maintenanceDateKey(new Date()) ? 'today' : ''}" data-calendar-day="${maintenanceDateKey(day)}"><button class="maintenance-day-number" type="button" data-calendar-date="${maintenanceDateKey(day)}">${day.getDate()}</button><div class="maintenance-day-events">${matches.slice(0, 3).map(eventButton).join('')}${matches.length > 3 ? `<button type="button" class="maintenance-more-events" data-calendar-date="${maintenanceDateKey(day)}">+${matches.length - 3} más</button>` : ''}</div></section>`;
        }).join('')}</div>`;
    }
    const start = new Date(pivot);
    if (maintenanceCalendarView === 'week') start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
    const dayCount = maintenanceCalendarView === 'week' ? 7 : 1;
    for (let i = 0; i < dayCount; i++) days.push(new Date(start.getFullYear(), start.getMonth(), start.getDate() + i));
    if (maintenanceCalendarView === 'week') return `<div class="maintenance-week-grid">${days.map(day => `<section class="maintenance-week-day"><header><span>${day.toLocaleDateString('es-MX', { weekday: 'short' })}</span><strong>${day.getDate()}</strong></header><div class="maintenance-day-events">${events.filter(event => maintenanceDateKey(event.date) === maintenanceDateKey(day)).map(eventButton).join('') || '<span class="maintenance-empty-day">Sin servicios</span>'}</div></section>`).join('')}</div>`;
    const day = days[0];
    const slots = [];
    for (let minute = 510; minute < 1020; minute += 30) {
        const time = `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
        const matches = events.filter(event => maintenanceDateKey(event.date) === maintenanceDateKey(day) && event.date.toTimeString().slice(0, 5) === time);
        slots.push(`<div class="maintenance-day-slot"><time>${time}</time><div>${matches.map(eventButton).join('')}</div></div>`);
    }
    return `<div class="maintenance-day-view"><header>${day.toLocaleDateString('es-MX', { dateStyle: 'full' })}</header>${slots.join('')}</div>`;
}

async function credentialsView() {
    const { credentials } = await api('credentials');
    return `<div class="notice"><span>!</span><div><strong>Bóveda protegida</strong>Los secretos se cifran en el servidor con una clave fuera de la base de datos. La clave de cifrado debe estar configurada para guardar o revelar credenciales.</div></div><section class="section-panel"><div class="toolbar"><span class="mono">${credentials.length} accesos</span><span class="toolbar-spacer"></span><span class="field-help">La contraseña permanece oculta hasta solicitarla.</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Servicio</th><th>Usuario</th><th>Equipo relacionado</th><th>Actualización</th><th></th></tr></thead><tbody>${credentials.map(item => `<tr><td><span class="table-primary">${escapeHtml(item.label)}</span></td><td>${escapeHtml(item.username || '—')}</td><td>${escapeHtml(item.asset_tag || 'General')}</td><td>${fmtDate(item.updated_at)}</td><td class="table-actions"><button class="small-button" data-reveal="${item.id}">Revelar</button></td></tr>`).join('')}</tbody></table>${credentials.length ? '' : emptyState('Bóveda vacía', 'Guarda accesos técnicos con cifrado activo.')}</div></section>`;
}

async function documentsView() {
    const { documents } = await api('documents');
    const isStaff = ['admin', 'technician'].includes(sessionUser.role);
    const createButtons = isStaff ? '<div class="document-list-actions"><button class="primary-button" id="create-service-order"><span class="button-icon">+</span>F-SIS-01 · Orden de Servicio</button><button class="secondary-button" id="create-corrective-request"><span class="button-icon">+</span>F-SIS-02 · Solicitud Correctiva</button></div>' : '';
    return `<section class="section-panel"><div class="toolbar document-toolbar"><span class="mono">${documents.length} documentos</span><span class="toolbar-spacer"></span><span class="field-help">${isStaff ? 'Los formatos nuevos guardan las dos firmas, fecha e IP.' : 'Consulta tus formatos firmados y pendientes.'}</span>${createButtons}</div>${!isStaff ? '<div class="document-access-note">La creación de formatos está disponible para administración y técnicos.</div>' : ''}<div class="table-wrap"><table class="data-table"><thead><tr><th>Documento</th><th>Equipo</th><th>Responsable</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${documents.map(doc => { const signed = Boolean(doc.signed_at && (doc.form_type === 'responsibility' || doc.performed_by_signed_at)); return `<tr><td><span class="table-primary">${escapeHtml(doc.title)}</span><span class="table-secondary">${signed ? `Firmado ${dateTime(doc.signed_at)}` : 'Pendiente de firma'}</span></td><td>${escapeHtml(doc.asset_tag)}<span class="table-secondary">${escapeHtml(doc.brand)} ${escapeHtml(doc.model)}</span></td><td>${escapeHtml(doc.user_name)}</td><td><span class="status-pill ${signed ? 'status-completed' : 'status-pending'}">${signed ? 'Firmado' : 'Pendiente'}</span></td><td>${!signed && !isStaff ? `<button class="small-button" data-sign-doc="${doc.id}" data-title="${escapeHtml(doc.title)}">Firmar</button>` : signed ? `<button class="small-button" data-doc-receipt="${doc.id}" data-title="${escapeHtml(doc.title)}">Imprimir</button>` : '—'}</td></tr>`; }).join('')}</tbody></table>${documents.length ? '' : emptyState('Sin documentos por ahora', isStaff ? 'Crea una Orden de Servicio o una Solicitud Correctiva.' : 'No tienes documentos pendientes de firma.')}</div></section>`;
}

async function addDocumentDeleteButtons() {
    try {
        const { documents } = await api('documents');
        const rows = [...document.querySelectorAll('.data-table tbody tr')].filter(row => !row.querySelector('.empty-state'));
        documents.forEach((doc, index) => {
            const actions = rows[index]?.lastElementChild;
            if (!actions || actions.querySelector(`[data-delete-doc="${doc.id}"]`)) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'small-button';
            button.dataset.deleteDoc = String(doc.id);
            button.dataset.title = doc.title;
            button.textContent = 'Eliminar';
            button.addEventListener('click', () => deleteDocument(button.dataset.deleteDoc, button.dataset.title));
            actions.append(' ', button);
        });
    } catch (error) { toast(error.message, 'error'); }
}

function deleteDocument(id, title) {
    if (!window.confirm(`¿Confirmas eliminar “${title}”?`)) return;
    showModal('Confirmar eliminación', `<form id="delete-document-form"><p>Se eliminará el documento “${escapeHtml(title)}”. Para continuar, confirma con tu contraseña.</p><div class="field"><label>Contraseña de tu cuenta *</label><input name="password" type="password" required autocomplete="current-password"></div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cancelar</button><button class="primary-button" type="submit">Eliminar documento</button></div></form>`);
    document.querySelector('#delete-document-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await api('document-delete', { method: 'POST', body: { id: Number(id), password: new FormData(form).get('password') } });
            closeModal();
            toast('Documento eliminado.');
            await renderApp();
        } catch (error) {
            document.querySelector('#modal-error').textContent = error.message;
            button.disabled = false;
        }
    });
}

function formatFileSize(bytes) {
    const size = Number(bytes) || 0;
    return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function responsibilityLetterUrl(letterId, preview = false) {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', 'responsibility-letter-download');
    url.searchParams.set('id', String(letterId));
    if (preview) url.searchParams.set('preview', '1');
    return url.href;
}

async function manageResponsibilityLetters(assetId, assetLabel, assignedUserId = '') {
    try {
        const { letters } = await api('responsibility-letters', { query: { assetId } });
        const isStaff = ['admin', 'technician'].includes(sessionUser.role);
        const asset = assetsCache.find(item => String(item.id) === String(assetId));
        const assignedPeople = asset?.assignees?.length
            ? asset.assignees
            : peopleCache.filter(person => (asset?.assigned_user_ids || []).includes(Number(person.id))).map(person => ({ id: person.id, user_id: person.id, name: person.name }));
        const lettersByAssignee = new Map();
        for (const letter of letters) {
            const key = letter.assignee_id ? `assignee:${letter.assignee_id}` : `user:${letter.user_id}`;
            const personLetters = lettersByAssignee.get(key) || [];
            personLetters.push(letter);
            lettersByAssignee.set(key, personLetters);
        }
        const assignmentRows = assignedPeople.length
            ? `<ul class="responsibility-assignment-list">${assignedPeople.map(person => {
                const key = person.user_id ? `assignee:${person.id}` : `assignee:${person.id}`;
                const fallbackLetters = person.user_id ? lettersByAssignee.get(`user:${person.user_id}`) || [] : [];
                const personLetters = lettersByAssignee.get(key) || fallbackLetters;
                const statusClass = personLetters.length ? 'status-completed' : 'status-pending';
                const statusLabel = personLetters.length ? `Carta cargada · ${personLetters.length}` : 'Carta pendiente';
                return `<li><div><strong>${escapeHtml(formatPersonName(person.name))}</strong><span class="status-pill ${statusClass}">${statusLabel}</span></div>${isStaff ? `<button type="button" class="small-button" data-select-letter-assignee="${person.id}">${personLetters.length ? 'Reemplazar carta' : 'Subir carta'}</button>` : ''}</li>`;
            }).join('')}</ul>`
            : '<div class="empty-state"><strong>Sin personas asignadas</strong><span>Asigna a las personas que comparten este equipo para controlar sus cartas.</span></div>';
        const letterRows = letters.length
            ? `<ul class="responsibility-letter-list">${letters.map(letter => {
                const downloadUrl = responsibilityLetterUrl(letter.id);
                return `<li><div><strong>${escapeHtml(formatPersonName(letter.user_name))} · ${escapeHtml(letter.original_name)}</strong><span>${formatFileSize(letter.file_size)} · ${dateTime(letter.created_at)}</span></div><div class="responsibility-letter-actions"><button type="button" class="small-button" data-preview-letter="${letter.id}" data-letter-name="${escapeHtml(letter.original_name)}" data-letter-mime="${escapeHtml(letter.mime_type)}">Vista previa</button><a class="small-button letter-download" href="${escapeHtml(downloadUrl)}">Descargar</a><button type="button" class="small-button" data-print-letter="${letter.id}">Imprimir</button>${isStaff ? `<button type="button" class="small-button danger-button" data-delete-letter="${letter.id}" data-letter-owner="${escapeHtml(formatPersonName(letter.user_name))}">Eliminar</button>` : ''}</div></li>`;
            }).join('')}</ul>`
            : '<div class="empty-state"><strong>Sin cartas escaneadas</strong><span>Agrega una carta por cada persona que use este activo.</span></div>';
        const uploadForm = isStaff ? `<form id="responsibility-letter-form" class="responsibility-letter-form"><input type="hidden" name="assetId" value="${escapeHtml(assetId)}"><div class="field"><label for="responsibility-letter-assignee">Persona *</label><select id="responsibility-letter-assignee" name="assigneeId" required>${selectOptions(assignedPeople, 'id', person => formatPersonName(person.name), 'Selecciona persona')}</select><span class="field-help">Cada persona asignada tiene una carta. Al subir otra, reemplaza la anterior.</span></div><div class="field"><label for="responsibility-letter-file">Archivo escaneado *</label><input id="responsibility-letter-file" name="letter" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div><div id="responsibility-letter-preview" class="responsibility-letter-preview hidden" aria-live="polite"></div><div id="responsibility-letter-error" class="form-error" role="alert"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button><button class="primary-button" type="submit">Subir carta</button></div></form>` : '<div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>';
        showModal(`Cartas responsivas · ${assetLabel}`, `<p class="field-help">Cada persona asignada debe tener su propia carta. Archivos PDF, JPG o PNG; máximo 10 MB.</p><section class="responsibility-assignment-section"><h3>Personas asignadas</h3>${assignmentRows}</section><section class="responsibility-files-section"><h3>Cartas cargadas</h3>${letterRows}</section>${uploadForm}`);
        const assigneeSelect = document.querySelector('#responsibility-letter-assignee');
        if (assigneeSelect && assignedUserId) {
            const assignedPerson = assignedPeople.find(person => String(person.user_id) === String(assignedUserId));
            if (assignedPerson) assigneeSelect.value = String(assignedPerson.id);
        }
        const previewContainer = document.querySelector('#responsibility-letter-preview');
        const fileInput = document.querySelector('#responsibility-letter-file');
        document.querySelectorAll('[data-select-letter-assignee]').forEach(button => button.addEventListener('click', () => {
            if (assigneeSelect) assigneeSelect.value = button.dataset.selectLetterAssignee;
            fileInput?.click();
        }));
        document.querySelectorAll('[data-preview-letter]').forEach(button => button.addEventListener('click', () => previewResponsibilityLetter(
            button.dataset.previewLetter,
            button.dataset.letterName,
            button.dataset.letterMime,
        )));
        document.querySelectorAll('[data-print-letter]').forEach(button => button.addEventListener('click', () => printResponsibilityLetter(button.dataset.printLetter)));
        document.querySelectorAll('[data-delete-letter]').forEach(button => button.addEventListener('click', async () => {
            if (!window.confirm(`¿Eliminar la carta responsiva de ${button.dataset.letterOwner}? Esta acción no se puede deshacer.`)) return;
            button.disabled = true;
            try {
                await api('responsibility-letter-delete', { method: 'POST', body: { id: Number(button.dataset.deleteLetter) } });
                toast('Carta responsiva eliminada.');
                await manageResponsibilityLetters(assetId, assetLabel, assignedUserId);
                if (currentRoute === 'assets') await renderPage();
            } catch (error) {
                toast(error.message, 'error');
                button.disabled = false;
            }
        }));
        fileInput?.addEventListener('change', event => {
            const file = event.target.files?.[0];
            if (!file || !previewContainer) return;
            const isImage = file.type.startsWith('image/');
            previewContainer.classList.remove('hidden');
            previewContainer.innerHTML = '';
            if (isImage) {
                const image = document.createElement('img');
                image.src = URL.createObjectURL(file);
                image.alt = 'Vista previa de la carta responsiva';
                image.className = 'responsibility-letter-image';
                previewContainer.appendChild(image);
                return;
            }
            const pdfBadge = document.createElement('div');
            pdfBadge.className = 'responsibility-letter-file-badge';
            pdfBadge.textContent = file.name || 'Archivo PDF';
            previewContainer.appendChild(pdfBadge);
        });
        document.querySelector('#responsibility-letter-form')?.addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const submitButton = form.querySelector('[type="submit"]');
            const errorRegion = document.querySelector('#responsibility-letter-error');
            errorRegion.textContent = '';
            submitButton.disabled = true;
            try {
                const response = await fetch(new URL('api.php?action=responsibility-letter-upload', window.location.href), {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(result.error || 'No fue posible subir el archivo.');
                toast(result.cleanupWarning
                    ? 'Carta nueva guardada, pero no se pudo retirar el archivo anterior del servidor.'
                    : result.replaced
                        ? 'Carta responsiva reemplazada para esta persona.'
                        : 'Carta escaneada guardada para esta persona y este activo.');
                await manageResponsibilityLetters(assetId, assetLabel, assignedUserId);
                if (currentRoute === 'assets') await renderPage();
            } catch (error) {
                errorRegion.textContent = error.message;
                submitButton.disabled = false;
            }
        });
    } catch (error) {
        toast(error.message, 'error');
    }
}

function previewResponsibilityLetter(letterId, originalName, mimeType) {
    const previewUrl = escapeHtml(responsibilityLetterUrl(letterId, true));
    const preview = mimeType === 'application/pdf'
        ? `<iframe class="responsibility-letter-preview-frame" src="${previewUrl}" title="${escapeHtml(originalName)}"></iframe>`
        : `<img class="responsibility-letter-preview-image" src="${previewUrl}" alt="${escapeHtml(originalName)}">`;
    const downloadUrl = escapeHtml(responsibilityLetterUrl(letterId));
    showModal(`Vista previa · ${originalName}`, `<div class="responsibility-letter-preview-large">${preview}</div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button><a class="secondary-button letter-download" href="${downloadUrl}">Descargar</a><button class="primary-button" type="button" id="print-responsibility-letter">Imprimir</button></div>`);
    document.querySelector('#modal-root .modal')?.classList.add('letter-preview-modal');
    document.querySelector('#print-responsibility-letter')?.addEventListener('click', () => printResponsibilityLetter(letterId));
}

function printResponsibilityLetter(letterId) {
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        toast('Permite ventanas emergentes para imprimir la carta.', 'error');
        return;
    }
    const frame = printWindow.document.createElement('iframe');
    frame.src = responsibilityLetterUrl(letterId, true);
    frame.title = 'Carta responsiva para imprimir';
    frame.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;border:0';
    printWindow.document.body.style.margin = '0';
    printWindow.document.body.append(frame);
    frame.addEventListener('load', () => window.setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 350), { once: true });
}

function documentFieldsMarkup(formType) {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const activityChoices = (name, label) => `<tr><th scope="row">${label}</th>${[['done', 'Sí'], ['not_done', 'No'], ['not_applicable', 'N/A']].map(([value, text]) => `<td><label class="fsis-choice" title="${text}"><input type="radio" name="${name}" value="${value}" required><span class="fsis-choice-mark" data-answer="${text}"></span><span class="fsis-choice-label">${text}</span></label></td>`).join('')}</tr>`;
    const satisfaction = `<input class="fsis-rating-manual" type="number" name="satisfaction" min="6" max="10" step="1" required inputmode="numeric" aria-label="Calificacion de satisfaccion de 6 a 10">`;
    const signatureSection = `<section class="fsis-signatures"><div class="fsis-signature-block"><div class="fsis-name-row"><strong>NOMBRE DEL USUARIO:</strong><span id="document-signed-name" class="fsis-name-value">Selecciona una persona</span><input type="hidden" name="signedName" required></div><div class="fsis-sign-area">${signaturePad('document-user-signature-pad', 'document-clear-user-signature')}<span>FIRMA</span></div></div><div class="fsis-signature-block"><div class="fsis-name-row"><strong>REALIZADO POR:</strong><input name="performedByName" required maxlength="140" value="${escapeHtml(sessionUser.name)}" aria-label="Realizado por"></div><div class="fsis-sign-area">${signaturePad('document-performed-signature-pad', 'document-clear-performed-signature')}<span>FIRMA</span></div></div></section>`;
    // La lista de personas se construye desde los nombres que realmente aparecen
    // asignados en ACTIVOS. No se limita a users/asset_users: hay activos importados
    // con nombres completos guardados solo en asset_assignees o source_assignee_name.
    const normalizePersonName = value => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLocaleLowerCase('es-MX');
    const peopleByName = new Map();
    const userByNormalizedName = new Map(peopleCache.map(person => [normalizePersonName(person.name), person]));
    for (const asset of assetsCache) {
        const names = [
            ...(Array.isArray(asset.assigned_users) ? asset.assigned_users : []),
            ...(Array.isArray(asset.assignees) ? asset.assignees.flatMap(assignee => [assignee.name, assignee.account_name]) : []),
            ...String(asset.assigned_name || '').split(/[\r\n;]+/),
            ...String(asset.source_assignee_name || '').split(/[\r\n;]+/),
        ];
        for (const rawName of names) {
            const name = String(rawName || '').replace(/\s+/g, ' ').trim();
            const key = normalizePersonName(name);
            if (!key || ['sin asignar', 'sin asignado', ''].includes(key) || peopleByName.has(key)) continue;
            const account = userByNormalizedName.get(key);
            peopleByName.set(key, {
                id: account ? String(account.id) : `asset-person:${encodeURIComponent(name)}`,
                name,
                accountId: account ? Number(account.id) : null,
            });
        }
    }
    const uniquePeople = [...peopleByName.values()].sort((a,b) => a.name.localeCompare(b.name, 'es-MX'));
    const assetOptions = assetsCache.filter(item => item.type === 'computer' && item.status !== 'retired').sort((a,b) => a.asset_tag.localeCompare(b.asset_tag, 'es-MX'));
    const assetSelectors = `<div class="fsis-link-selectors fsis-capture-selectors"><label>PERSONA A QUIEN SE REALIZA EL MANTENIMIENTO *<input type="search" id="document-user-search" placeholder="Buscar por nombre..." autocomplete="off"><select name="userId" id="document-user" required><option value="">Selecciona persona</option>${selectOptions(uniquePeople, 'id', person => person.name, '')}</select><span class="field-help" id="document-related-assets">Los equipos relacionados se mostrarán como referencia; puedes elegir cualquier equipo.</span></label><label>EQUIPO AL QUE SE REALIZA EL MANTENIMIENTO *<input type="search" id="document-asset-search" placeholder="Buscar por etiqueta, marca o modelo..." autocomplete="off"><select name="assetId" id="document-asset" required><option value="">Selecciona equipo</option>${selectOptions(assetOptions, 'id', item => `${item.asset_tag} · ${item.brand} ${item.model}`, '')}</select><span class="field-help" id="document-related-people">Las personas asignadas al equipo aparecen aquí sin limitar la selección.</span></label></div>`;
    const equipmentDetails = `<section class="fsis-equipment"><div class="fsis-section-tag">DATOS DEL EQUIPO:</div><div class="fsis-equipment-data" id="document-equipment-data"><textarea name="equipmentDetails" rows="5" maxlength="1200" required placeholder="Escribe aquí los datos del equipo: tipo, etiqueta, marca, modelo, número de serie y demás información necesaria."></textarea></div></section>`;
    const assetAndUser = `<section class="fsis-equipment"><div class="fsis-section-tag">DATOS DEL EQUIPO:</div>${assetSelectors}<div class="fsis-equipment-data" id="document-equipment-data"><textarea name="equipmentDetails" rows="5" maxlength="1200" required placeholder="Escribe aquí los datos del equipo: tipo, etiqueta, marca, modelo, número de serie y demás información necesaria."></textarea></div></section>`;
    if (formType === 'service_order') {
        return `${assetSelectors}<article class="fsis01-sheet" aria-label="Formato F-SIS-01 Rev. 04 Orden de Servicio"><header class="fsis01-header"><img class="fsis01-letterhead" src="assets/fsis01-header.jpg" alt="Encabezado institucional Grupo RAME"><div class="fsis01-title">ORDEN DE SERVICIO</div><span class="fsis01-revision">F-SIS-01 Rev.04</span></header><section class="fsis-meta"><label><strong>FECHA:</strong><input name="date" type="date" value="${today}" required></label><label><strong>DEPARTAMENTO:</strong><input name="department" maxlength="140"></label></section>${equipmentDetails}<section class="fsis-activities"><div class="fsis-section-tag">ACTIVIDADES REALIZADAS</div><table class="fsis-table"><thead><tr><th></th><th>REALIZADAS</th><th>NO REALIZADAS</th><th>NO APLICA</th></tr></thead><tbody>${activityChoices('cleaningExterior', 'LIMPIEZA EXTERIOR')}${activityChoices('cleaningInterior', 'LIMPIEZA INTERIOR')}${activityChoices('systemCleanup', 'DEPURACIÓN DE SISTEMA')}${activityChoices('virusReview', 'REVISIÓN DE VIRUS')}</tbody></table><div class="fsis-password"><strong>CONTRASEÑA DE COMPUTADORA *</strong><input type="text" name="computerPassword" maxlength="120" required autocomplete="off" placeholder="Escribe la contraseña de la computadora"></div></section><section class="fsis-survey"><div class="fsis-section-tag">ENCUESTA DE SATISFACCIÓN</div><p>Favor de Responder de 6 a 10 de acuerdo a la Satisfacción del Servicio, siendo 6 Pésimo, 7 Puede Mejorar, 8 Bueno, 9 Supero mis Expectativas y 10 Excelente.</p><div class="fsis-satisfaction-row"><strong>SATISFACCIÓN DEL MANTENIMIENTO REALIZADO</strong>${satisfaction}</div></section><section class="fsis-comments"><div class="fsis-section-tag">COMENTARIOS O SUGERENCIAS</div><textarea name="comments" maxlength="2000" rows="4" aria-label="Comentarios o sugerencias"></textarea></section>${signatureSection}<footer class="fsis-footer">F-SIS-01 Rev.04</footer></article>`;
    }
    const correctiveSatisfaction = `<div class="sheet-satisfaction">${[6, 7, 8, 9, 10].map(score => `<label><input type="radio" name="satisfaction" value="${score}" required><span>${score}</span></label>`).join('')}</div>`;
    return `<article class="word-sheet"><header class="sheet-header"><span>F-SIS-02 · REV. 03</span><h2>Solicitud de Mantenimiento Correctivo a Equipo de Software y Hardware</h2></header><div class="sheet-grid sheet-meta"><label>FECHA *<input name="date" type="date" value="${today}" required></label><label>FECHA DE CIERRE<input name="closingDate" type="date"></label></div>${assetAndUser}<section class="sheet-section"><label class="sheet-wide-field">FALLA QUE PRESENTA *<textarea name="failure" maxlength="2000" required rows="3"></textarea></label><label class="sheet-wide-field">DIAGNÓSTICO *<textarea name="diagnosis" maxlength="2000" required rows="3"></textarea></label><label class="sheet-wide-field">ACTIVIDADES REALIZADAS<textarea name="activities" maxlength="3000" rows="3"></textarea></label></section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${correctiveSatisfaction}<div class="sheet-grid"><label>TIEMPO DE RESPUESTA<input name="responseTime" maxlength="140"></label></div><label class="sheet-wide-field">SOLUCIÓN QUE SE LE DIO<textarea name="solution" maxlength="2000" rows="3"></textarea></label><label class="sheet-wide-field">COMENTARIOS O SUGERENCIAS<textarea name="comments" maxlength="2000" rows="3"></textarea></label></section>${signatureSection}</article>`;
}

function emptyState(title, detail) {
    return `<div class="empty-state"><span class="empty-mark">·</span><strong>${title}</strong>${detail}</div>`;
}

function bindPageActions() {
    document.querySelectorAll('[data-route]').forEach(button => button.addEventListener('click', async () => { currentRoute = button.dataset.route; await renderApp(); }));
    document.querySelectorAll('[data-create]').forEach(button => button.addEventListener('click', () => openCreateForm(button.dataset.create, null, 'service_order', null, button.dataset.assetType || 'computer')));
    document.querySelector('#create-service-order')?.addEventListener('click', () => openCreateForm('documents', null, 'service_order'));
    document.querySelector('#create-corrective-request')?.addEventListener('click', () => openCreateForm('documents', null, 'corrective_request'));
    document.querySelector('#table-search')?.addEventListener('input', filterAssets);
    ['#asset-department-filter', '#asset-status-filter', '#asset-responsibility-filter'].forEach(selector => {
        document.querySelector(selector)?.addEventListener('change', filterAssets);
    });
    document.querySelector('#asset-filter-clear')?.addEventListener('click', () => {
        document.querySelector('#table-search').value = '';
        document.querySelector('#asset-department-filter').value = '';
        document.querySelector('#asset-status-filter').value = '';
        document.querySelector('#asset-responsibility-filter').value = '';
        assetTab = 'all';
        assetPage = 1;
        document.querySelectorAll('[data-asset-tab]').forEach(tab => {
            const selected = tab.dataset.assetTab === assetTab;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        filterAssets();
    });
    document.querySelectorAll('[data-asset-tab]').forEach(tab => tab.addEventListener('click', () => {
        assetTab = tab.dataset.assetTab;
        assetPage = 1;
        document.querySelectorAll('[data-asset-tab]').forEach(item => {
            const selected = item === tab;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-selected', String(selected));
        });
        filterAssets();
    }));
    document.querySelector('#asset-page-size')?.addEventListener('change', event => {
        assetPageSize = Number(event.currentTarget.value);
        assetPage = 1;
        filterAssets();
    });
    document.querySelector('#asset-page-previous')?.addEventListener('click', () => {
        assetPage = Math.max(1, assetPage - 1);
        filterAssets();
    });
    document.querySelector('#asset-page-next')?.addEventListener('click', () => {
        assetPage++;
        filterAssets();
    });
    document.querySelector('#maintenance-search')?.addEventListener('input', filterMaintenances);
    document.querySelector('#department-plan-year')?.addEventListener('change', event => { maintenanceCalendarDate = new Date(Number(event.currentTarget.value), maintenanceCalendarDate.getMonth(), 1); renderApp(); });
    document.querySelectorAll('[data-department-edit-month]').forEach(button => button.addEventListener('click', () => openDepartmentMonthEditor(Number(button.dataset.departmentEditMonth), maintenanceDepartmentPlanCache)));
    document.querySelectorAll('[data-department-equipment]').forEach(button => button.addEventListener('click', () => openDepartmentDetails(Number(button.dataset.departmentEquipment))));
    document.querySelectorAll('[data-department-notify]').forEach(button => button.addEventListener('click', () => {
        const plan = maintenanceDepartmentPlanCache?.plans.find(item => Number(item.id) === Number(button.dataset.departmentNotify));
        openDepartmentDetails(Number(button.dataset.departmentNotify), plan?.request_status !== 'confirmed');
    }));
    document.querySelector('#maintenance-status-filter')?.addEventListener('change', filterMaintenances);
    document.querySelector('#maintenance-calendar-prev')?.addEventListener('click', () => {
        maintenanceCalendarDate = maintenanceCalendarView === 'month'
            ? new Date(maintenanceCalendarDate.getFullYear(), maintenanceCalendarDate.getMonth() - 1, 1)
            : new Date(maintenanceCalendarDate.getFullYear(), maintenanceCalendarDate.getMonth(), maintenanceCalendarDate.getDate() - (maintenanceCalendarView === 'week' ? 7 : 1));
        renderApp();
    });
    document.querySelector('#maintenance-calendar-next')?.addEventListener('click', () => {
        maintenanceCalendarDate = maintenanceCalendarView === 'month'
            ? new Date(maintenanceCalendarDate.getFullYear(), maintenanceCalendarDate.getMonth() + 1, 1)
            : new Date(maintenanceCalendarDate.getFullYear(), maintenanceCalendarDate.getMonth(), maintenanceCalendarDate.getDate() + (maintenanceCalendarView === 'week' ? 7 : 1));
        renderApp();
    });
    document.querySelector('#maintenance-calendar-today')?.addEventListener('click', () => { maintenanceCalendarDate = new Date(); renderApp(); });
    document.querySelectorAll('[data-calendar-view]').forEach(button => button.addEventListener('click', () => { maintenanceCalendarView = button.dataset.calendarView; renderApp(); }));
    document.querySelectorAll('[data-calendar-date]').forEach(button => button.addEventListener('click', () => { maintenanceCalendarDate = new Date(`${button.dataset.calendarDate}T12:00:00`); maintenanceCalendarView = 'day'; renderApp(); }));
    document.querySelectorAll('[data-maintenance-event]').forEach(button => button.addEventListener('click', () => showMaintenanceDetails(button.dataset.maintenanceEvent)));
    const calendarLabel = document.querySelector('#maintenance-calendar-label');
    if (calendarLabel) {
        const date = maintenanceCalendarDate;
        calendarLabel.textContent = maintenanceCalendarView === 'month'
            ? new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' }).format(date)
            : maintenanceCalendarView === 'day'
                ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'full' }).format(date)
                : (() => { const monday = new Date(date); monday.setDate(date.getDate() - ((date.getDay() + 6) % 7)); const sunday = new Date(monday); sunday.setDate(monday.getDate() + 6); return `${monday.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })} – ${sunday.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })}`; })();
    }
    document.querySelectorAll('[data-asset-history]').forEach(button => button.addEventListener('click', () => openAssetHistory(button.dataset.assetHistory, button.dataset.assetLabel)));
    document.querySelectorAll('[data-reveal-asset-password]').forEach(button => button.addEventListener('click', () => revealAssetPassword(button.dataset.revealAssetPassword, button.dataset.assetLabel)));
    document.querySelectorAll('[data-edit-asset]').forEach(button => button.addEventListener('click', () => openCreateForm('assets', assetsCache.find(asset => String(asset.id) === button.dataset.editAsset))));
    document.querySelectorAll('[data-edit-license]').forEach(button => button.addEventListener('click', async () => {
        const { licenses } = await api('licenses');
        openCreateForm('licenses', licenses.find(license => String(license.id) === button.dataset.editLicense));
    }));
    document.querySelectorAll('[data-reveal-license-key]').forEach(button => button.addEventListener('click', () => revealLicenseKey(button.dataset.revealLicenseKey)));
    document.querySelectorAll('[data-reveal]').forEach(button => button.addEventListener('click', () => revealCredential(button)));
    document.querySelectorAll('[data-complete]').forEach(button => button.addEventListener('click', () => openCreateForm('documents', null, 'service_order', {
        id: button.dataset.complete,
        assetId: button.dataset.assetId,
        userId: button.dataset.userId,
    })));
    document.querySelectorAll('[data-approve-reschedule]').forEach(button => button.addEventListener('click', () => approveReschedule(button.dataset.approveReschedule)));
    document.querySelectorAll('[data-reschedule-maintenance]').forEach(button => button.addEventListener('click', () => openMaintenanceReschedule(button.dataset.rescheduleMaintenance)));
    document.querySelectorAll('[data-cancel-maintenance]').forEach(button => button.addEventListener('click', () => cancelMaintenance(button.dataset.cancelMaintenance)));
    document.querySelectorAll('[data-sign-doc]').forEach(button => button.addEventListener('click', () => openDocumentSign(button.dataset.signDoc, button.dataset.title)));
    document.querySelectorAll('[data-receipt]').forEach(button => button.addEventListener('click', () => printMaintenance(button.dataset.receipt)));
    document.querySelectorAll('[data-doc-receipt]').forEach(button => button.addEventListener('click', () => printSimpleDocument(button.dataset)));
    document.querySelectorAll('[data-delete-doc]').forEach(button => button.addEventListener('click', () => deleteDocument(button.dataset.deleteDoc, button.dataset.title)));
    if (currentRoute === 'documents' && ['admin', 'technician'].includes(sessionUser.role)) void addDocumentDeleteButtons();
    document.querySelectorAll('[data-asset-letters]').forEach(button => button.addEventListener('click', () => manageResponsibilityLetters(
        button.dataset.assetLetters,
        button.dataset.assetLabel,
        button.dataset.assignedUser,
    )));
}

function filterAssets() {
    const query = (document.querySelector('#table-search')?.value || '').toLowerCase();
    const department = document.querySelector('#asset-department-filter')?.value || '';
    const status = document.querySelector('#asset-status-filter')?.value || '';
    const responsibility = document.querySelector('#asset-responsibility-filter')?.value || '';
    const rows = [...document.querySelectorAll('.asset-group tbody tr')];
    const matchingRows = rows.filter(row => {
        const departmentMatches = !department || (department === '__none__' ? !row.dataset.department : row.dataset.department === department);
        return row.dataset.search.includes(query)
            && departmentMatches
            && (!status || row.dataset.status === status)
            && (!responsibility || row.dataset.missingResponsibility === 'true')
            && (assetTab === 'all' || row.dataset.deviceCategory === assetTab);
    });
    const pageSize = assetPageSize === 0 ? Math.max(matchingRows.length, 1) : assetPageSize;
    const pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
    assetPage = Math.min(assetPage, pageCount);
    const start = (assetPage - 1) * pageSize;
    const pageRows = new Set(matchingRows.slice(start, start + pageSize));
    rows.forEach(row => { row.hidden = !pageRows.has(row); });
    document.querySelectorAll('.asset-group').forEach(group => { group.hidden = matchingRows.length === 0; });
    const count = document.querySelector('#asset-result-count');
    if (count) count.textContent = `${matchingRows.length} de ${assetsCache.length} activos`;
    const groupSummary = document.querySelector('.asset-group .panel-head p');
    if (groupSummary) groupSummary.textContent = `${matchingRows.length} equipos en la vista actual`;
    const summary = document.querySelector('#asset-page-summary');
    if (summary) summary.textContent = matchingRows.length ? `Mostrando ${start + 1}–${Math.min(start + pageSize, matchingRows.length)} de ${matchingRows.length}` : 'Sin registros';
    const previous = document.querySelector('#asset-page-previous');
    const next = document.querySelector('#asset-page-next');
    if (previous) previous.disabled = assetPage <= 1;
    if (next) next.disabled = assetPage >= pageCount;
}

function filterMaintenances() {
    const query = (document.querySelector('#maintenance-search')?.value || '').trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-MX');
    const status = document.querySelector('#maintenance-status-filter')?.value || '';
    const events = [...document.querySelectorAll('[data-maintenance-event]')];
    let visible = 0;
    events.forEach(event => {
        const matches = (!query || event.dataset.search.includes(query)) && (!status || event.dataset.status === status);
        event.hidden = !matches;
        if (matches) visible++;
    });
    const count = document.querySelector('#maintenance-result-count');
    if (count) count.textContent = `${visible} servicios visibles`;
}

function selectOptions(items, valueKey, labelFn, prompt) {
    return `<option value="">${prompt}</option>${items.map(item => `<option value="${item[valueKey]}">${escapeHtml(labelFn(item))}</option>`).join('')}`;
}

const historyTypeLabels = { repair: 'Reparación o cambio', failure: 'Falla', inspection: 'Revisión', other: 'Otro evento' };

async function openAssetHistory(assetId, assetLabel) {
    try {
        const { history } = await api('asset-history', { query: { assetId } });
        const staffCanAdd = ['admin', 'technician'].includes(sessionUser.role);
        const historyRows = history.length
            ? `<ol class="history-list">${history.map(item => `<li class="history-entry"><div class="history-entry-top"><strong>${historyTypeLabels[item.event_type] || 'Evento'}${item.component ? ` · ${escapeHtml(item.component)}` : ''}</strong><time>${dateTime(item.occurred_at)}</time></div><p>${escapeHtml(item.details)}</p><span class="table-secondary">Registró: ${escapeHtml(item.created_by_name || 'Cuenta eliminada')}</span></li>`).join('')}</ol>`
            : emptyState('Sin historial todavía', 'Aquí aparecerán fallas, cambios de piezas y revisiones de este equipo.');
        const form = staffCanAdd ? `<details class="history-add"><summary>Registrar evento</summary><form id="history-form"><div class="form-grid"><div class="field"><label>Tipo de evento *</label><select name="eventType"><option value="repair">Reparación o cambio de pieza</option><option value="failure">Falla</option><option value="inspection">Revisión</option><option value="other">Otro</option></select></div><div class="field"><label>Fecha y hora *</label><input name="occurredAt" type="datetime-local" required value="${new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}"></div><div class="field full"><label>Pieza o componente</label><input name="component" maxlength="160" placeholder="Pantalla, batería, cámara…"></div><div class="field full"><label>Detalle *</label><textarea name="details" maxlength="4000" required placeholder="Qué se detectó, qué se cambió y resultado…"></textarea></div></div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button class="primary-button" type="submit">Guardar en historial</button></div></form></details>` : '';
        showModal(`Historial · ${assetLabel}`, `${form}<div id="history-content">${historyRows}</div><div class="form-actions"><button type="button" class="secondary-button" data-close>Cerrar</button></div>`);
        document.querySelector('#history-form')?.addEventListener('submit', async event => {
            event.preventDefault();
            const formData = Object.fromEntries(new FormData(event.currentTarget).entries());
            try {
                await api('asset-history-save', { method: 'POST', body: { ...formData, assetId } });
                await openAssetHistory(assetId, assetLabel);
                toast('Evento agregado al historial del equipo.');
            } catch (error) {
                document.querySelector('#modal-error').textContent = error.message;
            }
        });
    } catch (error) {
        toast(error.message, 'error');
    }
}

async function refreshMaintenanceSlots(dateField, timeField, excludeId = 0) {
    const date = dateField?.value || '';
    if (!timeField) return;
    timeField.disabled = true;
    timeField.innerHTML = '<option value="">Selecciona una fecha primero</option>';
    if (!date) return;
    try {
        const result = await api('maintenance-availability', { query: { date, excludeId } });
        timeField.innerHTML = '<option value="">Selecciona un horario</option>' + result.slots.map(slot => `<option value="${slot.time}" ${slot.available ? '' : 'disabled'}>${slot.time}${slot.available ? '' : ' · No disponible'}</option>`).join('');
        timeField.disabled = !result.slots.some(slot => slot.available);
        if (timeField.disabled) timeField.innerHTML = '<option value="">No hay horarios disponibles</option>';
    } catch (error) {
        timeField.innerHTML = `<option value="">${escapeHtml(error.message)}</option>`;
    }
}

function openCreateForm(route, record = null, documentFormType = 'service_order', maintenanceRecord = null, assetType = 'computer') {
    let title = '';
    let fields = '';
    let documentSignatures = null;
    let serviceAssets = [];
    const nameKey = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('es-MX');
    const assignedNames = asset => [...new Set([
        ...(Array.isArray(asset?.assigned_users) ? asset.assigned_users : []),
        ...(Array.isArray(asset?.assignees) ? asset.assignees.flatMap(person => [person.name, person.account_name]) : []),
        ...String(asset?.assigned_name || '').split(/[\r\n;,|]+/),
        ...String(asset?.source_assignee_name || '').split(/[\r\n;,|]+/),
    ].map(name => String(name || '').replace(/\s+/g, ' ').trim()).filter(name => name && !['sin asignar', 'sin asignado'].includes(nameKey(name))))];
    if (route === 'assets') {
        title = 'Registrar activo';
        fields = `<div class="field"><label>Etiqueta de inventario *</label><input name="assetTag" required maxlength="80" placeholder="TI-0248"></div><div class="field"><label>Tipo *</label><select name="type" id="asset-type"><option value="computer">Computadora</option><option value="phone">Celular</option><option value="tablet">Tableta</option><option value="other">Otro</option></select></div><div class="field hidden" id="phone-category-field"><label>Clasificación del celular *</label><select name="phoneCategory"><option value="">Selecciona clasificación</option><option value="administrative">Administrativo</option><option value="operational">Operacional</option></select></div><div class="field hidden" id="phone-format-field"><label>Estado del celular *</label><select name="phoneFormatStatus"><option value="">Selecciona estado</option><option value="active">Activo</option><option value="formatted">Formateado</option><option value="to_format">Por formatear</option></select></div><div class="field"><label>Marca *</label><input name="brand" required maxlength="100" placeholder="Lenovo"></div><div class="field"><label>Modelo *</label><input name="model" required maxlength="120" placeholder="ThinkPad E14"></div><div class="field"><label>Número de serie</label><input name="serialNumber" maxlength="150"></div><div class="field"><label>Usuario de Windows</label><input name="windowsUsername" maxlength="190" autocomplete="off"></div><div class="field full"><label>Contraseña de Windows / RDP</label><input name="windowsPassword" type="password" maxlength="4096" autocomplete="new-password"><span class="field-help">Se guarda sin cifrar en la base de datos. Al editar, déjala vacía para conservar la contraseña actual.</span>${record ? '<label><input name="clearWindowsPassword" type="checkbox" value="1"> Eliminar la contraseña guardada</label>' : ''}</div><div class="field"><label>Estado</label><select name="status"><option value="assigned">Activo</option><option value="available" selected>Disponible</option><option value="maintenance">En mantenimiento</option><option value="retired">Dado de baja</option></select></div><div class="field"><label>Persona asignada</label><select name="assignedUserId">${selectOptions(peopleCache, 'id', person => person.name, 'Sin asignar')}</select></div><div class="field"><label>Fecha de asignación</label><input name="assignedDate" type="date"></div><div class="field"><label>Departamento</label><input name="department" maxlength="140"></div><div class="field"><label>Puesto</label><input name="position" maxlength="140"></div><div class="field"><label>Vencimiento de garantía</label><input name="warrantyUntil" type="date"></div><div class="field"><label>Terminal</label><input name="terminal" maxlength="190"></div><div class="field"><label>Monitor</label><input name="monitor" maxlength="160"></div><div class="field"><label>Mouse</label><input name="mouse" maxlength="160"></div><div class="field full"><label>RDP (equipo/host)</label><input name="rdpAccess" maxlength="190"></div><div class="field full"><label>Notas</label><textarea name="notes" maxlength="2000"></textarea></div>`;
    } else if (route === 'licenses') {
        title = 'Registrar licencia';
        fields = `<div class="field"><label>Producto *</label><input name="product" required maxlength="160" placeholder="Microsoft 365"></div><div class="field"><label>Proveedor</label><input name="vendor" maxlength="140"></div><div class="field"><label>Asientos disponibles *</label><input name="seats" type="number" min="1" value="1" required></div><div class="field"><label>Activaciones en uso</label><input name="activations" type="number" min="0" value="0"></div><div class="field"><label>Vence el</label><input name="expiresOn" type="date"></div><div class="field"><label>Estado</label><select name="status"><option value="active">Activa</option><option value="expiring">Por vencer</option><option value="inactive">Inactiva</option><option value="expired">Vencida</option></select></div><div class="field full"><label>Clave de activación</label><input name="activationKey" type="password" autocomplete="new-password"><span class="field-help">Se cifra en el servidor; al editar, dejar en blanco conserva la clave guardada.</span></div><div class="field full"><label>Notas</label><textarea name="notes" maxlength="2000" placeholder="No incluyas claves de activación en este campo"></textarea></div>`;
    } else if (route === 'maintenance') {
        title = 'Agendar mantenimiento';
        serviceAssets = assetsCache.filter(asset => asset.type === 'computer' && asset.status !== 'retired');
        const servicePeople = new Map();
        for (const asset of serviceAssets) for (const name of assignedNames(asset)) if (!servicePeople.has(nameKey(name))) servicePeople.set(nameKey(name), name);
        const personOptions = [...servicePeople.values()].map(name => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
        const assetOptions = serviceAssets.map(asset => `<option value="${asset.id}">${escapeHtml(`${asset.asset_tag} · ${asset.brand} ${asset.model}`)}</option>`).join('');
        fields = `<div class="field full"><label>Equipo *</label><input type="search" id="maintenance-asset-search" placeholder="Buscar por inventario, marca o modelo"><select name="assetId" id="maintenance-asset" required><option value="">Selecciona equipo</option>${assetOptions}</select><span class="field-help" id="maintenance-related-assets">La persona y el equipo se seleccionan de forma independiente.</span></div><div class="field full"><label>Persona atendida *</label><input type="search" id="maintenance-person-search" placeholder="Buscar nombre (sin importar acentos o mayúsculas)"><select name="personName" id="maintenance-person" required><option value="">Selecciona persona</option>${personOptions}</select><span class="field-help" id="maintenance-related-people">Las personas provienen de las asignaciones registradas en Activos.</span></div><div class="field"><label>Fecha *</label><input name="scheduledDate" id="maintenance-date" type="date" required></div><div class="field"><label>Horario *</label><select name="scheduledTime" id="maintenance-time" required disabled><option value="">Selecciona una fecha primero</option></select></div><div class="field full"><label>Alcance del servicio</label><textarea name="notes" maxlength="2000" placeholder="Revisión preventiva, actualizaciones, limpieza…"></textarea></div><div class="field full"><span class="field-help">Horario: 8:30 a. m. a 5:00 p. m. en intervalos de 30 minutos. Los horarios ocupados no se pueden reservar.</span></div>`;
    } else if (route === 'credentials') {
        title = 'Nueva credencial';
        fields = `<div class="field full"><label>Servicio o sistema *</label><input name="label" required maxlength="140" placeholder="Consola de administración"></div><div class="field full"><label>Nombre de usuario</label><input name="username" maxlength="190" autocomplete="off"></div><div class="field full"><label>Contraseña o secreto *</label><input name="secret" type="password" required autocomplete="new-password"></div><div class="field full"><label>Equipo relacionado</label><select name="assetId">${selectOptions(assetsCache, 'id', asset => `${asset.asset_tag} · ${asset.brand} ${asset.model}`, 'General, sin equipo')}</select></div>`;
    } else if (route === 'documents') {
        title = 'Nuevo formato de servicio';
        const serviceOrderSelected = documentFormType === 'service_order' ? 'selected' : '';
        const correctiveRequestSelected = documentFormType === 'corrective_request' ? 'selected' : '';
        fields = `${maintenanceRecord ? '<input type="hidden" name="formType" value="service_order">' : `<div class="document-format-picker"><label>FORMATO<select name="formType" id="document-form-type"><option value="service_order" ${serviceOrderSelected}>F-SIS-01 · Orden de Servicio</option><option value="corrective_request" ${correctiveRequestSelected}>F-SIS-02 · Solicitud Correctiva</option></select></label></div>`}<div id="document-form-fields">${documentFieldsMarkup(documentFormType)}</div>`;
    } else if (route === 'users') {
        title = 'Agregar usuario';
        fields = `<div class="field full"><label>Nombre completo *</label><input name="name" required maxlength="140"></div><div class="field full"><label>Correo electrónico *</label><input name="email" required type="email" maxlength="190"></div><div class="field"><label>Área</label><input name="department" maxlength="140"></div><div class="field"><label>Permiso</label><select name="role"><option value="user">Usuario</option><option value="technician">Técnico</option><option value="admin">Administrador</option></select></div><div class="field full"><label>Contraseña temporal (8 caracteres mínimo) *</label><input name="password" type="password" required minlength="8" autocomplete="new-password"><span class="field-help">Entrega la contraseña por un canal seguro; el usuario podrá cambiarla en una siguiente versión.</span></div>`;
    }
    showModal(title, `<form id="create-form"><div class="${route === 'documents' ? 'document-form-container' : 'form-grid'}">${fields}</div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button type="button" class="secondary-button" data-close>Cancelar</button><button class="primary-button" type="submit">Guardar registro</button></div></form>`);
    if (route === 'maintenance') {
        const assetSelect = document.querySelector('#maintenance-asset');
        const personSelect = document.querySelector('#maintenance-person');
        const namesForAsset = asset => asset ? assignedNames(asset) : [];
        const filterSelect = (input, select) => {
            const query = nameKey(input.value);
            [...select.options].forEach(option => { option.hidden = Boolean(query) && Boolean(option.value) && !nameKey(option.textContent).includes(query); });
        };
        document.querySelector('#maintenance-asset-search').addEventListener('input', event => filterSelect(event.currentTarget, assetSelect));
        document.querySelector('#maintenance-person-search').addEventListener('input', event => filterSelect(event.currentTarget, personSelect));
        assetSelect.addEventListener('change', () => {
            const asset = serviceAssets.find(item => String(item.id) === assetSelect.value);
            const names = namesForAsset(asset);
            if (names.length) {
                const personOption = [...personSelect.options].find(option => nameKey(option.value) === nameKey(names[0]));
                if (personOption) personSelect.value = personOption.value;
            }
            document.querySelector('#maintenance-related-people').textContent = names.length ? `Registrado en Activos para este equipo: ${names.join(', ')}` : 'Este equipo no muestra personas asignadas en Activos.';
        });
        personSelect.addEventListener('change', () => {
            const matches = serviceAssets.filter(asset => namesForAsset(asset).some(name => nameKey(name) === nameKey(personSelect.value)));
            if (matches.length === 1) assetSelect.value = String(matches[0].id);
            document.querySelector('#maintenance-related-assets').textContent = personSelect.value
                ? (matches.length ? `Equipos relacionados en Activos: ${matches.map(asset => asset.asset_tag).join(', ')}.${matches.length === 1 ? ' Se seleccionó el único equipo relacionado.' : ' Selecciona el equipo que se atenderá.'} Puedes seleccionar cualquier otro equipo.` : 'No hay equipos relacionados registrados en Activos; puedes seleccionar otro equipo.')
                : 'La persona y el equipo se seleccionan de forma independiente.';
        });
        const dateField = document.querySelector('#maintenance-date');
        const timeField = document.querySelector('#maintenance-time');
        dateField.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        dateField.addEventListener('change', () => refreshMaintenanceSlots(dateField, timeField));
    }
    if (route === 'assets') {
        const typeField = document.querySelector('#create-form [name="type"]');
        const hiddenType = document.createElement('input');
        hiddenType.type = 'hidden';
        hiddenType.id = 'asset-type';
        hiddenType.name = 'type';
        hiddenType.value = record?.type || assetType;
        typeField.replaceWith(hiddenType);
        const assigneeSelect = document.querySelector('#create-form [name="assignedUserId"]');
        assigneeSelect.name = 'assignedUserIds';
        assigneeSelect.multiple = true;
        assigneeSelect.size = 5;
        assigneeSelect.setAttribute('aria-label', 'Personas asignadas, máximo cinco');
        assigneeSelect.closest('.field').querySelector('label').textContent = 'Personas asignadas (máximo 5)';
        document.querySelector('#create-form [name="serialNumber"]').closest('.field').insertAdjacentHTML('afterend', '<div class="field"><label>Antivirus</label><input name="antivirusProduct" maxlength="160"></div><div class="field"><label>Vencimiento del antivirus</label><input name="antivirusExpiresOn" type="date"></div>');
    }
    if (route === 'licenses') {
        document.querySelector('#create-form [name="seats"]').insertAdjacentHTML('afterend', '<label><input type="checkbox" name="seatsConfirmed" value="1" checked> Capacidad contratada confirmada</label>');
    }
    if (route === 'documents') {
        document.querySelector('#modal-root .modal').classList.add('document-modal');
        if (maintenanceRecord) {
            document.querySelector('#modal-root .modal-head h2').textContent = 'Firmar Orden de Servicio · Cerrar mantenimiento';
            document.querySelector('#create-form .primary-button').textContent = 'Firmar y cerrar mantenimiento';
            const personSelect = document.querySelector('#document-user');
            const normalizeName = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('es-MX');
            const personOption = [...personSelect.options].find(option => normalizeName(option.textContent) === normalizeName(maintenanceRecord.personName));
            personSelect.value = personOption?.value || '';
            document.querySelector('#document-asset').value = String(maintenanceRecord.assetId);
        }
    }
    if (record) {
        const fieldsByRoute = {
            assets: { assetTag: 'asset_tag', brand: 'brand', model: 'model', serialNumber: 'serial_number', antivirusProduct: 'antivirus_product', antivirusExpiresOn: 'antivirus_expires_on', windowsUsername: 'windows_username', status: 'status', assignedDate: 'assigned_date', department: 'department', position: 'job_title', warrantyUntil: 'warranty_until', terminal: 'terminal', monitor: 'monitor', mouse: 'mouse', rdpAccess: 'rdp_access', notes: 'notes' },
            licenses: { product: 'product', vendor: 'vendor', seats: 'seats', activations: 'activations', expiresOn: 'expires_on', status: 'status', notes: 'notes' },
        }[route] || {};
        for (const [field, key] of Object.entries(fieldsByRoute)) {
            const input = document.querySelector(`#create-form [name="${field}"]`);
            if (input) input.value = record[key] ?? '';
        }
        if (route === 'licenses') {
            document.querySelector('#create-form [name="seatsConfirmed"]').checked = Number(record.seats_confirmed) === 1;
        }
        if (route === 'assets') {
            const assigneeSelect = document.querySelector('#create-form [name="assignedUserIds"]');
            const assignedIds = (record.assigned_user_ids || []).map(Number);
            [...assigneeSelect.options].forEach(option => {
                option.selected = assignedIds.includes(Number(option.value));
            });
        }
        document.querySelector('#modal-root .modal-head h2').textContent = route === 'assets' ? 'Editar activo' : 'Actualizar licencia';
        document.querySelector('#create-form .primary-button').textContent = 'Guardar cambios';
    }
    if (route === 'documents') {
        documentSignatures = {
            user: initSignature('document-user-signature-pad', 'document-clear-user-signature'),
            performedBy: initSignature('document-performed-signature-pad', 'document-clear-performed-signature'),
        };
        const syncDocumentIdentity = () => {
            const userSelect = document.querySelector('#document-user');
            const assetSelect = document.querySelector('#document-asset');
            const selectedPersonName = userSelect?.value
                ? userSelect.selectedOptions[0]?.textContent || ''
                : '';
            const asset = assetsCache.find(item => String(item.id) === assetSelect?.value);
            const signedName = document.querySelector('[name="signedName"]');
            const signedNameDisplay = document.querySelector('#document-signed-name');
            const department = document.querySelector('[name="department"]');
            const equipmentDetails = document.querySelector('[name="equipmentDetails"]');
            const normalizeName = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('es-MX');
            const assignedNames = item => [...new Set([
                ...(Array.isArray(item?.assigned_users) ? item.assigned_users : []),
                ...(Array.isArray(item?.assignees) ? item.assignees.flatMap(person => [person.name, person.account_name]) : []),
                ...String(item?.assigned_name || '').split(/[\r\n;,|]+/),
                ...String(item?.source_assignee_name || '').split(/[\r\n;,|]+/),
            ].map(name => String(name || '').replace(/\s+/g, ' ').trim()).filter(Boolean))];
            const relatedAssets = assetsCache.filter(item => assignedNames(item).some(name => normalizeName(name) === normalizeName(selectedPersonName)));
            const relatedNames = asset ? assignedNames(asset) : [];
            const generatedDetails = asset ? [
                `CÓDIGO DE EQUIPO: ${asset.asset_tag || '—'}`,
                `MODELO: ${[asset.brand, asset.model].filter(Boolean).join(' ') || '—'}`,
                `NÚMERO DE SERIE / SERVICE TAG: ${asset.serial_number || '—'}`,
            ].join('\n') : '';
            const relatedAssetsHint = document.querySelector('#document-related-assets');
            const relatedPeopleHint = document.querySelector('#document-related-people');
            if (signedName) signedName.value = selectedPersonName;
            if (signedNameDisplay) signedNameDisplay.textContent = selectedPersonName || 'Selecciona una persona';
            if (asset && department) department.value = asset.department || '';
            if (equipmentDetails && (!equipmentDetails.value || equipmentDetails.value === equipmentDetails.dataset.assetAutofill)) {
                equipmentDetails.value = generatedDetails;
                equipmentDetails.dataset.assetAutofill = generatedDetails;
            }
            if (relatedAssetsHint) relatedAssetsHint.textContent = selectedPersonName ? (relatedAssets.length ? `Equipos relacionados: ${relatedAssets.map(item => item.asset_tag).join(', ')}. Puedes seleccionar otro equipo.` : 'Sin equipos relacionados registrados; puedes seleccionar cualquier equipo.') : 'Los equipos relacionados se mostrarán como referencia; puedes elegir cualquier equipo.';
            if (relatedPeopleHint) relatedPeopleHint.textContent = asset ? (relatedNames.length ? `Personas asignadas en Activos: ${relatedNames.join(', ')}. Puedes seleccionar otra persona.` : 'Este equipo no tiene personas asignadas registradas.') : 'Las personas asignadas al equipo aparecen aquí sin limitar la selección.';
        };
        const filterSearchableSelect = (searchInput, select) => {
            const query = searchInput.value.trim().toLocaleLowerCase('es-MX').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const selectedValue = select.value;
            for (const option of [...select.options]) {
                if (!option.value) { option.hidden = false; continue; }
                const label = option.textContent.toLocaleLowerCase('es-MX').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                option.hidden = Boolean(query) && !label.includes(query);
            }
            if (selectedValue && [...select.options].some(option => option.value === selectedValue && !option.hidden)) select.value = selectedValue;
            else if (query) select.value = '';
        };
        const userSearch = document.querySelector('#document-user-search');
        const assetSearch = document.querySelector('#document-asset-search');
        const userSelect = document.querySelector('#document-user');
        const assetSelect = document.querySelector('#document-asset');
        userSearch?.addEventListener('input', () => filterSearchableSelect(userSearch, userSelect));
        assetSearch?.addEventListener('input', () => filterSearchableSelect(assetSearch, assetSelect));
        userSelect.addEventListener('change', syncDocumentIdentity);
        assetSelect.addEventListener('change', () => {
            const asset = assetsCache.find(item => String(item.id) === assetSelect.value);
            const names = asset ? [...new Set([
                ...(Array.isArray(asset.assigned_users) ? asset.assigned_users : []),
                ...(Array.isArray(asset.assignees) ? asset.assignees.flatMap(person => [person.name, person.account_name]) : []),
                ...String(asset.assigned_name || '').split(/[\r\n;,|]+/),
                ...String(asset.source_assignee_name || '').split(/[\r\n;,|]+/),
            ].map(name => String(name || '').trim()).filter(Boolean))] : [];
            const matchingPerson = [...userSelect.options].find(option => option.value && names.some(name => nameKey(name) === nameKey(option.textContent)));
            if (matchingPerson) userSelect.value = matchingPerson.value;
            syncDocumentIdentity();
        });
        userSelect.addEventListener('change', () => {
            const selectedName = userSelect.selectedOptions[0]?.textContent || '';
            const matches = assetsCache.filter(item => item.type === 'computer' && item.status !== 'retired' && [
                ...(Array.isArray(item.assigned_users) ? item.assigned_users : []),
                ...(Array.isArray(item.assignees) ? item.assignees.flatMap(person => [person.name, person.account_name]) : []),
                ...String(item.assigned_name || '').split(/[\r\n;,|]+/),
                ...String(item.source_assignee_name || '').split(/[\r\n;,|]+/),
            ].some(name => nameKey(name) === nameKey(selectedName)));
            if (matches.length === 1) assetSelect.value = String(matches[0].id);
            syncDocumentIdentity();
        });
        document.querySelector('#document-form-type')?.addEventListener('change', event => {
            const selectedUser = document.querySelector('#document-user').value;
            const selectedAsset = document.querySelector('#document-asset').value;
            document.querySelector('#document-form-fields').innerHTML = documentFieldsMarkup(event.currentTarget.value);
            document.querySelector('#document-user').value = selectedUser;
            documentSignatures = {
                user: initSignature('document-user-signature-pad', 'document-clear-user-signature'),
                performedBy: initSignature('document-performed-signature-pad', 'document-clear-performed-signature'),
            };
            const newUserSearch = document.querySelector('#document-user-search');
            const newAssetSearch = document.querySelector('#document-asset-search');
            const newUserSelect = document.querySelector('#document-user');
            const newAssetSelect = document.querySelector('#document-asset');
            newUserSearch?.addEventListener('input', () => filterSearchableSelect(newUserSearch, newUserSelect));
            newAssetSearch?.addEventListener('input', () => filterSearchableSelect(newAssetSearch, newAssetSelect));
            newUserSelect.addEventListener('change', () => {
                const selectedName = newUserSelect.selectedOptions[0]?.textContent || '';
                const matches = assetsCache.filter(item => item.type === 'computer' && item.status !== 'retired' && [
                    ...(Array.isArray(item.assigned_users) ? item.assigned_users : []),
                    ...(Array.isArray(item.assignees) ? item.assignees.flatMap(person => [person.name, person.account_name]) : []),
                    ...String(item.assigned_name || '').split(/[\r\n;,|]+/),
                    ...String(item.source_assignee_name || '').split(/[\r\n;,|]+/),
                ].some(name => nameKey(name) === nameKey(selectedName)));
                if (matches.length === 1) newAssetSelect.value = String(matches[0].id);
                syncDocumentIdentity();
            });
            newAssetSelect.addEventListener('change', () => {
                const asset = assetsCache.find(item => String(item.id) === newAssetSelect.value);
                const names = asset ? [
                    ...(Array.isArray(asset.assigned_users) ? asset.assigned_users : []),
                    ...(Array.isArray(asset.assignees) ? asset.assignees.flatMap(person => [person.name, person.account_name]) : []),
                    ...String(asset.assigned_name || '').split(/[\r\n;,|]+/),
                    ...String(asset.source_assignee_name || '').split(/[\r\n;,|]+/),
                ] : [];
                const matchingPerson = [...newUserSelect.options].find(option => option.value && names.some(name => nameKey(name) === nameKey(option.textContent)));
                if (matchingPerson) newUserSelect.value = matchingPerson.value;
                syncDocumentIdentity();
            });
            syncDocumentIdentity();
            if (selectedAsset && [...document.querySelector('#document-asset').options].some(option => option.value === selectedAsset)) {
                document.querySelector('#document-asset').value = selectedAsset;
                syncDocumentIdentity();
            }
        });
        syncDocumentIdentity();
    }
    let isSubmitting = false;
    document.querySelector('#create-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (maintenanceRecord && isSubmitting) return;
        if (maintenanceRecord) isSubmitting = true;
        const submitButton = event.currentTarget.querySelector('[type="submit"]');
        const originalSubmitLabel = submitButton.textContent;
        if (maintenanceRecord) {
            submitButton.disabled = true;
            submitButton.textContent = 'Guardando Orden de Servicio…';
        }
        const formEntries = new FormData(event.currentTarget);
        const formData = Object.fromEntries(formEntries.entries());
        if (record) formData.id = record.id;
        if (route === 'assets') formData.assignedUserIds = formEntries.getAll('assignedUserIds').map(Number);
        if (route === 'licenses') formData.seatsConfirmed = Number(formEntries.has('seatsConfirmed'));
        if (route === 'maintenance') {
            formData.scheduledAt = `${formData.scheduledDate} ${formData.scheduledTime}`;
            delete formData.scheduledDate;
            delete formData.scheduledTime;
        }
        const action = { assets: 'asset-save', licenses: 'license-save', maintenance: 'maintenance-save', credentials: 'credential-save', documents: 'document-save', users: 'user-save' }[route];
        if (route === 'documents') {
            const formType = formData.formType;
            // Los nombres de Activos pueden no tener cuenta en users. En ese caso
            // signedName conserva a la persona atendida y el servidor usa al técnico
            // autenticado como propietario interno del documento.
            if (maintenanceRecord || (formType === 'service_order' && String(formData.userId || '').startsWith('asset-person:'))) {
                formData.userId = '';
            }
            const excludedFields = ['formType', 'assetId', 'userId', 'signedName', 'performedByName', 'maintenanceId'];
            formData.fields = Object.fromEntries(Object.entries(formData).filter(([key]) => !excludedFields.includes(key)));
            formData.signature = documentSignatures.user();
            formData.performedBySignature = documentSignatures.performedBy();
            formData.formType = formType;
            if (maintenanceRecord) formData.maintenanceId = maintenanceRecord.id;
        }
        try {
            const result = await api(action, { method: 'POST', body: formData });
            closeModal();
            if (route === 'maintenance') {
                toast(result.message || 'Servicio guardado en la agenda.');
            }
            else toast(maintenanceRecord ? 'Orden de Servicio firmada y guardada; mantenimiento cerrado.' : route === 'documents' ? 'Formato guardado con las dos firmas.' : 'Registro guardado.');
            await renderApp();
        } catch (error) {
            document.querySelector('#modal-error').textContent = error.message;
            if (maintenanceRecord) {
                isSubmitting = false;
                submitButton.disabled = false;
                submitButton.textContent = originalSubmitLabel;
            }
        }
    });
}

function showModal(title, body) {
    document.querySelector('#modal-root')?.remove();
    const root = document.createElement('div');
    root.id = 'modal-root';
    root.className = 'modal-backdrop';
    root.innerHTML = `<section class="modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}"><header class="modal-head"><div><h2>${escapeHtml(title)}</h2></div><button class="modal-close" type="button" data-close aria-label="Cerrar">×</button></header><div class="modal-body">${body}</div></section>`;
    root.addEventListener('click', event => { if (event.target === root || event.target.closest('[data-close]')) closeModal(); });
    document.body.append(root);
    root.querySelector('input,select,textarea,button')?.focus();
}

function closeModal() { document.querySelector('#modal-root')?.remove(); }

function showInviteLink(message, inviteUrl) {
    toast(message, 'error');
    showModal('Enlace listo para compartir', `<div class="notice"><span>!</span><div><strong>La agenda se actualizó</strong>${escapeHtml(message)} Copia el enlace y envíaselo al usuario por un canal seguro.</div></div><div class="field"><label>Enlace de confirmación</label><input id="invite-link" readonly value="${escapeHtml(inviteUrl)}"></div><div class="form-actions"><button type="button" class="secondary-button" data-close>Cerrar</button><button type="button" class="primary-button" id="copy-invite">Copiar enlace</button></div>`);
    document.querySelector('#copy-invite').addEventListener('click', async () => {
        const link = document.querySelector('#invite-link');
        try { await navigator.clipboard.writeText(link.value); toast('Enlace copiado.'); } catch { link.select(); document.execCommand('copy'); toast('Enlace copiado.'); }
    });
}

function showMaintenanceDetails(id) {
    const item = maintenanceCache.find(record => String(record.id) === String(id));
    if (!item) return;
    const date = item.calendar_at || item.scheduled_at;
    const active = !['completed', 'cancelled'].includes(item.status);
    const staff = ['admin', 'technician'].includes(sessionUser.role);
    const actions = staff ? `<div class="form-actions">${item.status === 'reschedule_requested' ? `<button type="button" class="secondary-button" data-approve-reschedule="${item.id}">Aceptar cambio</button>` : ''}${active ? `<button type="button" class="secondary-button" data-reschedule-maintenance="${item.id}">Reagendar</button><button type="button" class="secondary-button danger-button" data-cancel-maintenance="${item.id}">Cancelar servicio</button><button type="button" class="primary-button" id="maintenance-detail-complete">Cerrar con F-SIS-01</button>` : item.status === 'completed' ? `<button type="button" class="primary-button" id="maintenance-detail-receipt">Ver constancia</button>` : ''}</div>` : '';
    showModal(`Servicio · ${item.asset_tag}`, `<div class="maintenance-detail"><dl><dt>Fecha y hora</dt><dd>${escapeHtml(dateTime(date))}</dd><dt>Equipo</dt><dd>${escapeHtml(`${item.asset_tag} · ${item.brand} ${item.model}`)}</dd><dt>Persona</dt><dd>${escapeHtml(item.user_name)}</dd><dt>Estado</dt><dd>${escapeHtml(statusText(item.status))}</dd><dt>Servicio</dt><dd>${escapeHtml(item.service_notes || 'Sin notas')}</dd>${item.status === 'reschedule_requested' && item.proposed_at ? `<dt>Horario anterior</dt><dd>${escapeHtml(dateTime(item.scheduled_at))}</dd>` : ''}</dl></div>${actions}<div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>`);
    document.querySelector('#maintenance-detail-complete')?.addEventListener('click', () => {
        closeModal();
        openCreateForm('documents', null, 'service_order', { id: item.id, assetId: item.asset_id, userId: item.user_id, personName: item.user_name });
    });
    document.querySelector('#maintenance-detail-receipt')?.addEventListener('click', () => { closeModal(); printMaintenance(item.id); });
    document.querySelectorAll('#modal-root [data-approve-reschedule]').forEach(button => button.addEventListener('click', () => { closeModal(); approveReschedule(item.id); }));
    document.querySelectorAll('#modal-root [data-reschedule-maintenance]').forEach(button => button.addEventListener('click', () => { closeModal(); openMaintenanceReschedule(item.id); }));
    document.querySelectorAll('#modal-root [data-cancel-maintenance]').forEach(button => button.addEventListener('click', () => { closeModal(); cancelMaintenance(item.id); }));
}

function openMaintenanceReschedule(id) {
    const item = maintenanceCache.find(record => String(record.id) === String(id));
    if (!item) return;
    showModal('Reagendar servicio', `<form id="maintenance-reschedule-form"><div class="form-grid"><div class="field"><label>Fecha nueva *</label><input name="date" id="reschedule-date" type="date" required></div><div class="field"><label>Horario disponible *</label><select name="time" id="reschedule-time" required disabled><option value="">Selecciona una fecha primero</option></select></div></div><div class="field-help">El horario original se libera cuando se guarda el cambio. Los horarios ocupados se muestran deshabilitados.</div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button type="button" class="secondary-button" data-close>Volver</button><button type="submit" class="primary-button">Guardar nuevo horario</button></div></form>`);
    const dateField = document.querySelector('#reschedule-date');
    const timeField = document.querySelector('#reschedule-time');
    dateField.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    dateField.addEventListener('change', () => refreshMaintenanceSlots(dateField, timeField, Number(id)));
    document.querySelector('#maintenance-reschedule-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await api('maintenance-reschedule', { method: 'POST', body: { id: Number(id), scheduledAt: `${dateField.value} ${timeField.value}` } });
            closeModal();
            toast('Servicio reprogramado; el horario anterior quedó disponible.');
            await renderApp();
        } catch (error) {
            document.querySelector('#modal-error').textContent = error.message;
            button.disabled = false;
        }
    });
}

async function cancelMaintenance(id) {
    if (!window.confirm('¿Confirmas cancelar este mantenimiento? Se liberará su horario.')) return;
    try {
        await api('maintenance-cancel', { method: 'POST', body: { id: Number(id) } });
        toast('Mantenimiento cancelado y horario liberado.');
        await renderApp();
    } catch (error) { toast(error.message, 'error'); }
}

async function approveReschedule(id) {
    try {
        const result = await api('maintenance-approve', { method: 'POST', body: { id } });
        await renderApp();
        if (!result.emailSent && result.inviteUrl) showInviteLink(result.emailMessage, result.inviteUrl);
        else toast(result.emailMessage);
    } catch (error) { toast(error.message, 'error'); }
}

async function revealCredential(button) {
    button.disabled = true;
    try {
        const result = await api('credential-reveal', { method: 'POST', body: { id: button.dataset.reveal } });
        showModal('Credencial revelada', `<div class="notice"><span>!</span><div>Evita copiarla a lugares no protegidos. Esta vista desaparece al cerrar el cuadro.</div></div><div class="field"><label>Secreto</label><input readonly value="${escapeHtml(result.secret)}" id="revealed-secret"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>`);
        document.querySelector('#revealed-secret').select();
    } catch (error) { toast(error.message, 'error'); }
    finally { button.disabled = false; }
}

async function revealAssetPassword(id, assetLabel) {
    try {
        const result = await api('asset-password', { method: 'POST', body: { id } });
        showModal(`Contraseña · ${assetLabel}`, `<div class="notice"><span>!</span><div>Acceso restringido a administración. Evita copiarla en lugares no protegidos.</div></div><div class="field"><label>Contraseña</label><input readonly value="${escapeHtml(result.password)}" id="revealed-asset-password"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>`);
        document.querySelector('#revealed-asset-password').select();
    } catch (error) { toast(error.message, 'error'); }
}

async function revealLicenseKey(id) {
    try {
        const result = await api('license-key', { method: 'POST', body: { id } });
        showModal('Clave de activación', `<div class="notice"><span>!</span><div>La clave solo se muestra a administración. Evita copiarla en lugares no protegidos.</div></div><div class="field"><label>Clave</label><input readonly value="${escapeHtml(result.activationKey)}" id="revealed-license-key"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>`);
        document.querySelector('#revealed-license-key').select();
    } catch (error) { toast(error.message, 'error'); }
}

function signaturePad(padId = 'signature-pad', clearId = 'clear-signature') {
    return `<canvas class="signature-pad" id="${padId}" aria-label="Área para firmar"></canvas><div class="signature-tools"><span>Firma con el dedo o el cursor</span><button type="button" id="${clearId}">Limpiar firma</button></div>`;
}

function initSignature(padId = 'signature-pad', clearId = 'clear-signature') {
    const canvas = document.getElementById(padId);
    if (!canvas) return () => '';
    const context = canvas.getContext('2d');
    const scale = window.devicePixelRatio || 1;
    const bounds = canvas.getBoundingClientRect();
    canvas.width = bounds.width * scale;
    canvas.height = bounds.height * scale;
    context.scale(scale, scale);
    context.strokeStyle = '#19352d';
    context.lineWidth = 2;
    context.lineCap = 'round';
    let drawing = false;
    let hasInk = false;
    const point = event => { const rect = canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top }; };
    canvas.addEventListener('pointerdown', event => { drawing = true; canvas.setPointerCapture(event.pointerId); const p = point(event); context.beginPath(); context.moveTo(p.x, p.y); });
    canvas.addEventListener('pointermove', event => { if (!drawing) return; const p = point(event); context.lineTo(p.x, p.y); context.stroke(); hasInk = true; });
    canvas.addEventListener('pointerup', () => { drawing = false; });
    canvas.addEventListener('pointercancel', () => { drawing = false; });
    document.getElementById(clearId).addEventListener('click', () => { context.clearRect(0, 0, canvas.width, canvas.height); hasInk = false; });
    return () => hasInk ? canvas.toDataURL('image/png') : '';
}

function openDocumentSign(id, title) {
    showModal('Firmar carta responsiva', `<form id="document-sign-form"><p style="font-size:12px;color:var(--muted);line-height:1.6">Al firmar confirmas que revisaste las condiciones del documento <strong>${escapeHtml(title)}</strong>.</p><div class="field"><label>Nombre completo *</label><input name="signedName" required maxlength="140" value="${escapeHtml(sessionUser.name)}"></div><div class="field" style="margin-top:14px"><label>Firma *</label>${signaturePad()}</div><div id="modal-error" class="form-error" role="alert"></div><div class="form-actions"><button type="button" class="secondary-button" data-close>Cancelar</button><button class="primary-button" type="submit">Firmar documento</button></div></form>`);
    const signature = initSignature();
    document.querySelector('#document-sign-form').addEventListener('submit', async event => {
        event.preventDefault();
        const signedName = new FormData(event.currentTarget).get('signedName');
        try {
            await api('document-sign', { method: 'POST', body: { id, signedName, signature: signature() } });
            closeModal(); toast('Firma registrada.'); await renderApp();
        } catch (error) { document.querySelector('#modal-error').textContent = error.message; }
    });
}

async function printMaintenance(id) {
    try {
        const result = await api('maintenance-receipt', { method: 'POST', body: { id } });
        showModal('Orden de Servicio firmada', `<div class="document-preview-scroll"><div class="document-preview-surface">${digitalFormReceipt(result)}</div></div><div class="document-preview-controls no-print"><button class="small-button" type="button" data-preview-zoom="out" aria-label="Reducir escala">−</button><button class="small-button" type="button" data-preview-zoom="fit">Ajustar</button><button class="small-button" type="button" data-preview-zoom="in" aria-label="Aumentar escala">+</button><button class="secondary-button" type="button" data-close>Cerrar</button><button class="primary-button" type="button" id="print-receipt">Imprimir / Guardar PDF</button></div>`);
        document.querySelector('#modal-root .modal')?.classList.add('document-modal', 'document-preview-modal');
        bindDocumentPreviewZoom();
        document.querySelector('#print-receipt').addEventListener('click', () => window.print());
    } catch (error) { toast(error.message, 'error'); }
}

async function printSimpleDocument(data) {
    try {
        const receipt = await api('document-receipt', { method: 'POST', body: { id: data.docReceipt } });
        const content = receipt.formType === 'responsibility'
            ? `<article class="receipt digital-form-receipt"><span class="eyebrow">Rame TI · Resguardo de activos</span><h1>${escapeHtml(receipt.title)}</h1><div class="receipt-meta"><div><span>Equipo</span>${escapeHtml(receipt.asset)}</div><div><span>Responsable</span>${escapeHtml(receipt.user)}</div></div><p>${escapeHtml(receipt.body || 'Se registra la entrega y recepción del equipo descrito.')}</p><div class="document-signatures"><div class="document-signature"><img src="${receipt.signature}" alt="Firma de ${escapeHtml(receipt.signedName)}"><strong>${escapeHtml(receipt.signedName)}</strong><span>Firma de conformidad · ${dateTime(receipt.signedAt)}</span></div></div></article>`
            : `<article class="receipt digital-form-receipt">${digitalFormReceipt(receipt)}</article>`;
        const exportWord = receipt.formType === 'service_order' && ['admin', 'technician'].includes(sessionUser.role) ? '<button class="primary-button" type="button" id="download-fsis01">Descargar F-SIS-01 (Word)</button>' : '';
        showModal('Documento firmado', `<div class="document-preview-scroll"><div class="document-preview-surface">${content}</div></div><div class="document-preview-controls no-print"><button class="small-button" type="button" data-preview-zoom="out" aria-label="Reducir escala">−</button><button class="small-button" type="button" data-preview-zoom="fit">Ajustar</button><button class="small-button" type="button" data-preview-zoom="in" aria-label="Aumentar escala">+</button><button class="secondary-button" type="button" data-close>Cerrar</button><button class="secondary-button" type="button" id="print-receipt">Imprimir / Guardar PDF</button>${exportWord}</div>`);
        document.querySelector('#modal-root .modal')?.classList.add('document-modal', 'document-preview-modal');
        bindDocumentPreviewZoom();
        document.querySelector('#print-receipt').addEventListener('click', () => window.print());
        document.querySelector('#download-fsis01')?.addEventListener('click', () => downloadServiceOrderDocx(data.docReceipt));
    } catch (error) { toast(error.message, 'error'); }
}

function bindDocumentPreviewZoom() {
    const viewport = document.querySelector('.document-preview-scroll');
    const surface = document.querySelector('.document-preview-surface');
    if (!viewport || !surface) return;
    let zoom = Math.min(1, Math.max(0.35, (viewport.clientWidth - 24) / Math.max(surface.scrollWidth, 1)));
    const apply = () => { surface.style.zoom = String(zoom); };
    document.querySelectorAll('[data-preview-zoom]').forEach(button => button.addEventListener('click', () => {
        if (button.dataset.previewZoom === 'fit') zoom = Math.min(1, (viewport.clientWidth - 24) / Math.max(surface.scrollWidth, 1));
        if (button.dataset.previewZoom === 'in') zoom = Math.min(1.6, zoom + 0.1);
        if (button.dataset.previewZoom === 'out') zoom = Math.max(0.3, zoom - 0.1);
        apply();
    }));
    apply();
}

async function downloadServiceOrderDocx(id) {
    try {
        const url = new URL('api.php?action=document-export', window.location.href);
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id }),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            throw new Error(error.error || 'No se pudo generar el archivo F-SIS-01.');
        }
        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = `F-SIS-01_Orden_de_Servicio_${id}.docx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
        toast('F-SIS-01 descargado en formato Word.');
    } catch (error) {
        toast(error.message, 'error');
    }
}

function digitalFormReceipt(receipt) {
    const values = receipt.formData || {};
    const esc = value => escapeHtml(value == null ? '' : String(value));
    const staticValue = value => `<span class="fsis-static-value">${esc(value || '—')}</span>`;
    const metaField = (label, value) => `<label><strong>${label}:</strong>${staticValue(value)}</label>`;
    const choiceCell = (value, target, label) => `<td><span class="fsis-choice-mark fsis-choice-static">${value === target ? label : ''}</span></td>`;
    const activityRow = (label, value) => `<tr><th scope="row">${label}</th>${choiceCell(value, 'done', 'Sí')}${choiceCell(value, 'not_done', 'No')}${choiceCell(value, 'not_applicable', 'N/A')}</tr>`;
    const selectedPerson = receipt.signedName || receipt.user || '';
    const signatureBlock = (name, image, label) => `<div class="fsis-signature-block"><div class="fsis-name-row"><strong>${label}:</strong>${staticValue(name)}</div><div class="fsis-sign-area fsis-sign-area-static"><img class="fsis-signature-image" src="${image}" alt="Firma de ${esc(name)}"><span>FIRMA</span></div></div>`;
    if (receipt.formType === 'service_order') {
        return `<article class="receipt digital-form-receipt"><article class="fsis01-sheet fsis01-receipt" aria-label="F-SIS-01 Rev. 04 Orden de Servicio"><header class="fsis01-header"><img class="fsis01-letterhead" src="assets/fsis01-header.jpg" alt="Encabezado institucional Grupo RAME"><div class="fsis01-title">ORDEN DE SERVICIO</div><span class="fsis01-revision">F-SIS-01 Rev.04</span></header><section class="fsis-meta">${metaField('FECHA', values.date)}${metaField('DEPARTAMENTO', values.department)}</section><section class="fsis-equipment"><div class="fsis-section-tag">DATOS DEL EQUIPO:</div><div class="fsis-equipment-data"><div class="fsis-static-textarea">${esc(values.equipmentDetails || '—').replace(/\r?\n/g, '<br>')}</div></div></section><section class="fsis-activities"><div class="fsis-section-tag">ACTIVIDADES REALIZADAS</div><table class="fsis-table"><thead><tr><th></th><th>REALIZADAS</th><th>NO REALIZADAS</th><th>NO APLICA</th></tr></thead><tbody>${activityRow('LIMPIEZA EXTERIOR', values.cleaningExterior)}${activityRow('LIMPIEZA INTERIOR', values.cleaningInterior)}${activityRow('DEPURACIÓN DE SISTEMA', values.systemCleanup)}${activityRow('REVISIÓN DE VIRUS', values.virusReview)}</tbody></table><div class="fsis-password"><strong>CONTRASEÑA DE COMPUTADORA</strong>${staticValue(values.computerPassword)}</div></section><section class="fsis-survey"><div class="fsis-section-tag">ENCUESTA DE SATISFACCIÓN</div><p>Favor de Responder de 6 a 10 de acuerdo a la Satisfacción del Servicio, siendo 6 Pésimo, 7 Puede Mejorar, 8 Bueno, 9 Superó mis Expectativas y 10 Excelente.</p><div class="fsis-satisfaction-row"><strong>SATISFACCIÓN DEL MANTENIMIENTO REALIZADO</strong><div class="fsis-rating fsis-rating-static"><span>${esc(values.satisfaction || '—')}</span></div></div></section><section class="fsis-comments"><div class="fsis-section-tag">COMENTARIOS O SUGERENCIAS</div><div class="fsis-static-comments">${esc(values.comments || '—').replace(/\r?\n/g,'<br>')}</div></section><section class="fsis-signatures">${signatureBlock(selectedPerson, receipt.signature, 'NOMBRE DEL USUARIO')}${signatureBlock(receipt.performedByName, receipt.performedBySignature, 'REALIZADO POR')}</section><footer class="fsis-footer">F-SIS-01 Rev.04</footer></article></article>`;
    }
    const activityField = (label, value) => `<div class="sheet-print-field"><strong>${label}</strong><span>${esc(value || '—')}</span></div>`;
    const satisfaction = `<div class="sheet-satisfaction sheet-print-satisfaction">${[6, 7, 8, 9, 10].map(score => `<span class="${Number(values.satisfaction) === score ? 'selected' : ''}">${score}</span>`).join('')}</div>`;
    const signatures = `<div class="sheet-signatures sheet-print-signatures"><div><strong>NOMBRE DEL USUARIO: ${esc(receipt.signedName)}</strong><img class="sheet-signature-image" src="${receipt.signature}" alt="Firma de ${esc(receipt.signedName)}"><label>FIRMA DEL USUARIO</label></div><div><strong>REALIZADO POR: ${esc(receipt.performedByName)}</strong><img class="sheet-signature-image" src="${receipt.performedBySignature}" alt="Firma de ${esc(receipt.performedByName)}"><label>FIRMA</label></div></div>`;
    return `<article class="receipt digital-form-receipt"><article class="word-sheet word-sheet-corrective"><header class="sheet-header"><span>F-SIS-02 · REV. 03</span><h2>Solicitud de Mantenimiento Correctivo a Equipo de Software y Hardware</h2></header><div class="sheet-grid sheet-meta">${activityField('FECHA', values.date)}${activityField('FECHA DE CIERRE', values.closingDate)}</div>${activityField('DATOS DEL EQUIPO', values.equipmentDetails)}<section class="sheet-section">${activityField('FALLA QUE PRESENTA', values.failure)}${activityField('DIAGNÓSTICO', values.diagnosis)}${activityField('ACTIVIDADES REALIZADAS', values.activities)}</section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${satisfaction}${activityField('TIEMPO DE RESPUESTA', values.responseTime)}${activityField('SOLUCIÓN QUE SE LE DIO', values.solution)}${activityField('COMENTARIOS O SUGERENCIAS', values.comments)}</section>${signatures}</article></article>`;
}

start();
