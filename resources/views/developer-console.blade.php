<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Consola de mantenimiento · Rhino CRM</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: dark; --bg:#0b0f14; --panel:#121923; --panel2:#17212d; --line:#2b394a; --text:#e8eef5; --muted:#9cadbf; --ok:#72d59b; --warn:#ffd27a; --bad:#ff8b8b; --accent:#78a9ff; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif; background:var(--bg); color:var(--text); }
        button,input,select,textarea { font:inherit; }
        button { cursor:pointer; }
        .hidden { display:none !important; }
        .login { min-height:100vh; display:grid; place-items:center; padding:24px; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:20px; box-shadow:0 18px 50px #0007; }
        .login .card { width:min(430px,100%); }
        h1,h2,h3 { margin:0 0 12px; }
        p { color:var(--muted); }
        label { display:block; margin:12px 0 6px; color:var(--muted); }
        input,select,textarea { width:100%; background:#0d141d; color:var(--text); border:1px solid var(--line); border-radius:8px; padding:10px 12px; }
        textarea { min-height:130px; resize:vertical; }
        .primary,.secondary,.danger { border:1px solid var(--line); border-radius:8px; padding:9px 13px; color:var(--text); background:var(--panel2); }
        .primary { background:#245caa; border-color:#3475ce; }
        .danger { background:#6d272d; border-color:#a74650; }
        .wide { width:100%; margin-top:16px; }
        .error { color:var(--bad); white-space:pre-wrap; }
        .shell { min-height:100vh; display:grid; grid-template-columns:245px 1fr; }
        aside { background:#0e151e; border-right:1px solid var(--line); padding:18px; position:sticky; top:0; height:100vh; }
        .brand { font-weight:750; font-size:17px; margin-bottom:4px; }
        .role { color:var(--muted); font-size:12px; margin-bottom:20px; }
        nav button { display:block; width:100%; text-align:left; margin:5px 0; background:transparent; border:1px solid transparent; color:var(--muted); border-radius:8px; padding:9px 10px; }
        nav button.active,nav button:hover { color:var(--text); background:var(--panel); border-color:var(--line); }
        .logout { position:absolute; left:18px; right:18px; bottom:18px; width:calc(100% - 36px); }
        main { padding:24px; min-width:0; }
        .top { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:20px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:12px; }
        .metric { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px; }
        .metric strong { display:block; font-size:20px; margin-top:5px; }
        .OK { color:var(--ok); } .WARNING { color:var(--warn); } .ERROR,.FAILED { color:var(--bad); }
        .toolbar { display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:8px; margin-bottom:12px; }
        pre { background:#070b10; color:#d8e1eb; border:1px solid var(--line); border-radius:9px; padding:14px; overflow:auto; white-space:pre-wrap; overflow-wrap:anywhere; max-height:66vh; }
        table { width:100%; border-collapse:collapse; background:var(--panel); border:1px solid var(--line); }
        th,td { padding:9px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-weight:600; }
        .command { display:flex; gap:12px; justify-content:space-between; align-items:center; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px; margin-bottom:10px; }
        .command p { margin:3px 0 0; }
        .split { display:grid; grid-template-columns:minmax(250px,36%) 1fr; gap:12px; }
        .file-list { max-height:68vh; overflow:auto; border:1px solid var(--line); border-radius:9px; }
        .file-list button { width:100%; text-align:left; color:var(--text); background:var(--panel); border:0; border-bottom:1px solid var(--line); padding:9px; overflow-wrap:anywhere; }
        .file-list button:hover { background:var(--panel2); }
        .notice { border-left:3px solid var(--warn); padding:10px 12px; background:#3d321755; color:var(--warn); margin-bottom:12px; }
        .section-title { margin-top:20px; } .empty { padding:10px; } .audit-pre { max-height:180px; margin:0; }
        .row { display:flex; gap:8px; align-items:end; }
        .row > * { flex:1; }
        @media (max-width:800px) { .shell { grid-template-columns:1fr; } aside { position:static; height:auto; border-right:0; border-bottom:1px solid var(--line); } nav { display:flex; overflow:auto; gap:4px; } nav button { width:auto; white-space:nowrap; } .logout { position:static; width:100%; margin-top:10px; } main { padding:15px; } .toolbar,.split { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<section id="loginView" class="login">
    <form id="loginForm" class="card" autocomplete="on">
        <h1>Rhino Maintenance Console</h1>
        <p>Acceso restringido a la cuenta <strong>SUPER_ADMIN</strong>. Esta consola no ofrece una shell del sistema.</p>
        <label for="email">Correo</label><input id="email" type="email" required autocomplete="username">
        <label for="password">Contraseña</label><input id="password" type="password" required autocomplete="current-password">
        <button class="primary wide" type="submit">Iniciar sesión</button>
        <div id="loginError" class="error"></div>
    </form>
</section>

<div id="appView" class="shell hidden">
    <aside>
        <div class="brand">Rhino Maintenance</div>
        <div id="identity" class="role">SUPER_ADMIN</div>
        <nav id="nav">
            <button data-tab="status" class="active">Estado</button>
            <button data-tab="logs">Logs</button>
            <button data-tab="commands">Acciones seguras</button>
            <button data-tab="source">Código autorizado</button>
            <button data-tab="assistant">Asistente IA</button>
            <button data-tab="audit">Auditoría</button>
        </nav>
        <button id="logout" class="secondary logout">Cerrar sesión</button>
    </aside>
    <main>
        <div class="top"><div><h1 id="pageTitle">Estado del sistema</h1><div id="globalMessage" class="error"></div></div><button id="refresh" class="secondary">Actualizar</button></div>

        <section id="tab-status" class="tab"><div id="statusContent">Cargando…</div></section>
        <section id="tab-logs" class="tab hidden">
            <div class="toolbar">
                <select id="logFile"></select>
                <select id="logLevel"><option value="">Todos los niveles</option><option>ERROR</option><option>CRITICAL</option><option>WARNING</option><option>INFO</option><option>DEBUG</option></select>
                <input id="logSearch" placeholder="Buscar en el log">
                <button id="loadLogs" class="primary">Consultar</button>
            </div>
            <pre id="logOutput">Seleccione un archivo.</pre>
        </section>
        <section id="tab-commands" class="tab hidden"><div class="notice">No hay terminal libre. Solo pueden ejecutarse las acciones enumeradas y las que cambian estado requieren contraseña y frase de confirmación.</div><div id="commandList"></div><pre id="commandOutput">La salida aparecerá aquí.</pre></section>
        <section id="tab-source" class="tab hidden">
            <div class="split">
                <div><div class="row"><input id="sourceSearch" placeholder="Buscar archivo"><button id="findSource" class="secondary">Buscar</button></div><div id="sourceList" class="file-list"></div></div>
                <pre id="sourceOutput">Solo se permite leer app/, routes/, config/, database/migrations/ y docs/.</pre>
            </div>
        </section>
        <section id="tab-assistant" class="tab hidden">
            <div class="notice">El asistente únicamente analiza contexto depurado y propone cambios. No escribe archivos, no ejecuta comandos y no aplica migraciones.</div>
            <label for="aiQuestion">Pregunta o problema</label><textarea id="aiQuestion" placeholder="Ejemplo: analiza por qué falló esta medición y propón una corrección segura."></textarea>
            <div class="row"><div><label for="aiLog">Log opcional</label><select id="aiLog"><option value="">Sin log</option></select></div><div><label for="aiLines">Líneas</label><input id="aiLines" type="number" value="120" min="1" max="500"></div></div>
            <label for="aiSources">Archivos autorizados, uno por línea</label><textarea id="aiSources" placeholder="app/Modules/CRM/Services/ContactManagementService.php"></textarea>
            <button id="askAi" class="primary wide">Generar diagnóstico</button>
            <pre id="aiOutput">Configure la integración del servidor para activar esta función.</pre>
        </section>
        <section id="tab-audit" class="tab hidden"><div id="auditContent">Cargando…</div></section>
    </main>
</div>

<script nonce="{{ $nonce }}">
(() => {
    'use strict';
    const API = '/api/v1';
    let token = sessionStorage.getItem('rhino_console_token') || '';
    let activeTab = 'status';
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    const json = value => JSON.stringify(value, null, 2);

    async function request(path, options = {}) {
        const headers = {'Accept':'application/json', ...(options.headers || {})};
        if (token) headers.Authorization = `Bearer ${token}`;
        if (options.body && typeof options.body !== 'string') { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
        const response = await fetch(API + path, {...options, headers, cache:'no-store'});
        let payload = null;
        try { payload = await response.json(); } catch (_) { payload = {success:false,error:{message:`Respuesta HTTP ${response.status}`}}; }
        if (response.status === 401) { clearSession(); }
        if (!response.ok || !payload.success) throw new Error(payload?.error?.message || `Error HTTP ${response.status}`);
        return payload.data;
    }

    function clearSession() { token=''; sessionStorage.removeItem('rhino_console_token'); $('appView').classList.add('hidden'); $('loginView').classList.remove('hidden'); }
    function showApp(me) { $('identity').textContent = `${me.role} · ${me.user_id}`; $('loginView').classList.add('hidden'); $('appView').classList.remove('hidden'); loadTab(activeTab); }
    async function verify() { if (!token) return; try { const me = await request('/me'); if (me.role !== 'SUPER_ADMIN') throw new Error('Esta cuenta no es SUPER_ADMIN.'); showApp(me); } catch (error) { clearSession(); $('loginError').textContent = error.message; } }

    $('loginForm').addEventListener('submit', async event => {
        event.preventDefault(); $('loginError').textContent='';
        try {
            const data = await request('/auth/login', {method:'POST', body:{email:$('email').value, password:$('password').value, device_name:'Rhino Maintenance Console', platform:'WEB'}});
            token = data.access_token; sessionStorage.setItem('rhino_console_token', token);
            const me = await request('/me');
            if (me.role !== 'SUPER_ADMIN') { await request('/auth/logout', {method:'POST'}).catch(()=>{}); throw new Error('La consola solo admite SUPER_ADMIN.'); }
            $('password').value=''; showApp(me);
        } catch (error) { clearSession(); $('loginError').textContent=error.message; }
    });

    $('logout').addEventListener('click', async () => { await request('/auth/logout', {method:'POST'}).catch(()=>{}); clearSession(); });
    $('nav').addEventListener('click', event => { const button=event.target.closest('button[data-tab]'); if (!button) return; activeTab=button.dataset.tab; document.querySelectorAll('#nav button').forEach(x=>x.classList.toggle('active',x===button)); document.querySelectorAll('.tab').forEach(x=>x.classList.add('hidden')); $(`tab-${activeTab}`).classList.remove('hidden'); const titles={status:'Estado del sistema',logs:'Logs de errores',commands:'Acciones seguras',source:'Código autorizado',assistant:'Asistente de mantenimiento',audit:'Auditoría técnica'}; $('pageTitle').textContent=titles[activeTab]; loadTab(activeTab); });
    $('refresh').addEventListener('click', () => loadTab(activeTab));

    async function loadTab(tab) { $('globalMessage').textContent=''; try { if (tab==='status') await loadStatus(); if (tab==='logs') await loadLogFiles(); if (tab==='commands') await loadCommands(); if (tab==='source') await loadSourceFiles(); if (tab==='audit') await loadAudit(); if (tab==='assistant') await loadLogFiles(); } catch (error) { $('globalMessage').textContent=error.message; } }

    async function loadStatus() {
        const d=await request('/admin/developer-console/status');
        const metrics=[['Estado',d.overall_status],['PHP',d.application.php_version],['Laravel',d.application.laravel_version],['MySQL',d.database.connected?'Conectado':'Error'],['Cron',d.scheduler.status],['Cola pendiente',d.queue.pending ?? 'N/D'],['Trabajos fallidos',d.queue.failed ?? 'N/D'],['IA',d.integrations.openai_console_enabled?'Configurada':'Pendiente']];
        $('statusContent').innerHTML=`<div class="grid">${metrics.map(([k,v])=>`<div class="metric"><span>${esc(k)}</span><strong class="${esc(String(v))}">${esc(v)}</strong></div>`).join('')}</div><h2 class="section-title">Detalle</h2><pre>${esc(json(d))}</pre>`;
    }

    async function loadLogFiles() {
        const files=await request('/admin/developer-console/log-files');
        const current=$('logFile').value; const currentAi=$('aiLog').value;
        $('logFile').innerHTML=files.map(f=>`<option value="${esc(f.key)}">${esc(f.label)} · ${esc(f.size)} bytes</option>`).join('') || '<option value="laravel">Laravel</option>';
        $('aiLog').innerHTML='<option value="">Sin log</option>'+files.map(f=>`<option value="${esc(f.key)}">${esc(f.label)}</option>`).join('');
        if ([...$('logFile').options].some(o=>o.value===current)) $('logFile').value=current;
        if ([...$('aiLog').options].some(o=>o.value===currentAi)) $('aiLog').value=currentAi;
    }
    $('loadLogs').addEventListener('click', async () => { try { const q=new URLSearchParams({file:$('logFile').value,lines:'300'}); if ($('logLevel').value) q.set('level',$('logLevel').value); if ($('logSearch').value) q.set('search',$('logSearch').value); const d=await request('/admin/developer-console/logs?'+q); $('logOutput').textContent=d.entries.map(e=>e.raw).join('\n\n') || 'No se encontraron entradas.'; } catch(e){ $('logOutput').textContent=e.message; } });

    async function loadCommands() {
        const actions=await request('/admin/developer-console/commands');
        $('commandList').innerHTML=actions.map(a=>`<div class="command"><div><strong>${esc(a.label)}</strong><p>${esc(a.description)}</p><small>${esc(a.category)}${a.mutates_state?' · requiere confirmación':''}</small></div><button class="${a.mutates_state?'danger':'secondary'}" data-action="${esc(a.id)}" data-mutates="${a.mutates_state?'1':'0'}" data-phrase="${esc(a.confirmation_phrase || '')}">Ejecutar</button></div>`).join('');
    }
    $('commandList').addEventListener('click', async event => { const button=event.target.closest('button[data-action]'); if (!button) return; let body={}; if (button.dataset.mutates==='1') { const phrase=prompt(`Escriba exactamente: ${button.dataset.phrase}`); if (phrase===null) return; const password=prompt('Confirme la contraseña de SUPER_ADMIN:'); if (password===null) return; body={confirmation_phrase:phrase,reauth_password:password}; } button.disabled=true; $('commandOutput').textContent='Ejecutando…'; try { const d=await request('/admin/developer-console/commands/'+encodeURIComponent(button.dataset.action),{method:'POST',body}); $('commandOutput').textContent=d.output || 'Acción completada.'; } catch(e){ $('commandOutput').textContent=e.message; } finally { button.disabled=false; } });

    async function loadSourceFiles() { const q=new URLSearchParams({limit:'200'}); if ($('sourceSearch').value) q.set('search',$('sourceSearch').value); const files=await request('/admin/developer-console/source-files?'+q); $('sourceList').innerHTML=files.map(f=>`<button data-path="${esc(f.path)}">${esc(f.path)}<br><small>${esc(f.size)} bytes</small></button>`).join('') || '<p class="empty">Sin resultados.</p>'; }
    $('findSource').addEventListener('click',()=>loadSourceFiles().catch(e=>$('sourceOutput').textContent=e.message));
    $('sourceList').addEventListener('click',async event=>{const button=event.target.closest('button[data-path]');if(!button)return;$('sourceOutput').textContent='Cargando…';try{const d=await request('/admin/developer-console/source?path='+encodeURIComponent(button.dataset.path));$('sourceOutput').textContent=`${d.path}${d.truncated?' [TRUNCADO]':''}\n\n${d.content}`;}catch(e){$('sourceOutput').textContent=e.message;}});

    $('askAi').addEventListener('click',async()=>{const paths=$('aiSources').value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);$('aiOutput').textContent='Analizando…';$('askAi').disabled=true;try{const d=await request('/admin/developer-console/ai/analyze',{method:'POST',body:{question:$('aiQuestion').value,log_file:$('aiLog').value||null,log_lines:Number($('aiLines').value||120),source_paths:paths}});$('aiOutput').textContent=d.response;}catch(e){$('aiOutput').textContent=e.message;}finally{$('askAi').disabled=false;}});

    async function loadAudit() { const rows=await request('/admin/developer-console/actions?limit=150'); $('auditContent').innerHTML=`<table><thead><tr><th>Fecha</th><th>Acción</th><th>Estado</th><th>Duración</th><th>Resultado</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.created_at)}</td><td>${esc(r.action_type)}<br><small>${esc(r.action_name)}</small></td><td class="${esc(r.status)}">${esc(r.status)}</td><td>${esc(r.duration_ms ?? '')} ms</td><td><pre class="audit-pre">${esc(r.error_message || r.result_summary || '')}</pre></td></tr>`).join('')}</tbody></table>`; }

    verify();
})();
</script>
</body>
</html>
