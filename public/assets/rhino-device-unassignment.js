(() => {
  'use strict';

  const content = document.getElementById('content');
  const role = document.getElementById('identityRole');
  if (!content || !role) return;

  const isSuperAdmin = () => role.textContent.trim() === 'Administrador global';

  function addUnassignActions() {
    if (!isSuperAdmin()) return;

    content.querySelectorAll('button.suspend-device, button.activate-device').forEach(action => {
      const cell = action.closest('td');
      if (!cell || cell.querySelector('.unassign-device')) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'button small danger unassign-device';
      button.textContent = 'Desvincular';
      button.dataset.id = action.dataset.id || '';
      cell.appendChild(button);
    });
  }

  content.addEventListener('click', async event => {
    const button = event.target.closest('.unassign-device');
    if (!button || !isSuperAdmin()) return;

    const deviceId = button.dataset.id;
    if (!deviceId) return;

    const reason = window.prompt('Motivo de la desvinculación (obligatorio):');
    if (!reason || !reason.trim()) return;

    const confirmed = window.confirm(
      'La báscula quedará SIN SOCIO y dejará de transmitir a la cuenta actual. ' +
      'Se liberarán sus vínculos de eventos, se invalidará el secreto anterior y se conservarán todos los pesajes históricos. ¿Continuar?'
    );
    if (!confirmed) return;

    button.disabled = true;
    try {
      const token = localStorage.getItem('rhino_token') || sessionStorage.getItem('rhino_token') || '';
      const response = await fetch(`/api/v1/admin/devices/${encodeURIComponent(deviceId)}/unassign`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ reason: reason.trim() }),
      });

      let payload;
      try {
        payload = await response.json();
      } catch {
        payload = { success: false, error: { message: `Respuesta HTTP ${response.status}` } };
      }

      if (!response.ok || payload.success === false) {
        throw new Error(payload?.error?.message || `No se pudo desvincular la báscula (HTTP ${response.status}).`);
      }

      window.alert('Báscula desvinculada correctamente. Ya está disponible para registrarse con otro socio. El historial se conservó.');
      window.location.reload();
    } catch (error) {
      window.alert(error?.message || 'No fue posible desvincular la báscula.');
      button.disabled = false;
    }
  });

  const observer = new MutationObserver(addUnassignActions);
  observer.observe(content, { childList: true, subtree: true });
  addUnassignActions();
})();
