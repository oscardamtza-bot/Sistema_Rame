const inviteRoot = document.querySelector('#invite-app');
const inviteToastRegion = document.querySelector('#toast-region');
let inviteCsrf = '';
const inviteToken = inviteRoot.dataset.token;
const safe = value => String(value || '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const displayDate = value => new Intl.DateTimeFormat('es-MX', { dateStyle: 'full', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T')));

async function loadInviteAvailability(date) {
    const timeSelect = document.querySelector('#proposed-time');
    if (!timeSelect) return;
    timeSelect.disabled = true;
    timeSelect.innerHTML = '<option value="">Consultando disponibilidad…</option>';
    if (!date) { timeSelect.innerHTML = '<option value="">Selecciona una fecha primero</option>'; return; }
    try {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', 'invite-availability');
        url.searchParams.set('token', inviteToken);
        url.searchParams.set('date', date);
        const response = await fetch(url, { credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo consultar la disponibilidad.');
        timeSelect.innerHTML = '<option value="">Selecciona un horario</option>' + result.slots.map(slot => `<option value="${slot.time}" ${slot.available ? '' : 'disabled'}>${slot.time}${slot.available ? '' : ' · No disponible'}</option>`).join('');
        timeSelect.disabled = !result.slots.some(slot => slot.available);
    } catch (error) {
        timeSelect.innerHTML = `<option value="">${safe(error.message)}</option>`;
    }
}

function inviteMessage(text, error = false) {
    inviteToastRegion.innerHTML = `<div class="toast ${error ? 'error' : ''}">${safe(text)}</div>`;
}

async function loadInvite() {
    try {
        const stateResponse = await fetch('api.php?action=state', { credentials: 'same-origin' });
        const state = await stateResponse.json();
        inviteCsrf = state.csrfToken;
        const departmentResponse = await fetch(`api.php?action=department-invite&token=${encodeURIComponent(inviteToken)}`, { credentials: 'same-origin' });
        const departmentResult = await departmentResponse.json();
        if (departmentResponse.ok) { renderDepartmentInvite(departmentResult.invite); return; }
        const response = await fetch(`api.php?action=invite&token=${encodeURIComponent(inviteToken)}`);
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Invitación no disponible.');
        const invite = result.invite;
        const labels = { pending: 'Confirmación pendiente', confirmed: 'Horario confirmado', reschedule_requested: 'Cambio de horario solicitado', completed: 'Mantenimiento completado', cancelled: 'Invitación cancelada' };
        inviteRoot.innerHTML = `<div class="invite-brand"><span class="invite-logo-frame"><img class="invite-logo" src="../Logo-Grupo-RAME-3.png" alt="Grupo Rame"></span></div><section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Mantenimiento preventivo</span><h1>Hola, ${safe(invite.user_name.split(' ')[0])}</h1><p>${labels[invite.status] || 'Invitación de servicio'}</p></header><div class="invite-card-body"><div class="invite-equipment"><strong>${safe(invite.asset_tag)} · ${safe(invite.brand)} ${safe(invite.model)}</strong><span>Equipo programado para revisión</span></div><div class="invite-schedule"><span>Horario propuesto</span><strong>${displayDate(invite.scheduled_at)}</strong></div>${invite.notes ? `<p style="color:var(--muted);font-size:12px">${safe(invite.notes)}</p>` : ''}${invite.status === 'pending' ? `<div class="invite-actions"><button class="primary-button" id="confirm-invite">Confirmar horario <span class="button-icon">✓</span></button><button class="secondary-button" id="show-reschedule">Proponer otro horario</button></div><div id="reschedule-form" class="hidden" style="margin-top:18px"><div class="field"><label>Fecha que te funciona</label><input type="date" id="proposed-date"><label>Horario disponible</label><select id="proposed-time" disabled><option value="">Selecciona una fecha primero</option></select><span class="field-help">Atención de 8:30 a. m. a 5:00 p. m. en intervalos de 30 minutos.</span></div><div class="invite-actions"><button class="primary-button" id="submit-reschedule">Enviar propuesta</button></div></div>` : `<div class="invite-success">${invite.status === 'confirmed' ? 'Gracias, tu horario quedó confirmado.' : invite.status === 'reschedule_requested' ? `Recibimos tu propuesta para ${displayDate(invite.proposed_at)}. El equipo de TI te confirmará el cambio.` : invite.status === 'completed' ? 'El mantenimiento ya fue registrado como completado.' : 'Contacta al equipo de TI si necesitas reprogramar el servicio.'}</div>`}</div></section><div class="invite-foot">Rame TI · Gestión de servicio</div>`;
        document.querySelector('#confirm-invite')?.addEventListener('click', () => submitResponse('confirm'));
        document.querySelector('#show-reschedule')?.addEventListener('click', () => document.querySelector('#reschedule-form').classList.remove('hidden'));
        const proposedDate = document.querySelector('#proposed-date');
        if (proposedDate) proposedDate.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
        proposedDate?.addEventListener('change', () => loadInviteAvailability(proposedDate.value));
        document.querySelector('#submit-reschedule')?.addEventListener('click', () => {
            const date = document.querySelector('#proposed-date')?.value;
            const time = document.querySelector('#proposed-time')?.value;
            submitResponse('reschedule', date && time ? `${date} ${time}` : '');
        });
    } catch (error) {
        inviteRoot.innerHTML = `<section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Rame TI</span><h1>Enlace no disponible</h1><p>Invitación de mantenimiento</p></header><div class="invite-card-body"><div class="notice error"><span>!</span><div>${safe(error.message)}</div></div><p style="color:var(--muted);font-size:12px">Verifica que el enlace esté completo o contacta a tu equipo de TI.</p></div></section>`;
    }
}

function renderDepartmentInvite(invite) {
    const monthName = new Intl.DateTimeFormat('es-MX', { month: 'long' }).format(new Date(invite.year, invite.month - 1, 1));
    const equipment = invite.items.map(item => `<li><strong>${safe(item.asset_tag)}</strong> · ${safe(`${item.brand || ''} ${item.model || ''}`.trim())}<br><span>${safe(item.assigned_people_snapshot || 'Sin persona asignada en Activos')}</span></li>`).join('');
    const canConfirm = invite.status === 'email_sent' && invite.tokenStatus === 'sent';
    inviteRoot.innerHTML = `<div class="invite-brand"><span class="invite-logo-frame"><img class="invite-logo" src="../Logo-Grupo-RAME-3.png" alt="Grupo Rame"></span></div><section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Solicitud de mantenimiento preventivo</span><h1>${safe(invite.department)}</h1><p>${monthName} ${invite.year} · ${invite.itemCount} equipos</p></header><div class="invite-card-body"><p>Revisa los equipos del departamento y selecciona un día y hora de inicio disponibles. El sistema asignará un espacio de 30 minutos por equipo desde la hora elegida, omitirá los horarios ocupados y continuará en días posteriores del mismo mes si hace falta. La atención es de 8:30 a. m. a 5:00 p. m.</p><ul class="invite-equipment-list">${equipment}</ul>${canConfirm ? `<div class="field"><label for="department-date">Fecha de atención</label><input id="department-date" type="date"><label for="department-time">Hora de inicio</label><select id="department-time" disabled><option value="">Selecciona una fecha</option></select></div><div class="invite-actions"><button class="primary-button" id="department-confirm" disabled>Confirmar mantenimiento</button></div>` : `<div class="invite-success">${invite.status === 'confirmed' ? `Horario confirmado: ${displayDate(invite.scheduledStart)}. ${invite.itemCount} equipos se registraron en la agenda.` : 'Esta solicitud ya no permite confirmar un horario.'}</div>`}<div id="department-response-message"></div></div></section><div class="invite-foot">Rame TI · Gestión interna de activos y servicios</div>`;
    const date = document.querySelector('#department-date');
    const time = document.querySelector('#department-time');
    if (!date) return;
    date.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    date.addEventListener('change', async () => {
        time.disabled = true; time.innerHTML = '<option value="">Consultando horarios…</option>';
        try {
            const url = new URL('api.php', window.location.href); url.searchParams.set('action', 'department-availability'); url.searchParams.set('token', inviteToken); url.searchParams.set('date', date.value);
            const response = await fetch(url, { credentials: 'same-origin' }); const result = await response.json(); if (!response.ok) throw new Error(result.error || 'No se pudo consultar la disponibilidad.');
            time.innerHTML = '<option value="">Selecciona una hora disponible</option>' + result.slots.filter(slot => slot.available).map(slot => `<option value="${slot.time}">${slot.time}</option>`).join('');
            if (!result.slots.some(slot => slot.available)) time.innerHTML = '<option value="">No hay capacidad restante para este día</option>';
            time.disabled = !result.slots.some(slot => slot.available); document.querySelector('#department-confirm').disabled = time.disabled;
        } catch (error) { time.innerHTML = `<option value="">${safe(error.message)}</option>`; document.querySelector('#department-confirm').disabled = true; }
    });
    time.addEventListener('change', () => { document.querySelector('#department-confirm').disabled = !time.value; });
    document.querySelector('#department-confirm').addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true;
        try {
            const response = await fetch('api.php?action=department-confirm', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': inviteCsrf }, body: JSON.stringify({ token: inviteToken, scheduledAt: `${date.value} ${time.value}` }) });
            const result = await response.json(); if (!response.ok) throw new Error(result.error || 'No se pudo confirmar el horario.');
            inviteMessage(`${result.scheduledCount} equipos agendados. Gracias.`); await loadInvite();
        } catch (error) { document.querySelector('#department-response-message').innerHTML = `<div class="notice error"><span>!</span><div>${safe(error.message)}</div></div>`; button.disabled = !time.value; }
    });
}

async function submitResponse(choice, proposedAt = '') {
    try {
        const response = await fetch('api.php?action=invite-response', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': inviteCsrf },
            body: JSON.stringify({ token: inviteToken, choice, proposedAt }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo guardar tu respuesta.');
        inviteMessage(choice === 'confirm' ? 'Horario confirmado. Gracias.' : 'Propuesta enviada al equipo de TI.');
        await loadInvite();
    } catch (error) { inviteMessage(error.message, true); }
}

loadInvite();
