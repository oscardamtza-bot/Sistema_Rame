const inviteRoot = document.querySelector('#invite-app');
const inviteToastRegion = document.querySelector('#toast-region');
let inviteCsrf = '';
const inviteToken = inviteRoot.dataset.token;
const safe = value => String(value || '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const displayDate = value => new Intl.DateTimeFormat('es-MX', { dateStyle: 'full', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T')));

function inviteMessage(text, error = false) {
    inviteToastRegion.innerHTML = `<div class="toast ${error ? 'error' : ''}">${safe(text)}</div>`;
}

async function loadInvite() {
    try {
        const stateResponse = await fetch('api.php?action=state', { credentials: 'same-origin' });
        const state = await stateResponse.json();
        inviteCsrf = state.csrfToken;
        const response = await fetch(`api.php?action=invite&token=${encodeURIComponent(inviteToken)}`);
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Invitación no disponible.');
        const invite = result.invite;
        const labels = { pending: 'Confirmación pendiente', confirmed: 'Horario confirmado', reschedule_requested: 'Cambio de horario solicitado', completed: 'Mantenimiento completado', cancelled: 'Invitación cancelada' };
        inviteRoot.innerHTML = `<div class="invite-brand"><span class="invite-logo-frame"><img class="invite-logo" src="../Logo-Grupo-RAME-3.png" alt="Grupo Rame"></span></div><section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Mantenimiento preventivo</span><h1>Hola, ${safe(invite.user_name.split(' ')[0])}</h1><p>${labels[invite.status] || 'Invitación de servicio'}</p></header><div class="invite-card-body"><div class="invite-equipment"><strong>${safe(invite.asset_tag)} · ${safe(invite.brand)} ${safe(invite.model)}</strong><span>Equipo programado para revisión</span></div><div class="invite-schedule"><span>Horario propuesto</span><strong>${displayDate(invite.scheduled_at)}</strong></div>${invite.notes ? `<p style="color:var(--muted);font-size:12px">${safe(invite.notes)}</p>` : ''}${invite.status === 'pending' ? `<div class="invite-actions"><button class="primary-button" id="confirm-invite">Confirmar horario <span class="button-icon">✓</span></button><button class="secondary-button" id="show-reschedule">Proponer otro horario</button></div><div id="reschedule-form" class="hidden" style="margin-top:18px"><div class="field"><label>Horario que te funciona</label><input type="datetime-local" id="proposed-at"></div><div class="invite-actions"><button class="primary-button" id="submit-reschedule">Enviar propuesta</button></div></div>` : `<div class="invite-success">${invite.status === 'confirmed' ? 'Gracias, tu horario quedó confirmado.' : invite.status === 'reschedule_requested' ? `Recibimos tu propuesta para ${displayDate(invite.proposed_at)}. El equipo de TI te confirmará el cambio.` : invite.status === 'completed' ? 'El mantenimiento ya fue registrado como completado.' : 'Contacta al equipo de TI si necesitas reprogramar el servicio.'}</div>`}</div></section><div class="invite-foot">Rame TI · Gestión de servicio</div>`;
        document.querySelector('#confirm-invite')?.addEventListener('click', () => submitResponse('confirm'));
        document.querySelector('#show-reschedule')?.addEventListener('click', () => document.querySelector('#reschedule-form').classList.remove('hidden'));
        document.querySelector('#submit-reschedule')?.addEventListener('click', () => submitResponse('reschedule', document.querySelector('#proposed-at').value));
    } catch (error) {
        inviteRoot.innerHTML = `<section class="invite-card"><header class="invite-card-head"><span class="eyebrow">Rame TI</span><h1>Enlace no disponible</h1><p>Invitación de mantenimiento</p></header><div class="invite-card-body"><div class="notice error"><span>!</span><div>${safe(error.message)}</div></div><p style="color:var(--muted);font-size:12px">Verifica que el enlace esté completo o contacta a tu equipo de TI.</p></div></section>`;
    }
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