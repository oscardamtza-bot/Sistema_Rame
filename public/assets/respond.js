const inviteRoot = document.querySelector('#invite-app');
const inviteToken = inviteRoot?.dataset.token || '';
let inviteCsrf = '';

const escapeInviteText = value => String(value || '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
})[character]);

const displayInviteDate = value => new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'full',
    timeStyle: 'short',
}).format(new Date(String(value).replace(' ', 'T')));

function showInviteMessage(message, isError = false) {
    const region = document.querySelector('#toast-region');
    region.innerHTML = `<div class="toast ${isError ? 'error' : ''}">${escapeInviteText(message)}</div>`;
}

async function inviteRequest(action, { method = 'GET', query = {}, body } = {}) {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
    const headers = {};
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = inviteCsrf;
    }
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: method === 'GET' ? undefined : JSON.stringify(body || {}),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'No se pudo procesar la invitación.');
    return result;
}

async function loadInviteAvailability(date) {
    const timeSelect = document.querySelector('#proposed-time');
    if (!timeSelect) return;
    timeSelect.disabled = true;
    timeSelect.innerHTML = '<option value="">Consultando disponibilidad…</option>';
    if (!date) {
        timeSelect.innerHTML = '<option value="">Selecciona una fecha primero</option>';
        return;
    }
    try {
        const result = await inviteRequest('invite-availability', {
            query: { token: inviteToken, date },
        });
        timeSelect.innerHTML = '<option value="">Selecciona un horario</option>' + result.slots.map(slot =>
            `<option value="${escapeInviteText(slot.time)}" ${slot.available ? '' : 'disabled'}>${escapeInviteText(slot.time)}${slot.available ? '' : ' · No disponible'}</option>`
        ).join('');
        timeSelect.disabled = !result.slots.some(slot => slot.available);
    } catch (error) {
        timeSelect.innerHTML = `<option value="">${escapeInviteText(error.message)}</option>`;
    }
}

async function submitInviteResponse(choice, proposedAt = '') {
    const buttons = inviteRoot.querySelectorAll('button');
    buttons.forEach(button => { button.disabled = true; });
    try {
        await inviteRequest('invite-response', {
            method: 'POST',
            body: { token: inviteToken, choice, proposedAt },
        });
        showInviteMessage(choice === 'confirm' ? 'Horario confirmado. Gracias.' : 'Propuesta enviada al equipo de TI.');
        await loadInvite();
    } catch (error) {
        showInviteMessage(error.message, true);
        buttons.forEach(button => { button.disabled = false; });
    }
}

async function loadInvite() {
    try {
        if (!/^[a-f0-9]{64}$/.test(inviteToken)) throw new Error('El enlace no existe o ya no es válido.');
        const stateResponse = await fetch('api.php?action=state', { credentials: 'same-origin' });
        const state = await stateResponse.json();
        if (!stateResponse.ok) throw new Error(state.error || 'No se pudo iniciar la invitación.');
        inviteCsrf = state.csrfToken;
        const result = await inviteRequest('invite', { query: { token: inviteToken } });
        renderInvite(result.invite);
    } catch (error) {
        inviteRoot.innerHTML = `<section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Rame TI</span><h1>Enlace no disponible</h1><p>Invitación de mantenimiento</p></header><div class="invite-card-body"><div class="notice error"><span>!</span><div>${escapeInviteText(error.message)}</div></div><p style="color:var(--muted);font-size:12px">Verifica que el enlace esté completo o contacta a tu equipo de TI.</p></div></section>`;
    }
}

function renderInvite(invite) {
    const labels = {
        pending: 'Confirmación pendiente',
        confirmed: 'Horario confirmado',
        reschedule_requested: 'Cambio de horario solicitado',
        completed: 'Mantenimiento completado',
        cancelled: 'Invitación cancelada',
    };
    const firstName = String(invite.user_name || '').split(' ')[0];
    const pending = invite.status === 'pending';
    const statusMessage = invite.status === 'confirmed'
        ? 'Gracias, tu horario quedó confirmado.'
        : invite.status === 'reschedule_requested'
            ? `Recibimos tu propuesta para ${displayInviteDate(invite.proposed_at)}. El equipo de TI te confirmará el cambio.`
            : invite.status === 'completed'
                ? 'El mantenimiento ya fue registrado como completado.'
                : 'Contacta al equipo de TI si necesitas reprogramar el servicio.';
    inviteRoot.innerHTML = `<div class="invite-brand"><span class="invite-logo-frame"><img class="invite-logo" src="../Logo-Grupo-RAME-3.png" alt="Grupo Rame"></span></div><section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Mantenimiento preventivo</span><h1>Hola, ${escapeInviteText(firstName)}</h1><p>${escapeInviteText(labels[invite.status] || 'Invitación de servicio')}</p></header><div class="invite-card-body"><div class="invite-equipment"><strong>${escapeInviteText(invite.asset_tag)} · ${escapeInviteText(`${invite.brand} ${invite.model}`)}</strong><span>Equipo programado para revisión</span></div><div class="invite-schedule"><span>Horario propuesto</span><strong>${escapeInviteText(displayInviteDate(invite.scheduled_at))}</strong></div>${invite.notes ? `<p style="color:var(--muted);font-size:12px">${escapeInviteText(invite.notes)}</p>` : ''}${pending ? `<div class="invite-actions"><button class="primary-button" id="confirm-invite">Confirmar horario <span class="button-icon">✓</span></button><button class="secondary-button" id="show-reschedule">Proponer otro horario</button></div><div id="reschedule-form" class="hidden" style="margin-top:18px"><div class="field"><label for="proposed-date">Fecha que te funciona</label><input type="date" id="proposed-date"><label for="proposed-time">Horario disponible</label><select id="proposed-time" disabled><option value="">Selecciona una fecha primero</option></select><span class="field-help">Atención de 8:30 a. m. a 5:00 p. m. en intervalos de 30 minutos.</span></div><div class="invite-actions"><button class="primary-button" id="submit-reschedule">Enviar propuesta</button></div></div>` : `<div class="invite-success">${escapeInviteText(statusMessage)}</div>`}<div id="invite-response-message" aria-live="polite"></div></div></section><div class="invite-foot">Rame TI · Gestión de servicio</div>`;
    document.querySelector('#confirm-invite')?.addEventListener('click', () => submitInviteResponse('confirm'));
    document.querySelector('#show-reschedule')?.addEventListener('click', () => document.querySelector('#reschedule-form').classList.remove('hidden'));
    const proposedDate = document.querySelector('#proposed-date');
    if (proposedDate) {
        proposedDate.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        proposedDate.addEventListener('change', () => loadInviteAvailability(proposedDate.value));
    }
    document.querySelector('#submit-reschedule')?.addEventListener('click', () => {
        const date = proposedDate?.value || '';
        const time = document.querySelector('#proposed-time')?.value || '';
        submitInviteResponse('reschedule', date && time ? `${date} ${time}` : '');
    });
}

loadInvite();
