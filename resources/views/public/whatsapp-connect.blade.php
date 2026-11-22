<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>Conectar con {{ $business_name }}</title>
    <style>
        :root { color-scheme: light; --primary:#12324a; --accent:#2f7d8c; --surface:#f4f7f8; --text:#172027; --muted:#5d6a73; --ok:#1b6e3c; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--surface); color:var(--text); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; display:grid; place-items:center; padding:20px; }
        main { width:min(100%,520px); background:#fff; border-radius:24px; box-shadow:0 18px 50px rgba(18,50,74,.15); padding:28px; }
        .brand { color:var(--primary); font-weight:800; letter-spacing:.06em; font-size:14px; }
        h1 { margin:10px 0 8px; font-size:27px; line-height:1.15; }
        p { color:var(--muted); line-height:1.5; }
        .code { background:#ecf6f7; border:1px solid #cbe6e9; border-radius:14px; padding:14px; text-align:center; font-weight:800; color:var(--primary); font-size:20px; letter-spacing:.06em; }
        .step { display:flex; gap:12px; margin:22px 0; align-items:flex-start; }
        .number { width:32px; height:32px; flex:0 0 32px; border-radius:50%; display:grid; place-items:center; background:var(--primary); color:#fff; font-weight:800; }
        .step strong { display:block; margin-bottom:4px; }
        a.button, button.button { width:100%; border:0; border-radius:14px; min-height:52px; display:flex; align-items:center; justify-content:center; text-decoration:none; font-weight:800; font-size:16px; cursor:pointer; padding:12px 18px; }
        .primary { background:var(--primary); color:#fff; }
        .secondary { background:#e8f1f3; color:var(--primary); }
        .whatsapp { background:#1f9d55; color:#fff; }
        .whatsapp[aria-disabled="true"] { background:#a8b8ad; pointer-events:none; }
        .timer { text-align:center; font-size:18px; font-weight:800; color:var(--primary); margin:12px 0; }
        .done { color:var(--ok); font-weight:700; }
        small { display:block; margin-top:18px; color:var(--muted); line-height:1.4; }
    </style>
</head>
<body>
<main>
    <div class="brand">RHINO CRM</div>
    <h1>Asegura tu informe y plan alimenticio</h1>
    <p>Evento: <strong>{{ $campaign_name }}</strong>. Completa estos pasos desde tu teléfono.</p>
    <div class="code">{{ $public_code }}</div>

    @if ($status === 'CONFIRMED')
        <p class="done">Tu WhatsApp ya quedó confirmado. Puedes cerrar esta página.</p>
    @else
        <div class="step">
            <div class="number">1</div>
            <div style="flex:1">
                <strong>Agrega el contacto de RHINO</strong>
                <p>Descarga la tarjeta de contacto y guárdala en tu teléfono.</p>
                <a id="vcardButton" class="button secondary" href="{{ $vcard_url }}" download>Agregar contacto por vCard</a>
            </div>
        </div>

        <div class="step">
            <div class="number">2</div>
            <div style="flex:1">
                <strong>Espera cinco minutos</strong>
                <p>Esto ayuda a que el contacto recién guardado aparezca correctamente en WhatsApp.</p>
                <div id="timer" class="timer">05:00</div>
            </div>
        </div>

        <div class="step">
            <div class="number">3</div>
            <div style="flex:1">
                <strong>Envía el mensaje preparado</strong>
                <p>WhatsApp se intentará abrir al terminar el contador. Si tu teléfono lo bloquea, presiona el botón.</p>
                <a id="waButton" class="button whatsapp" aria-disabled="true" href="{{ $whatsapp_url }}">Abrir WhatsApp</a>
            </div>
        </div>
        <small>No se envía ningún mensaje sin tu confirmación dentro de WhatsApp. El código vincula tu conversación con tu pesaje del evento.</small>
    @endif
</main>
@if ($status !== 'CONFIRMED')
<script>
(() => {
    const total = {{ (int) $countdown_seconds }};
    const storageKey = 'rhino-vcard-start-{{ $token }}';
    const timer = document.getElementById('timer');
    const waButton = document.getElementById('waButton');
    const vcardButton = document.getElementById('vcardButton');
    let interval = null;

    const format = seconds => `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    const enableWhatsapp = () => {
        waButton.setAttribute('aria-disabled', 'false');
        timer.textContent = 'Listo';
        timer.classList.add('done');
    };
    const tick = () => {
        const started = Number(localStorage.getItem(storageKey) || 0);
        if (!started) { timer.textContent = format(total); return; }
        const remaining = Math.max(0, total - Math.floor((Date.now() - started) / 1000));
        timer.textContent = format(remaining);
        if (remaining === 0) {
            clearInterval(interval);
            enableWhatsapp();
            if (document.visibilityState === 'visible') window.location.href = waButton.href;
        }
    };
    vcardButton.addEventListener('click', () => {
        localStorage.setItem(storageKey, String(Date.now()));
        clearInterval(interval);
        interval = setInterval(tick, 1000);
        tick();
    });
    if (localStorage.getItem(storageKey)) {
        interval = setInterval(tick, 1000);
        tick();
    }
})();
</script>
@endif
</body>
</html>
