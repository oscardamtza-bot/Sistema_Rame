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
    const table = (rows, phoneTable = false) => `<div class="table-wrap"><table class="data-table"><thead><tr><th>Etiqueta / equipo</th><th>Tipo</th>${phoneTable ? '<th>Estado celular</th>' : ''}<th>Usuario de Windows</th><th>Persona asignada</th><th>Departamento</th><th>Puesto</th><th>Vencimiento garantía</th><th>Terminal</th><th>Monitor</th><th>Mouse</th><th>RDP</th><th>Contraseña</th><th>Estado inventario</th><th>Fecha de asignación</th>${canEdit || historyAccess ? '<th>Acciones</th>' : ''}</tr></thead><tbody>${rows.map(asset => `<tr data-search="${escapeHtml([asset.asset_tag, asset.brand, asset.model, asset.assigned_name || '', asset.windows_username || '', asset.department || '', asset.job_title || '', asset.terminal || ''].join(' ').toLowerCase())}" data-status="${asset.status}"><td><span class="table-primary">${escapeHtml(asset.asset_tag)}</span><span class="table-secondary">${escapeHtml(asset.brand)} ${escapeHtml(asset.model)} · ${escapeHtml(asset.serial_number || 'Sin número de serie')}</span></td><td>${typeLabels[asset.type] || 'Otro'}</td>${phoneTable ? `<td><span class="status-pill status-${asset.phone_format_status || 'pending'}">${phoneFormatText(asset.phone_format_status)}</span></td>` : ''}<td>${escapeHtml(asset.windows_username || '—')}</td><td>${escapeHtml(asset.assigned_name || 'Sin asignar')}</td><td>${escapeHtml(asset.department || '—')}</td><td>${escapeHtml(asset.job_title || '—')}</td><td>${fmtAssignedDate(asset.warranty_until)}</td><td>${escapeHtml(asset.terminal || '—')}</td><td>${escapeHtml(asset.monitor || '—')}</td><td>${escapeHtml(asset.mouse || '—')}</td><td>${escapeHtml(asset.rdp_access || '—')}</td><td>${Number(asset.has_windows_password) ? (sessionUser.role === 'admin' ? `<button class="small-button" data-reveal-asset-password="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}">Revelar</button>` : 'Protegida') : '—'}</td><td><span class="status-pill status-${asset.status}">${statusText(asset.status)}</span></td><td>${fmtAssignedDate(asset.assigned_date)}</td>${canEdit || historyAccess ? `<td class="table-actions">${asset.type === 'computer' || asset.type === 'phone' ? `<button class="small-button" data-asset-letters="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}" data-assigned-user="${asset.assigned_user_id || ''}">Carta responsiva</button>` : ''}${canEdit ? `<button class="small-button" data-edit-asset="${asset.id}">Editar</button>` : ''}${historyAccess ? `<button class="small-button" data-asset-history="${asset.id}" data-asset-label="${escapeHtml(asset.asset_tag)}">Historial</button>` : ''}</td>` : ''}</tr>`).join('')}</tbody></table>${rows.length ? '' : emptyState('Sin equipos en esta categoría', 'Los equipos que registres aparecerán aquí.')}</div>`;
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
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'small-button';
    clearButton.id = 'asset-filter-clear';
    clearButton.textContent = 'Limpiar filtros';
    count.id = 'asset-result-count';

    toolbar.insertBefore(departmentFilter, count);
    toolbar.insertBefore(statusFilter, count);
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

async function maintenanceView() {
    const { maintenances } = await api('maintenances');
    return `<section class="section-panel"><div class="toolbar"><span class="mono">${maintenances.length} servicios</span><span class="toolbar-spacer"></span><label class="field-help" for="maintenance-date-from">Desde</label><input class="search-input" id="maintenance-date-from" type="date" aria-label="Fecha inicial"><label class="field-help" for="maintenance-date-to">Hasta</label><input class="search-input" id="maintenance-date-to" type="date" aria-label="Fecha final"><button class="small-button" id="maintenance-date-clear" type="button">Limpiar fechas</button><span class="field-help">Filtra por fecha programada.</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha programada</th><th>Equipo</th><th>Usuario</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${maintenances.map(item => `<tr data-scheduled-date="${escapeHtml(item.scheduled_at.slice(0, 10))}"><td>${dateTime(item.scheduled_at)}<span class="table-secondary">${item.status === 'cancelled' ? 'Cancelado · no cuenta' : `Mantenimiento ${item.annual_maintenance_number}/2`}</span>${item.proposed_at ? `<span class="table-secondary">Propone: ${dateTime(item.proposed_at)}</span>` : ''}</td><td><span class="table-primary">${escapeHtml(item.asset_tag)}</span><span class="table-secondary">${escapeHtml(item.brand)} ${escapeHtml(item.model)}</span></td><td>${escapeHtml(item.user_name)}<span class="table-secondary">${escapeHtml(item.user_email)}</span></td><td><span class="status-pill status-${item.status}">${statusText(item.status)}</span></td><td>${item.status === 'reschedule_requested' && ['admin', 'technician'].includes(sessionUser.role) ? `<button class="small-button" data-approve-reschedule="${item.id}">Aceptar horario</button> ` : ''}${item.status !== 'completed' && item.status !== 'cancelled' && ['admin', 'technician'].includes(sessionUser.role) ? `<button class="small-button" data-complete="${item.id}" data-asset-id="${item.asset_id}" data-user-id="${item.user_id}">Firmar Orden de Servicio</button>` : item.status === 'completed' ? `<button class="small-button" data-receipt="${item.id}">Constancia</button>` : '—'}</td></tr>`).join('')}</tbody></table>${maintenances.length ? '' : emptyState('Agenda despejada', 'Programa un mantenimiento preventivo para iniciar el flujo de confirmación.')}</div></section>`;
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
                return `<li><div><strong>${escapeHtml(formatPersonName(letter.user_name))} · ${escapeHtml(letter.original_name)}</strong><span>${formatFileSize(letter.file_size)} · ${dateTime(letter.created_at)}</span></div><div class="responsibility-letter-actions"><button type="button" class="small-button" data-preview-letter="${letter.id}" data-letter-name="${escapeHtml(letter.original_name)}" data-letter-mime="${escapeHtml(letter.mime_type)}">Vista previa</button><a class="small-button letter-download" href="${escapeHtml(downloadUrl)}">Descargar</a><button type="button" class="small-button" data-print-letter="${letter.id}">Imprimir</button>${isStaff ? `<button type="button" class="small-button danger-button" data-delete-letter="${letter.id}">Eliminar</button>` : ''}</div></li>`;
            }).join('')}</ul>`
            : '<div class="empty-state"><strong>Sin cartas escaneadas</strong><span>Agrega una carta por cada persona que use este activo.</span></div>';
        const uploadForm = isStaff ? `<form id="responsibility-letter-form" class="responsibility-letter-form"><input type="hidden" name="assetId" value="${escapeHtml(assetId)}"><div class="field"><label for="responsibility-letter-assignee">Persona *</label><select id="responsibility-letter-assignee" name="assigneeId" required>${selectOptions(assignedPeople, 'id', person => formatPersonName(person.name), 'Selecciona persona')}</select><span class="field-help">Cada persona asignada puede tener su propia carta responsiva.</span></div><div class="field"><label for="responsibility-letter-file">Archivo escaneado *</label><input id="responsibility-letter-file" name="letter" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div><div id="responsibility-letter-preview" class="responsibility-letter-preview hidden" aria-live="polite"></div><div id="responsibility-letter-error" class="form-error" role="alert"></div><div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button><button class="primary-button" type="submit">Subir carta</button></div></form>` : '<div class="form-actions"><button class="secondary-button" type="button" data-close>Cerrar</button></div>';
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
            if (!window.confirm('¿Eliminar esta carta responsiva? Esta acción no se puede deshacer.')) return;
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
                toast('Carta escaneada guardada para esta persona y este activo.');
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
    const activityChoices = (name, label) => `<tr><th>${label}</th>${[['done', 'Realizadas'], ['not_done', 'No realizadas'], ['not_applicable', 'No aplica']].map(([value, text]) => `<td><label class="sheet-check"><input type="radio" name="${name}" value="${value}" required><span>${text}</span></label></td>`).join('')}</tr>`;
    const satisfaction = `<div class="sheet-satisfaction">${[6, 7, 8, 9, 10].map(score => `<label><input type="radio" name="satisfaction" value="${score}" required><span>${score}</span></label>`).join('')}</div><p class="sheet-note">6 Pésimo · 7 Puede mejorar · 8 Bueno · 9 Superó mis expectativas · 10 Excelente.</p>`;
    const signatureSection = `<div class="sheet-signatures"><div><strong>NOMBRE DEL USUARIO:</strong><input name="signedName" required maxlength="140"><label>FIRMA DEL USUARIO</label>${signaturePad('document-user-signature-pad', 'document-clear-user-signature')}</div><div><strong>REALIZADO POR:</strong><input name="performedByName" required maxlength="140" value="${escapeHtml(sessionUser.name)}"><label>FIRMA</label>${signaturePad('document-performed-signature-pad', 'document-clear-performed-signature')}</div></div>`;
    const assetAndUser = `<section class="sheet-section"><h3>DATOS DEL EQUIPO</h3><div class="sheet-grid"><label>Usuario que reporta *<select name="userId" id="document-user" required>${selectOptions(peopleCache, 'id', person => person.name, 'Selecciona usuario')}</select></label><label>Equipo *<select name="assetId" id="document-asset" required>${selectOptions(assetsCache, 'id', asset => `${asset.asset_tag} · ${asset.brand} ${asset.model}`, 'Selecciona equipo')}</select></label></div><div class="sheet-assigned-assets" id="document-assigned-assets">Selecciona un usuario para consultar sus equipos asignados.</div><div class="sheet-equipment-data" id="document-equipment-data">Selecciona un equipo para completar sus datos.</div></section>`;
    if (formType === 'service_order') {
        return `<article class="word-sheet"><header class="sheet-header"><span>F-SIS-01 · REV. 04</span><h2>ORDEN DE SERVICIO</h2></header><div class="sheet-grid sheet-meta"><label>FECHA *<input name="date" type="date" value="${today}" required></label><label>DEPARTAMENTO<input name="department" maxlength="140"></label></div>${assetAndUser}<section class="sheet-section"><h3>ACTIVIDADES REALIZADAS</h3><table class="sheet-activity-table"><thead><tr><th></th><th>REALIZADAS</th><th>NO REALIZADAS</th><th>NO APLICA</th></tr></thead><tbody>${activityChoices('cleaningExterior', 'LIMPIEZA EXTERIOR')}${activityChoices('cleaningInterior', 'LIMPIEZA INTERIOR')}${activityChoices('systemCleanup', 'DEPURACIÓN DE SISTEMA')}${activityChoices('virusReview', 'REVISIÓN DE VIRUS')}</tbody></table><div class="sheet-password"><strong>CONTRASEÑA DE COMPUTADORA</strong><label class="sheet-check"><input type="checkbox" name="computerPasswordStatus" value="in_vault"><span>Resguardada en bóveda</span></label><span class="sheet-note">No escribas la contraseña en este formato.</span></div></section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${satisfaction}<label class="sheet-wide-field">COMENTARIOS O SUGERENCIAS<textarea name="comments" maxlength="2000" rows="3"></textarea></label></section>${signatureSection}</article>`;
    }
    return `<article class="word-sheet"><header class="sheet-header"><span>F-SIS-02 · REV. 03</span><h2>Solicitud de Mantenimiento Correctivo a Equipo de Software y Hardware</h2></header><div class="sheet-grid sheet-meta"><label>FECHA *<input name="date" type="date" value="${today}" required></label><label>FECHA DE CIERRE<input name="closingDate" type="date"></label></div>${assetAndUser}<section class="sheet-section"><label class="sheet-wide-field">FALLA QUE PRESENTA *<textarea name="failure" maxlength="2000" required rows="3"></textarea></label><label class="sheet-wide-field">DIAGNÓSTICO *<textarea name="diagnosis" maxlength="2000" required rows="3"></textarea></label><label class="sheet-wide-field">ACTIVIDADES REALIZADAS<textarea name="activities" maxlength="3000" rows="3"></textarea></label></section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${satisfaction}<div class="sheet-grid"><label>TIEMPO DE RESPUESTA<input name="responseTime" maxlength="140"></label></div><label class="sheet-wide-field">SOLUCIÓN QUE SE LE DIO<textarea name="solution" maxlength="2000" rows="3"></textarea></label><label class="sheet-wide-field">COMENTARIOS O SUGERENCIAS<textarea name="comments" maxlength="2000" rows="3"></textarea></label></section>${signatureSection}</article>`;
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
    ['#asset-department-filter', '#asset-status-filter'].forEach(selector => {
        document.querySelector(selector)?.addEventListener('change', filterAssets);
    });
    document.querySelector('#asset-filter-clear')?.addEventListener('click', () => {
        document.querySelector('#table-search').value = '';
        document.querySelector('#asset-department-filter').value = '';
        document.querySelector('#asset-status-filter').value = '';
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
    document.querySelector('#maintenance-date-from')?.addEventListener('change', filterMaintenances);
    document.querySelector('#maintenance-date-to')?.addEventListener('change', filterMaintenances);
    document.querySelector('#maintenance-date-clear')?.addEventListener('click', () => {
        document.querySelector('#maintenance-date-from').value = '';
        document.querySelector('#maintenance-date-to').value = '';
        filterMaintenances();
    });
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
    document.querySelectorAll('[data-sign-doc]').forEach(button => button.addEventListener('click', () => openDocumentSign(button.dataset.signDoc, button.dataset.title)));
    document.querySelectorAll('[data-receipt]').forEach(button => button.addEventListener('click', () => printMaintenance(button.dataset.receipt)));
    document.querySelectorAll('[data-doc-receipt]').forEach(button => button.addEventListener('click', () => printSimpleDocument(button.dataset)));
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
    const rows = [...document.querySelectorAll('.asset-group tbody tr')];
    const matchingRows = rows.filter(row => {
        const departmentMatches = !department || (department === '__none__' ? !row.dataset.department : row.dataset.department === department);
        return row.dataset.search.includes(query)
            && departmentMatches
            && (!status || row.dataset.status === status)
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
    const from = document.querySelector('#maintenance-date-from')?.value || '';
    const to = document.querySelector('#maintenance-date-to')?.value || '';
    document.querySelectorAll('tr[data-scheduled-date]').forEach(row => {
        const date = row.dataset.scheduledDate;
        row.hidden = (from !== '' && date < from) || (to !== '' && date > to);
    });
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

function openCreateForm(route, record = null, documentFormType = 'service_order', maintenanceRecord = null, assetType = 'computer') {
    let title = '';
    let fields = '';
    let documentSignatures = null;
    if (route === 'assets') {
        title = 'Registrar activo';
        fields = `<div class="field"><label>Etiqueta de inventario *</label><input name="assetTag" required maxlength="80" placeholder="TI-0248"></div><div class="field"><label>Tipo *</label><select name="type" id="asset-type"><option value="computer">Computadora</option><option value="phone">Celular</option><option value="tablet">Tableta</option><option value="other">Otro</option></select></div><div class="field hidden" id="phone-category-field"><label>Clasificación del celular *</label><select name="phoneCategory"><option value="">Selecciona clasificación</option><option value="administrative">Administrativo</option><option value="operational">Operacional</option></select></div><div class="field hidden" id="phone-format-field"><label>Estado del celular *</label><select name="phoneFormatStatus"><option value="">Selecciona estado</option><option value="active">Activo</option><option value="formatted">Formateado</option><option value="to_format">Por formatear</option></select></div><div class="field"><label>Marca *</label><input name="brand" required maxlength="100" placeholder="Lenovo"></div><div class="field"><label>Modelo *</label><input name="model" required maxlength="120" placeholder="ThinkPad E14"></div><div class="field"><label>Número de serie</label><input name="serialNumber" maxlength="150"></div><div class="field"><label>Usuario de Windows</label><input name="windowsUsername" maxlength="190" autocomplete="off"></div><div class="field full"><label>Contraseña de Windows / RDP</label><input name="windowsPassword" type="password" maxlength="4096" autocomplete="new-password"><span class="field-help">Se guarda sin cifrar en la base de datos. Al editar, déjala vacía para conservar la contraseña actual.</span>${record ? '<label><input name="clearWindowsPassword" type="checkbox" value="1"> Eliminar la contraseña guardada</label>' : ''}</div><div class="field"><label>Estado</label><select name="status"><option value="assigned">Activo</option><option value="available" selected>Disponible</option><option value="maintenance">En mantenimiento</option><option value="retired">Dado de baja</option></select></div><div class="field"><label>Persona asignada</label><select name="assignedUserId">${selectOptions(peopleCache, 'id', person => person.name, 'Sin asignar')}</select></div><div class="field"><label>Fecha de asignación</label><input name="assignedDate" type="date"></div><div class="field"><label>Departamento</label><input name="department" maxlength="140"></div><div class="field"><label>Puesto</label><input name="position" maxlength="140"></div><div class="field"><label>Vencimiento de garantía</label><input name="warrantyUntil" type="date"></div><div class="field"><label>Terminal</label><input name="terminal" maxlength="190"></div><div class="field"><label>Monitor</label><input name="monitor" maxlength="160"></div><div class="field"><label>Mouse</label><input name="mouse" maxlength="160"></div><div class="field full"><label>RDP (equipo/host)</label><input name="rdpAccess" maxlength="190"></div><div class="field full"><label>Notas</label><textarea name="notes" maxlength="2000"></textarea></div>`;
    } else if (route === 'licenses') {
        title = 'Registrar licencia';
        fields = `<div class="field"><label>Producto *</label><input name="product" required maxlength="160" placeholder="Microsoft 365"></div><div class="field"><label>Proveedor</label><input name="vendor" maxlength="140"></div><div class="field"><label>Asientos disponibles *</label><input name="seats" type="number" min="1" value="1" required></div><div class="field"><label>Activaciones en uso</label><input name="activations" type="number" min="0" value="0"></div><div class="field"><label>Vence el</label><input name="expiresOn" type="date"></div><div class="field"><label>Estado</label><select name="status"><option value="active">Activa</option><option value="expiring">Por vencer</option><option value="inactive">Inactiva</option><option value="expired">Vencida</option></select></div><div class="field full"><label>Clave de activación</label><input name="activationKey" type="password" autocomplete="new-password"><span class="field-help">Se cifra en el servidor; al editar, dejar en blanco conserva la clave guardada.</span></div><div class="field full"><label>Notas</label><textarea name="notes" maxlength="2000" placeholder="No incluyas claves de activación en este campo"></textarea></div>`;
    } else if (route === 'maintenance') {
        title = 'Agendar mantenimiento';
        const assetOptions = selectOptions(assetsCache.filter(asset => asset.status !== 'retired'), 'id', asset => `${asset.asset_tag} · ${asset.brand} ${asset.model}`, 'Selecciona equipo');
        fields = `<div class="field full"><label>Equipo *</label><select name="assetId" id="maintenance-asset" required>${assetOptions}</select></div><div class="field full"><label>Usuario *</label><select name="userId" id="maintenance-user" required>${selectOptions(peopleCache, 'id', person => `${person.name} · ${person.email}`, 'Selecciona usuario')}</select></div><div class="field full"><label>Fecha y hora *</label><input name="scheduledAt" type="datetime-local" required></div><div class="field full"><label>Alcance del servicio</label><textarea name="notes" maxlength="2000" placeholder="Revisión preventiva, actualizaciones, limpieza…"></textarea></div><div class="field full"><span class="field-help">Selecciona un horario futuro. Se usará la hora local de Ciudad de México; al guardar, se enviará la invitación por correo.</span></div>`;
        const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        fields = fields.replace('type="datetime-local" required', `type="datetime-local" min="${localNow}" required`);
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
        assigneeSelect.closest('.field').classList.add('full');
        assigneeSelect.insertAdjacentHTML('afterend', '<textarea name="assignedPersonNames" rows="2" maxlength="700" placeholder="O escribe los nombres manualmente, uno por línea" aria-label="Nombres asignados escritos manualmente"></textarea><span class="field-help">Selecciona usuarios de la lista (Ctrl para varios) y/o escribe nombres a mano. Máximo 5 personas en total.</span>');
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
            document.querySelector('#document-user').value = String(maintenanceRecord.userId);
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
            document.querySelector('#create-form [name="assignedPersonNames"]').value = (record.assignees || []).filter(person => !person.user_id).map(person => person.name).join('\n');
        }
        document.querySelector('#modal-root .modal-head h2').textContent = route === 'assets' ? 'Editar activo' : 'Actualizar licencia';
        document.querySelector('#create-form .primary-button').textContent = 'Guardar cambios';
    }
    if (route === 'documents') {
        documentSignatures = {
            user: initSignature('document-user-signature-pad', 'document-clear-user-signature'),
            performedBy: initSignature('document-performed-signature-pad', 'document-clear-performed-signature'),
        };
        const syncDocumentIdentity = (selectAssignedEquipment = false) => {
            const userSelect = document.querySelector('#document-user');
            const assetSelect = document.querySelector('#document-asset');
            const person = peopleCache.find(item => String(item.id) === userSelect.value);
            const assignedAssets = person ? assetsCache.filter(item => (item.assigned_user_ids || []).includes(Number(person.id))) : [];
            if (selectAssignedEquipment) assetSelect.value = assignedAssets.length ? String(assignedAssets[0].id) : '';
            const asset = assetsCache.find(item => String(item.id) === assetSelect.value);
            const signedName = document.querySelector('[name="signedName"]');
            const department = document.querySelector('[name="department"]');
            if (person && signedName) signedName.value = person.name;
            if (person && department) department.value = person.department || '';
            document.querySelector('#document-assigned-assets').textContent = !person
                ? 'Selecciona un usuario para consultar sus equipos asignados.'
                : assignedAssets.length
                    ? `Equipos asignados a ${person.name}: ${assignedAssets.map(item => `${item.asset_tag} · ${item.brand} ${item.model}`).join('; ')}`
                    : `${person.name} no tiene equipos asignados.`;
            document.querySelector('#document-equipment-data').textContent = asset ? `${asset.asset_tag} · ${asset.brand} ${asset.model} · Serie: ${asset.serial_number || 'No registrada'}` : 'Selecciona un equipo para completar sus datos.';
        };
        document.querySelector('#document-user').addEventListener('change', () => syncDocumentIdentity(true));
        document.querySelector('#document-asset').addEventListener('change', syncDocumentIdentity);
        document.querySelector('#document-form-type')?.addEventListener('change', event => {
            const selectedUser = document.querySelector('#document-user').value;
            const selectedAsset = document.querySelector('#document-asset').value;
            document.querySelector('#document-form-fields').innerHTML = documentFieldsMarkup(event.currentTarget.value);
            document.querySelector('#document-user').value = selectedUser;
            document.querySelector('#document-asset').value = selectedAsset;
            documentSignatures = {
                user: initSignature('document-user-signature-pad', 'document-clear-user-signature'),
                performedBy: initSignature('document-performed-signature-pad', 'document-clear-performed-signature'),
            };
            document.querySelector('#document-user').addEventListener('change', syncDocumentIdentity);
            document.querySelector('#document-asset').addEventListener('change', syncDocumentIdentity);
            syncDocumentIdentity();
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
        if (route === 'assets') {
            formData.assignedUserIds = formEntries.getAll('assignedUserIds').map(Number);
            formData.assignedPersonNames = String(formEntries.get('assignedPersonNames') || '').split(/\r?\n/).map(name => name.trim()).filter(Boolean);
        }
        if (route === 'licenses') formData.seatsConfirmed = Number(formEntries.has('seatsConfirmed'));
        if (route === 'maintenance') formData.scheduledAt = formData.scheduledAt.replace('T', ' ');
        const action = { assets: 'asset-save', licenses: 'license-save', maintenance: 'maintenance-save', credentials: 'credential-save', documents: 'document-save', users: 'user-save' }[route];
        if (route === 'documents') {
            const formType = formData.formType;
            if (formType === 'service_order' && !formData.computerPasswordStatus) formData.computerPasswordStatus = 'not_applicable';
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
                if (!result.emailSent && result.inviteUrl) showInviteLink(result.emailMessage, result.inviteUrl);
                else toast(result.emailMessage);
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
        showModal('Orden de Servicio firmada', `<article class="receipt digital-form-receipt">${digitalFormReceipt(result)}</article><div class="form-actions no-print"><button class="secondary-button" type="button" data-close>Cerrar</button><button class="primary-button" type="button" id="print-receipt">Imprimir / Guardar PDF</button></div>`);
        document.querySelector('#print-receipt').addEventListener('click', () => window.print());
    } catch (error) { toast(error.message, 'error'); }
}

async function printSimpleDocument(data) {
    try {
        const receipt = await api('document-receipt', { method: 'POST', body: { id: data.docReceipt } });
        const content = receipt.formType === 'responsibility'
            ? `<article class="receipt digital-form-receipt"><span class="eyebrow">Rame TI · Resguardo de activos</span><h1>${escapeHtml(receipt.title)}</h1><div class="receipt-meta"><div><span>Equipo</span>${escapeHtml(receipt.asset)}</div><div><span>Responsable</span>${escapeHtml(receipt.user)}</div></div><p>${escapeHtml(receipt.body || 'Se registra la entrega y recepción del equipo descrito.')}</p><div class="document-signatures"><div class="document-signature"><img src="${receipt.signature}" alt="Firma de ${escapeHtml(receipt.signedName)}"><strong>${escapeHtml(receipt.signedName)}</strong><span>Firma de conformidad · ${dateTime(receipt.signedAt)}</span></div></div></article>`
            : `<article class="receipt digital-form-receipt">${digitalFormReceipt(receipt)}</article>`;
        showModal('Documento firmado', `${content}<div class="form-actions no-print"><button class="secondary-button" type="button" data-close>Cerrar</button><button class="primary-button" type="button" id="print-receipt">Imprimir / Guardar PDF</button></div>`);
        document.querySelector('#print-receipt').addEventListener('click', () => window.print());
    } catch (error) { toast(error.message, 'error'); }
}

function digitalFormReceipt(receipt) {
    const values = receipt.formData || {};
    const activityCell = value => `<td><span class="sheet-print-box">${value ? 'X' : ''}</span></td>`;
    const activityRow = (label, value) => `<tr><th>${label}</th>${activityCell(value === 'done')}${activityCell(value === 'not_done')}${activityCell(value === 'not_applicable')}</tr>`;
    const field = (label, value) => `<div class="sheet-print-field"><strong>${label}</strong><span>${escapeHtml(value || '—')}</span></div>`;
    const satisfaction = `<div class="sheet-satisfaction sheet-print-satisfaction">${[6, 7, 8, 9, 10].map(score => `<span class="${Number(values.satisfaction) === score ? 'selected' : ''}">${score}</span>`).join('')}</div><p class="sheet-note">6 Pésimo · 7 Puede mejorar · 8 Bueno · 9 Superó mis expectativas · 10 Excelente.</p>`;
    const equipment = `<section class="sheet-section"><h3>DATOS DEL EQUIPO</h3><div class="sheet-print-grid">${field('EQUIPO', receipt.asset)}${field('USUARIO QUE REPORTA', receipt.user)}</div></section>`;
    const signatures = `<div class="sheet-signatures sheet-print-signatures"><div><strong>NOMBRE DEL USUARIO: ${escapeHtml(receipt.signedName)}</strong><img class="sheet-signature-image" src="${receipt.signature}" alt="Firma de ${escapeHtml(receipt.signedName)}"><label>FIRMA DEL USUARIO</label><span class="sheet-note">${dateTime(receipt.signedAt)} · IP ${escapeHtml(receipt.signedIp || 'no disponible')}</span></div><div><strong>REALIZADO POR: ${escapeHtml(receipt.performedByName)}</strong><img class="sheet-signature-image" src="${receipt.performedBySignature}" alt="Firma de ${escapeHtml(receipt.performedByName)}"><label>FIRMA</label><span class="sheet-note">${dateTime(receipt.performedBySignedAt)} · IP ${escapeHtml(receipt.performedBySignedIp || 'no disponible')}</span></div></div>`;
    if (receipt.formType === 'service_order') {
        return `<article class="word-sheet word-sheet-service-order"><header class="sheet-header"><span>F-SIS-01 · REV. 04</span><h2>ORDEN DE SERVICIO</h2></header><div class="sheet-grid sheet-meta">${field('FECHA', values.date)}${field('DEPARTAMENTO', values.department)}</div>${equipment}<section class="sheet-section"><h3>ACTIVIDADES REALIZADAS</h3><table class="sheet-activity-table"><thead><tr><th></th><th>REALIZADAS</th><th>NO REALIZADAS</th><th>NO APLICA</th></tr></thead><tbody>${activityRow('LIMPIEZA EXTERIOR', values.cleaningExterior)}${activityRow('LIMPIEZA INTERIOR', values.cleaningInterior)}${activityRow('DEPURACIÓN DE SISTEMA', values.systemCleanup)}${activityRow('REVISIÓN DE VIRUS', values.virusReview)}</tbody></table><div class="sheet-password"><strong>CONTRASEÑA DE COMPUTADORA</strong><span class="sheet-print-box">${values.computerPasswordStatus === 'in_vault' ? 'X' : ''}</span><span>Resguardada en bóveda</span></div></section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${satisfaction}${field('COMENTARIOS O SUGERENCIAS', values.comments)}</section>${signatures}</article>`;
    }
    return `<article class="word-sheet word-sheet-corrective"><header class="sheet-header"><span>F-SIS-02 · REV. 03</span><h2>Solicitud de Mantenimiento Correctivo a Equipo de Software y Hardware</h2></header><div class="sheet-grid sheet-meta">${field('FECHA', values.date)}${field('FECHA DE CIERRE', values.closingDate)}</div>${equipment}<section class="sheet-section">${field('FALLA QUE PRESENTA', values.failure)}${field('DIAGNÓSTICO', values.diagnosis)}${field('ACTIVIDADES REALIZADAS', values.activities)}</section><section class="sheet-section"><h3>ENCUESTA DE SATISFACCIÓN</h3><p>Favor de responder de 6 a 10 de acuerdo con la satisfacción del servicio.</p>${satisfaction}<div class="sheet-print-grid">${field('TIEMPO DE RESPUESTA', values.responseTime)}</div>${field('SOLUCIÓN QUE SE LE DIO', values.solution)}${field('COMENTARIOS O SUGERENCIAS', values.comments)}</section>${signatures}</article>`;
}

start();