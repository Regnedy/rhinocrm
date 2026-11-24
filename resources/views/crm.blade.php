<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#152445">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="rhino-version" content="{{ $version }}">
    <meta name="rhino-default-dial-code" content="{{ $defaultDialCode }}">
    <meta name="rhino-default-country-iso" content="{{ $defaultCountryIso }}">
    <title>Rhino CRM</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/rhino-192.png" sizes="192x192">
    <link rel="apple-touch-icon" href="/icons/rhino-192.png">
    <link rel="stylesheet" href="/assets/rhino-crm.css?v={{ rawurlencode($assetVersion) }}">
    <script defer src="/assets/rhino-crm.js?v={{ rawurlencode($assetVersion) }}"></script>
    <script defer src="/assets/rhino-device-unassignment.js?v={{ rawurlencode($assetVersion) }}"></script>
</head>
<body>
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<section id="loginView" class="auth-page">
    <div class="auth-brand">
        <div class="logo">R</div>
        <div><h1>Rhino CRM</h1><p>Prospectos, equipos, básculas y seguimiento comercial.</p></div>
    </div>
    <form id="loginForm" class="auth-card" autocomplete="on">
        <h2>Iniciar sesión</h2>
        <p class="muted">Utiliza la cuenta creada en el instalador o una cuenta del socio.</p>
        <label>Correo<input id="loginEmail" type="email" required autocomplete="username"></label>
        <label>Contraseña<input id="loginPassword" type="password" required autocomplete="current-password"></label>
        <label class="check"><input id="rememberSession" type="checkbox" checked> Mantener la sesión en este dispositivo</label>
        <button class="button primary wide" type="submit">Entrar</button>
        <div id="loginError" class="form-error"></div>
        <div class="auth-foot">Firebase Authentication podrá habilitarse posteriormente sin cambiar MySQL.</div>
    </form>
</section>

<div id="appView" class="app hidden">
    <aside id="sidebar" class="sidebar">
        <a class="brand" href="#dashboard"><span class="logo small">R</span><span><strong>Rhino CRM</strong><small id="brandRole">CRM</small></span></a>
        <nav id="navigation" class="navigation"></nav>
        <div class="sidebar-footer">
            <button id="installApp" class="nav-action hidden">Instalar en este dispositivo</button>
            <a id="developerConsoleLink" class="nav-action hidden" href="/admin/developer-console">Consola técnica</a>
            <button id="logoutButton" class="nav-action danger-text">Cerrar sesión</button>
        </div>
    </aside>

    <div class="workspace">
        <header class="topbar">
            <button id="menuButton" class="icon-button mobile-only" aria-label="Abrir menú">☰</button>
            <div><h1 id="pageTitle">Dashboard</h1><p id="pageSubtitle" class="muted compact"></p></div>
            <div class="identity"><span id="identityName">Usuario</span><small id="identityRole"></small></div>
        </header>
        <main id="content" class="content"><div class="loading">Cargando…</div></main>
    </div>
</div>

<div id="modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card">
        <div class="modal-head"><div><h2 id="modalTitle"></h2><p id="modalSubtitle" class="muted compact"></p></div><button id="modalClose" class="icon-button" aria-label="Cerrar">×</button></div>
        <div id="modalBody" class="modal-body"></div>
    </div>
</div>
</body>
</html>
