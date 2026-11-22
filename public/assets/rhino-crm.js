(() => {
  'use strict';

  const API = '/api/v1';
  const DEFAULT_DIAL_CODE = (document.querySelector('meta[name="rhino-default-dial-code"]')?.content || '52').replace(/\D/g,'') || '52';
  const DEFAULT_COUNTRY_ISO = document.querySelector('meta[name="rhino-default-country-iso"]')?.content || 'MX';
  const $ = id => document.getElementById(id);
  const content = $('content');
  const state = {
    token: localStorage.getItem('rhino_token') || sessionStorage.getItem('rhino_token') || '',
    persistent: Boolean(localStorage.getItem('rhino_token')),
    me: null,
    users: [], teams: [], campaigns: [], campaignInvitations: [], nutritionCampaigns: [], nutritionPills: [], nutritionTemplates: [], locations: [], stages: [], contacts: [], partners: [], ranks: [], movementTypes: [],
    workMode: {mode:'INDIVIDUAL',team_mode_enabled:false,can_switch:false,automatic_responsible_user_id:null},
    selectedPartner: localStorage.getItem('rhino_selected_partner') || '',
    installPrompt: null,
  };

  let dashboardWorkspace = null;

  const roleNames = {
    SUPER_ADMIN: 'Administrador global', PARTNER_ADMIN: 'Socio', LOCATION_MANAGER: 'Coordinador',
    TEAM_LEADER: 'Coordinador de equipo', ADVISOR: 'Responsable', OPERATOR: 'Operador'
  };
  const statusNames = {ACTIVE:'Activo',READY:'Lista',SCHEDULED:'Programado',DRAFT:'Borrador',CLOSED:'Cerrado',CANCELLED:'Cancelado',COMPLETED:'Completado',PENDING:'Pendiente',ACCEPTED:'Aceptada',SUSPENDED:'Suspendido',BLOCKED:'Bloqueado',REVOKED:'Revocado',LOST:'Perdido',FAILED:'Fallido',EXPIRED:'Expirado',QUEUED:'En cola',PROCESSING:'Procesando',SENT:'Enviado',DELIVERED:'Entregada',READ:'Leída',SKIPPED:'Omitido',SELECTED:'Seleccionado',INVITATION_PENDING:'Invitación pendiente',INVITATION_SENT:'Invitación enviada',RESPONDED:'Respondió',CONFIRMED:'Confirmó',DECLINED:'No confirmó',ATTENDED:'Asistió',NO_SHOW:'No asistió',REWEIGHED:'Nuevo pesaje',TASTING_COMPLETED:'Degustación realizada',INTERESTED:'Interesado',SALE_COMPLETED:'Venta realizada',NO_RESPONSE:'Sin respuesta',OPTED_OUT:'No desea mensajes',OPEN:'Abierta',ESCALATED:'Escalada',ESCALATED_TO_ADMIN:'Escalada a administración',RESOLVED:'Resuelta',INACTIVE:'Inactivo',FINISHED:'Finalizado'};
  const campaignTypeNames = {HOME_PARTY:'Home Party',PLAZA:'Plaza',STREET:'Calle',BRANCH:'Sucursal',TEST:'Prueba',WEIGHING_EVENT:'Jornada de pesaje',SUPERMARKET:'Evento en supermercado',FAIR:'Feria o exposición',COMMUNITY:'Evento comunitario',CORPORATE:'Evento empresarial',DEMO:'Demostración',OTHER:'Otro'};
  const participantTypeNames = {NEW_PROSPECT:'Prospecto nuevo',EXISTING_PROSPECT:'Prospecto existente',EXISTING_CUSTOMER:'Cliente existente'};
  const campaignMemberRoleNames = {RESPONSIBLE:'Responsable',STAFF:'Participante',ORGANIZER:'Organizador',COLLABORATOR:'Colaborador'};
  const nutritionStageLabels = {
    SELECTED:'Seleccionado',INVITATION_PENDING:'Invitación pendiente',INVITATION_SENT:'Invitación enviada',
    DELIVERED:'Entregada',READ:'Leída',RESPONDED:'Respondió',CONFIRMED:'Confirmó',
    DECLINED:'No confirmó',ATTENDED:'Asistió',NO_SHOW:'No asistió',REWEIGHED:'Nuevo pesaje',
    TASTING_COMPLETED:'Degustación realizada',INTERESTED:'Interesado',SALE_COMPLETED:'Venta realizada',
    NO_RESPONSE:'Sin respuesta',OPTED_OUT:'No desea mensajes'
  };
  const nutritionPipelineOrder = ['SELECTED','INVITATION_PENDING','INVITATION_SENT','DELIVERED','READ','RESPONDED','CONFIRMED','ATTENDED','REWEIGHED','TASTING_COMPLETED','INTERESTED','SALE_COMPLETED','DECLINED','NO_SHOW','NO_RESPONSE','OPTED_OUT'];
  const stageFallback = [
    ['NEW','Nuevo'],['CONTACTED','Contactado'],['APPOINTMENT_SCHEDULED','Cita agendada'],['FOLLOW_UP','Seguimiento'],
    ['MEASUREMENT_COMPLETED','Medición realizada'],['INTERESTED','Interesado'],['QUOTE_SENT','Cotización enviada'],
    ['NEGOTIATION','Negociación'],['CLOSED','Cliente'],['REPURCHASE','Recompra'],['LOST','Perdido']
  ].map(([id,label]) => ({id,label,allowed_to:[]}));

  function esc(value) {
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }
  function money(minor, currency='MXN') {
    return new Intl.NumberFormat('es-MX',{style:'currency',currency,maximumFractionDigits:2}).format(Number(minor || 0)/100);
  }
  function dateTime(value) {
    if (!value) return '—';
    const d = new Date(value); return Number.isNaN(d.getTime()) ? esc(value) : new Intl.DateTimeFormat('es-MX',{dateStyle:'medium',timeStyle:'short'}).format(d);
  }
  function dateInput(value) {
    const d = value ? new Date(value) : new Date(Date.now()+60*60*1000);
    const pad=n=>String(n).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
  function periodNow(){ return new Date().toISOString().slice(0,7); }
  function uuid(){ return crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`; }
  function has(permission) { return state.me?.permissions?.includes('*') || state.me?.permissions?.includes(permission); }
  function isSuper(){ return state.me?.role === 'SUPER_ADMIN'; }
  function teamMode(){ return isSuper() || state.workMode?.mode === 'TEAM' || state.workMode?.team_mode_enabled === true; }
  function myUserId(){ return state.me?.user_id || state.me?.id || ''; }
  function selectedPartnerQuery(){ return isSuper() && state.selectedPartner ? `?partner_id=${encodeURIComponent(state.selectedPartner)}` : ''; }

  let toastTimer;
  function toast(message, type='ok') {
    const el=$('toast'); el.textContent=message; el.className=`toast show ${type}`;
    clearTimeout(toastTimer); toastTimer=setTimeout(()=>el.className='toast',3500);
  }
  function errorMessage(error) {
    if (error?.details) return Object.values(error.details).flat().join('\n');
    return error?.message || 'La operación no pudo completarse.';
  }
  async function request(path, options={}) {
    const headers = {'Accept':'application/json', ...(options.headers || {})};
    if (state.token) headers.Authorization=`Bearer ${state.token}`;
    if (options.body !== undefined && !(options.body instanceof FormData)) headers['Content-Type']='application/json';
    const response = await fetch(API+path,{method:options.method||'GET',headers,body:options.body === undefined ? undefined : (options.body instanceof FormData ? options.body : JSON.stringify(options.body)),credentials:'same-origin'});
    let payload; try { payload=await response.json(); } catch { payload={success:false,error:{message:`Respuesta HTTP ${response.status}`}}; }
    if (response.status===401) { clearSession(); showLogin(); }
    if (!response.ok || payload.success===false) throw payload.error || {message:`Error HTTP ${response.status}`};
    return payload.data;
  }
  async function requestBlob(path) {
    const headers={'Accept':'application/pdf'};
    if(state.token)headers.Authorization=`Bearer ${state.token}`;
    const response=await fetch(API+path,{headers,credentials:'same-origin'});
    if(response.status===401){clearSession();showLogin();throw {message:'La sesión expiró.'};}
    if(!response.ok){let message=`Error HTTP ${response.status}`;try{const payload=await response.json();message=payload?.error?.message||message;}catch{}throw {message};}
    return response.blob();
  }
  function digitsOnly(value){return String(value||'').replace(/\D/g,'');}
  function localPhoneValue(value){let digits=digitsOnly(value);if(digits.startsWith(DEFAULT_DIAL_CODE)&&digits.length>10)digits=digits.slice(DEFAULT_DIAL_CODE.length);return digits.slice(-10);}
  function normalizePhone(value){let digits=digitsOnly(value);if(!digits)return '';if(digits.length===10)digits=DEFAULT_DIAL_CODE+digits;else if(digits.startsWith('00'))digits=digits.slice(2);return '+'+digits;}
  function phoneField(value='',required=true){return `<label>WhatsApp<div class="phone-input"><span>+${esc(DEFAULT_DIAL_CODE)}</span><input name="whatsapp" inputmode="tel" autocomplete="tel-national" maxlength="10" pattern="[0-9]{10}" value="${esc(localPhoneValue(value))}" placeholder="10 dígitos" ${required?'required':''}></div><small class="field-help">${esc(DEFAULT_COUNTRY_ISO)} (+${esc(DEFAULT_DIAL_CODE)}) se agrega automáticamente.</small></label>`;}
  function downloadBlob(blob,filename){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename||'documento.pdf';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),30000);}

  function saveToken(token, persistent) {
    localStorage.removeItem('rhino_token'); sessionStorage.removeItem('rhino_token');
    (persistent ? localStorage : sessionStorage).setItem('rhino_token',token);
    state.token=token; state.persistent=persistent;
  }
  function clearSession() {
    localStorage.removeItem('rhino_token'); sessionStorage.removeItem('rhino_token');
    state.token=''; state.me=null; state.users=[]; state.teams=[]; state.campaigns=[]; state.campaignInvitations=[]; state.nutritionCampaigns=[]; state.nutritionPills=[]; state.nutritionTemplates=[]; state.locations=[]; state.contacts=[]; state.ranks=[]; state.movementTypes=[];
    state.workMode={mode:'INDIVIDUAL',team_mode_enabled:false,can_switch:false,automatic_responsible_user_id:null};
  }
  function showLogin() { $('appView').classList.add('hidden'); $('loginView').classList.remove('hidden'); }
  function showApp() { $('loginView').classList.add('hidden'); $('appView').classList.remove('hidden'); }

  $('loginForm').addEventListener('submit', async event => {
    event.preventDefault(); $('loginError').textContent='';
    const button=event.submitter; button.disabled=true;
    try {
      const result=await request('/auth/login',{method:'POST',body:{email:$('loginEmail').value.trim(),password:$('loginPassword').value,device_name:'Rhino CRM Web',platform:'WEB',app_version:document.querySelector('meta[name="rhino-version"]')?.content || 'web'}});
      saveToken(result.access_token,$('rememberSession').checked); $('loginPassword').value=''; await bootstrap();
    } catch(e){ $('loginError').textContent=errorMessage(e); }
    finally { button.disabled=false; }
  });
  $('logoutButton').addEventListener('click', async () => { try{await request('/auth/logout',{method:'POST',body:{}});}catch{} clearSession(); showLogin(); });
  $('menuButton').addEventListener('click',()=> $('sidebar').classList.toggle('open'));
  $('modalClose').addEventListener('click',closeModal);
  $('modal').addEventListener('click',e=>{if(e.target===$('modal'))closeModal();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});

  function openModal(title, subtitle, html) {
    $('modalTitle').textContent=title; $('modalSubtitle').textContent=subtitle||''; $('modalBody').innerHTML=html; $('modal').classList.remove('hidden');
  }
  function closeModal(){ $('modal').classList.add('hidden'); $('modalBody').innerHTML=''; }
  function setHeader(title,subtitle=''){ $('pageTitle').textContent=title; $('pageSubtitle').textContent=subtitle; }
  function loading(){ content.innerHTML='<div class="loading">Cargando…</div>'; }
  function empty(title,text){ return `<div class="empty-state"><strong>${esc(title)}</strong>${esc(text)}</div>`; }
  function badge(status){ const s=String(status||'').toUpperCase(); const cls=['ACTIVE','COMPLETED','CLOSED','CUSTOMER'].includes(s)?'ok':['SUSPENDED','BLOCKED','REVOKED','LOST','FAILED','CANCELLED'].includes(s)?'bad':['PENDING','NEW','CONTACTED','SCHEDULED','DRAFT'].includes(s)?'warn':'blue'; return `<span class="badge ${cls}">${esc(statusNames[s] || stageLabel(s) || s)}</span>`; }
  function stageLabel(id){ return (state.stages.length?state.stages:stageFallback).find(x=>x.id===id)?.label || id; }
  function campaignTypeLabel(id){ return campaignTypeNames[String(id||'').toUpperCase()] || id || 'Evento'; }

  function navigationItems(){
    if (isSuper()) return [
      ['dashboard','▦','Resumen'],['partners','◎','Socios'],['permissions','☷','Permisos'],['nutrition','◈','Nutrición'],['organization','◇','Organización'],['movements','≡','Movimientos'],['devices','▣','Dispositivos'],['kiosk-versions','↻','Versiones Kiosko'],['sessions','◷','Sesiones']
    ];
    const items=[['dashboard','▦','Resumen']];
    if(has('campaigns.read')){const pendingInvites=(state.campaignInvitations||[]).filter(x=>x.status==='PENDING').length;items.push(['events','◆',pendingInvites?`Eventos (${pendingInvites})`:'Eventos']);}
    if(has('nutrition.read')) items.push(['nutrition','◈','Nutrición']);
    if(has('nutrition_campaigns.read')) items.push(['followups','✦','Seguimiento']);
    if(has('organization.read')) items.push(['organization','◇','Mi organización']);
    if(has('contacts.read')) items.push(['prospects','◉','Prospectos'],['agenda','□','Agenda'],['pipeline','⇢','Pipeline'],['clients','★','Clientes']);
    if(teamMode() && has('teams.read')) items.push(['teams','♟','Equipos']);
    if(has('users.read')) items.push(['users','♙',teamMode()?'Usuarios':'Mi cuenta']);
    if(has('devices.read')) items.push(['devices','▣','Dispositivos']);
    if(has('movements.read')) items.push(['movements','≡','Movimientos']);
    if(has('sessions.read')) items.push(['sessions','◷','Sesiones']);
    return items;
  }
  function renderNavigation(){
    const current=(location.hash||'#dashboard').slice(1).split('/')[0];
    $('navigation').innerHTML=navigationItems().map(([id,icon,label])=>`<a href="#${id}" class="${current===id?'active':''}"><span class="nav-icon">${icon}</span>${esc(label)}</a>`).join('');
    $('developerConsoleLink').classList.toggle('hidden',!isSuper());
  }

  async function bootstrap(){
    if(!state.token){showLogin();return;}
    try{
      state.me=await request('/me');
      if(!isSuper()){
        try{state.workMode=await request('/work-mode');}
        catch{state.workMode={mode:'INDIVIDUAL',team_mode_enabled:false,can_switch:false,automatic_responsible_user_id:myUserId()};}
        if(has('campaigns.read')){try{state.campaignInvitations=await request('/campaign-invitations');}catch{state.campaignInvitations=[];}}
      }
      showApp();
      $('identityName').textContent=state.me.name || state.me.email || state.me.user_id;
      $('identityRole').textContent=roleNames[state.me.role] || state.me.role;
      $('brandRole').textContent=roleNames[state.me.role] || state.me.role;
      renderNavigation();
      const pendingInvites=(state.campaignInvitations||[]).filter(x=>x.status==='PENDING').length;
      if(pendingInvites)setTimeout(()=>toast(`Tienes ${pendingInvites} invitación${pendingInvites===1?'':'es'} pendiente${pendingInvites===1?'':'s'} a eventos.`),250);
      await loadStageCatalog();
      route();
    }catch(e){clearSession();showLogin();$('loginError').textContent=errorMessage(e);}
  }
  async function loadStageCatalog(){
    try{state.stages=await request('/pipeline/stages');}catch{state.stages=stageFallback;}
  }
  window.addEventListener('hashchange',route);
  async function route(){
    if(!state.me)return;
    $('sidebar').classList.remove('open'); renderNavigation(); loading();
    const routeParts=(location.hash||'#dashboard').slice(1).split('/');
    const tab=routeParts[0];
    const allowed=navigationItems().some(x=>x[0]===tab); const target=allowed?tab:'dashboard';
    try{
      if(target==='dashboard') await renderDashboard();
      else if(target==='partners') await renderPartners();
      else if(target==='permissions') await renderPartnerPermissions();
      else if(target==='organization') await renderOrganization();
      else if(target==='movements') await renderMovements();
      else if(target==='events') await renderCampaigns();
      else if(target==='nutrition') await renderNutrition(routeParts[1]||null);
      else if(target==='followups') await renderNutritionCampaigns(routeParts[1]||null);
      else if(target==='prospects') await renderContacts('PROSPECT');
      else if(target==='clients') await renderContacts('CUSTOMER');
      else if(target==='agenda') await renderAgenda();
      else if(target==='pipeline') await renderPipeline();
      else if(target==='teams') await renderTeams();
      else if(target==='users') await renderUsers();
      else if(target==='devices') await renderDevices();
      else if(target==='kiosk-versions') await renderKioskVersions();
      else if(target==='sessions') await renderSessions();
    }catch(e){content.innerHTML=`<div class="card card-pad"><h2>No se pudo cargar</h2><p class="form-error">${esc(errorMessage(e))}</p></div>`;}
  }

  async function renderDashboard(){
    setHeader('Dashboard','Organiza tus indicadores y accesos según tu forma de trabajo.');
    const period=periodNow();
    dashboardWorkspace=await request(`/dashboard/workspace?period=${period}`);
    content.innerHTML=`<div class="page-actions"><div><h2>Mi dashboard</h2><p class="muted compact">El diseño se guarda en tu usuario y se adapta a celular, web y futura app RHINO CRM.</p></div><button id="editDashboard" class="button secondary">Editar dashboard</button></div><div class="grid dashboard-grid">${(dashboardWorkspace.items||[]).map(dashboardGadgetHtml).join('')||empty('Dashboard vacío','Agrega gadgets para comenzar.')}</div>`;
    $('editDashboard')?.addEventListener('click',()=>openDashboardEditor(dashboardWorkspace));
    document.querySelectorAll('[data-gadget-link]').forEach(x=>x.onclick=()=>{location.hash=x.dataset.gadgetLink;});
  }
  function dashboardGadgetHtml(gadget){
    const data=gadget.data||{},width=[3,4,6,8,12].includes(Number(gadget.width))?Number(gadget.width):4;
    let body='';
    if(data.type==='metric_group')body=`<div class="gadget-metrics">${(data.metrics||[]).map(m=>`<div><span>${esc(m.label)}</span><strong>${m.format==='money'?money(m.value):esc(m.value)}</strong></div>`).join('')}</div>`;
    else if(data.type==='summary')body=`<div class="gadget-summary">${(data.rows||[]).map(r=>`<div><span>${esc(r.label)}</span><strong>${esc(r.value)}</strong></div>`).join('')}</div>`;
    else if(data.type==='list')body=(data.items||[]).length?`<div class="list compact-list">${data.items.map(i=>`<div class="list-item"><div><strong>${esc(i.title)}</strong><div class="meta">${dateTime(i.meta)}</div></div>${i.status?badge(i.status):''}</div>`).join('')}</div>`:empty('Sin información','No hay elementos para mostrar.');
    else body=empty('Sin información',data.message||'Este gadget todavía no tiene datos.');
    return `<section class="card card-pad span-${width} gadget-card"><div class="gadget-head"><div><h2>${esc(gadget.name)}</h2><p class="muted compact">${esc(gadget.description||'')}</p></div>${data.link?`<button class="button small secondary" data-gadget-link="${esc(data.link)}">Abrir</button>`:''}</div>${body}</section>`;
  }
  function openDashboardEditor(workspace){
    const available=workspace.available_gadgets||[],layout=(workspace.layout||[]).slice().sort((a,b)=>Number(a.position||0)-Number(b.position||0));
    const byCode=Object.fromEntries(available.map(x=>[x.code,x]));
    openModal('Editar dashboard','Activa, ordena y cambia el tamaño de tus gadgets.',`<form id="dashboardLayoutForm"><div id="dashboardLayoutRows" class="dashboard-editor">${layout.map((entry,index)=>dashboardEditorRow(entry,byCode[entry.code],index)).join('')}</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Guardar diseño</button></div><div class="form-error" id="dashboardLayoutError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    bindDashboardEditorButtons();
    $('dashboardLayoutForm').onsubmit=async e=>{e.preventDefault();const rows=[...document.querySelectorAll('.dashboard-editor-row')].map((row,index)=>({code:row.dataset.code,enabled:row.querySelector('[name=enabled]').checked,width:Number(row.querySelector('[name=width]').value),position:index+1}));const btn=e.submitter;btn.disabled=true;try{await request('/dashboard/layout',{method:'PUT',body:{layout:rows}});closeModal();toast('Dashboard actualizado.');renderDashboard();}catch(err){$('dashboardLayoutError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }
  function dashboardEditorRow(entry,gadget,index){if(!gadget)return '';const widths=(gadget.allowed_widths||[gadget.default_width]).map(w=>`<option value="${w}" ${Number(entry.width)===Number(w)?'selected':''}>${w}/12</option>`).join('');return `<div class="dashboard-editor-row" data-code="${esc(entry.code)}"><label class="check-field"><input name="enabled" type="checkbox" ${entry.enabled?'checked':''}> <span><strong>${esc(gadget.name)}</strong><small>${esc(gadget.description||'')}</small></span></label><select name="width">${widths}</select><div class="dashboard-order"><button type="button" class="button small secondary dashboard-up" ${index===0?'disabled':''}>↑</button><button type="button" class="button small secondary dashboard-down">↓</button></div></div>`;}
  function bindDashboardEditorButtons(){document.querySelectorAll('.dashboard-up').forEach(b=>b.onclick=()=>{const row=b.closest('.dashboard-editor-row');if(row.previousElementSibling)row.parentNode.insertBefore(row,row.previousElementSibling);bindDashboardEditorButtons();});document.querySelectorAll('.dashboard-down').forEach(b=>b.onclick=()=>{const row=b.closest('.dashboard-editor-row');if(row.nextElementSibling)row.parentNode.insertBefore(row.nextElementSibling,row);bindDashboardEditorButtons();});}
  function metric(label,value,hint,classes='span-3'){return `<div class="card metric ${classes}"><div class="label">${esc(label)}</div><div class="value">${esc(value)}</div><div class="hint">${esc(hint)}</div></div>`;}
  function renderFunnel(funnel){ const entries=(state.stages.length?state.stages:stageFallback).filter(s=>Number(funnel[s.id]||0)>0); const max=Math.max(1,...entries.map(s=>Number(funnel[s.id]||0))); return entries.length?entries.map(s=>`<div class="funnel-row"><label>${esc(s.label)}</label><div class="progress"><span style="width:${Math.round((Number(funnel[s.id]||0)/max)*100)}%"></span></div><strong>${Number(funnel[s.id]||0)}</strong></div>`).join(''):empty('Sin prospectos','El embudo aparecerá cuando se registren contactos.'); }
  function advisorList(rows){return rows.length?`<div class="list">${rows.slice(0,8).map(r=>`<div class="list-item"><div><strong>${esc(r.name)}</strong><div class="meta">${Number(r.sales_closed||0)} ventas</div></div><strong>${money(r.revenue_generated_minor)}</strong></div>`).join('')}</div>`:empty('Sin resultados','Todavía no hay ventas del periodo.');}

  async function loadPartners(){ if(!isSuper())return []; state.partners=await request('/admin/partners'); return state.partners; }
  function partnerVerificationBadge(partner){
    const status=String(partner.firebase_verification_status||'MISSING').toUpperCase();
    if(status==='VERIFIED')return '<span class="badge ok">Verificado</span>';
    if(status==='NOT_APPLICABLE')return '<span class="badge blue">Acceso local</span>';
    if(status==='PENDING')return '<span class="badge warn">Pendiente</span>';
    return '<span class="badge bad">Sin configurar</span>';
  }
  function partnerPrimaryAccount(partner){
    if(!partner.primary_admin_id)return '<span class="badge bad">Sin cuenta principal</span><small class="block">Completa el acceso desde Administrar.</small>';
    return `<strong>${esc(partner.primary_admin_name||'Cuenta principal')}</strong><small class="block">${esc(partner.primary_admin_email||'')}</small>${partner.primary_admin_status&&partner.primary_admin_status!=='ACTIVE'?badge(partner.primary_admin_status):''}`;
  }
  function partnerTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Socio</th><th>Cuenta principal</th><th>Firebase</th><th>Último acceso</th><th>Dispositivos</th><th>Estado</th><th></th></tr></thead><tbody>${rows.map(p=>`<tr><td class="name-cell"><strong>${esc(p.business_name)}</strong><small>Socio ID: ${esc(p.partner_number||p.id)} · ${esc(p.rank_name||'Sin rango')}</small></td><td>${partnerPrimaryAccount(p)}</td><td>${partnerVerificationBadge(p)}${p.primary_admin_firebase_verification_sent_at?`<small class="block">Enviado ${dateTime(p.primary_admin_firebase_verification_sent_at)}</small>`:''}</td><td>${dateTime(p.primary_admin_last_login_at)}</td><td>${Number(p.devices_count||0)}</td><td>${badge(p.status)}</td><td><button class="button small primary partner-access" data-id="${esc(p.id)}">Administrar</button> <button class="button small secondary partner-permissions" data-id="${esc(p.id)}">Permisos</button> <button class="button small secondary partner-organization" data-id="${esc(p.id)}">Organización</button> ${p.status==='ACTIVE'?`<button class="button small danger partner-suspend" data-id="${esc(p.id)}">Suspender</button>`:`<button class="button small success partner-activate" data-id="${esc(p.id)}">Activar</button>`}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin socios','Crea el primer socio para comenzar.');}
  async function renderPartners(){
    setHeader('Socios','Alta, cuenta principal, Firebase, permisos y control organizacional.'); const rows=await loadPartners();
    content.innerHTML=`<div class="page-actions"><div><h2>Socios registrados</h2><p class="muted compact">Cada socio se crea junto con su cuenta principal y verificación de Firebase.</p></div><button id="newPartner" class="button primary">Nuevo socio</button></div>${partnerTable(rows)}`;
    $('newPartner').addEventListener('click',openPartnerForm); bindPartnerActions();
  }
  function bindPartnerActions(){
    document.querySelectorAll('.partner-access').forEach(b=>b.addEventListener('click',()=>openPartnerAccess(b.dataset.id)));
    document.querySelectorAll('.partner-permissions').forEach(b=>b.addEventListener('click',()=>{state.selectedPartner=b.dataset.id;localStorage.setItem('rhino_selected_partner',state.selectedPartner);location.hash='#permissions';}));
    document.querySelectorAll('.partner-organization').forEach(b=>b.addEventListener('click',()=>{state.selectedPartner=b.dataset.id;localStorage.setItem('rhino_selected_partner',state.selectedPartner);location.hash='#organization';}));
    document.querySelectorAll('.partner-suspend').forEach(b=>b.addEventListener('click',async()=>{const reason=prompt('Motivo de suspensión:');if(!reason)return;await request(`/admin/partners/${encodeURIComponent(b.dataset.id)}/suspend`,{method:'POST',body:{reason}});toast('Socio suspendido.');state.partners=[];renderPartners();}));
    document.querySelectorAll('.partner-activate').forEach(b=>b.addEventListener('click',async()=>{await request(`/admin/partners/${encodeURIComponent(b.dataset.id)}/activate`,{method:'POST',body:{}});toast('Socio activado.');state.partners=[];renderPartners();}));
  }
  async function openPartnerAccess(partnerId){
    if(!state.partners.length)await loadPartners();
    const p=state.partners.find(x=>x.id===partnerId);
    if(!p){toast('Socio no encontrado.','bad');return;}
    if(!p.primary_admin_id){openPartnerPrimaryAccessForm(p);return;}
    const verification=String(p.firebase_verification_status||'MISSING').toUpperCase();
    const verificationText=verification==='VERIFIED'?'Correo verificado':verification==='NOT_APPLICABLE'?'Acceso local':verification==='PENDING'?'Verificación pendiente':'Firebase incompleto';
    openModal(`Acceso de ${p.business_name}`,'Cuenta principal del socio y sincronización automática con Firebase.',`<div class="grid">
      <section class="card card-pad span-12"><div class="table-wrap"><table><tbody>
        <tr><th>Responsable</th><td>${esc(p.primary_admin_name||'—')}</td></tr><tr><th>Correo</th><td>${esc(p.primary_admin_email||'—')}</td></tr><tr><th>Estado</th><td>${badge(p.primary_admin_status||'ACTIVE')}</td></tr><tr><th>Firebase</th><td>${partnerVerificationBadge(p)} · ${esc(verificationText)}</td></tr><tr><th>Último acceso</th><td>${dateTime(p.primary_admin_last_login_at)}</td></tr><tr><th>Último envío</th><td>${dateTime(p.primary_admin_firebase_verification_sent_at)}</td></tr>
      </tbody></table></div>${p.primary_admin_firebase_verification_last_error?`<p class="form-error">Último error: ${esc(p.primary_admin_firebase_verification_last_error)}</p>`:''}</section>
    </div><div class="form-actions"><button class="button secondary" id="refreshPartnerFirebase">Actualizar estado</button>${verification==='PENDING'?'<button class="button primary" id="resendPartnerVerification">Reenviar verificación</button><button class="button secondary" id="copyPartnerVerification">Copiar enlace</button>':''}<button class="button secondary" id="changePartnerPassword">${verification==='MISSING'?'Configurar Firebase y contraseña':'Cambiar contraseña'}</button><button class="button secondary" data-close>Cerrar</button></div>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('refreshPartnerFirebase').onclick=async()=>{const b=$('refreshPartnerFirebase');b.disabled=true;try{await request(`/admin/partners/${encodeURIComponent(partnerId)}/primary-access/refresh-firebase`,{method:'POST',body:{}});state.partners=[];await loadPartners();toast('Estado actualizado.');openPartnerAccess(partnerId);}catch(e){toast(errorMessage(e),'bad');}finally{b.disabled=false;}};
    $('resendPartnerVerification')?.addEventListener('click',async()=>{const b=$('resendPartnerVerification');b.disabled=true;try{const result=await request(`/admin/partners/${encodeURIComponent(partnerId)}/primary-access/resend-verification`,{method:'POST',body:{}});if(result.verification?.error)throw {message:result.verification.error};state.partners=[];await loadPartners();toast(result.verification?.email_verified?'El correo ya está verificado.':'Correo de verificación reenviado.');openPartnerAccess(partnerId);}catch(e){toast(errorMessage(e),'bad');}finally{b.disabled=false;}});
    $('copyPartnerVerification')?.addEventListener('click',async()=>{const b=$('copyPartnerVerification');b.disabled=true;try{const data=await request(`/admin/partners/${encodeURIComponent(partnerId)}/primary-access/verification-link`);await navigator.clipboard.writeText(data.verification_link);toast('Enlace de verificación copiado.');}catch(e){toast(errorMessage(e),'bad');}finally{b.disabled=false;}});
    $('changePartnerPassword').onclick=()=>openPartnerPasswordForm(p);
  }
  function openPartnerPrimaryAccessForm(partner){
    openModal(`Crear acceso para ${partner.business_name}`,'Rhino comprobará el correo en CRM y Firebase. Si pertenece a un alta anterior sin uso, podrás transferirlo sin SSH.',`<form id="partnerPrimaryAccessForm"><div class="form-grid"><label>Nombre de la persona responsable<input name="name" required maxlength="160" value="${esc(partner.business_name||'')}"></label><label>Correo de acceso<input name="email" type="email" required maxlength="255"></label><label>Contraseña temporal<input name="password" type="password" minlength="12" maxlength="128" required></label><label>Confirmar contraseña<input name="password_confirmation" type="password" minlength="12" maxlength="128" required></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Crear cuenta principal</button></div><div class="form-error" id="partnerPrimaryAccessError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('partnerPrimaryAccessForm').onsubmit=async e=>{
      e.preventDefault();
      const f=Object.fromEntries(new FormData(e.currentTarget));
      const error=$('partnerPrimaryAccessError');
      error.textContent='';
      if(f.password!==f.password_confirmation){error.textContent='Las contraseñas no coinciden.';return;}
      const b=e.submitter;b.disabled=true;
      try{
        const status=await request(`/admin/partners/${encodeURIComponent(partner.id)}/primary-access/email-status?email=${encodeURIComponent(f.email.trim())}`);
        let data;
        if(status.available){
          data=await request(`/admin/partners/${encodeURIComponent(partner.id)}/primary-access`,{method:'POST',body:f});
        }else{
          const existing=status.existing||{};
          const owner=existing.partner_name||'otra cuenta de Rhino CRM';
          if(!status.can_transfer){
            error.textContent=`El correo ya está registrado en ${owner}. ${status.block_reason||'Utiliza otro correo.'}`;
            return;
          }
          const verb=status.same_partner?'usar esta cuenta existente como principal':'transferir la cuenta existente';
          const warning=`El correo ${existing.email||f.email} ya está registrado en ${owner}. Rhino puede ${verb} para ${partner.business_name}. Se actualizarán la contraseña y los permisos, y se cerrarán sus sesiones anteriores. ¿Continuar?`;
          if(!confirm(warning))return;
          const confirmation=prompt('Para confirmar escribe TRANSFERIR');
          if(String(confirmation||'').trim().toUpperCase()!=='TRANSFERIR'){
            error.textContent='Transferencia cancelada: no se escribió TRANSFERIR.';
            return;
          }
          data=await request(`/admin/partners/${encodeURIComponent(partner.id)}/primary-access/transfer-existing`,{method:'POST',body:{...f,confirmation:'TRANSFERIR',reason:`Cuenta corregida desde Socios; origen: ${owner}.`}});
        }
        state.partners=[];closeModal();
        toast(data.verification?.error?'Cuenta lista; revisa el envío de verificación.':'Cuenta principal lista y verificación enviada.');
        renderPartners();
      }catch(err){error.textContent=errorMessage(err);}
      finally{b.disabled=false;}
    };
  }
  function openPartnerPasswordForm(partner){
    openModal(`${partner.firebase_verification_status==='MISSING'?'Configurar Firebase':'Cambiar contraseña'} de ${partner.business_name}`,'La cuenta se sincronizará en Rhino y Firebase; todas las sesiones actuales se cerrarán.',`<form id="partnerPasswordForm"><div class="form-grid"><label>Nueva contraseña<input name="password" type="password" minlength="12" maxlength="128" required></label><label>Confirmar contraseña<input name="password_confirmation" type="password" minlength="12" maxlength="128" required></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Actualizar contraseña</button></div><div class="form-error" id="partnerPasswordError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=()=>openPartnerAccess(partner.id);
    $('partnerPasswordForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));if(f.password!==f.password_confirmation){$('partnerPasswordError').textContent='Las contraseñas no coinciden.';return;}const b=e.submitter;b.disabled=true;try{await request(`/admin/partners/${encodeURIComponent(partner.id)}/primary-access/password`,{method:'PUT',body:f});toast('Contraseña actualizada.');state.partners=[];await loadPartners();openPartnerAccess(partner.id);}catch(err){$('partnerPasswordError').textContent=errorMessage(err);}finally{b.disabled=false;}};
  }

  async function renderPartnerPermissions(){
    setHeader('Permisos de socios','Define el límite de acceso para cada organización.');
    const partners=await loadPartners();
    if(!state.selectedPartner){
      content.innerHTML=partnerSelector(partners,'Selecciona un socio para configurar los módulos y operaciones disponibles.');
      bindPartnerSelector('permissions');
      return;
    }

    const [catalog,data]=await Promise.all([
      request('/admin/permissions/catalog'),
      request(`/admin/partners/${encodeURIComponent(state.selectedPartner)}/permissions`)
    ]);
    const groups=catalog.groups||[];
    const selected=new Set(data.policy?.permissions||[]);
    const partner=data.partner||partners.find(p=>p.id===state.selectedPartner)||{};
    const policyLabel=data.policy?.custom?'Política personalizada':'Acceso predeterminado';
    const policyClass=data.policy?.custom?'warn':'ok';

    content.innerHTML=`<div class="page-actions"><div><h2>${esc(partner.business_name||'Socio')}</h2><p class="muted compact">Socio ID: ${esc(partner.partner_number||partner.id||state.selectedPartner)} · ${esc(data.effect||'')}</p></div><div class="actions"><button id="changePermissionPartner" class="button secondary">Cambiar socio</button><span class="badge ${policyClass}">${esc(policyLabel)}</span></div></div>
      <section class="card card-pad permission-summary"><div><strong id="partnerPermissionCount">${selected.size}</strong><span> de ${Number(catalog.permission_count||0)} permisos habilitados</span></div><p>Los cambios se aplican en el servidor de inmediato. Los usuarios deben recargar o volver a entrar para actualizar el menú visible.</p></section>
      <div class="permission-toolbar"><input id="permissionSearch" placeholder="Buscar módulo o permiso"><div class="actions"><button id="permissionAll" class="button secondary" type="button">Habilitar todos</button><button id="permissionNone" class="button secondary" type="button">Quitar todos</button></div></div>
      <form id="partnerPermissionForm">
        <div class="permission-groups">${groups.map(group=>`<section class="card card-pad permission-group" data-permission-group="${esc(group.code)}"><div class="permission-group-head"><div><h3>${esc(group.label)}</h3><p>${esc(group.description||'')}</p></div><label class="permission-group-switch"><input type="checkbox" class="permission-group-toggle" data-group="${esc(group.code)}"> Todo</label></div><div class="permission-list">${(group.permissions||[]).map(permission=>`<label class="permission-item" data-search="${esc(`${group.label} ${permission.label} ${permission.description} ${permission.code}`.toLowerCase())}"><input type="checkbox" class="partner-permission-check" name="permissions" value="${esc(permission.code)}" data-group="${esc(group.code)}" data-depends="${esc((permission.depends_on||[]).join(','))}" ${selected.has(permission.code)?'checked':''}><span><strong>${esc(permission.label)}</strong><small>${esc(permission.description||'')}</small><code>${esc(permission.code)}</code></span></label>`).join('')}</div></section>`).join('')}</div>
        <section class="card card-pad permission-save-card"><label>Motivo del cambio<input id="permissionReason" maxlength="1000" placeholder="Ej. Habilitar administración de plantillas de dietas"></label><div class="form-actions"><button id="resetPartnerPermissions" type="button" class="button danger" ${data.policy?.custom?'':'disabled'}>Restaurar predeterminados</button><button class="button primary" type="submit">Guardar permisos</button></div><div class="form-error" id="partnerPermissionError"></div></section>
      </form>`;

    $('changePermissionPartner').onclick=()=>{state.selectedPartner='';localStorage.removeItem('rhino_selected_partner');renderPartnerPermissions();};
    $('permissionSearch').oninput=e=>filterPartnerPermissions(e.target.value);
    $('permissionAll').onclick=()=>{document.querySelectorAll('.partner-permission-check').forEach(x=>x.checked=true);syncPartnerPermissionUi();};
    $('permissionNone').onclick=()=>{document.querySelectorAll('.partner-permission-check').forEach(x=>x.checked=false);syncPartnerPermissionUi();};
    document.querySelectorAll('.permission-group-toggle').forEach(toggle=>toggle.onchange=()=>{document.querySelectorAll(`.partner-permission-check[data-group="${CSS.escape(toggle.dataset.group)}"]`).forEach(x=>x.checked=toggle.checked);syncPartnerPermissionUi();});
    document.querySelectorAll('.partner-permission-check').forEach(check=>check.onchange=()=>{applyPartnerPermissionDependencies(check);syncPartnerPermissionUi();});
    $('partnerPermissionForm').onsubmit=async e=>{e.preventDefault();const button=e.submitter;button.disabled=true;$('partnerPermissionError').textContent='';try{const permissions=[...document.querySelectorAll('.partner-permission-check:checked')].map(x=>x.value);await request(`/admin/partners/${encodeURIComponent(state.selectedPartner)}/permissions`,{method:'PUT',body:{permissions,reason:$('permissionReason').value.trim()||null}});toast('Permisos del socio actualizados.');await renderPartnerPermissions();}catch(err){$('partnerPermissionError').textContent=errorMessage(err);button.disabled=false;}};
    $('resetPartnerPermissions').onclick=async()=>{if(!confirm('¿Restaurar el acceso predeterminado del socio? Se eliminarán todas las restricciones personalizadas.'))return;const button=$('resetPartnerPermissions');button.disabled=true;try{await request(`/admin/partners/${encodeURIComponent(state.selectedPartner)}/permissions`,{method:'DELETE',body:{reason:$('permissionReason').value.trim()||'Restauración administrativa'}});toast('Permisos predeterminados restaurados.');await renderPartnerPermissions();}catch(err){$('partnerPermissionError').textContent=errorMessage(err);button.disabled=false;}};
    syncPartnerPermissionUi();
  }

  function applyPartnerPermissionDependencies(check){
    if(check.checked){
      const dependencies=(check.dataset.depends||'').split(',').filter(Boolean);
      dependencies.forEach(code=>{const dependency=document.querySelector(`.partner-permission-check[value="${CSS.escape(code)}"]`);if(dependency&&!dependency.checked){dependency.checked=true;applyPartnerPermissionDependencies(dependency);}});
      return;
    }
    const code=check.value;
    document.querySelectorAll('.partner-permission-check:checked').forEach(candidate=>{
      const dependencies=(candidate.dataset.depends||'').split(',').filter(Boolean);
      if(dependencies.includes(code)){candidate.checked=false;applyPartnerPermissionDependencies(candidate);}
    });
  }

  function syncPartnerPermissionUi(){
    const checks=[...document.querySelectorAll('.partner-permission-check')];
    if($('partnerPermissionCount'))$('partnerPermissionCount').textContent=String(checks.filter(x=>x.checked).length);
    document.querySelectorAll('.permission-group-toggle').forEach(toggle=>{
      const groupChecks=[...document.querySelectorAll(`.partner-permission-check[data-group="${CSS.escape(toggle.dataset.group)}"]`)];
      const enabled=groupChecks.filter(x=>x.checked).length;
      toggle.checked=groupChecks.length>0&&enabled===groupChecks.length;
      toggle.indeterminate=enabled>0&&enabled<groupChecks.length;
    });
  }

  function filterPartnerPermissions(value){
    const query=String(value||'').trim().toLowerCase();
    document.querySelectorAll('.permission-group').forEach(group=>{
      let visible=0;
      group.querySelectorAll('.permission-item').forEach(item=>{const match=!query||String(item.dataset.search||'').includes(query);item.classList.toggle('hidden',!match);if(match)visible++;});
      group.classList.toggle('hidden',visible===0);
    });
  }
  async function loadRanks(force=false){
    if(!force&&state.ranks.length)return state.ranks;
    state.ranks=await request('/organization/ranks'+(isSuper()?'?include_inactive=1':''));
    return state.ranks;
  }
  async function openPartnerForm(){
    let plans=[],ranks=[],partners=[];
    try{
      [plans,ranks,partners]=await Promise.all([request('/plans'),loadRanks(true),loadPartners()]);
    }catch(e){toast(errorMessage(e),'bad');return;}
    openModal('Crear socio','Se creará su espacio privado, ubicación técnica, posición organizacional y administrador.',`<form id="partnerForm"><div class="form-grid">
      <label>Socio ID de la compañía<input name="partner_number" required inputmode="numeric" pattern="[0-9]+" maxlength="40" placeholder="125452"></label>
      <label>Nombre del socio o negocio<input name="business_name" required maxlength="200"></label>
      <label>Plan<select name="plan_id" required>${plans.map(p=>`<option value="${esc(p.id)}">${esc(p.name)} · ${p.max_users} usuarios</option>`).join('')}</select></label>
      <label>Rango inicial<select name="current_rank_id" required>${ranks.filter(r=>r.active).map(r=>`<option value="${esc(r.id)}">${esc(r.name)}</option>`).join('')}</select></label>
      <label class="full">Patrocinador directo<select name="sponsor_partner_id"><option value="">Sin patrocinador asignado</option>${partners.filter(p=>p.status==='ACTIVE').map(p=>`<option value="${esc(p.id)}">${esc(p.partner_number||p.id)} · ${esc(p.business_name)}</option>`).join('')}</select><small class="field-help">Este socio será el upline directo. La relación puede corregirse únicamente con auditoría.</small></label>
      <label>País<input name="country" value="MX" required></label><label>Código telefónico<input name="country_dial_code" value="52"></label>
      <div class="full"><h3>Cuenta principal</h3><p class="muted compact">Rhino creará la cuenta en Firebase, sincronizará la contraseña y enviará la verificación automáticamente.</p></div><label>Nombre<input name="admin_name" required maxlength="160"></label><label>Correo<input name="admin_email" type="email" required maxlength="255"></label><label>Contraseña temporal<input name="admin_password" type="password" minlength="12" maxlength="128" required></label><label>Confirmar contraseña<input name="admin_password_confirmation" type="password" minlength="12" maxlength="128" required></label>
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary" type="submit">Crear socio y acceso</button></div><div class="form-error" id="partnerFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('partnerForm').onsubmit=async e=>{e.preventDefault();const form=new FormData(e.currentTarget),btn=e.submitter;$('partnerFormError').textContent='';if(form.get('admin_password')!==form.get('admin_password_confirmation')){$('partnerFormError').textContent='Las contraseñas no coinciden.';return;}btn.disabled=true;try{
      const partner=await request('/partners',{method:'POST',body:{
        plan_id:form.get('plan_id'),partner_number:form.get('partner_number'),business_name:form.get('business_name'),
        country:form.get('country'),country_dial_code:form.get('country_dial_code'),location_name:'Principal',
        sponsor_partner_id:form.get('sponsor_partner_id')||null,current_rank_id:form.get('current_rank_id'),
        admin_name:form.get('admin_name'),admin_email:form.get('admin_email'),admin_password:form.get('admin_password'),admin_password_confirmation:form.get('admin_password_confirmation')
      }});
      state.selectedPartner=partner.partner_id;state.partners=[];localStorage.setItem('rhino_selected_partner',state.selectedPartner);closeModal();
      const verification=partner.primary_access?.verification||{};
      toast(verification.error?`Socio ${partner.partner_number} creado; revisa la verificación.`:`Socio ${partner.partner_number} creado y correo de verificación enviado.`);
      await renderPartners();
    }catch(err){$('partnerFormError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }

  async function loadUsers(force=false){
    if(!force&&state.users.length)return state.users;
    if(isSuper()&&!state.selectedPartner)return [];
    state.users=await request('/security/users'+selectedPartnerQuery());return state.users;
  }
  async function loadTeams(force=false){ if(isSuper())return [];if(!force&&state.teams.length)return state.teams;state.teams=await request('/teams');return state.teams; }
  async function loadLocations(force=false){ if(!force&&state.locations.length)return state.locations;if(isSuper()){if(!state.selectedPartner)return [];state.locations=await request('/locations?partner_id='+encodeURIComponent(state.selectedPartner));return state.locations;}state.locations=await request('/locations');return state.locations; }
  function workModeCard(){
    if(isSuper() || !state.workMode?.can_switch) return '';
    const isTeam=teamMode();
    return `<section class="card card-pad mode-card"><div class="page-actions"><div><h3>${isTeam?'Modo equipo':'Modo individual'}</h3><p class="muted compact">${isTeam?'Puedes agregar responsables, coordinadores y equipos.':'Tú eres el responsable automático de eventos, prospectos y seguimientos.'}</p></div><button id="toggleWorkMode" class="button ${isTeam?'secondary':'primary'}">${isTeam?'Usar modo individual':'Activar modo equipo'}</button></div></section>`;
  }
  function bindWorkModeActions(){
    $('toggleWorkMode')?.addEventListener('click',async()=>{
      const next=teamMode()?'INDIVIDUAL':'TEAM';
      if(next==='INDIVIDUAL'&&!confirm('Se ocultarán equipos y asignaciones en la interfaz. Los datos existentes no se borrarán. ¿Continuar?'))return;
      const button=$('toggleWorkMode');button.disabled=true;
      try{
        state.workMode=await request('/work-mode',{method:'PUT',body:{mode:next}});
        state.users=[];state.teams=[];renderNavigation();toast(next==='TEAM'?'Modo equipo activado.':'Modo individual activado.');
        await route();
      }catch(error){toast(errorMessage(error),'bad');button.disabled=false;}
    });
  }
  async function renderUsers(){
    if(isSuper()){
      setHeader('Usuarios','Roles, acceso, suspensión y sesiones.');
      if(!state.selectedPartner){const partners=await loadPartners();content.innerHTML=partnerSelector(partners,'Selecciona un socio para administrar sus usuarios.');bindPartnerSelector('users');return;}
      const users=await loadUsers(true); const partnerName=state.partners.find(p=>p.id===state.selectedPartner)?.business_name;
      content.innerHTML=`<div class="page-actions"><div><h2>${esc(partnerName||'Usuarios del socio')}</h2><p class="muted compact">${users.length} usuarios registrados.</p></div><div class="actions"><button class="button secondary" id="changePartner">Cambiar socio</button>${has('users.create')?'<button class="button primary" id="newUser">Agregar usuario</button>':''}</div></div>
        ${users.length?`<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead><tbody>${users.map(u=>`<tr><td class="name-cell"><strong>${esc(u.name)}</strong><small>${esc(u.email)}</small></td><td>${esc(roleNames[u.role]||u.role)}</td><td>${dateTime(u.last_login_at)}</td><td>${badge(u.status)}</td><td>${u.status==='ACTIVE'?`<button class="button small danger suspend-user" data-id="${esc(u.id)}">Suspender</button>`:`<button class="button small success activate-user" data-id="${esc(u.id)}">Activar</button>`} <button class="button small secondary revoke-user" data-id="${esc(u.id)}">Cerrar sesiones</button></td></tr>`).join('')}</tbody></table></div>`:empty('Sin usuarios','Crea el administrador principal del socio.')}`;
    }else{
      const users=await loadUsers(true);
      if(!teamMode()){
        setHeader('Mi cuenta','Tu acceso y el modo de trabajo del socio.');
        const me=users.find(u=>u.id===myUserId())||users.find(u=>u.email===state.me?.email)||{id:myUserId(),name:state.me?.name,email:state.me?.email,role:state.me?.role,status:'ACTIVE'};
        content.innerHTML=`${workModeCard()}<section class="card card-pad"><h2>Socio responsable</h2><p class="muted">En modo individual, tus eventos, prospectos, citas y ventas se asignan automáticamente a esta cuenta.</p><div class="table-wrap"><table><tbody><tr><th>Nombre</th><td>${esc(me.name||'—')}</td></tr><tr><th>Correo</th><td>${esc(me.email||'—')}</td></tr><tr><th>Rol</th><td>${esc(roleNames[me.role]||me.role||'Socio')}</td></tr><tr><th>Estado</th><td>${badge(me.status||'ACTIVE')}</td></tr></tbody></table></div><div class="form-actions"><button class="button secondary revoke-user" data-id="${esc(me.id)}">Cerrar otras sesiones</button></div></section>`;
        bindWorkModeActions();
      }else{
        setHeader('Usuarios','Responsables, coordinadores, acceso y sesiones.');
        content.innerHTML=`${workModeCard()}<div class="page-actions"><div><h2>Equipo de trabajo</h2><p class="muted compact">${users.length} usuarios registrados.</p></div>${has('users.create')?'<button class="button primary" id="newUser">Agregar responsable</button>':''}</div>
          ${users.length?`<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead><tbody>${users.map(u=>`<tr><td class="name-cell"><strong>${esc(u.name)}</strong><small>${esc(u.email)}</small></td><td>${esc(roleNames[u.role]||u.role)}</td><td>${dateTime(u.last_login_at)}</td><td>${badge(u.status)}</td><td>${u.id===myUserId()?'':(u.status==='ACTIVE'?`<button class="button small danger suspend-user" data-id="${esc(u.id)}">Suspender</button>`:`<button class="button small success activate-user" data-id="${esc(u.id)}">Activar</button>`)} <button class="button small secondary revoke-user" data-id="${esc(u.id)}">Cerrar sesiones</button></td></tr>`).join('')}</tbody></table></div>`:empty('Sin responsables','Agrega personas cuando necesites trabajar en equipo.')}`;
        bindWorkModeActions();
      }
    }
    $('changePartner')?.addEventListener('click',()=>{state.selectedPartner='';localStorage.removeItem('rhino_selected_partner');state.users=[];state.locations=[];renderUsers();});
    $('newUser')?.addEventListener('click',openUserForm);
    document.querySelectorAll('.suspend-user').forEach(b=>b.onclick=async()=>{const reason=prompt('Motivo de suspensión:');if(!reason)return;await request(`/security/users/${encodeURIComponent(b.dataset.id)}/suspend`,{method:'POST',body:{reason}});toast('Usuario suspendido.');state.users=[];renderUsers();});
    document.querySelectorAll('.activate-user').forEach(b=>b.onclick=async()=>{await request(`/security/users/${encodeURIComponent(b.dataset.id)}/activate`,{method:'POST',body:{}});toast('Usuario activado.');state.users=[];renderUsers();});
    document.querySelectorAll('.revoke-user').forEach(b=>b.onclick=async()=>{const reason=prompt('Motivo para cerrar las sesiones:','Cierre administrativo');if(!reason)return;await request(`/security/users/${encodeURIComponent(b.dataset.id)}/revoke-sessions`,{method:'POST',body:{reason}});toast('Sesiones revocadas.');});
  }
  function partnerSelector(partners,text){return `<div class="card card-pad"><h2>Seleccionar socio</h2><p class="muted">${esc(text)}</p><div class="form-grid"><label class="full">Socio<select id="partnerSelector"><option value="">Seleccione…</option>${partners.map(p=>`<option value="${esc(p.id)}">${esc(p.business_name)}</option>`).join('')}</select></label></div></div>`;}
  function bindPartnerSelector(tab){$('partnerSelector').onchange=e=>{state.selectedPartner=e.target.value;if(state.selectedPartner)localStorage.setItem('rhino_selected_partner',state.selectedPartner);state.users=[];state.locations=[];state.partners=state.partners||[];location.hash='#'+tab;route();};}
  async function openUserForm(){
    await loadUsers(true);
    openModal('Agregar usuario','Crea una cuenta para una persona que colaborará con el socio.',`<form id="userForm"><div class="form-grid">
      <label>Nombre<input name="name" required></label><label>Correo<input name="email" type="email" required></label><label class="full">Rol<select name="role">${isSuper()?'<option value="PARTNER_ADMIN">Socio</option>':''}<option value="ADVISOR">Responsable</option><option value="TEAM_LEADER">Coordinador de equipo</option><option value="OPERATOR">Operador</option>${isSuper()?'':'<option value="PARTNER_ADMIN">Socio</option>'}</select></label>
      <label class="full">Contraseña temporal<input name="password" type="password" minlength="12" required></label>
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Crear usuario</button></div><div class="form-error" id="userFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('userForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));if(isSuper())f.partner_id=state.selectedPartner;if(!f.location_id)delete f.location_id;const btn=e.submitter;btn.disabled=true;try{await request('/users',{method:'POST',body:f});state.users=[];closeModal();toast('Usuario creado.');renderUsers();}catch(err){$('userFormError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }


  async function loadCampaigns(force=false){
    if(!force&&state.campaigns.length)return state.campaigns;
    state.campaigns=await request('/campaigns');return state.campaigns;
  }
  async function loadCampaignInvitations(force=false){
    if(!force&&state.campaignInvitations.length)return state.campaignInvitations;
    state.campaignInvitations=await request('/campaign-invitations');return state.campaignInvitations;
  }
  function campaignTypeOptions(selected='WEIGHING_EVENT'){
    return Object.entries(campaignTypeNames).map(([id,label])=>`<option value="${id}" ${id===selected?'selected':''}>${esc(label)}</option>`).join('');
  }
  function eventAttentionLabel(value){
    return {PENDING_DIET:'Dieta pendiente',DIET_GENERATING:'Generando dieta',DIET_GENERATED:'Dieta generada',SALE_RECORDED:'Venta registrada',DIET_AND_SALE:'Dieta y venta',CLOSED_ASSIGNED:'Cerrado y asignado',CLOSED_NO_DIET:'Cerrado sin dieta'}[String(value||'').toUpperCase()]||value||'Pendiente';
  }
  function eventActivityLabel(value){
    return {INVITATION_SENT:'Invitación enviada',INVITATION_ACCEPTED:'Invitación aceptada',INVITATION_DECLINED:'Invitación rechazada',INVITATION_REVOKED:'Invitación revocada',PARTICIPANT_REGISTERED:'Persona registrada',NUTRITION_PROFILE_UPDATED:'Perfil alimentario actualizado',DIET_GENERATED:'Dieta generada y contacto asignado',SALE_RECORDED:'Venta registrada',EVENT_CLOSED:'Evento cerrado'}[String(value||'').toUpperCase()]||String(value||'Actividad').replaceAll('_',' ');
  }
  function invitationPanel(rows){
    const pending=(rows||[]).filter(x=>x.status==='PENDING');
    if(!pending.length)return '';
    return `<section class="card card-pad"><div class="page-actions"><div><h2>Invitaciones pendientes</h2><p class="muted compact">Acepta para trabajar únicamente con las personas de ese evento.</p></div><span class="badge warn">${pending.length}</span></div><div class="list">${pending.map(i=>`<div class="list-item"><div><strong>${esc(i.campaign_name)}</strong><div class="meta">${esc(i.organizer_partner_name||'Organizador')} · invitó ${esc(i.inviter_name||'un usuario')} · ${dateTime(i.starts_at)}</div><small>${esc(i.venue_name||'Lugar por definir')}</small></div><div class="actions"><button class="button small success invitation-respond" data-id="${esc(i.id)}" data-response="ACCEPTED">Aceptar</button><button class="button small secondary invitation-respond" data-id="${esc(i.id)}" data-response="DECLINED">Rechazar</button></div></div>`).join('')}</div></section>`;
  }
  async function renderCampaigns(){
    setHeader('Eventos','Kiosko registra; el autor de la dieta recibe el contacto y su seguimiento.');
    const [campaigns,invitations]=await Promise.all([loadCampaigns(true),loadCampaignInvitations(true)]);
    content.innerHTML=`${invitationPanel(invitations)}<div class="page-actions"><div><h2>Eventos colaborativos</h2><p class="muted compact">Invita a otro socio, trabajen sobre las personas del evento y cierren con ventas, responsables y notas.</p></div>${has('campaigns.create')?'<button id="newCampaign" class="button primary">Crear evento</button>':''}</div>
      <div class="filters two"><input id="campaignSearch" placeholder="Buscar evento o lugar"><select id="campaignStatus"><option value="">Todos los estados</option><option value="ACTIVE">Activos</option><option value="SCHEDULED">Programados</option><option value="CLOSED">Cerrados</option><option value="CANCELLED">Cancelados</option></select><button id="filterCampaigns" class="button secondary">Filtrar</button></div>
      <div id="campaignResults">${campaignCards(campaigns)}</div>`;
    $('newCampaign')?.addEventListener('click',()=>openCampaignForm());
    $('filterCampaigns').onclick=async()=>{const q=new URLSearchParams();if($('campaignSearch').value)q.set('search',$('campaignSearch').value);if($('campaignStatus').value)q.set('status',$('campaignStatus').value);state.campaigns=await request('/campaigns?'+q);$('campaignResults').innerHTML=campaignCards(state.campaigns);bindCampaignCards();};
    $('campaignSearch').addEventListener('keydown',e=>{if(e.key==='Enter')$('filterCampaigns').click();});
    document.querySelectorAll('.invitation-respond').forEach(button=>button.onclick=async()=>{button.disabled=true;try{const result=await request(`/campaign-invitations/${encodeURIComponent(button.dataset.id)}/respond`,{method:'POST',body:{response:button.dataset.response}});state.campaigns=[];state.campaignInvitations=[];toast(button.dataset.response==='ACCEPTED'?'Invitación aceptada. Ya puedes abrir el evento.':'Invitación rechazada.');await renderCampaigns();if(button.dataset.response==='ACCEPTED'&&result.campaign_id)openCampaignDetail(result.campaign_id);}catch(e){toast(errorMessage(e),'bad');button.disabled=false;}});
    bindCampaignCards();
  }
  function campaignCards(rows){
    return rows.length?`<div class="grid">${rows.map(c=>`<article class="card card-pad span-4 event-card"><div class="event-head"><div><span class="badge purple">${esc(campaignTypeLabel(c.type))}</span><h3>${esc(c.name)}</h3></div>${badge(c.display_status||c.status)}</div><p class="event-place">${esc(c.venue_name||c.address||'Lugar por definir')}</p><div class="event-date">${dateTime(c.starts_at)}${c.ends_at?` · hasta ${dateTime(c.ends_at)}`:''}</div><div class="kpi-strip event-kpis"><div class="kpi-chip"><strong>${Number(c.participants_count||0)}</strong><small>Personas</small></div><div class="kpi-chip"><strong>${Number(c.measurements_count||0)}</strong><small>Pesajes</small></div></div><div class="form-actions"><button class="button small secondary campaign-open" data-id="${esc(c.id)}">Ver evento</button></div></article>`).join('')}</div>`:empty('Sin eventos','Crea una jornada de pesaje, un Home Party u otra minicampaña.');
  }
  function bindCampaignCards(){document.querySelectorAll('.campaign-open').forEach(b=>b.onclick=()=>openCampaignDetail(b.dataset.id));}
  async function openCampaignForm(campaign=null){
    const [users,teams,devices]=await Promise.all([
      teamMode()&&has('users.read')?loadUsers(true):Promise.resolve([]),
      teamMode()&&has('teams.read')?loadTeams(true):Promise.resolve([]),
      has('devices.read')?request('/devices'):Promise.resolve([])
    ]);
    const memberIds=new Set((campaign?.members||[]).filter(x=>x.member_partner_id===campaign?.partner_id).map(x=>x.id));const deviceIds=new Set((campaign?.devices||[]).map(x=>x.id));
    const collaborationFields=teamMode()?`
      <label>Equipo<select name="team_id"><option value="">Sin equipo específico</option>${teams.map(t=>`<option value="${esc(t.id)}" ${t.id===campaign?.team_id?'selected':''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label>Organizador interno<select name="responsible_user_id"><option value="">Usuario actual</option>${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}" ${u.id===campaign?.responsible_user_id?'selected':''}>${esc(u.name)}</option>`).join('')}</select></label>
      <label>Equipo interno<select name="member_user_ids" multiple size="5">${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}" ${memberIds.has(u.id)?'selected':''}>${esc(u.name)} · ${esc(roleNames[u.role]||u.role)}</option>`).join('')}</select><small class="field-help">Los socios externos se invitan después de crear el evento.</small></label>`
      :`<div class="full inline-notice"><strong>Organizador:</strong> ${esc(state.me?.name||'Socio')}. Podrás invitar al colaborador al guardar.</div>`;
    openModal(campaign?'Editar evento':'Crear evento',campaign?'Actualiza el evento sin perder participantes ni pesajes.':'Programa el lugar, las básculas y después invita al colaborador.',`<form id="campaignForm"><div class="form-grid">
      <label class="full">Nombre del evento<input name="name" value="${esc(campaign?.name||'')}" required placeholder="Jornada de bienestar"></label>
      <label>Tipo<select name="type">${campaignTypeOptions(campaign?.type||'WEIGHING_EVENT')}</select></label><label>Lugar o anfitrión<input name="venue_name" value="${esc(campaign?.venue_name||'')}" placeholder="Plaza, casa o negocio"></label>
      <label class="full">Dirección<input name="address" value="${esc(campaign?.address||'')}" placeholder="Calle, número y referencia"></label><label>Ciudad<input name="city" value="${esc(campaign?.city||'')}"></label><label>Estado<input name="state" value="${esc(campaign?.state||'')}"></label>
      <label>Inicio<input name="starts_at" type="datetime-local" value="${dateInput(campaign?.starts_at)}" required></label><label>Finalización<input name="ends_at" type="datetime-local" value="${dateInput(campaign?.ends_at||new Date(Date.now()+3*60*60*1000))}"></label>
      ${collaborationFields}
      <label>Meta de prospectos<input name="prospects_target" type="number" min="0" value="${Number(campaign?.prospects_target||0)}"></label><label>Meta de pesajes<input name="measurements_target" type="number" min="0" value="${Number(campaign?.measurements_target||0)}"></label>
      <label>Presupuesto MXN<input name="budget" type="number" min="0" step="0.01" value="${Number(campaign?.budget_minor||0)/100}"></label><label>Moneda<input name="currency" value="${esc(campaign?.currency||'MXN')}" maxlength="3"></label>
      <label>Básculas<select name="device_ids" multiple size="5">${devices.filter(d=>d.status==='ACTIVE').map(d=>`<option value="${esc(d.id)}" ${deviceIds.has(d.id)?'selected':''}>${esc(d.mac_address)}</option>`).join('')}</select><small class="field-help">Al activar el evento, Kiosko atribuirá los pesajes automáticamente.</small></label>
      <label class="full">Notas de preparación<textarea name="notes" placeholder="Indicaciones, contacto del anfitrión, montaje…">${esc(campaign?.notes||'')}</textarea></label>
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">${campaign?'Guardar cambios':'Crear evento'}</button></div><div class="form-error" id="campaignFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('campaignForm').onsubmit=async e=>{
      e.preventDefault();const form=e.currentTarget,fd=new FormData(form),btn=e.submitter;btn.disabled=true;$('campaignFormError').textContent='';
      const body={name:fd.get('name'),type:fd.get('type'),venue_name:fd.get('venue_name')||null,address:fd.get('address')||null,city:fd.get('city')||null,state:fd.get('state')||null,starts_at:fd.get('starts_at'),ends_at:fd.get('ends_at')||null,team_id:teamMode()?(fd.get('team_id')||null):null,responsible_user_id:teamMode()?(fd.get('responsible_user_id')||myUserId()):myUserId(),prospects_target:Number(fd.get('prospects_target')||0),measurements_target:Number(fd.get('measurements_target')||0),budget_minor:Math.round(Number(fd.get('budget')||0)*100),currency:String(fd.get('currency')||'MXN').toUpperCase(),notes:fd.get('notes')||null,member_user_ids:teamMode()&&form.elements.member_user_ids?[...form.elements.member_user_ids.selectedOptions].map(o=>o.value):[myUserId()],device_ids:[...form.elements.device_ids.selectedOptions].map(o=>o.value)};
      try{const saved=await request(campaign?`/campaigns/${encodeURIComponent(campaign.id)}`:'/campaigns',{method:campaign?'PATCH':'POST',body});state.campaigns=[];closeModal();toast(campaign?'Evento actualizado.':'Evento creado. Ahora puedes invitar al colaborador.');await renderCampaigns();openCampaignDetail(saved.id);}catch(err){$('campaignFormError').textContent=errorMessage(err);}finally{btn.disabled=false;}
    };
  }
  function campaignMembersHtml(campaign){
    return campaign.members?.length?`<div class="list">${campaign.members.map(m=>`<div class="list-item"><div><strong>${esc(m.name)}</strong><div class="meta">${esc(campaignMemberRoleNames[m.member_role]||m.member_role)}${m.partner_name?` · ${esc(m.partner_name)}`:''}</div></div>${badge(m.status)}</div>`).join('')}</div>`:empty('Sin colaboradores','Invita a la segunda persona del evento.');
  }
  function campaignInvitationsHtml(campaign){
    if(!campaign.can_manage)return '';
    const rows=campaign.invitations||[];
    return `<section class="card card-pad span-6"><div class="page-actions"><div><h3>Invitaciones</h3><p class="muted compact">La persona debe tener una cuenta activa en Rhino CRM.</p></div>${!['CLOSED','CANCELLED'].includes(campaign.status)&&has('campaigns.invite')?'<button id="campaignInvite" class="button small primary">Invitar</button>':''}</div>${rows.length?`<div class="list">${rows.map(i=>`<div class="list-item"><div><strong>${esc(i.user_name||i.invited_name||i.user_email)}</strong><div class="meta">${esc(i.partner_name||i.invited_email||'')} · ${dateTime(i.created_at)}</div></div><div>${badge(i.status)} ${!['REVOKED','DECLINED'].includes(i.status)&&!['CLOSED','CANCELLED'].includes(campaign.status)?`<button class="button small danger invitation-revoke" data-id="${esc(i.id)}">Revocar</button>`:''}</div></div>`).join('')}</div>`:empty('Sin invitaciones','Invita por correo al colaborador que estará trabajando contigo.')}</section>`;
  }
  function campaignClosureHistory(campaign){
    const snap=campaign.closure_snapshot||{};if(campaign.status!=='CLOSED')return '';
    const totals=snap.totals||{};
    return `<section class="card card-pad"><h3>Cierre del evento</h3><div class="grid">${metric('Contactos asignados',totals.contacts_assigned||0,'Por dieta','span-3')}${metric('Dietas',totals.diets_generated||0,'Generadas','span-3')}${metric('Productos',totals.products_sold||0,'Vendidos','span-3')}${metric('Ingresos',money(totals.revenue_minor||0,campaign.currency),'Del evento','span-3 ok')}</div><label>Notas del evento<textarea readonly>${esc(campaign.closing_notes||snap.notes||'Sin notas.')}</textarea></label>${(snap.member_stats||[]).length?`<h4>Participación</h4><div class="table-wrap"><table><thead><tr><th>Persona</th><th>Registrados</th><th>Pesajes</th><th>Dietas / contactos</th><th>Ventas</th></tr></thead><tbody>${snap.member_stats.map(m=>`<tr><td>${esc(m.name)}</td><td>${Number(m.registered||0)}</td><td>${Number(m.measurements||0)}</td><td>${Number(m.diets||0)} / ${Number(m.contacts_owned||0)}</td><td>${Number(m.sales||0)} · ${money(m.revenue_minor||0,campaign.currency)}</td></tr>`).join('')}</tbody></table></div>`:''}</section>`;
  }
  function eventPartyPipelineHtml(pipeline){
    const stages=pipeline?.party_pipeline||[];
    if(!stages.length)return empty('Sin pipeline todavía','Los estados aparecerán cuando Kiosko sincronice pesajes del evento.');
    return `<section class="card card-pad event-party-pipeline"><div class="page-actions"><div><h3>Pipeline operativo del evento</h3><p class="muted compact">Del pesaje al contacto, dieta, respuesta positiva y compra.</p></div></div><div class="event-pipeline-flow">${stages.map((stage,index)=>`<article class="event-pipeline-stage"><span class="event-pipeline-number">${index+1}</span><div><small>${esc(stage.label)}</small><strong>${Number(stage.count||0)}</strong>${stage.conversion_from_previous===null||stage.conversion_from_previous===undefined?'':`<em>${Number(stage.conversion_from_previous).toFixed(1)}% avance</em>`}</div></article>`).join('<span class="event-pipeline-arrow">→</span>')}</div></section>`;
  }
  function eventProfileCell(p){
    const complete=Boolean(p.profile_complete)&&Boolean(p.whatsapp);
    const missing=(p.profile_missing_fields||[]).map(x=>({whatsapp:'WhatsApp',name:'nombre',age:'edad',gender:'género',height_cm:'estatura',profile:'perfil'}[x]||x));
    return `<td class="name-cell"><strong>${esc(p.name)}</strong><small>${p.whatsapp?esc(p.whatsapp):'WhatsApp pendiente'} · ${esc(p.public_code||'Sin código')}</small><span class="badge ${complete?'ok':'warn'}">${complete?'Perfil completo':'Perfil incompleto'}</span>${!complete&&missing.length?`<small>Falta: ${esc(missing.join(', '))}</small>`:''}</td>`;
  }
  function eventAssigneeControl(campaign,p){
    const current=p.assigned_advisor_id||p.owner_user_id||'';
    if(!campaign.can_manage||['CLOSED','CANCELLED'].includes(campaign.status))return esc(p.assigned_advisor_name||p.owner_name||'Sin asignar');
    const members=campaign.members||[];
    return `<select class="event-assignee" data-contact-id="${esc(p.contact_id)}"><option value="">Sin asignar</option>${members.map(m=>`<option value="${esc(m.id)}" ${m.id===current?'selected':''}>${esc(m.name)} · ${esc(campaignMemberRoleNames[m.member_role]||m.member_role)}</option>`).join('')}</select>`;
  }
  function eventPurchaseControl(campaign,p){
    if(Number(p.sale_count||0)>0||p.purchase_outcome==='PURCHASED')return `<span class="badge ok">Compró</span>${Number(p.sale_revenue_minor||0)>0?`<small>${money(p.sale_revenue_minor,campaign.currency)}</small>`:''}`;
    if(['CLOSED','CANCELLED'].includes(campaign.status)||!has('campaigns.collaborate'))return p.purchase_outcome==='NO_PURCHASE'?'No compró':'Sin dato';
    return `<select class="event-purchase-outcome" data-contact-id="${esc(p.contact_id)}"><option value="UNKNOWN" ${p.purchase_outcome!=='NO_PURCHASE'?'selected':''}>Sin dato</option><option value="NO_PURCHASE" ${p.purchase_outcome==='NO_PURCHASE'?'selected':''}>No compró</option></select>`;
  }
  function eventFollowupControl(campaign,p){
    const current=String(p.event_state?.commercial_status||p.commercial_status||'UNEXPLORED');
    if(['CLOSED','CANCELLED'].includes(campaign.status)||!has('campaigns.collaborate'))return esc(current==='NOT_INTERESTED_NOW'?'No interesado':current==='INTERESTED'?'Respuesta positiva':current==='EVALUATING'?'Evaluando':current==='PURCHASE_COMPLETED'?'Compra':'Pendiente');
    if(current==='PURCHASE_COMPLETED')return '<span class="badge ok">Compra</span>';
    return `<select class="event-followup-status" data-contact-id="${esc(p.contact_id)}"><option value="UNEXPLORED" ${current==='UNEXPLORED'?'selected':''}>Pendiente</option><option value="INTERESTED" ${current==='INTERESTED'?'selected':''}>Respondió positivo</option><option value="EVALUATING" ${current==='EVALUATING'?'selected':''}>Evaluando</option><option value="NOT_INTERESTED_NOW" ${current==='NOT_INTERESTED_NOW'?'selected':''}>No interesado</option></select>`;
  }
  async function openCampaignDetail(id){
    openModal('Evento','Cargando…','<div class="loading">Cargando…</div>');
    try{
      const [campaign,participants,pipeline,reportInfo,activity]=await Promise.all([
        request(`/campaigns/${encodeURIComponent(id)}`),
        request(`/campaigns/${encodeURIComponent(id)}/participants`),
        request(`/campaigns/${encodeURIComponent(id)}/pipeline`).catch(()=>({party_pipeline:[]})),
        has('campaigns.report')?request(`/campaigns/${encodeURIComponent(id)}/personal-reports`).catch(()=>({eligible_count:0,reasons:{},reason_labels:{},latest_batch:null})):Promise.resolve({eligible_count:0,reasons:{},reason_labels:{},latest_batch:null}),
        request(`/campaigns/${encodeURIComponent(id)}/activity`).catch(()=>[])
      ]);const r=campaign.report?.totals||{};const reportBatch=reportInfo.latest_batch||null;
      $('modalTitle').textContent=campaign.name;$('modalSubtitle').textContent=`${campaignTypeLabel(campaign.type)} · ${dateTime(campaign.starts_at)}`;
      $('modalBody').innerHTML=`<div class="event-detail-head"><div>${badge(campaign.display_status||campaign.status)} ${campaign.is_collaborator?'<span class="badge blue">Evento compartido</span>':''}</div><p>${esc(campaign.venue_name||campaign.address||'Lugar por definir')}${campaign.city?` · ${esc(campaign.city)}`:''}</p></div>
        <div class="grid">${metric('Personas',r.participants||participants.length,'Registradas','span-3')}${metric('Pesajes',r.measurements||participants.reduce((n,p)=>n+Number(p.measurements_count||0),0),'Vinculados','span-3 ok')}${metric('Perfiles completos',participants.filter(p=>p.profile_complete&&p.whatsapp).length,'Con WhatsApp','span-3 ok')}${metric('Sin contacto',participants.filter(p=>!(p.event_state?.whatsapp_captured)).length,'WhatsApp pendiente','span-3 warn')}${metric('Dietas',participants.filter(p=>p.nutrition_plan_id).length,'Generadas','span-3')}${metric('Ventas',r.sales||participants.reduce((n,p)=>n+Number(p.sale_count||0),0),'Atribuidas','span-3 ok')}${metric('Ingresos',money(r.revenue_minor||participants.reduce((n,p)=>n+Number(p.sale_revenue_minor||0),0),campaign.currency),'Atribuidos','span-3')}${metric('Meta',campaign.prospects_target||0,'Prospectos','span-3')}</div>
        ${eventPartyPipelineHtml(pipeline)}
        <div class="grid event-info"><section class="card card-pad span-6"><div class="page-actions"><div><h3>Party del evento</h3><p class="muted compact">El líder puede distribuir prospectos entre estos colaboradores.</p></div>${campaign.can_manage&&!['CLOSED','CANCELLED'].includes(campaign.status)?'<span class="badge blue">QR disponible en RHINO Kiosco</span>':''}</div>${campaignMembersHtml(campaign)}</section>${campaignInvitationsHtml(campaign)}<section class="card card-pad span-6"><h3>Básculas vinculadas</h3>${campaign.devices?.length?`<div class="list">${campaign.devices.map(d=>`<div class="list-item"><div><strong>${esc(d.mac_address)}</strong><div class="meta">${Number(d.total_measurements||0)} mediciones totales</div></div>${badge(d.status)}</div>`).join('')}</div>`:empty('Sin básculas','El registro manual sigue disponible.')}</section></div>
        ${campaignClosureHistory(campaign)}
        ${has('campaigns.report')?`<section class="card card-pad personal-report-panel"><div class="page-actions"><div><h3>Informes personalizados por WhatsApp</h3><p class="muted compact">Los informes individuales se envían únicamente a personas con consentimiento.</p></div><div>${badge(reportBatch?.status||'PENDING')}</div></div><div class="grid">${metric('Elegibles',reportInfo.eligible_count||0,'Listos para envío','span-3 ok')}${metric('Enviados',reportBatch?.sent_count||0,'Incluye entregados','span-3')}${metric('Entregados',reportBatch?.delivered_count||0,'Confirmados','span-3')}${metric('Fallidos',reportBatch?.failed_count||0,'Revisar cola','span-3')}</div></section>`:''}
        <h3>Prospectos del evento</h3><p class="muted compact">Solo los perfiles completos con WhatsApp verificado aparecen en verde.</p>${participants.length?`<div class="table-wrap"><table class="event-prospect-table"><thead><tr><th>Persona</th><th>Registrado por</th><th>Pesajes</th><th>Dieta</th><th>Asignado a</th><th>Compra</th><th>Atención</th><th></th></tr></thead><tbody>${participants.map(p=>`<tr class="${p.profile_complete&&p.whatsapp?'profile-complete-row':'profile-incomplete-row'}">${eventProfileCell(p)}<td>${esc(p.registered_by_name||'Kiosko')}</td><td>${Number(p.measurements_count||0)}</td><td>${p.nutrition_plan_id?badge(p.diet_delivery_status||'COMPLETED'):'Pendiente'}</td><td>${eventAssigneeControl(campaign,p)}</td><td class="event-purchase-cell">${eventPurchaseControl(campaign,p)}</td><td>${eventFollowupControl(campaign,p)}</td><td>${has('campaigns.collaborate')?`<button class="button small secondary campaign-contact-open" data-contact-id="${esc(p.contact_id)}">Abrir atención</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin personas','Registra personas manualmente o activa una báscula durante el evento.')}
        ${activity.length?`<details class="card card-pad"><summary><strong>Actividad del evento</strong></summary><div class="list">${activity.slice(0,30).map(a=>`<div class="list-item"><div><strong>${esc(eventActivityLabel(a.activity_type))}</strong><div class="meta">${esc(a.actor_name||'Sistema')}${a.contact_name?` · ${esc(a.contact_name)}`:''}</div></div><small>${dateTime(a.created_at)}</small></div>`).join('')}</div></details>`:''}
        <div class="form-actions event-actions">${has('campaigns.report')&&campaign.status==='CLOSED'&&Number(reportInfo.eligible_count||0)>0?`<button id="campaignSendReports" class="button primary">Enviar informes (${Number(reportInfo.eligible_count)})</button>`:''}${has('campaigns.participants.create')&&!['CLOSED','CANCELLED'].includes(campaign.status)?'<button id="campaignParticipant" class="button primary">Registrar persona</button>':''}${campaign.can_manage&&has('campaigns.update')&&!['CLOSED','CANCELLED'].includes(campaign.status)?'<button id="campaignEdit" class="button secondary">Editar</button>':''}${campaign.can_manage&&has('campaigns.activate')&&!['ACTIVE','CLOSED','CANCELLED'].includes(campaign.status)?'<button id="campaignActivate" class="button success">Activar evento</button>':''}${campaign.can_manage&&has('campaigns.close')&&campaign.status==='ACTIVE'?'<button id="campaignClose" class="button success">Revisar y cerrar</button>':''}${campaign.can_manage&&has('campaigns.update')&&!['CLOSED','CANCELLED'].includes(campaign.status)?'<button id="campaignCancel" class="button danger">Cancelar</button>':''}</div>`;
      document.querySelectorAll('.campaign-contact-open').forEach(button=>button.addEventListener('click',()=>openCampaignParticipantWorkspace(id,button.dataset.contactId)));
      document.querySelectorAll('.event-assignee').forEach(select=>select.addEventListener('change',async()=>{if(!select.value)return;select.disabled=true;try{await request(`/campaigns/${encodeURIComponent(id)}/participants/${encodeURIComponent(select.dataset.contactId)}/assignee`,{method:'PATCH',body:{user_id:select.value}});toast('Prospecto asignado.');openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');select.disabled=false;}}));
      document.querySelectorAll('.event-purchase-outcome').forEach(select=>select.addEventListener('change',async()=>{select.disabled=true;try{await request(`/campaigns/${encodeURIComponent(id)}/participants/${encodeURIComponent(select.dataset.contactId)}/purchase-outcome`,{method:'PATCH',body:{outcome:select.value}});toast('Resultado comercial actualizado.');openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');select.disabled=false;}}));
      document.querySelectorAll('.event-followup-status').forEach(select=>select.addEventListener('change',async()=>{const value=select.value,contactId=select.dataset.contactId;select.disabled=true;try{if(['INTERESTED','EVALUATING'].includes(value)){await request(`/campaigns/${encodeURIComponent(id)}/participants/${encodeURIComponent(contactId)}/responded`,{method:'POST',body:{}});}await request(`/campaigns/${encodeURIComponent(id)}/participants/${encodeURIComponent(contactId)}/commercial-status`,{method:'PATCH',body:{status:value}});toast(value==='INTERESTED'?'Respuesta positiva registrada.':'Seguimiento actualizado.');openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');select.disabled=false;}}));
      document.querySelectorAll('.invitation-revoke').forEach(button=>button.onclick=async()=>{if(!confirm('¿Revocar el acceso de esta persona al evento?'))return;try{await request(`/campaigns/${encodeURIComponent(id)}/invitations/${encodeURIComponent(button.dataset.id)}`,{method:'DELETE'});toast('Invitación revocada.');openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');}});
      $('campaignInvite')?.addEventListener('click',()=>openCampaignInviteForm(campaign));
      $('campaignSendReports')?.addEventListener('click',async()=>{const count=Number(reportInfo.eligible_count||0);if(!confirm(`¿Enviar ${count} informe${count===1?'':'s'} por WhatsApp?`))return;const button=$('campaignSendReports');button.disabled=true;try{const result=await request(`/campaigns/${encodeURIComponent(id)}/personal-reports/dispatch`,{method:'POST',body:{}});toast(result.message||'Informes programados.');openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');button.disabled=false;}});
      $('campaignParticipant')?.addEventListener('click',()=>openCampaignParticipantForm(campaign));
      $('campaignEdit')?.addEventListener('click',()=>openCampaignForm(campaign));
      $('campaignActivate')?.addEventListener('click',async()=>{try{await request(`/campaigns/${encodeURIComponent(id)}/activate`,{method:'POST',body:{}});toast('Evento activado; Kiosko ya puede atribuir pesajes.');state.campaigns=[];openCampaignDetail(id);}catch(e){toast(errorMessage(e),'bad');}});
      $('campaignClose')?.addEventListener('click',()=>openCampaignCloseForm(campaign));
      $('campaignCancel')?.addEventListener('click',async()=>{if(!confirm('¿Cancelar este evento?'))return;await request(`/campaigns/${encodeURIComponent(id)}/cancel`,{method:'POST',body:{}});toast('Evento cancelado.');state.campaigns=[];openCampaignDetail(id);});
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }
  async function openCampaignInviteForm(campaign){
    openModal('Invitar colaborador',campaign.name,`<form id="campaignInviteForm"><div class="form-grid"><label class="full">Correo de la persona<input name="email" type="email" required placeholder="socio@correo.com"></label></div><div class="inline-notice">Recibirá la invitación dentro de Rhino CRM. Al aceptar verá únicamente los datos de este evento mientras esté activo.</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Enviar invitación</button></div><div class="form-error" id="campaignInviteError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=()=>openCampaignDetail(campaign.id);
    $('campaignInviteForm').onsubmit=async e=>{e.preventDefault();const btn=e.submitter,fd=new FormData(e.currentTarget);btn.disabled=true;try{await request(`/campaigns/${encodeURIComponent(campaign.id)}/invitations`,{method:'POST',body:{email:String(fd.get('email')||'').trim()}});toast('Invitación enviada.');openCampaignDetail(campaign.id);}catch(err){$('campaignInviteError').textContent=errorMessage(err);btn.disabled=false;}};
  }
  async function openCampaignParticipantForm(campaign){
    openModal('Registrar persona',campaign.name,`<form id="participantForm"><div class="form-grid"><label>Nombre<input name="name" required></label>${phoneField()}<label>Edad<input name="age" type="number" min="1" max="130"></label><label>Estatura cm<input name="height_cm" type="number" min="30" max="300" step="0.1"></label><label class="full">Perfil o interés<input name="profile" placeholder="Interés principal"></label></div><div class="inline-notice"><strong>Registrado por:</strong> ${esc(state.me?.name||'Usuario actual')}. El responsable y propietario se asignarán automáticamente a quien genere la primera dieta.</div><p class="muted">Si el WhatsApp ya existe en la organización anfitriona, Rhino reutilizará el expediente.</p><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Registrar</button></div><div class="form-error" id="participantFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=()=>openCampaignDetail(campaign.id);
    $('participantForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),btn=e.submitter;btn.disabled=true;const body={name:fd.get('name'),whatsapp:normalizePhone(fd.get('whatsapp')),age:fd.get('age')?Number(fd.get('age')):null,height_cm:fd.get('height_cm')?Number(fd.get('height_cm')):null,profile:fd.get('profile')||null};try{const result=await request(`/campaigns/${encodeURIComponent(campaign.id)}/participants`,{method:'POST',body});toast('Persona registrada. El responsable continúa sin asignar hasta generar la dieta.');state.campaigns=[];openCampaignParticipantWorkspace(campaign.id,result.contact_id);}catch(err){$('participantFormError').textContent=errorMessage(err);btn.disabled=false;}};
  }
  function measurementMetricIcon(code){
    return {weight_kg:'⚖',bmi:'BMI',body_fat_percent:'◌',muscle_percent:'💪',lean_mass_kg:'◉',subcutaneous_fat_percent:'◌',visceral_fat:'◍',water_percent:'💧',skeletal_muscle_percent:'🦵',muscle_mass_kg:'💪',bone_mass_kg:'◆',protein_percent:'P',bmr_kcal:'⚡',metabolic_age:'⌛'}[code]||'•';
  }
  function measurementNumber(value,decimals=1){
    const number=Number(value);if(!Number.isFinite(number))return '—';
    return number.toLocaleString('es-MX',{minimumFractionDigits:Number(decimals||0),maximumFractionDigits:Number(decimals||0)});
  }
  function measurementValue(metric){return `${measurementNumber(metric?.value,metric?.decimals)}${metric?.unit?` ${esc(metric.unit)}`:''}`;}
  function measurementTrendHtml(metric){
    const trend=metric?.trend;if(!trend)return '';
    const arrow=trend.direction==='UP'?'↑':trend.direction==='DOWN'?'↓':'→';
    const sign=Number(trend.delta)>0?'+':'';
    return `<span class="measurement-trend ${String(trend.direction||'').toLowerCase()}">${arrow} ${sign}${measurementNumber(trend.delta,metric.decimals)}${metric.unit?` ${esc(metric.unit)}`:''}</span>`;
  }
  function measurementSparkline(points){
    if(!points||points.length<2)return '<div class="measurement-spark-empty">Sin comparación todavía</div>';
    const values=points.map(p=>Number(p.value)).filter(Number.isFinite);if(values.length<2)return '';
    const min=Math.min(...values),max=Math.max(...values),span=Math.max(max-min,0.01),width=240,height=66,pad=6;
    const coords=values.map((value,index)=>`${pad+(index/(values.length-1))*(width-pad*2)},${height-pad-((value-min)/span)*(height-pad*2)}`).join(' ');
    return `<svg class="measurement-spark" viewBox="0 0 ${width} ${height}" role="img" aria-label="Tendencia"><polyline points="${coords}" fill="none" vector-effect="non-scaling-stroke"/><circle cx="${coords.split(' ').at(-1).split(',')[0]}" cy="${coords.split(' ').at(-1).split(',')[1]}" r="3"/></svg>`;
  }
  function measurementGaugeHtml(scale){
    if(!scale)return '<div class="measurement-scale-missing">Agrega la estatura para mostrar el indicador visual de peso.</div>';
    const span=Math.max(.1,Number(scale.maximum)-Number(scale.minimum));
    const boundaries=(scale.segments||[]).filter(s=>s.boundary_kg!==null&&s.boundary_kg!==undefined).map(s=>({label:s.boundary_kg,left:Math.min(100,Math.max(0,((Number(s.boundary_kg)-Number(scale.minimum))/span)*100))}));
    const stops=[boundaries[0]?.left??25,boundaries[1]?.left??50,boundaries[2]?.left??75];
    const gradient=`linear-gradient(90deg,#51b4e8 0 ${stops[0]}%,#55c94d ${stops[0]}% ${stops[1]}%,#f4b52d ${stops[1]}% ${stops[2]}%,#ee4b57 ${stops[2]}% 100%)`;
    return `<div class="measurement-gauge" aria-label="Indicador visual de peso">
      <div class="measurement-gauge-boundaries">${boundaries.map(b=>`<span style="left:${b.left}%">${measurementNumber(b.label,1)}</span>`).join('')}</div>
      <div class="measurement-gauge-track" style="background:${gradient}"><span class="measurement-gauge-marker" style="left:${Number(scale.marker_percent||0)}%" title="${measurementNumber(scale.value,1)} kg"></span></div>
      <div class="measurement-gauge-labels">${(scale.segments||[]).map(segment=>`<span>${esc(segment.label)}</span>`).join('')}</div>
    </div>`;
  }
  function measurementMetricHtml(metric){
    return `<details class="measurement-metric tone-${esc(metric.tone||'neutral')}"><summary><span class="measurement-metric-icon">${esc(measurementMetricIcon(metric.code))}</span><span class="measurement-metric-name">${esc(metric.label)}${metric.derived?'<small>Estimado</small>':''}</span><span class="measurement-metric-reading">${measurementValue(metric)}${measurementTrendHtml(metric)}</span><span class="measurement-chevron">⌄</span></summary><div class="measurement-metric-detail"><p>${esc(metric.description||'')}</p>${metric.reference_label?`<p><strong>Rango visual:</strong> ${esc(metric.reference_label)}</p>`:''}${metric.trend?`<p><strong>Medición anterior:</strong> ${measurementNumber(metric.trend.previous_value,metric.decimals)}${metric.unit?` ${esc(metric.unit)}`:''}</p>`:''}</div></details>`;
  }
  function measurementHistoryHtml(dashboard){
    const series=(dashboard.series||[]).filter(s=>(s.points||[]).length>1);
    const history=dashboard.history||[];
    return `<div class="measurement-history-block"><div class="page-actions"><div><h3>Historial de pesajes</h3><p class="muted compact">Compara la evolución registrada por la báscula.</p></div><span class="badge blue">${Number(dashboard.summary?.measurements_count||history.length)} pesaje(s)</span></div>
      ${series.length?`<div class="measurement-series-grid">${series.map(s=>{const first=s.points[0],last=s.points.at(-1),delta=Number(last.value)-Number(first.value);return `<article class="measurement-series-card"><div><strong>${esc(s.label)}</strong><small>${measurementNumber(last.value,s.decimals)}${s.unit?` ${esc(s.unit)}`:''} · ${delta>0?'+':''}${measurementNumber(delta,s.decimals)}${s.unit?` ${esc(s.unit)}`:''}</small></div>${measurementSparkline(s.points)}</article>`;}).join('')}</div>`:''}
      ${history.length?`<details class="measurement-history-list"><summary>Ver mediciones registradas</summary><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Peso</th><th>IMC</th><th>Grasa</th><th>Fuente</th></tr></thead><tbody>${history.map(row=>{const by=Object.fromEntries((row.metrics||[]).map(m=>[m.code,m]));const source=row.source||{};return `<tr><td>${dateTime(row.measured_at)}</td><td>${by.weight_kg?measurementValue(by.weight_kg):'—'}</td><td>${by.bmi?measurementValue(by.bmi):'—'}</td><td>${by.body_fat_percent?measurementValue(by.body_fat_percent):'—'}</td><td>${esc(source.device_name||source.captured_by_name||'Kiosko')}</td></tr>`;}).join('')}</tbody></table></div></details>`:''}
    </div>`;
  }
  function measurementDashboardHtml(dashboard,{compact=false}={}){
    const latest=dashboard?.latest;if(!latest)return empty('Sin pesajes','Las mediciones vinculadas aparecerán aquí cuando Kiosko sincronice una báscula.');
    const metrics=latest.metrics||[],by=Object.fromEntries(metrics.map(m=>[m.code,m])),weight=by.weight_kg;
    const source=latest.source||{};
    const secondary=metrics.filter(m=>m.code!=='weight_kg');
    const visible=compact?secondary.slice(0,5):secondary;
    return `<div class="measurement-dashboard ${compact?'compact':''}">
      <section class="measurement-hero"><div class="measurement-hero-head"><div><span class="measurement-eyebrow">Último pesaje</span><div class="measurement-weight"><span class="measurement-weight-icon">⚖</span><strong>${weight?measurementValue(weight):'—'}</strong>${weight?.reference_label?`<span class="measurement-reference tone-${esc(weight.tone||'neutral')}">${esc(weight.reference_label)}</span>`:''}</div></div><div class="measurement-date">${dateTime(latest.measured_at)}</div></div>${measurementGaugeHtml(dashboard.weight_scale)}<p class="measurement-source">${esc(source.campaign_name||source.location_name||'Medición registrada')} · ${esc(source.device_name||source.captured_by_name||'Kiosko')}</p></section>
      <section class="measurement-metrics">${visible.length?visible.map(measurementMetricHtml).join(''):empty('Sin composición corporal','La báscula únicamente reportó el peso.')}</section>
      ${compact?'':measurementHistoryHtml(dashboard)}
      <p class="measurement-disclaimer">${esc(dashboard.presentation?.disclaimer||'Los resultados son informativos y dependen del modelo de báscula.')}</p>
    </div>`;
  }
  async function openCampaignParticipantWorkspace(campaignId,contactId){
    openModal('Atención del evento','Cargando persona…','<div class="loading">Cargando…</div>');
    try{
      const workspace=await request(`/campaigns/${encodeURIComponent(campaignId)}/participants/${encodeURIComponent(contactId)}`);const p=workspace.participant,n=workspace.nutrition||{},profile=n.profile||{},plans=n.plans||[],latest=workspace.measurements?.[0]||null,measurementDashboard=workspace.measurement_dashboard||null;
      $('modalTitle').textContent=p.name;$('modalSubtitle').textContent=`${workspace.campaign.name} · ${eventAttentionLabel(p.attention_status)}`;
      $('modalBody').innerHTML=`<div class="page-actions"><div><button id="backToCampaign" class="button small secondary">← Evento</button><p class="muted compact">${esc(p.whatsapp||'')} · ${esc(p.public_code||'Sin código')}</p></div>${badge(workspace.campaign.status)}</div>
        <div class="grid"><section class="card card-pad span-6"><h3>Responsabilidad</h3><div class="table-wrap"><table><tbody><tr><th>Registrado por</th><td>${esc(p.registered_by_name||'Kiosko')}</td></tr><tr><th>Responsable</th><td>${esc(p.owner_name||'Sin asignar')}</td></tr><tr><th>Regla</th><td>La primera persona que genera la dieta recibe el contacto y su seguimiento.</td></tr></tbody></table></div></section><section class="card card-pad span-6 event-measurement-card"><h3>Resultados del pesaje</h3>${measurementDashboardHtml(measurementDashboard,{compact:true})}</section></div>
        <section class="card card-pad"><h3>Perfil alimentario</h3><form id="eventNutritionProfileForm"><div class="form-grid">
          <label>Objetivo<select name="goal"><option value="">Selecciona…</option>${['Mejorar hábitos','Control gradual de peso','Mantener peso','Aumentar masa y apoyar actividad'].map(v=>`<option value="${esc(v)}" ${profile.goal===v?'selected':''}>${esc(v)}</option>`).join('')}</select></label>
          <label>Actividad<select name="activity_level"><option value="">Selecciona…</option>${['Baja','Moderada','Alta'].map(v=>`<option value="${v}" ${profile.activity_level===v?'selected':''}>${v}</option>`).join('')}</select></label>
          <label>Comidas por día<select name="meals_per_day">${[3,4,5].map(v=>`<option value="${v}" ${Number(profile.meals_per_day||4)===v?'selected':''}>${v}</option>`).join('')}</select></label>
          <label>Patrón<select name="dietary_pattern"><option value="">Omnívora</option><option value="Vegetariana" ${String(profile.dietary_pattern||'').toLowerCase().includes('vegetarian')?'selected':''}>Vegetariana</option><option value="Vegana" ${String(profile.dietary_pattern||'').toLowerCase().includes('vegan')?'selected':''}>Vegana</option></select></label>
          <label>Presupuesto<select name="budget_level"><option value="">Sin especificar</option><option value="LOW" ${profile.budget_level==='LOW'?'selected':''}>Bajo</option><option value="MEDIUM" ${profile.budget_level==='MEDIUM'?'selected':''}>Medio</option><option value="HIGH" ${profile.budget_level==='HIGH'?'selected':''}>Alto</option></select></label>
          <label>Tiempo para cocinar<input name="cooking_time_minutes" type="number" min="1" max="480" value="${esc(profile.cooking_time_minutes||'')}"></label>
          <label class="full">Preferencias<textarea name="preferences">${esc(nutritionArrayText(profile.preferences?.likes||profile.preferences?.preferred_foods||profile.preferences))}</textarea></label>
          <label class="full">Alimentos que evita<textarea name="avoid">${esc(nutritionArrayText(profile.preferences?.avoid||profile.preferences?.excluded_foods||[]))}</textarea></label>
          <label class="full">Alergias o intolerancias<textarea name="allergies">${esc(nutritionArrayText(profile.allergies))}</textarea></label>
          <label class="full">Condiciones declaradas<textarea name="health_flags">${esc(nutritionArrayText(profile.health_flags))}</textarea></label>
          <label class="full">Medicamentos<textarea name="medications">${esc(nutritionArrayText(profile.medications))}</textarea></label>
          <label class="full">Horarios y rutina<textarea name="schedule_preferences">${esc(nutritionArrayText(profile.schedule_preferences))}</textarea></label>
          <label class="full">Acceso a alimentos y cocina<textarea name="food_access_notes">${esc(profile.food_access_notes||'')}</textarea></label>
        </div>${workspace.can_edit?'<div class="form-actions"><button class="button primary">Guardar perfil</button></div>':''}<div class="form-error" id="eventNutritionProfileError"></div></form></section>
        <section class="card card-pad"><div class="page-actions"><div><h3>Dietas</h3><p class="muted compact">Al generar la primera dieta quedarás como propietario del contacto.</p></div>${workspace.can_edit&&latest?'<button id="eventGenerateDiet" class="button primary">Generar dieta y tomar seguimiento</button>':''}</div>${plans.length?`<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Estado</th><th>Entrega</th><th>Responsable</th><th></th></tr></thead><tbody>${plans.map(plan=>`<tr><td>${dateTime(plan.generated_at)}</td><td>${badge(plan.status)}</td><td>${badge(plan.delivery_status)}</td><td>${esc(p.owner_name||'—')}</td><td><button class="button small secondary event-plan-pdf" data-id="${esc(plan.id)}">PDF</button></td></tr>`).join('')}</tbody></table></div>`:empty('Sin dieta','Completa el perfil y genera la primera dieta.')}</section>
        <section class="card card-pad"><div class="page-actions"><div><h3>Ventas del evento</h3><p class="muted compact">La venta se atribuye a quien la registra; no cambia al propietario del contacto.</p></div>${workspace.can_edit&&has('campaigns.sales')?'<button id="eventNewSale" class="button success">Registrar venta</button>':''}</div>${workspace.sales?.length?`<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Vendedor</th><th>Total</th><th>Productos</th></tr></thead><tbody>${workspace.sales.map(s=>`<tr><td>${dateTime(s.closed_at||s.created_at)}</td><td>${esc(s.seller_name||'—')}</td><td>${money(s.total_revenue_minor,s.currency_snapshot||'MXN')}</td><td>${(s.items||[]).map(i=>`${Number(i.quantity)} ${esc(i.product_name_snapshot)}`).join(', ')}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin ventas','Registra las compras realizadas durante el evento.')}</section>`;
      $('backToCampaign').onclick=()=>openCampaignDetail(campaignId);
      if(!workspace.can_edit)$('eventNutritionProfileForm').querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=true);
      $('eventNutritionProfileForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget)),split=v=>String(v||'').split(/[,\n]+/).map(x=>x.trim()).filter(Boolean),btn=e.submitter;btn.disabled=true;const body={goal:f.goal||null,activity_level:f.activity_level||null,meals_per_day:Number(f.meals_per_day||4),dietary_pattern:f.dietary_pattern||null,budget_level:f.budget_level||null,cooking_time_minutes:f.cooking_time_minutes?Number(f.cooking_time_minutes):null,preferences:{likes:split(f.preferences),avoid:split(f.avoid)},allergies:split(f.allergies),health_flags:split(f.health_flags),medications:split(f.medications),schedule_preferences:split(f.schedule_preferences),food_access_notes:f.food_access_notes||null};try{await request(`/campaigns/${encodeURIComponent(campaignId)}/participants/${encodeURIComponent(contactId)}/nutrition-profile`,{method:'PUT',body});toast('Perfil alimentario guardado.');openCampaignParticipantWorkspace(campaignId,contactId);}catch(err){$('eventNutritionProfileError').textContent=errorMessage(err);btn.disabled=false;}};
      $('eventGenerateDiet')?.addEventListener('click',()=>openCampaignDietForm(campaignId,contactId,latest?.id||n.latest_measurement_id));
      document.querySelectorAll('.event-plan-pdf').forEach(button=>button.onclick=async()=>{try{const blob=await requestBlob(`/campaigns/${encodeURIComponent(campaignId)}/participants/${encodeURIComponent(contactId)}/plans/${encodeURIComponent(button.dataset.id)}/pdf?download=1`);downloadBlob(blob,`Plan_alimenticio_${String(p.name||'RHINO').replace(/\s+/g,'_')}.pdf`);toast('PDF generado.');}catch(e){toast(errorMessage(e),'bad');}});
      $('eventNewSale')?.addEventListener('click',()=>openCampaignSaleForm(campaignId,contactId,p.name));
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }
  async function openCampaignDietForm(campaignId,contactId,measurementId){
    let templates=[];try{const response=await request('/nutrition-engine/templates');templates=response?.items||response||[];}catch{}
    openModal('Generar dieta','Quedarás como propietario del contacto y responsable de su seguimiento.',`<form id="eventDietForm"><div class="form-grid"><label class="full">Plantilla<select name="template_id"><option value="">Automática según perfil</option>${templates.filter(t=>t.status==='ACTIVE').map(t=>`<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label></div><div class="inline-notice warn"><strong>Regla de propiedad:</strong> al confirmar, tu usuario quedará como responsable. Una segunda persona no podrá reemplazarte generando otra dieta.</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Confirmar y generar dieta</button></div><div class="form-error" id="eventDietError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=()=>openCampaignParticipantWorkspace(campaignId,contactId);
    $('eventDietForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),btn=e.submitter;btn.disabled=true;try{await request(`/campaigns/${encodeURIComponent(campaignId)}/participants/${encodeURIComponent(contactId)}/diet`,{method:'POST',body:{measurement_id:measurementId||null,template_id:fd.get('template_id')||null}});toast('Dieta generada. El contacto y su seguimiento quedaron a tu cargo.');state.campaigns=[];openCampaignParticipantWorkspace(campaignId,contactId);}catch(err){$('eventDietError').textContent=errorMessage(err);btn.disabled=false;}};
  }
  async function openCampaignSaleForm(campaignId,contactId,contactName){
    try{
      const products=await request(`/campaigns/${encodeURIComponent(campaignId)}/products`);
      if(!products.length){toast('No hay productos activos con inventario para este evento.','bad');return;}
      openModal('Registrar venta',contactName,`<form id="eventSaleForm"><div class="form-grid"><label class="full">Producto<select name="product_id" required>${products.map(p=>`<option value="${esc(p.id)}" data-price="${Number(p.base_price_minor||0)}">${esc(p.name)} · ${money(p.base_price_minor,p.currency)} · stock ${Number(p.stock||0)}</option>`).join('')}</select></label><label>Cantidad<input name="quantity" type="number" min="1" value="1" required></label><label>Método de pago<select name="payment_method"><option value="CASH">Efectivo</option><option value="CARD">Tarjeta</option><option value="TRANSFER">Transferencia</option><option value="OTHER">Otro</option></select></label></div><div class="inline-notice">La venta se acreditará a ${esc(state.me?.name||'usuario actual')}. No cambia al responsable nutricional.</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button success">Registrar venta</button></div><div class="form-error" id="eventSaleError"></div></form>`);
      $('modalBody').querySelector('[data-close]').onclick=()=>openCampaignParticipantWorkspace(campaignId,contactId);
      $('eventSaleForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),btn=e.submitter;btn.disabled=true;try{await request(`/campaigns/${encodeURIComponent(campaignId)}/participants/${encodeURIComponent(contactId)}/sales`,{method:'POST',headers:{'X-Idempotency-Key':uuid()},body:{payment_method:fd.get('payment_method'),cart:[{product_id:fd.get('product_id'),quantity:Number(fd.get('quantity')||1)}]}});toast('Venta registrada.');openCampaignParticipantWorkspace(campaignId,contactId);}catch(err){$('eventSaleError').textContent=errorMessage(err);btn.disabled=false;}};
    }catch(e){toast(errorMessage(e),'bad');}
  }
  async function openCampaignCloseForm(campaign){
    openModal('Cerrar evento','Revisa ventas, contactos asignados y agrega una nota opcional.','<div class="loading">Preparando cierre…</div>');
    try{
      const preview=await request(`/campaigns/${encodeURIComponent(campaign.id)}/close-preview`),t=preview.totals||{};
      $('modalBody').innerHTML=`<form id="campaignCloseForm"><div class="grid">${metric('Personas',t.participants||0,'Registradas','span-3')}${metric('Dietas',t.diets_generated||0,'Generadas','span-3')}${metric('Contactos',t.contacts_assigned||0,'Con responsable','span-3 ok')}${metric('Sin dieta',t.without_diet||0,'Quedarán sin propietario','span-3')}${metric('Ventas',t.sales||0,'Registradas','span-3')}${metric('Productos',t.products_sold||0,'Calculados','span-3')}${metric('Ingresos',money(t.revenue_minor||0,campaign.currency),'Del evento','span-3 ok')}</div>
        ${(preview.ownership_conflicts||[]).length?`<div class="inline-notice bad"><strong>No se puede cerrar todavía.</strong> Ya existe el mismo WhatsApp en la organización que recibiría estos contactos: ${(preview.ownership_conflicts||[]).map(x=>esc(x.contact_name)).join(', ')}.</div>`:''}
        ${(preview.without_diet||[]).length?`<div class="inline-notice warn"><strong>Sin dieta:</strong> ${(preview.without_diet||[]).map(x=>esc(x.contact_name)).join(', ')}. Permanecerán como registros del organizador sin responsable asignado.</div>`:''}
        <h3>Productos vendidos</h3>${(preview.products||[]).length?`<div class="table-wrap"><table><thead><tr><th>Producto</th><th>Calculado</th><th>Confirmar cantidad</th><th>Ingreso</th></tr></thead><tbody>${preview.products.map(row=>`<tr><td>${esc(row.product_name)}</td><td>${Number(row.quantity||0)}</td><td><input class="event-close-product" data-id="${esc(row.product_id)}" type="number" min="0" value="${Number(row.quantity||0)}"></td><td>${money(row.revenue_minor,campaign.currency)}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin productos vendidos','No se registraron ventas de productos.')}
        <label class="full">Notas del evento<textarea id="campaignClosingNotes" maxlength="10000" placeholder="Observaciones generales para consultar en el historial…">${esc(preview.closing_notes||'')}</textarea></label>
        <div class="inline-notice"><strong>Al cerrar:</strong> cada contacto con dieta se transferirá a la organización y al usuario que la generó. El capturista conservará el crédito de “Registrado por”.</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button success" ${(preview.ownership_conflicts||[]).length?'disabled':''}>Confirmar cierre</button></div><div class="form-error" id="campaignCloseError"></div></form>`;
      $('modalBody').querySelector('[data-close]').onclick=()=>openCampaignDetail(campaign.id);
      $('campaignCloseForm').onsubmit=async e=>{e.preventDefault();if(!confirm('¿Cerrar definitivamente el evento y asignar los contactos según la dieta?'))return;const btn=e.submitter;btn.disabled=true;const product_totals=[...document.querySelectorAll('.event-close-product')].map(x=>({product_id:x.dataset.id,confirmed_quantity:Number(x.value||0)}));try{await request(`/campaigns/${encodeURIComponent(campaign.id)}/close`,{method:'POST',body:{notes:$('campaignClosingNotes').value.trim()||null,product_totals}});toast('Evento cerrado. Los contactos fueron asignados según la dieta.');state.campaigns=[];openCampaignDetail(campaign.id);}catch(err){$('campaignCloseError').textContent=errorMessage(err);btn.disabled=false;}};
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }


  function nutritionArrayText(value){
    if(!value)return '';
    if(Array.isArray(value))return value.map(x=>typeof x==='string'?x:(x.display||x.name||'')).filter(Boolean).join(', ');
    if(typeof value==='object')return Object.entries(value).filter(([,v])=>Boolean(v)).map(([k])=>k).join(', ');
    return String(value);
  }
  function nutritionGoalLabel(value){return {GENERAL_WELLNESS:'Mejora de hábitos',MAINTAIN:'Mantenimiento',REDUCE:'Control gradual de peso',GAIN:'Apoyo a actividad'}[String(value||'').toUpperCase()]||value||'—';}
  function nutritionSafetyLabel(value){return {AUTOMATIC_GENERAL_GUIDANCE:'Orientación automática general',DATA_INCOMPLETE:'Datos incompletos',PROFESSIONAL_REVIEW_RECOMMENDED:'Revisión profesional recomendada'}[String(value||'').toUpperCase()]||value||'—';}
  function nutritionMealTypeLabel(value){return {BREAKFAST:'Desayuno',SNACK:'Colación',LUNCH:'Comida',DINNER:'Cena',BEVERAGE:'Bebida',COMPONENT:'Componente'}[String(value||'').toUpperCase()]||value||'—';}
  function nutritionModuleTabs(active='plans'){
    const tabs=isSuper()
      ? [['meals','#nutrition/meals','Comidas'],['templates','#nutrition/templates','Plantillas de dietas']]
      : [['plans','#nutrition','Personas y planes'],['meals','#nutrition/meals','Comidas'],['templates','#nutrition/templates','Plantillas de dietas']];
    return `<nav class="nutrition-module-tabs">${tabs.map(([id,href,label])=>`<a href="${href}" class="${active===id?'active':''}">${esc(label)}</a>`).join('')}</nav>`;
  }

  async function renderNutrition(section=null){
    if(section==='meals'){await renderNutritionMeals();return;}
    if(section==='templates'){await renderNutritionTemplates();return;}
    if(isSuper()&&!section){await renderNutritionMeals();return;}
    if(section){await renderNutritionContact(section);return;}
    await renderNutritionHome();
  }

  async function renderNutritionHome(){
    setHeader('RHINO Nutrición','Planes personalizados, biblioteca de comidas y plantillas editables.');
    const [summary,contacts,batches]=await Promise.all([
      request('/nutrition-engine/summary'),
      request('/contacts?limit=80'),
      has('nutrition.ai.manage')?request('/nutrition-engine/ai/batches?limit=20'):Promise.resolve([])
    ]);
    state.contacts=contacts.items||[];
    content.innerHTML=`${nutritionModuleTabs('plans')}<div class="grid">
      ${metric('Comidas aprobadas',summary.library?.approved||0,'Disponibles para planes','span-3 ok')}
      ${metric('Borradores',summary.library?.drafts||0,'Pendientes de aprobación','span-3 warn')}
      ${metric('Planes actuales',summary.plans?.current||0,'Personas con plan vigente','span-3')}
      ${metric('Revisión requerida',summary.plans?.review_required||0,'Casos que requieren validación','span-3 bad')}
      <section class="card card-pad span-6 nutrition-admin-card"><div><span class="nutrition-admin-icon">🍽</span><h2>Administrar comidas</h2><p class="muted">Agrega, edita, duplica, aprueba, archiva o elimina preparaciones de tu organización.</p></div><a href="#nutrition/meals" class="button primary">Abrir módulo de comidas</a></section>
      <section class="card card-pad span-6 nutrition-admin-card"><div><span class="nutrition-admin-icon">▤</span><h2>Plantillas de dietas</h2><p class="muted">Edita reglas del motor y el contenido visual que se imprime en los PDF.</p></div><a href="#nutrition/templates" class="button primary">Abrir plantillas</a></section>
      <section class="card card-pad span-12"><div class="page-actions"><div><h2>Personas y planes</h2><p class="muted compact">Selecciona un expediente para completar su perfil y generar un plan a partir de sus pesajes.</p></div></div>
        <div class="filters two"><input id="nutritionContactSearch" placeholder="Buscar nombre, WhatsApp o código RHINO"><button id="nutritionContactFilter" class="button secondary">Buscar</button></div>
        <div id="nutritionContactResults">${nutritionContactTable(state.contacts)}</div></section>
      ${has('nutrition.ai.manage')?`<section class="card card-pad span-12"><div class="page-actions"><div><h2>Laboratorio de prompts</h2><p class="muted compact">Crea propuestas nuevas para la biblioteca; solo lo aprobado entra en los planes.</p></div><button id="newNutritionAiBatch" class="button primary">Crear prompt</button></div>${nutritionAiBatchTable(batches)}</section>`:''}
    </div>`;
    bindNutritionContactTable();
    $('nutritionContactFilter').onclick=async()=>{const q=new URLSearchParams({limit:'80'});const search=$('nutritionContactSearch').value.trim();if(search)q.set('search',search);const result=await request('/contacts?'+q);state.contacts=result.items||[];$('nutritionContactResults').innerHTML=nutritionContactTable(state.contacts);bindNutritionContactTable();};
    $('nutritionContactSearch').addEventListener('keydown',e=>{if(e.key==='Enter')$('nutritionContactFilter').click();});
    $('newNutritionAiBatch')?.addEventListener('click',openNutritionAiBatchForm);
    document.querySelectorAll('.nutrition-ai-open').forEach(b=>b.onclick=()=>openNutritionAiBatch(b.dataset.id));
  }

  async function renderNutritionMeals(){
    setHeader('Comidas','Módulo específico para agregar, editar, aprobar, duplicar y retirar preparaciones.');
    const result=await request('/nutrition-engine/pills?limit=300');
    state.nutritionPills=result.items||[];
    const libraryHelp=isSuper()
      ? 'Como administrador global puedes agregar, editar, aprobar y retirar comidas globales. Las comidas usadas en planes se archivan para conservar el historial.'
      : 'Las comidas globales se pueden consultar y duplicar. Las comidas de tu organización se pueden editar o retirar.';
    content.innerHTML=`${nutritionModuleTabs('meals')}<div class="page-actions"><div><h2>Biblioteca de comidas</h2><p class="muted compact">${esc(libraryHelp)}</p></div>${has('nutrition.pills.manage')?'<button id="newNutritionPill" class="button primary">Agregar comida</button>':''}</div>
      <div class="filters"><input id="nutritionPillSearch" placeholder="Buscar preparación"><select id="nutritionPillType"><option value="">Todos los tipos</option><option value="BREAKFAST">Desayunos</option><option value="SNACK">Colaciones</option><option value="LUNCH">Comidas</option><option value="DINNER">Cenas</option><option value="BEVERAGE">Bebidas</option><option value="COMPONENT">Componentes</option></select><select id="nutritionPillStatus"><option value="">Todos los estados</option><option value="APPROVED">Aprobadas</option><option value="DRAFT">Borradores</option><option value="ARCHIVED">Archivadas</option></select><button id="nutritionPillFilter" class="button secondary">Filtrar</button></div>
      <div id="nutritionPillResults">${nutritionPillTable(state.nutritionPills)}</div>`;
    bindNutritionPillTable();
    $('newNutritionPill')?.addEventListener('click',()=>openNutritionPillForm());
    $('nutritionPillFilter').onclick=async()=>{const q=new URLSearchParams({limit:'300'});if($('nutritionPillSearch').value.trim())q.set('search',$('nutritionPillSearch').value.trim());if($('nutritionPillType').value)q.set('meal_type',$('nutritionPillType').value);if($('nutritionPillStatus').value)q.set('status',$('nutritionPillStatus').value);const data=await request('/nutrition-engine/pills?'+q);state.nutritionPills=data.items||[];$('nutritionPillResults').innerHTML=nutritionPillTable(state.nutritionPills);bindNutritionPillTable();};
    $('nutritionPillSearch').addEventListener('keydown',e=>{if(e.key==='Enter')$('nutritionPillFilter').click();});
  }

  async function renderNutritionTemplates(){
    setHeader('Plantillas de dietas','Reglas de generación y diseño editable del PDF alimenticio.');
    const result=await request('/nutrition-engine/templates?status=ALL&limit=200');
    state.nutritionTemplates=result.items||[];
    content.innerHTML=`${nutritionModuleTabs('templates')}<div class="page-actions"><div><h2>Plantillas de dietas</h2><p class="muted compact">Las plantillas globales son base de solo lectura. Duplica una para personalizar reglas, textos y secciones del PDF.</p></div>${has('nutrition.templates.manage')?'<button id="newNutritionTemplate" class="button primary">Nueva plantilla</button>':''}</div>
      <div class="filters three"><input id="nutritionTemplateSearch" placeholder="Buscar plantilla"><select id="nutritionTemplateStatus"><option value="ALL">Todos los estados</option><option value="ACTIVE">Activas</option><option value="DRAFT">Borradores</option><option value="ARCHIVED">Archivadas</option></select><select id="nutritionTemplateOrigin"><option value="">Todos los orígenes</option><option value="PARTNER">Mi organización</option><option value="GLOBAL">Globales</option></select><button id="nutritionTemplateFilter" class="button secondary">Filtrar</button></div>
      <div id="nutritionTemplateResults">${nutritionTemplateTable(state.nutritionTemplates)}</div>`;
    bindNutritionTemplateTable();
    $('newNutritionTemplate')?.addEventListener('click',()=>openNutritionTemplateForm());
    $('nutritionTemplateFilter').onclick=async()=>{const q=new URLSearchParams({status:$('nutritionTemplateStatus').value||'ALL',limit:'200'});if($('nutritionTemplateSearch').value.trim())q.set('search',$('nutritionTemplateSearch').value.trim());const data=await request('/nutrition-engine/templates?'+q);let rows=data.items||[];const origin=$('nutritionTemplateOrigin').value;if(origin==='PARTNER')rows=rows.filter(x=>x.partner_id);if(origin==='GLOBAL')rows=rows.filter(x=>!x.partner_id);state.nutritionTemplates=rows;$('nutritionTemplateResults').innerHTML=nutritionTemplateTable(rows);bindNutritionTemplateTable();};
    $('nutritionTemplateSearch').addEventListener('keydown',e=>{if(e.key==='Enter')$('nutritionTemplateFilter').click();});
  }

  function nutritionContactTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Persona</th><th>Etapa</th><th>Pesajes</th><th>Último pesaje</th><th></th></tr></thead><tbody>${rows.map(c=>`<tr><td class="name-cell"><strong>${esc(c.name)}</strong><small>${esc(c.public_code||c.whatsapp)}</small></td><td>${badge(c.sales_stage)}</td><td>${Number(c.total_measurements||0)}</td><td>${dateTime(c.last_measurement_at)}</td><td><button class="button small primary nutrition-contact-open" data-id="${esc(c.id)}">Nutrición</button></td></tr>`).join('')}</tbody></table></div>`:empty('Sin personas','Registra un prospecto y vincula un pesaje para generar un plan.');}
  function bindNutritionContactTable(){document.querySelectorAll('.nutrition-contact-open').forEach(b=>b.onclick=()=>{location.hash=`#nutrition/${encodeURIComponent(b.dataset.id)}`;});}

  function nutritionPillTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Preparación</th><th>Tipo</th><th>Porción</th><th>Origen</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${rows.map(p=>`<tr><td class="name-cell"><strong>${esc((p.emoji||'')+' '+p.name)}</strong><small>${esc((p.allergens||[]).join(', ')||'Sin alérgenos declarados')}</small></td><td>${esc(nutritionMealTypeLabel(p.meal_type))}</td><td>${esc(p.portion_text||'—')}</td><td>${esc(p.partner_id?'Mi organización':'Global')}</td><td>${badge(p.status)}</td><td class="nutrition-row-actions"><button class="button small secondary nutrition-pill-view" data-id="${esc(p.id)}">Ver</button>${has('nutrition.pills.manage')?` <button class="button small secondary nutrition-pill-duplicate" data-id="${esc(p.id)}">Duplicar</button>`:''}${p.editable&&has('nutrition.pills.manage')?` <button class="button small secondary nutrition-pill-edit" data-id="${esc(p.id)}">Editar</button>${p.status==='DRAFT'?` <button class="button small success nutrition-pill-approve" data-id="${esc(p.id)}">Aprobar</button>`:''}${p.status!=='ARCHIVED'?` <button class="button small danger nutrition-pill-delete" data-id="${esc(p.id)}">Quitar</button>`:''}`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Biblioteca vacía','Ejecuta la carga inicial o agrega una comida.');}
  function bindNutritionPillTable(){
    document.querySelectorAll('.nutrition-pill-view').forEach(b=>b.onclick=()=>openNutritionPillDetail(b.dataset.id));
    document.querySelectorAll('.nutrition-pill-edit').forEach(b=>b.onclick=()=>openNutritionPillForm(state.nutritionPills.find(p=>p.id===b.dataset.id)));
    document.querySelectorAll('.nutrition-pill-approve').forEach(b=>b.onclick=async()=>{try{await request(`/nutrition-engine/pills/${encodeURIComponent(b.dataset.id)}/approve`,{method:'POST',body:{}});toast('Comida aprobada.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
    document.querySelectorAll('.nutrition-pill-duplicate').forEach(b=>b.onclick=async()=>{try{await request(`/nutrition-engine/pills/${encodeURIComponent(b.dataset.id)}/duplicate`,{method:'POST',body:{}});toast('Comida duplicada como borrador.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
    document.querySelectorAll('.nutrition-pill-delete').forEach(b=>b.onclick=async()=>{if(!confirm('¿Quitar esta comida? Si ya fue usada en un plan se archivará para conservar el historial.'))return;try{const result=await request(`/nutrition-engine/pills/${encodeURIComponent(b.dataset.id)}`,{method:'DELETE'});toast(result.deletion_mode==='DELETED'?'Comida eliminada.':'Comida archivada porque tiene historial.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
  }

  function nutritionTemplateTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Plantilla</th><th>Objetivos</th><th>Comidas/día</th><th>Origen</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${rows.map(t=>`<tr><td class="name-cell"><strong>${esc(t.name)}</strong><small>${esc(t.description||t.code)}</small></td><td>${esc((t.compatible_goals||[]).map(nutritionGoalLabel).join(', ')||'General')}</td><td>${Number(t.rules?.default_meals_per_day||4)}</td><td>${esc(t.partner_id?'Mi organización':'Global')}</td><td>${badge(t.status)}</td><td class="nutrition-row-actions"><button class="button small secondary nutrition-template-view" data-id="${esc(t.id)}">Ver</button>${has('nutrition.templates.manage')?` <button class="button small secondary nutrition-template-duplicate" data-id="${esc(t.id)}">Duplicar</button>`:''}${t.editable&&has('nutrition.templates.manage')?` <button class="button small secondary nutrition-template-edit" data-id="${esc(t.id)}">Editar</button>${t.status==='DRAFT'?` <button class="button small success nutrition-template-activate" data-id="${esc(t.id)}">Activar</button>`:''}${t.status!=='ARCHIVED'?` <button class="button small danger nutrition-template-delete" data-id="${esc(t.id)}">Quitar</button>`:''}`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin plantillas','Crea una plantilla o duplica una global.');}
  function bindNutritionTemplateTable(){
    document.querySelectorAll('.nutrition-template-view').forEach(b=>b.onclick=()=>openNutritionTemplateDetail(b.dataset.id));
    document.querySelectorAll('.nutrition-template-edit').forEach(b=>b.onclick=()=>openNutritionTemplateForm(state.nutritionTemplates.find(t=>t.id===b.dataset.id)));
    document.querySelectorAll('.nutrition-template-activate').forEach(b=>b.onclick=async()=>{try{await request(`/nutrition-engine/templates/${encodeURIComponent(b.dataset.id)}/activate`,{method:'POST',body:{}});toast('Plantilla activada.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
    document.querySelectorAll('.nutrition-template-duplicate').forEach(b=>b.onclick=async()=>{try{await request(`/nutrition-engine/templates/${encodeURIComponent(b.dataset.id)}/duplicate`,{method:'POST',body:{}});toast('Plantilla duplicada como borrador.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
    document.querySelectorAll('.nutrition-template-delete').forEach(b=>b.onclick=async()=>{if(!confirm('¿Quitar esta plantilla? Si tiene planes asociados se archivará para conservar el historial.'))return;try{const result=await request(`/nutrition-engine/templates/${encodeURIComponent(b.dataset.id)}`,{method:'DELETE'});toast(result.deletion_mode==='DELETED'?'Plantilla eliminada.':'Plantilla archivada porque tiene historial.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
  }

  function nutritionAiBatchTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Lote</th><th>Estado</th><th>Propuestas</th><th>Creado</th><th></th></tr></thead><tbody>${rows.map(b=>`<tr><td class="name-cell"><strong>${esc(b.title)}</strong><small>${esc(b.criteria?.meal_type||'')}</small></td><td>${badge(b.status)}</td><td>${Number(b.validation_summary?.valid||0)} válidas · ${Number(b.validation_summary?.invalid||0)} rechazadas</td><td>${dateTime(b.created_at)}</td><td><button class="button small secondary nutrition-ai-open" data-id="${esc(b.id)}">Abrir</button></td></tr>`).join('')}</tbody></table></div>`:empty('Sin prompts','Crea el primer lote para ampliar la biblioteca.');}

  async function renderNutritionContact(contactId){
    setHeader('Plan alimentario','Perfil, pesajes, generación autónoma y versiones.');
    const [workspace,templates]=await Promise.all([request(`/nutrition-engine/contacts/${encodeURIComponent(contactId)}`),request('/nutrition-engine/templates')]);
    const templateRows=templates?.items||templates||[];state.nutritionTemplates=templateRows;const c=workspace.contact,p=workspace.profile,s=workspace.safety;
    const reviewClass=s.requires_professional_review?'bad':(s.missing_fields?.length?'warn':'ok');
    content.innerHTML=`<div class="page-actions"><div><a href="#nutrition" class="button secondary small">← Nutrición</a><h2>${esc(c.name)}</h2><p class="muted compact">${esc(c.public_code||'Sin código')} · ${Number(c.total_measurements||0)} pesajes</p></div>${has('nutrition.plans.generate')&&c.total_measurements>0?'<div class="form-actions"><button id="generateQuickNutritionPlan" class="button success">⚡ Plan rápido del pesaje</button><button id="generateNutritionPlan" class="button primary">Plan completo</button></div>':''}</div>
      <div class="grid"><section class="card card-pad span-8"><h2>Perfil alimentario</h2><form id="nutritionProfileForm"><div class="form-grid">
        <label>Objetivo<select name="goal"><option value="">Selecciona…</option>${[['Mejorar hábitos','Mejorar hábitos'],['Control gradual de peso','Control gradual de peso'],['Mantener peso','Mantener peso'],['Aumentar masa y apoyar actividad','Aumentar masa y apoyar actividad']].map(([v,l])=>`<option value="${esc(v)}" ${p.goal===v?'selected':''}>${esc(l)}</option>`).join('')}</select></label>
        <label>Actividad<select name="activity_level"><option value="">Selecciona…</option>${['Baja','Moderada','Alta'].map(v=>`<option ${p.activity_level===v?'selected':''}>${v}</option>`).join('')}</select></label>
        <label>Comidas por día<select name="meals_per_day">${[3,4,5].map(v=>`<option value="${v}" ${Number(p.meals_per_day||4)===v?'selected':''}>${v}</option>`).join('')}</select></label>
        <label>Patrón<select name="dietary_pattern"><option value="">Omnívora</option><option value="Vegetariana" ${String(p.dietary_pattern).toLowerCase().includes('vegetarian')?'selected':''}>Vegetariana</option><option value="Vegana" ${String(p.dietary_pattern).toLowerCase().includes('vegan')?'selected':''}>Vegana</option></select></label>
        <label>Presupuesto<select name="budget_level"><option value="">Sin especificar</option><option value="LOW" ${p.budget_level==='LOW'?'selected':''}>Bajo</option><option value="MEDIUM" ${p.budget_level==='MEDIUM'?'selected':''}>Medio</option><option value="HIGH" ${p.budget_level==='HIGH'?'selected':''}>Alto</option></select></label>
        <label>Tiempo máximo para cocinar<input name="cooking_time_minutes" type="number" min="1" max="480" value="${esc(p.cooking_time_minutes||'')}" placeholder="Minutos"></label>
        <label class="full">Preferencias<textarea name="preferences" placeholder="Ej. pollo, frijoles, comida mexicana">${esc(nutritionArrayText(p.preferences?.likes||p.preferences?.preferred_foods||[]))}</textarea></label>
        <label class="full">Alimentos que evita<textarea name="avoid" placeholder="Ej. cebolla, pescado">${esc(nutritionArrayText(p.preferences?.avoid||p.preferences?.excluded_foods||[]))}</textarea></label>
        <label class="full">Alergias o intolerancias<textarea name="allergies" placeholder="Ej. lactosa, cacahuate">${esc(nutritionArrayText(p.allergies))}</textarea></label>
        <label class="full">Condiciones o alertas declaradas<textarea name="health_flags" placeholder="Solo datos declarados; no sustituye valoración profesional">${esc(nutritionArrayText(p.health_flags))}</textarea></label>
        <label class="full">Medicamentos declarados<textarea name="medications">${esc(nutritionArrayText(p.medications))}</textarea></label>
        <label class="full">Horarios y rutina<textarea name="schedule_preferences">${esc(nutritionArrayText(p.schedule_preferences))}</textarea></label>
        <label class="full">Acceso a alimentos y cocina<textarea name="food_access_notes">${esc(p.food_access_notes||'')}</textarea></label>
      </div><div class="form-actions"><button class="button primary">Guardar perfil</button></div><div class="form-error" id="nutritionProfileError"></div></form></section>
      <aside class="card card-pad span-4"><h2>Evaluación del motor</h2><div class="inline-notice ${reviewClass}"><strong>${esc(nutritionSafetyLabel(s.status))}</strong><div>${esc((s.reasons||[]).join(' ')||(s.missing_fields?.length?'Faltan: '+s.missing_fields.join(', '):'El modo automático general está disponible.'))}</div></div><p><strong>Último pesaje:</strong><br>${dateTime(workspace.latest_measurement_at)}</p><p><strong>Planes registrados:</strong> ${workspace.plans.length}</p></aside>
      <section class="card card-pad span-12"><h2>Historial de planes</h2>${nutritionPlanTable(workspace.plans)}</section></div>`;
    $('nutritionProfileForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));const split=v=>String(v||'').split(/[,\n]+/).map(x=>x.trim()).filter(Boolean);const body={goal:f.goal||null,activity_level:f.activity_level||null,meals_per_day:Number(f.meals_per_day||4),dietary_pattern:f.dietary_pattern||null,budget_level:f.budget_level||null,cooking_time_minutes:f.cooking_time_minutes?Number(f.cooking_time_minutes):null,preferences:{likes:split(f.preferences),avoid:split(f.avoid)},allergies:split(f.allergies),health_flags:split(f.health_flags),medications:split(f.medications),schedule_preferences:split(f.schedule_preferences),food_access_notes:f.food_access_notes||null};const btn=e.submitter;btn.disabled=true;try{await request(`/nutrition-engine/contacts/${encodeURIComponent(contactId)}/profile`,{method:'PUT',body});toast('Perfil guardado.');renderNutritionContact(contactId);}catch(err){$('nutritionProfileError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
    $('generateQuickNutritionPlan')?.addEventListener('click',()=>generateQuickNutritionPlan(contactId,workspace.latest_measurement_id));
    $('generateNutritionPlan')?.addEventListener('click',()=>openNutritionGenerateForm(contactId,workspace.latest_measurement_id,templateRows));
    document.querySelectorAll('.nutrition-plan-open').forEach(b=>b.onclick=()=>openNutritionPlanDetail(b.dataset.id));
    document.querySelectorAll('.nutrition-plan-delivered').forEach(b=>b.onclick=async()=>{await request(`/nutrition-engine/plans/${encodeURIComponent(b.dataset.id)}/delivery`,{method:'PATCH',body:{status:'DELIVERED'}});toast('Plan marcado como entregado.');renderNutritionContact(contactId);});
  }
  function nutritionPlanTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Plantilla</th><th>Pesaje</th><th>Seguridad</th><th>Entrega</th><th></th></tr></thead><tbody>${rows.map(p=>`<tr><td>${dateTime(p.generated_at)}</td><td>${esc(p.template_name||p.plan_snapshot?.template_name||'Plan RHINO')}</td><td>${esc(p.measurement_id)}</td><td>${badge(p.approval_status==='REVIEW_REQUIRED'?'PENDING':'COMPLETED')}</td><td>${badge(p.delivery_status)}</td><td><button class="button small secondary nutrition-plan-open" data-id="${esc(p.id)}">Ver plan</button>${has('nutrition.plans.update')&&!['DELIVERED','READ'].includes(p.delivery_status)?` <button class="button small success nutrition-plan-delivered" data-id="${esc(p.id)}">Marcar entregado</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin planes','Completa el perfil y genera el primer plan.');}
  function openNutritionGenerateForm(contactId,measurementId,templates){openModal('Generar plan alimentario','El motor usará el pesaje más reciente, el perfil y únicamente píldoras aprobadas.',`<form id="nutritionGenerateForm"><div class="form-grid"><label class="full">Plantilla<select name="template_id"><option value="">Automática según perfil</option>${(templates||[]).map(t=>`<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select></label></div><div class="inline-notice">Cada generación crea una versión nueva y conserva la anterior. Los casos con alertas quedan marcados para revisión.</div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Generar</button></div><div class="form-error" id="nutritionGenerateError"></div></form>`);$('modalBody').querySelector('[data-close]').onclick=closeModal;$('nutritionGenerateForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));const btn=e.submitter;btn.disabled=true;try{const plan=await request('/nutrition-engine/plans/generate',{method:'POST',body:{contact_id:contactId,measurement_id:measurementId||null,template_id:f.template_id||null}});toast('Plan generado.');closeModal();await renderNutritionContact(contactId);openNutritionPlanDetail(plan.id);}catch(err){$('nutritionGenerateError').textContent=errorMessage(err);}finally{btn.disabled=false;}};}
  async function generateQuickNutritionPlan(contactId,measurementId){
    if(!measurementId){toast('No hay un pesaje disponible para generar el plan rápido.','bad');return;}
    try{
      const plan=await request(`/nutrition-engine/measurements/${encodeURIComponent(measurementId)}/quick-nutrition`,{method:'POST',body:{}});
      toast('Plan rápido generado con los datos del pesaje.');
      await renderNutritionContact(contactId);
      openNutritionPlanDetail(plan.id);
    }catch(e){toast(errorMessage(e),'bad');}
  }
  function nutritionRecipeCards(items){const seen=new Set(),recipes=[];(items||[]).forEach(item=>{if(seen.has(item.pill_id))return;seen.add(item.pill_id);recipes.push(item.snapshot||{});});return recipes.length?`<div class="recipe-grid">${recipes.map(r=>`<article class="nutrition-recipe"><h4>${esc(r.emoji||'🍽️')} ${esc(r.name||'Preparación')}</h4><p><strong>Porción:</strong> ${esc(r.portion_text||'Según el plan')}</p><strong>Ingredientes</strong><ul>${(r.ingredients||[]).map(i=>`<li>${esc(typeof i==='string'?i:(i.display||i.name||''))}</li>`).join('')}</ul><p><strong>Preparación:</strong> ${esc(r.preparation||'Preparar con una cocción sencilla.')}</p></article>`).join('')}</div>`:empty('Sin recetario','Esta versión no contiene preparaciones detalladas.');}
  async function openNutritionPlanDetail(id){
    openModal('Plan alimenticio','Cargando versión…','<div class="loading">Cargando…</div>');
    try{
      const plan=await request(`/nutrition-engine/plans/${encodeURIComponent(id)}`),snap=plan.plan_snapshot||{};
      $('modalTitle').textContent=snap.title||'Plan alimenticio';$('modalSubtitle').textContent=`${dateTime(plan.generated_at)} · ${esc(plan.algorithm_version)}`;
      $('modalBody').innerHTML=`<div class="kpi-strip"><div class="kpi-chip"><strong>${esc(nutritionGoalLabel(snap.goal))}</strong><small>Objetivo</small></div><div class="kpi-chip"><strong>${Number(snap.meals_per_day||0)}</strong><small>Comidas al día</small></div><div class="kpi-chip"><strong>${esc(statusNames[plan.delivery_status]||plan.delivery_status)}</strong><small>Entrega</small></div></div>${snap.adjustment_reason?`<div class="inline-notice"><strong>Ajuste:</strong> ${esc(snap.adjustment_reason)}</div>`:''}${nutritionWeeklyPlanHtml(snap.weekly_plan||[])}<h3>🍲 Recetas</h3>${nutritionRecipeCards(plan.items||[])}<h3>🔄 Sustituciones</h3>${(snap.substitutions||[]).length?`<div class="list">${snap.substitutions.map(x=>`<div class="list-item"><div><strong>${esc(x.from)}</strong><div class="meta">${esc(x.to)}</div></div></div>`).join('')}</div>`:empty('Sin sustituciones','No se encontraron alternativas compatibles adicionales.')}<h3>🛒 Lista de compras</h3><div class="grid">${Object.entries(snap.shopping_list||{}).map(([k,v])=>`<div class="card card-pad span-4"><strong>${esc(k)}</strong><p class="muted">${esc((v||[]).join(', '))}</p></div>`).join('')}</div><div class="inline-notice warn">${esc(snap.medical_note||'Orientación general; no sustituye valoración profesional.')}</div><div class="form-actions nutrition-share-actions"><button id="nutritionPdfDownload" class="button secondary">Descargar PDF</button><button id="nutritionPlanImages" class="button secondary">Descargar imágenes</button><button id="nutritionPdfShare" class="button primary">Compartir PDF</button><button id="nutritionWhatsappDirect" class="button success">Enviar directo por WhatsApp</button></div>`;
      $('nutritionPdfDownload').onclick=()=>downloadNutritionPlanPdf(id);
      $('nutritionPlanImages').onclick=()=>openNutritionPlanImages(id);
      $('nutritionPdfShare').onclick=()=>shareNutritionPlanPdf(id);
      $('nutritionWhatsappDirect').onclick=()=>sendNutritionPlanDirect(id);
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }
  async function downloadNutritionPlanPdf(id){try{const info=await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`),blob=await requestBlob(`/nutrition-engine/plans/${encodeURIComponent(id)}/pdf?download=1`);downloadBlob(blob,info.filename);await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`,{method:'POST',body:{channel:'DOWNLOAD'}});toast('PDF generado y descargado.');}catch(e){toast(errorMessage(e),'bad');}}
  async function openNutritionPlanImages(id){
    try{
      const manifest=await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/images?format=jpg`);
      openModal('Imágenes del plan',`${Number(manifest.page_count||0)} páginas · mismo diseño que el PDF`,(manifest.pages||[]).length?`<div class="list">${manifest.pages.map(p=>`<div class="list-item"><div><strong>Página ${Number(p.page)}</strong><div class="meta">${esc(p.filename)}</div></div><button class="button small primary nutrition-image-download" data-page="${Number(p.page)}" data-name="${esc(p.filename)}">Descargar JPG</button></div>`).join('')}</div>`:empty('Sin imágenes','No se generaron páginas para este plan.'));
      document.querySelectorAll('.nutrition-image-download').forEach(b=>b.onclick=async()=>{b.disabled=true;try{const blob=await requestBlob(`/nutrition-engine/plans/${encodeURIComponent(id)}/images/${encodeURIComponent(b.dataset.page)}?format=jpg&download=1`);downloadBlob(blob,b.dataset.name||`plan-${id}-pagina-${b.dataset.page}.jpg`);}catch(e){toast(errorMessage(e),'bad');}finally{b.disabled=false;}});
    }catch(e){toast(errorMessage(e),'bad');}
  }
  async function shareNutritionPlanPdf(id){
    try{
      const info=await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`);
      if(window.RhinoCRM?.sharePdf){await window.RhinoCRM.sharePdf(info.pdf_url,info.filename,info.phone_e164,info.message,state.token);await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`,{method:'POST',body:{channel:'ANDROID_SHARE'}});toast('PDF preparado para compartir.');return;}
      const blob=await requestBlob(`/nutrition-engine/plans/${encodeURIComponent(id)}/pdf?download=1`),file=typeof File==='function'?new File([blob],info.filename,{type:'application/pdf'}):null;
      if(file&&navigator.share&&(!navigator.canShare||navigator.canShare({files:[file]}))){await navigator.share({title:`Plan alimenticio de ${info.contact_name}`,text:info.message,files:[file]});await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`,{method:'POST',body:{channel:'WEB_SHARE'}});toast('Compartición iniciada.');return;}
      downloadBlob(blob,info.filename);window.open(info.whatsapp_url,'_blank','noopener');await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`,{method:'POST',body:{channel:'WHATSAPP_APP'}});toast('Se descargó el PDF y se abrió el contacto de WhatsApp. Adjunta el documento descargado.');
    }catch(e){if(e?.name!=='AbortError')toast(errorMessage(e),'bad');}
  }
  async function sendNutritionPlanDirect(id){try{const info=await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/share`);if(!info.direct_send?.available){const reasons=[];if(!info.direct_send?.verified_contact)reasons.push('WhatsApp no verificado o sin consentimiento');if(!info.direct_send?.meta_configured)reasons.push('Meta Cloud API no configurada');if(!info.direct_send?.conversation_window_open&&!info.direct_send?.template_configured)reasons.push('conversación vencida y sin plantilla');throw {message:'Envío directo no disponible: '+reasons.join(', ')+'.'};}if(!confirm(`Enviar el PDF directamente a ${info.contact_name} (${info.phone_e164})?`))return;await request(`/nutrition-engine/plans/${encodeURIComponent(id)}/send-whatsapp`,{method:'POST',body:{}});toast('Plan enviado directamente por WhatsApp.');openNutritionPlanDetail(id);}catch(e){toast(errorMessage(e),'bad');}}
  function nutritionSplitLines(value){return String(value||'').split(/[,\n]+/).map(x=>x.trim()).filter(Boolean);}
  function nutritionUpperList(value){return nutritionSplitLines(value).map(x=>x.toUpperCase());}
  function nutritionNumberOrNull(value){return String(value??'').trim()===''?null:Number(value);}
  function nutritionSelected(value,current){return (Array.isArray(current)?current:[]).includes(value)?'selected':'';}
  function nutritionChecked(value){return value?'checked':'';}

  function openNutritionPillForm(pill=null){
    const ingredients=(pill?.ingredients||[]).map(x=>x.display||x.name||'').join('\n');
    const goals=pill?.compatible_goals||['GENERAL_WELLNESS','MAINTAIN'];
    const patterns=pill?.dietary_patterns||['OMNIVORE'];
    openModal(pill?'Editar comida':'Agregar comida',pill?'Al guardar volverá a borrador y deberá aprobarse nuevamente.':'La comida se guardará como borrador hasta que sea aprobada.',`<form id="nutritionPillForm">
      <section class="nutrition-form-section"><h3>Información principal</h3><div class="form-grid">
        <label>Tipo<select name="meal_type" required>${[['BREAKFAST','Desayuno'],['SNACK','Colación'],['LUNCH','Comida'],['DINNER','Cena'],['BEVERAGE','Bebida'],['COMPONENT','Componente']].map(([v,l])=>`<option value="${v}" ${pill?.meal_type===v?'selected':''}>${l}</option>`).join('')}</select></label>
        <label>Emoji o símbolo<input name="emoji" maxlength="16" value="${esc(pill?.emoji||'')}" placeholder="Ej. 🍲"></label>
        <label class="full">Nombre<input name="name" required maxlength="180" value="${esc(pill?.name||'')}"></label>
        <label class="full">Descripción<textarea name="description" rows="2" placeholder="Descripción breve para el catálogo">${esc(pill?.description||'')}</textarea></label>
        <label class="full">Porción base<input name="portion_text" required value="${esc(pill?.portion_text||'')}" placeholder="Ej. 2 tortillas, 100 g de pollo y 1 taza de verduras"></label>
      </div></section>
      <section class="nutrition-form-section"><h3>Ingredientes y preparación</h3><div class="form-grid">
        <label class="full">Ingredientes, uno por línea<textarea name="ingredients" rows="7" required>${esc(ingredients)}</textarea></label>
        <label class="full">Preparación<textarea name="preparation" rows="6" required>${esc(pill?.preparation||'')}</textarea></label>
        <label>Tiempo en minutos<input name="prep_minutes" type="number" min="1" max="480" value="${esc(pill?.prep_minutes||20)}"></label>
        <label>Presupuesto<select name="budget_level"><option value="">Sin especificar</option><option value="LOW" ${pill?.budget_level==='LOW'?'selected':''}>Bajo</option><option value="MEDIUM" ${pill?.budget_level==='MEDIUM'?'selected':''}>Medio</option><option value="HIGH" ${pill?.budget_level==='HIGH'?'selected':''}>Alto</option></select></label>
      </div></section>
      <section class="nutrition-form-section"><h3>Clasificación</h3><div class="form-grid">
        <label class="full">Grupos alimentarios<input name="food_groups" value="${esc((pill?.food_groups||[]).join(', '))}" placeholder="VEGETABLES, FRUIT, CEREALS_TUBERS, LEGUMES, ANIMAL_PROTEIN, DAIRY, HEALTHY_FATS"></label>
        <label class="full">Alérgenos<input name="allergens" value="${esc((pill?.allergens||[]).join(', '))}" placeholder="DAIRY, EGG, PEANUT, TREE_NUTS, GLUTEN, FISH, SHELLFISH, SOY"></label>
        <label class="full">Etiquetas<input name="tags" value="${esc((pill?.tags||['GENERAL']).join(', '))}" placeholder="MEXICAN, QUICK, HIGH_FIBER"></label>
        <label>Objetivos compatibles<select name="compatible_goals" multiple size="4"><option value="GENERAL_WELLNESS" ${nutritionSelected('GENERAL_WELLNESS',goals)}>Mejora de hábitos</option><option value="MAINTAIN" ${nutritionSelected('MAINTAIN',goals)}>Mantenimiento</option><option value="REDUCE" ${nutritionSelected('REDUCE',goals)}>Control gradual de peso</option><option value="GAIN" ${nutritionSelected('GAIN',goals)}>Apoyo a actividad</option></select></label>
        <label>Patrones compatibles<select name="dietary_patterns" multiple size="3"><option value="OMNIVORE" ${nutritionSelected('OMNIVORE',patterns)}>Omnívora</option><option value="VEGETARIAN" ${nutritionSelected('VEGETARIAN',patterns)}>Vegetariana</option><option value="VEGAN" ${nutritionSelected('VEGAN',patterns)}>Vegana</option></select></label>
        <label class="full">Banderas de exclusión<input name="excluded_flags" value="${esc((pill?.excluded_flags||[]).join(', '))}" placeholder="Ej. NO_DIABETES, NO_PREGNANCY"></label>
      </div></section>
      <section class="nutrition-form-section"><h3>Estimación nutricional opcional</h3><div class="form-grid nutrition-macro-fields">
        <label>kcal<input name="kcal_est" type="number" min="0" step="0.1" value="${esc(pill?.kcal_est??'')}"></label>
        <label>Proteína g<input name="protein_g" type="number" min="0" step="0.1" value="${esc(pill?.protein_g??'')}"></label>
        <label>Carbohidratos g<input name="carbs_g" type="number" min="0" step="0.1" value="${esc(pill?.carbs_g??'')}"></label>
        <label>Grasas g<input name="fat_g" type="number" min="0" step="0.1" value="${esc(pill?.fat_g??'')}"></label>
        <label>Fibra g<input name="fiber_g" type="number" min="0" step="0.1" value="${esc(pill?.fiber_g??'')}"></label>
        <label>Sodio mg<input name="sodium_mg" type="number" min="0" step="0.1" value="${esc(pill?.sodium_mg??'')}"></label>
      </div></section>
      <div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Guardar borrador</button></div><div class="form-error" id="nutritionPillError"></div>
    </form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('nutritionPillForm').onsubmit=async e=>{
      e.preventDefault();
      const form=e.currentTarget,fd=new FormData(form),ingredientLines=nutritionSplitLines(fd.get('ingredients'));
      const body={
        meal_type:fd.get('meal_type'),emoji:fd.get('emoji')||null,name:fd.get('name'),description:fd.get('description')||null,
        portion_text:fd.get('portion_text'),ingredients:ingredientLines.map(display=>({name:display.replace(/^\d+[\d./]*\s*(g|kg|ml|l|taza[s]?|pieza[s]?|cucharada[s]?|cucharadita[s]?)?\s*/i,'').trim()||display,display})),
        preparation:fd.get('preparation'),prep_minutes:nutritionNumberOrNull(fd.get('prep_minutes')),budget_level:fd.get('budget_level')||null,
        food_groups:nutritionUpperList(fd.get('food_groups')),allergens:nutritionUpperList(fd.get('allergens')),tags:nutritionUpperList(fd.get('tags')),
        compatible_goals:fd.getAll('compatible_goals'),dietary_patterns:fd.getAll('dietary_patterns'),excluded_flags:nutritionUpperList(fd.get('excluded_flags')),
        kcal_est:nutritionNumberOrNull(fd.get('kcal_est')),protein_g:nutritionNumberOrNull(fd.get('protein_g')),carbs_g:nutritionNumberOrNull(fd.get('carbs_g')),
        fat_g:nutritionNumberOrNull(fd.get('fat_g')),fiber_g:nutritionNumberOrNull(fd.get('fiber_g')),sodium_mg:nutritionNumberOrNull(fd.get('sodium_mg'))
      };
      const btn=e.submitter;btn.disabled=true;
      try{await request(pill?`/nutrition-engine/pills/${encodeURIComponent(pill.id)}`:'/nutrition-engine/pills',{method:pill?'PATCH':'POST',body});closeModal();toast('Comida guardada como borrador.');renderNutritionMeals();}
      catch(err){$('nutritionPillError').textContent=errorMessage(err);}finally{btn.disabled=false;}
    };
  }

  async function openNutritionPillDetail(id){
    openModal('Comida','Cargando…','<div class="loading">Cargando…</div>');
    try{
      const p=await request(`/nutrition-engine/pills/${encodeURIComponent(id)}`);
      $('modalTitle').textContent=((p.emoji||'')+' '+p.name).trim();
      $('modalSubtitle').textContent=`${nutritionMealTypeLabel(p.meal_type)} · versión ${p.current_version} · ${p.partner_id?'Mi organización':'Global'}`;
      const macro=[['kcal',p.kcal_est],['Proteína',p.protein_g!=null?`${p.protein_g} g`:null],['Carbohidratos',p.carbs_g!=null?`${p.carbs_g} g`:null],['Grasas',p.fat_g!=null?`${p.fat_g} g`:null],['Fibra',p.fiber_g!=null?`${p.fiber_g} g`:null],['Sodio',p.sodium_mg!=null?`${p.sodium_mg} mg`:null]].filter(([,v])=>v!=null);
      $('modalBody').innerHTML=`<div class="table-wrap"><table><tbody>
        <tr><th>Estado</th><td>${badge(p.status)}</td></tr><tr><th>Descripción</th><td>${esc(p.description||'—')}</td></tr><tr><th>Porción</th><td>${esc(p.portion_text||'—')}</td></tr>
        <tr><th>Ingredientes</th><td><ul class="compact-list">${(p.ingredients||[]).map(x=>`<li>${esc(x.display||x.name)}</li>`).join('')}</ul></td></tr><tr><th>Preparación</th><td>${esc(p.preparation||'—')}</td></tr>
        <tr><th>Tiempo / presupuesto</th><td>${esc(p.prep_minutes?`${p.prep_minutes} min`:'—')} · ${esc(p.budget_level||'Sin especificar')}</td></tr>
        <tr><th>Clasificación</th><td>${esc((p.food_groups||[]).join(', ')||'—')}</td></tr><tr><th>Alérgenos</th><td>${esc((p.allergens||[]).join(', ')||'Ninguno declarado')}</td></tr>
        <tr><th>Etiquetas</th><td>${esc((p.tags||[]).join(', ')||'—')}</td></tr><tr><th>Objetivos</th><td>${esc((p.compatible_goals||[]).map(nutritionGoalLabel).join(', ')||'—')}</td></tr>
        <tr><th>Patrones</th><td>${esc((p.dietary_patterns||[]).join(', ')||'—')}</td></tr><tr><th>Estimación nutricional</th><td>${esc(macro.map(([k,v])=>`${k}: ${v}`).join(' · ')||'No capturada')}</td></tr>
      </tbody></table></div><div class="form-actions">${has('nutrition.pills.manage')?`<button id="duplicateNutritionPillModal" class="button secondary">Duplicar</button>`:''}${p.editable&&has('nutrition.pills.manage')?`<button id="editNutritionPillModal" class="button secondary">Editar</button>${p.status==='DRAFT'?'<button id="approveNutritionPillModal" class="button success">Aprobar</button>':''}${p.status!=='ARCHIVED'?'<button id="deleteNutritionPillModal" class="button danger">Quitar</button>':''}`:''}</div>`;
      $('duplicateNutritionPillModal')?.addEventListener('click',async()=>{try{await request(`/nutrition-engine/pills/${encodeURIComponent(id)}/duplicate`,{method:'POST',body:{}});closeModal();toast('Comida duplicada como borrador.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
      $('editNutritionPillModal')?.addEventListener('click',()=>openNutritionPillForm(p));
      $('approveNutritionPillModal')?.addEventListener('click',async()=>{try{await request(`/nutrition-engine/pills/${encodeURIComponent(id)}/approve`,{method:'POST',body:{}});closeModal();toast('Comida aprobada.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
      $('deleteNutritionPillModal')?.addEventListener('click',async()=>{if(!confirm('¿Quitar esta comida? Si ya forma parte de planes anteriores se archivará para conservar el historial.'))return;try{const result=await request(`/nutrition-engine/pills/${encodeURIComponent(id)}`,{method:'DELETE'});closeModal();toast(result.deletion_mode==='DELETED'?'Comida eliminada.':'Comida archivada porque tiene historial.');renderNutritionMeals();}catch(e){toast(errorMessage(e),'bad');}});
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }

  function nutritionDefaultDocument(){return {
    title_template:'Plan Alimenticio para {name}',
    section_titles:{summary:'1. Resumen Personal',targets:'2. Metas Clave: Calorías y Macros',portions:'3. Guía Rápida de Porciones',hydration:'4. Hidratación y Hábitos',limits:'5. Alimentos y Hábitos a Limitar',shopping:'6. Lista de Compras Inteligente',weekly:'7. Tu Plan Semanal Flexible',benefits:'8. ¿Qué me Aporta?',recipes:'9. Recetario Rápido y Consejos de Cocina',cravings:'10. Estrategias ante Antojos',follow_up:'11. Recomendaciones de Seguimiento'},
    golden_rules_title:'Reglas de Oro',golden_rules:['Prioriza alimentos frescos, verduras, leguminosas y cereales integrales.','Usa agua simple como bebida principal y limita bebidas azucaradas.','Distribuye tus comidas de acuerdo con tu rutina y señales de hambre y saciedad.'],
    flexibility_note:'Este es un menú guía. Puedes intercambiar comidas del mismo tipo, respetando las porciones y restricciones indicadas.',
    hydration_text:'Agua simple e infusiones sin azúcar como bebidas principales. Ajusta la cantidad al clima, actividad, tolerancia y recomendaciones médicas personales.',
    cooking_intro:'Prefiere preparaciones a la plancha, al vapor, hervidas u horneadas. Sazona con hierbas y especias y modera sal, azúcar y grasas añadidas.',
    follow_up_notice:'Este documento es una orientación general y no sustituye una consulta con un profesional de salud o nutrición.',
    follow_up_items:['Mantén horarios realistas y registra qué comidas fueron más fáciles de seguir.','Ajusta variedad y presentación antes de reducir más las porciones.','Solicita revisión profesional ante síntomas, embarazo, lactancia, alergias graves, enfermedad o medicamentos.'],
    craving_support_intro:'Si aparece un antojo fuera de tu plan, evita decisiones impulsivas y usa una alternativa sencilla que puedas sostener.',
    craving_support_items:['Toma agua simple o una infusión sin azúcar y espera unos minutos antes de decidir.','Si persiste el hambre, elige una colación prevista en tu plan o una opción equivalente compatible con tus restricciones.','Si los antojos son frecuentes, intensos o difíciles de manejar, registra cuándo aparecen y coméntalo con un profesional de salud o nutrición.'],
    foods_to_limit:[{title:'Bebidas Azucaradas',emoji:'',items:['Refrescos y bebidas endulzadas','Jugos industrializados','Bebidas energéticas','Cafés o tés con jarabes']},{title:'Snacks y Ultraprocesados',emoji:'',items:['Frituras','Galletas, pasteles y pan dulce','Dulces con alto contenido de azúcar','Comida rápida frecuente']},{title:'Otros Alimentos y Hábitos',emoji:'',items:['Embutidos grasosos','Aderezos cremosos en exceso','Alimentos empanizados o fritos','Exceso de sal y alcohol']}],
    benefit_cards:[{title:'Avena',emoji:'',text:'Aporta fibra soluble y puede favorecer la saciedad.'},{title:'Leguminosas',emoji:'',text:'Aportan proteína vegetal, fibra y energía de absorción gradual.'},{title:'Huevo',emoji:'',text:'Fuente práctica de proteína completa y versátil.'},{title:'Pescado',emoji:'',text:'Aporta proteína y grasas insaturadas según la variedad.'},{title:'Hierbas y cítricos',emoji:'',text:'Ayudan a dar sabor sin depender de exceso de sal.'},{title:'Verduras de hoja verde',emoji:'',text:'Aportan vitaminas, minerales y volumen con pocas calorías.'}],
    show_risk_bar:true,show_macros:true,show_follow_up:true,show_limits:true,show_benefits:true,show_recipes:true,show_shopping_list:true,show_craving_support:true,
    footer_note:'Documento generado por RHINO CRM. Las mediciones de bioimpedancia son estimaciones y pueden variar según hidratación y condiciones de medición.'
  };}
  function nutritionTextLines(value){return (Array.isArray(value)?value:[]).join('\n');}
  function nutritionLimitLines(value){return (Array.isArray(value)?value:[]).map(x=>`${x.emoji||''}|${x.title||''}|${(x.items||[]).join('; ')}`).join('\n');}
  function nutritionBenefitLines(value){return (Array.isArray(value)?value:[]).map(x=>`${x.emoji||''}|${x.title||''}|${x.text||''}`).join('\n');}
  function nutritionParseLimitLines(value){return String(value||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean).map(line=>{const parts=line.split('|').map(x=>x.trim());const [emoji,title,itemsText]=parts.length>=3?parts:['',parts[0]||'',parts.slice(1).join('|')];return {emoji,title,items:String(itemsText||'').split(';').map(x=>x.trim()).filter(Boolean)};}).filter(x=>x.title&&x.items.length);}
  function nutritionParseBenefitLines(value){return String(value||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean).map(line=>{const parts=line.split('|').map(x=>x.trim());const [emoji,title,text]=parts.length>=3?parts:['',parts[0]||'',parts.slice(1).join('|')];return {emoji,title,text};}).filter(x=>x.title&&x.text);}

  function openNutritionTemplateForm(template=null){
    const documentData={...nutritionDefaultDocument(),...(template?.document||{})};
    documentData.section_titles={...nutritionDefaultDocument().section_titles,...(template?.document?.section_titles||{})};
    const rules=template?.rules||{};
    const goals=template?.compatible_goals||['GENERAL_WELLNESS','MAINTAIN','REDUCE','GAIN'];
    const patterns=template?.dietary_patterns||['OMNIVORE','VEGETARIAN','VEGAN'];
    const budgets=rules.prefer_budget_levels||[];
    const macros=rules.macro_percentages||{protein:30,carbs:40,fat:30};
    openModal(template?'Editar plantilla de dieta':'Nueva plantilla de dieta',template?'La edición crea una nueva versión en borrador; actívala cuando esté lista.':'Configura las reglas de selección de comidas y el contenido del PDF.',`<form id="nutritionTemplateForm">
      <section class="nutrition-form-section"><h3>Identificación y reglas del motor</h3><div class="form-grid">
        <label class="full">Nombre<input name="name" required maxlength="180" value="${esc(template?.name||'')}"></label>
        <label class="full">Descripción<textarea name="description" rows="2">${esc(template?.description||'')}</textarea></label>
        <label>Objetivos compatibles<select name="compatible_goals" multiple size="4"><option value="GENERAL_WELLNESS" ${nutritionSelected('GENERAL_WELLNESS',goals)}>Mejora de hábitos</option><option value="MAINTAIN" ${nutritionSelected('MAINTAIN',goals)}>Mantenimiento</option><option value="REDUCE" ${nutritionSelected('REDUCE',goals)}>Control gradual de peso</option><option value="GAIN" ${nutritionSelected('GAIN',goals)}>Apoyo a actividad</option></select></label>
        <label>Patrones compatibles<select name="dietary_patterns" multiple size="3"><option value="OMNIVORE" ${nutritionSelected('OMNIVORE',patterns)}>Omnívora</option><option value="VEGETARIAN" ${nutritionSelected('VEGETARIAN',patterns)}>Vegetariana</option><option value="VEGAN" ${nutritionSelected('VEGAN',patterns)}>Vegana</option></select></label>
        <label>Comidas por día<input name="default_meals_per_day" type="number" min="3" max="5" value="${esc(rules.default_meals_per_day||4)}"></label>
        <label>Repeticiones máximas por semana<input name="max_same_pill_per_week" type="number" min="1" max="7" value="${esc(rules.max_same_pill_per_week||1)}"></label>
        <label>Preparación máxima en minutos<input name="max_prep_minutes" type="number" min="1" max="480" value="${esc(rules.max_prep_minutes??'')}"></label>
        <label>Patrón obligatorio<select name="required_dietary_pattern"><option value="">Ninguno</option><option value="OMNIVORE" ${rules.required_dietary_pattern==='OMNIVORE'?'selected':''}>Omnívora</option><option value="VEGETARIAN" ${rules.required_dietary_pattern==='VEGETARIAN'?'selected':''}>Vegetariana</option><option value="VEGAN" ${rules.required_dietary_pattern==='VEGAN'?'selected':''}>Vegana</option></select></label>
        <label>Presupuestos preferidos<select name="prefer_budget_levels" multiple size="3"><option value="LOW" ${nutritionSelected('LOW',budgets)}>Bajo</option><option value="MEDIUM" ${nutritionSelected('MEDIUM',budgets)}>Medio</option><option value="HIGH" ${nutritionSelected('HIGH',budgets)}>Alto</option></select></label>
        <label>Etiquetas preferidas<input name="prefer_tags" value="${esc((rules.prefer_tags||[]).join(', '))}" placeholder="MEXICAN, QUICK"></label>
        <label class="full">Grupos alimentarios preferidos<input name="prefer_food_groups" value="${esc((rules.prefer_food_groups||[]).join(', '))}" placeholder="VEGETABLES, LEGUMES, ANIMAL_PROTEIN"></label>
        <label>% proteína<input name="macro_protein" type="number" min="10" max="50" value="${esc(macros.protein||30)}"></label><label>% carbohidratos<input name="macro_carbs" type="number" min="10" max="70" value="${esc(macros.carbs||40)}"></label><label>% grasas<input name="macro_fat" type="number" min="10" max="50" value="${esc(macros.fat||30)}"></label>
      </div><p class="field-help">Los porcentajes de proteína, carbohidratos y grasa deben sumar 100.</p></section>
      <section class="nutrition-form-section"><h3>Encabezados del PDF</h3><div class="form-grid">
        <label class="full">Título principal<input name="title_template" value="${esc(documentData.title_template||'')}" placeholder="Plan Alimenticio para {name}"></label>
        ${[['summary','Resumen personal'],['targets','Metas y macros'],['portions','Guía de porciones'],['hydration','Hidratación'],['limits','Alimentos a limitar'],['shopping','Lista de compras'],['weekly','Plan semanal'],['benefits','Qué me aporta'],['recipes','Recetario'],['cravings','Antojos'],['follow_up','Seguimiento']].map(([key,label])=>`<label>${esc(label)}<input name="section_${key}" value="${esc(documentData.section_titles?.[key]||'')}"></label>`).join('')}
      </div></section>
      <section class="nutrition-form-section"><h3>Contenido editorial</h3><div class="form-grid">
        <label class="full">Título de reglas<input name="golden_rules_title" value="${esc(documentData.golden_rules_title||'')}"></label>
        <label class="full">Reglas, una por línea<textarea name="golden_rules" rows="5">${esc(nutritionTextLines(documentData.golden_rules))}</textarea></label>
        <label class="full">Nota de flexibilidad<textarea name="flexibility_note" rows="3">${esc(documentData.flexibility_note||'')}</textarea></label>
        <label class="full">Texto de hidratación<textarea name="hydration_text" rows="3">${esc(documentData.hydration_text||'')}</textarea></label>
        <label class="full">Introducción del recetario<textarea name="cooking_intro" rows="3">${esc(documentData.cooking_intro||'')}</textarea></label>
        <label class="full">Aviso de seguimiento<textarea name="follow_up_notice" rows="3">${esc(documentData.follow_up_notice||'')}</textarea></label>
        <label class="full">Recomendaciones de seguimiento, una por línea<textarea name="follow_up_items" rows="5">${esc(nutritionTextLines(documentData.follow_up_items))}</textarea></label>
        <label class="full">Introducción para manejo de antojos<textarea name="craving_support_intro" rows="3">${esc(documentData.craving_support_intro||'')}</textarea></label>
        <label class="full">Estrategias ante antojos, una por línea<textarea name="craving_support_items" rows="5">${esc(nutritionTextLines(documentData.craving_support_items))}</textarea></label>
        <label class="full">Alimentos a limitar: emoji|título|elemento 1; elemento 2<textarea name="foods_to_limit" rows="8">${esc(nutritionLimitLines(documentData.foods_to_limit))}</textarea></label>
        <label class="full">Tarjetas de aportes: emoji|título|texto<textarea name="benefit_cards" rows="8">${esc(nutritionBenefitLines(documentData.benefit_cards))}</textarea></label>
        <label class="full">Nota al pie<textarea name="footer_note" rows="3">${esc(documentData.footer_note||'')}</textarea></label>
      </div></section>
      <section class="nutrition-form-section"><h3>Secciones visibles</h3><div class="nutrition-toggle-grid">
        <label><input type="checkbox" name="show_risk_bar" ${nutritionChecked(documentData.show_risk_bar)}> Barra de IMC</label><label><input type="checkbox" name="show_macros" ${nutritionChecked(documentData.show_macros)}> Calorías y macros</label>
        <label><input type="checkbox" name="show_follow_up" ${nutritionChecked(documentData.show_follow_up)}> Seguimiento</label><label><input type="checkbox" name="show_limits" ${nutritionChecked(documentData.show_limits)}> Alimentos a limitar</label>
        <label><input type="checkbox" name="show_benefits" ${nutritionChecked(documentData.show_benefits)}> Aportes</label><label><input type="checkbox" name="show_recipes" ${nutritionChecked(documentData.show_recipes)}> Recetario</label>
        <label><input type="checkbox" name="show_shopping_list" ${nutritionChecked(documentData.show_shopping_list)}> Lista de compras</label>
        <label><input type="checkbox" name="show_craving_support" ${nutritionChecked(documentData.show_craving_support)}> Estrategias ante antojos</label>
      </div></section>
      <div class="inline-notice">El PDF usa “Resumen Personal” y omite la palabra solicitada. Las secciones y textos se pueden cambiar desde esta plantilla.</div>
      <div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Guardar borrador</button></div><div class="form-error" id="nutritionTemplateError"></div>
    </form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('nutritionTemplateForm').onsubmit=async e=>{
      e.preventDefault();const form=e.currentTarget,fd=new FormData(form);
      const macro={protein:Number(fd.get('macro_protein')||30),carbs:Number(fd.get('macro_carbs')||40),fat:Number(fd.get('macro_fat')||30)};
      if(macro.protein+macro.carbs+macro.fat!==100){$('nutritionTemplateError').textContent='Los porcentajes de macros deben sumar 100.';return;}
      const documentPayload={
        title_template:fd.get('title_template'),section_titles:{summary:fd.get('section_summary'),targets:fd.get('section_targets'),portions:fd.get('section_portions'),hydration:fd.get('section_hydration'),limits:fd.get('section_limits'),shopping:fd.get('section_shopping'),weekly:fd.get('section_weekly'),benefits:fd.get('section_benefits'),recipes:fd.get('section_recipes'),cravings:fd.get('section_cravings'),follow_up:fd.get('section_follow_up')},
        golden_rules_title:fd.get('golden_rules_title'),golden_rules:String(fd.get('golden_rules')||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean),flexibility_note:fd.get('flexibility_note'),hydration_text:fd.get('hydration_text'),cooking_intro:fd.get('cooking_intro'),follow_up_notice:fd.get('follow_up_notice'),follow_up_items:String(fd.get('follow_up_items')||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean),foods_to_limit:nutritionParseLimitLines(fd.get('foods_to_limit')),benefit_cards:nutritionParseBenefitLines(fd.get('benefit_cards')),footer_note:fd.get('footer_note'),
        show_risk_bar:form.elements.show_risk_bar.checked,show_macros:form.elements.show_macros.checked,show_follow_up:form.elements.show_follow_up.checked,show_limits:form.elements.show_limits.checked,show_benefits:form.elements.show_benefits.checked,show_recipes:form.elements.show_recipes.checked,show_shopping_list:form.elements.show_shopping_list.checked,show_craving_support:form.elements.show_craving_support.checked
      };
      const body={name:fd.get('name'),description:fd.get('description')||null,compatible_goals:fd.getAll('compatible_goals'),dietary_patterns:fd.getAll('dietary_patterns'),rules:{days:7,default_meals_per_day:Number(fd.get('default_meals_per_day')||4),max_same_pill_per_week:Number(fd.get('max_same_pill_per_week')||1),max_prep_minutes:nutritionNumberOrNull(fd.get('max_prep_minutes')),required_dietary_pattern:fd.get('required_dietary_pattern')||null,prefer_budget_levels:fd.getAll('prefer_budget_levels'),prefer_tags:nutritionUpperList(fd.get('prefer_tags')),prefer_food_groups:nutritionUpperList(fd.get('prefer_food_groups')),portion_strategy:'BASE',macro_percentages:macro,document:documentPayload},document:documentPayload};
      const btn=e.submitter;btn.disabled=true;
      try{await request(template?`/nutrition-engine/templates/${encodeURIComponent(template.id)}`:'/nutrition-engine/templates',{method:template?'PATCH':'POST',body});closeModal();toast('Plantilla guardada como borrador.');renderNutritionTemplates();}
      catch(err){$('nutritionTemplateError').textContent=errorMessage(err);}finally{btn.disabled=false;}
    };
  }

  async function openNutritionTemplateDetail(id){
    openModal('Plantilla de dieta','Cargando…','<div class="loading">Cargando…</div>');
    try{
      const t=await request(`/nutrition-engine/templates/${encodeURIComponent(id)}`),r=t.rules||{},d=t.document||{};
      $('modalTitle').textContent=t.name;$('modalSubtitle').textContent=`${t.partner_id?'Mi organización':'Global'} · versión ${t.current_version}`;
      $('modalBody').innerHTML=`<div class="table-wrap"><table><tbody>
        <tr><th>Estado</th><td>${badge(t.status)}</td></tr><tr><th>Descripción</th><td>${esc(t.description||'—')}</td></tr><tr><th>Objetivos</th><td>${esc((t.compatible_goals||[]).map(nutritionGoalLabel).join(', ')||'—')}</td></tr><tr><th>Patrones</th><td>${esc((t.dietary_patterns||[]).join(', ')||'—')}</td></tr>
        <tr><th>Reglas</th><td>${Number(r.default_meals_per_day||4)} comidas/día · máximo ${Number(r.max_same_pill_per_week||1)} repetición(es) · ${r.max_prep_minutes?`${Number(r.max_prep_minutes)} min máximo`:'sin límite de preparación'}</td></tr>
        <tr><th>Macros</th><td>${esc(`${r.macro_percentages?.protein||30}% proteína · ${r.macro_percentages?.carbs||40}% carbohidratos · ${r.macro_percentages?.fat||30}% grasas`)}</td></tr>
        <tr><th>Título del PDF</th><td>${esc(d.title_template||'—')}</td></tr><tr><th>Secciones</th><td>${esc(Object.values(d.section_titles||{}).join(' · '))}</td></tr>
        <tr><th>Reglas editoriales</th><td>${esc((d.golden_rules||[]).join(' | ')||'—')}</td></tr><tr><th>Secciones visibles</th><td>${esc([d.show_macros&&'Macros',d.show_follow_up&&'Seguimiento',d.show_limits&&'Alimentos a limitar',d.show_benefits&&'Aportes',d.show_recipes&&'Recetario',d.show_shopping_list&&'Compras',d.show_craving_support&&'Antojos'].filter(Boolean).join(', ')||'Ninguna')}</td></tr>
      </tbody></table></div><div class="form-actions">${has('nutrition.templates.manage')?'<button id="duplicateNutritionTemplateModal" class="button secondary">Duplicar</button>':''}${t.editable&&has('nutrition.templates.manage')?`<button id="editNutritionTemplateModal" class="button secondary">Editar</button>${t.status==='DRAFT'?'<button id="activateNutritionTemplateModal" class="button success">Activar</button>':''}${t.status!=='ARCHIVED'?'<button id="deleteNutritionTemplateModal" class="button danger">Quitar</button>':''}`:''}</div>`;
      $('duplicateNutritionTemplateModal')?.addEventListener('click',async()=>{try{await request(`/nutrition-engine/templates/${encodeURIComponent(id)}/duplicate`,{method:'POST',body:{}});closeModal();toast('Plantilla duplicada como borrador.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
      $('editNutritionTemplateModal')?.addEventListener('click',()=>openNutritionTemplateForm(t));
      $('activateNutritionTemplateModal')?.addEventListener('click',async()=>{try{await request(`/nutrition-engine/templates/${encodeURIComponent(id)}/activate`,{method:'POST',body:{}});closeModal();toast('Plantilla activada.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
      $('deleteNutritionTemplateModal')?.addEventListener('click',async()=>{if(!confirm('¿Quitar esta plantilla? Si ya fue usada se archivará para conservar el historial.'))return;try{const result=await request(`/nutrition-engine/templates/${encodeURIComponent(id)}`,{method:'DELETE'});closeModal();toast(result.deletion_mode==='DELETED'?'Plantilla eliminada.':'Plantilla archivada porque tiene historial.');renderNutritionTemplates();}catch(e){toast(errorMessage(e),'bad');}});
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }

  function openNutritionAiBatchForm(){openModal('Crear prompt para ampliar biblioteca','La IA propone; RHINO valida y tú decides qué entra en producción.',`<form id="nutritionAiForm"><div class="form-grid"><label>Nombre del lote<input name="title" value="Nuevas opciones mexicanas"></label><label>Tipo<select name="meal_type"><option value="BREAKFAST">Desayunos</option><option value="SNACK">Colaciones</option><option value="LUNCH" selected>Comidas</option><option value="DINNER">Cenas</option></select></label><label>Cantidad<input name="count" type="number" min="1" max="50" value="10"></label><label>Presupuesto<select name="budget_level"><option value="LOW">Bajo</option><option value="MEDIUM">Medio</option><option value="HIGH">Alto</option></select></label><label>Tiempo máximo<input name="max_prep_minutes" type="number" min="1" max="480" value="30"></label><label>Estilo<input name="style" value="comida mexicana cotidiana"></label><label class="full">Priorizar<textarea name="include_ingredients">verduras, leguminosas, tortilla de maíz, huevo, pollo y alimentos disponibles en México</textarea></label><label class="full">Excluir<textarea name="exclude_ingredients">suplementos, productos detox, medicamentos, productos milagro y afirmaciones médicas</textarea></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Crear prompt</button></div><div class="form-error" id="nutritionAiError"></div></form>`);$('modalBody').querySelector('[data-close]').onclick=closeModal;$('nutritionAiForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));const btn=e.submitter;btn.disabled=true;try{const batch=await request('/nutrition-engine/ai/batches',{method:'POST',body:{title:f.title,meal_type:f.meal_type,count:Number(f.count||10),budget_level:f.budget_level,max_prep_minutes:Number(f.max_prep_minutes||30),style:f.style,include_ingredients:f.include_ingredients,exclude_ingredients:f.exclude_ingredients,compatible_goals:['GENERAL_WELLNESS','MAINTAIN'],dietary_patterns:['OMNIVORE']}});toast('Prompt creado.');openNutritionAiBatch(batch.id);}catch(err){$('nutritionAiError').textContent=errorMessage(err);}finally{btn.disabled=false;}};}
  async function openNutritionAiBatch(id){openModal('Laboratorio de prompts','Cargando…','<div class="loading">Cargando…</div>');try{const b=await request(`/nutrition-engine/ai/batches/${encodeURIComponent(id)}`);$('modalTitle').textContent=b.title;$('modalSubtitle').textContent=`${b.status} · ${dateTime(b.created_at)}`;$('modalBody').innerHTML=`<label>Prompt generado<textarea id="nutritionPromptText" rows="14" readonly>${esc(b.prompt_text)}</textarea></label><div class="form-actions"><button id="copyNutritionPrompt" class="button secondary">Copiar prompt</button></div><form id="nutritionAiImportForm"><label>Respuesta JSON de la IA<textarea name="response" rows="12" placeholder='{"pills":[...]}'></textarea></label><div class="form-actions"><button class="button primary">Validar propuestas</button></div><div class="form-error" id="nutritionAiImportError"></div></form><h3>Propuestas</h3>${nutritionAiProposalsHtml(b.proposals||[])}`;$('copyNutritionPrompt').onclick=async()=>{await navigator.clipboard.writeText(b.prompt_text);toast('Prompt copiado.');};$('nutritionAiImportForm').onsubmit=async e=>{e.preventDefault();const response=new FormData(e.currentTarget).get('response');const btn=e.submitter;btn.disabled=true;try{await request(`/nutrition-engine/ai/batches/${encodeURIComponent(id)}/import`,{method:'POST',body:{response}});toast('Propuestas validadas.');openNutritionAiBatch(id);}catch(err){$('nutritionAiImportError').textContent=errorMessage(err);}finally{btn.disabled=false;}};document.querySelectorAll('.nutrition-proposal-approve').forEach(x=>x.onclick=async()=>{await request(`/nutrition-engine/ai/batches/${encodeURIComponent(id)}/proposals/${encodeURIComponent(x.dataset.id)}/approve`,{method:'POST',body:{}});toast('Propuesta aprobada e incorporada.');openNutritionAiBatch(id);});document.querySelectorAll('.nutrition-proposal-reject').forEach(x=>x.onclick=async()=>{await request(`/nutrition-engine/ai/batches/${encodeURIComponent(id)}/proposals/${encodeURIComponent(x.dataset.id)}/reject`,{method:'POST',body:{}});toast('Propuesta rechazada.');openNutritionAiBatch(id);});}catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}}
  function nutritionAiProposalsHtml(rows){return rows.length?`<div class="list">${rows.map(p=>`<div class="list-item"><div><strong>${esc(p.payload?.name||p.proposed_code||'Propuesta')}</strong><div class="meta">${esc(p.payload?.portion_text||'')} · ${esc((p.validation_errors||[]).join(' '))}</div></div><div>${badge(p.status)} ${p.status==='VALIDATED'?`<button class="button small success nutrition-proposal-approve" data-id="${esc(p.id)}">Aprobar</button> <button class="button small danger nutrition-proposal-reject" data-id="${esc(p.id)}">Rechazar</button>`:''}</div></div>`).join('')}</div>`:empty('Sin propuestas','Pega el JSON producido por la IA y RHINO lo validará.');}


  async function loadNutritionCampaigns(force=false){
    if(!force&&state.nutritionCampaigns.length)return state.nutritionCampaigns;
    state.nutritionCampaigns=await request('/nutrition-campaigns');
    return state.nutritionCampaigns;
  }
  function nutritionStageLabel(stage){return nutritionStageLabels[String(stage||'').toUpperCase()]||stage||'—';}
  async function renderNutritionCampaigns(detailId=null){
    if(detailId){await renderNutritionCampaignDetail(detailId);return;}
    setHeader('Seguimiento nutricional','Invitaciones personalizadas para degustación y actualización de pesaje.');
    const rows=await loadNutritionCampaigns(true);
    content.innerHTML=`<div class="page-actions"><div><h2>Campañas de seguimiento</h2><p class="muted compact">Filtra personas con alimentación recomendada, selecciónalas mediante checklist y sigue cada invitación en su propio pipeline.</p></div>${has('nutrition_campaigns.create')?'<button id="newNutritionCampaign" class="button primary">Nueva campaña</button>':''}</div>
      <div class="filters two"><input id="nutritionCampaignSearch" placeholder="Buscar campaña"><select id="nutritionCampaignStatus"><option value="">Todos los estados</option><option value="DRAFT">Borradores</option><option value="READY">Listas</option><option value="ACTIVE">Activas</option><option value="CLOSED">Cerradas</option></select><button id="filterNutritionCampaigns" class="button secondary">Filtrar</button></div>
      <div id="nutritionCampaignResults">${nutritionCampaignCards(rows)}</div>`;
    $('newNutritionCampaign')?.addEventListener('click',()=>openNutritionCampaignForm());
    $('filterNutritionCampaigns')?.addEventListener('click',async()=>{
      const q=new URLSearchParams();
      if($('nutritionCampaignSearch').value)q.set('search',$('nutritionCampaignSearch').value);
      if($('nutritionCampaignStatus').value)q.set('status',$('nutritionCampaignStatus').value);
      state.nutritionCampaigns=await request('/nutrition-campaigns?'+q);
      $('nutritionCampaignResults').innerHTML=nutritionCampaignCards(state.nutritionCampaigns);
      bindNutritionCampaignCards();
    });
    $('nutritionCampaignSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter')$('filterNutritionCampaigns').click();});
    bindNutritionCampaignCards();
  }
  function nutritionCampaignCards(rows){
    return rows.length?`<div class="grid">${rows.map(c=>`<article class="card card-pad span-4"><div class="event-head"><div><span class="badge purple">Seguimiento nutricional</span><h3>${esc(c.name)}</h3></div>${badge(c.status)}</div><p class="muted">${esc(c.linked_event_name||'Sin evento de degustación vinculado')}</p><div class="event-date">${c.scheduled_at?dateTime(c.scheduled_at):'Fecha por confirmar'}</div><div class="kpi-strip event-kpis"><div class="kpi-chip"><strong>${Number(c.members_count||0)}</strong><small>Seleccionados</small></div><div class="kpi-chip"><strong>${Number(c.confirmed_count||0)}</strong><small>Confirmados</small></div><div class="kpi-chip"><strong>${Number(c.reweighed_count||0)}</strong><small>Nuevos pesajes</small></div></div><div class="form-actions"><button class="button small secondary nutrition-campaign-open" data-id="${esc(c.id)}">Abrir campaña</button></div></article>`).join('')}</div>`:empty('Sin campañas de seguimiento','Crea una campaña para invitar a personas que ya recibieron alimentación recomendada.');
  }
  function bindNutritionCampaignCards(){document.querySelectorAll('.nutrition-campaign-open').forEach(b=>b.onclick=()=>{location.hash=`#followups/${encodeURIComponent(b.dataset.id)}`;});}
  async function openNutritionCampaignForm(campaign=null){
    const [events,users,teams]=await Promise.all([
      has('campaigns.read')?loadCampaigns(true):Promise.resolve([]),
      teamMode()&&has('users.read')?loadUsers(true):Promise.resolve([]),
      teamMode()&&has('teams.read')?loadTeams(true):Promise.resolve([])
    ]);
    const f=campaign?.source_filters||{};
    openModal(campaign?'Editar campaña de seguimiento':'Nueva campaña de seguimiento','La campaña crea una fotografía de las personas seleccionadas; nada se envía hasta que confirmes el checklist.',`<form id="nutritionCampaignForm"><div class="form-grid">
      <label class="full">Nombre<input name="name" required value="${esc(campaign?.name||'Degustación y actualización RHINO')}" placeholder="Degustación y actualización RHINO — agosto"></label>
      <label>Evento de degustación<select name="linked_event_id"><option value="">Sin evento vinculado</option>${events.map(e=>`<option value="${esc(e.id)}" ${e.id===campaign?.linked_event_id?'selected':''}>${esc(e.name)} · ${dateTime(e.starts_at)}</option>`).join('')}</select></label>
      <label>Fecha programada<input name="scheduled_at" type="datetime-local" value="${campaign?.scheduled_at?dateInput(campaign.scheduled_at):''}"></label>
      ${teamMode()?`<label>Equipo<select name="team_id"><option value="">Sin equipo específico</option>${teams.map(t=>`<option value="${esc(t.id)}" ${t.id===campaign?.team_id?'selected':''}>${esc(t.name)}</option>`).join('')}</select></label><label>Responsable<select name="responsible_user_id"><option value="">Sin responsable</option>${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}" ${u.id===campaign?.responsible_user_id?'selected':''}>${esc(u.name)}</option>`).join('')}</select></label>`:`<div class="full inline-notice"><strong>Responsable automático:</strong> ${esc(state.me?.name||'Socio')}.</div>`}
      <label>Plan recomendado desde<input name="plan_from" type="date" value="${esc(f.plan_from||'')}"></label><label>Plan recomendado hasta<input name="plan_to" type="date" value="${esc(f.plan_to||'')}"></label>
      <label>Días mínimos desde el plan<input name="min_days_since_plan" type="number" min="0" max="3650" value="${Number(f.min_days_since_plan??30)}"></label><label>Días máximos desde el plan<input name="max_days_since_plan" type="number" min="0" max="3650" value="${f.max_days_since_plan??''}"></label>
      <label class="check-field"><input name="delivered_only" type="checkbox" ${f.delivered_only!==false?'checked':''}> Solo planes enviados o entregados</label>
      <label class="check-field"><input name="no_measurement_after_plan" type="checkbox" ${f.no_measurement_after_plan!==false?'checked':''}> Sin pesaje posterior al plan</label>
      <label class="check-field"><input name="require_marketing_consent" type="checkbox" ${f.require_marketing_consent!==false?'checked':''}> Con autorización para invitaciones</label>
      <label>Plantilla WhatsApp aprobada<input name="whatsapp_template_name" value="${esc(campaign?.whatsapp_template_name||'')}" placeholder="rhino_invitacion_seguimiento"></label>
      <label class="full">Notas<textarea name="notes" placeholder="Objetivo, instrucciones para responsables, datos de la degustación…">${esc(campaign?.notes||'')}</textarea></label>
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">${campaign?'Guardar cambios':'Crear campaña'}</button></div><div class="form-error" id="nutritionCampaignFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('nutritionCampaignForm').onsubmit=async e=>{
      e.preventDefault();const form=e.currentTarget,fd=new FormData(form),button=e.submitter;button.disabled=true;
      const body={
        name:fd.get('name'),linked_event_id:fd.get('linked_event_id')||null,scheduled_at:fd.get('scheduled_at')||null,
        team_id:teamMode()?(fd.get('team_id')||null):null,responsible_user_id:teamMode()?(fd.get('responsible_user_id')||null):myUserId(),
        whatsapp_template_name:fd.get('whatsapp_template_name')||null,notes:fd.get('notes')||null,
        source_filters:{plan_from:fd.get('plan_from')||null,plan_to:fd.get('plan_to')||null,min_days_since_plan:fd.get('min_days_since_plan')!==''?Number(fd.get('min_days_since_plan')):null,max_days_since_plan:fd.get('max_days_since_plan')!==''?Number(fd.get('max_days_since_plan')):null,delivered_only:fd.has('delivered_only'),no_measurement_after_plan:fd.has('no_measurement_after_plan'),require_marketing_consent:fd.has('require_marketing_consent'),limit:500}
      };
      try{const result=await request(campaign?`/nutrition-campaigns/${encodeURIComponent(campaign.id)}`:'/nutrition-campaigns',{method:campaign?'PATCH':'POST',body});state.nutritionCampaigns=[];closeModal();toast(campaign?'Campaña actualizada.':'Campaña creada.');location.hash=`#followups/${encodeURIComponent(result.campaign?.id||result.id||campaign?.id)}`;}
      catch(err){$('nutritionCampaignFormError').textContent=errorMessage(err);}finally{button.disabled=false;}
    };
  }
  async function renderNutritionCampaignDetail(id){
    setHeader('Campaña de seguimiento','Checklist, invitaciones individuales y pipeline por persona.');
    const data=await request(`/nutrition-campaigns/${encodeURIComponent(id)}`),c=data.campaign,r=data.report||{},members=data.members||[];
    const filters=c.source_filters||{};
    content.innerHTML=`<div class="page-actions"><div><a href="#followups" class="back-link">← Campañas</a><h2>${esc(c.name)}</h2><p class="muted compact">${esc(c.linked_event_name||'Sin evento vinculado')} · ${c.scheduled_at?dateTime(c.scheduled_at):'Fecha por confirmar'}</p></div><div class="actions">${badge(c.status)}${has('nutrition_campaigns.update')&&!['CLOSED','CANCELLED'].includes(c.status)?'<button id="editNutritionCampaign" class="button secondary">Editar</button>':''}${has('nutrition_campaigns.update')&&['DRAFT','READY'].includes(c.status)?'<button id="activateNutritionCampaign" class="button success">Activar</button>':''}${has('nutrition_campaigns.update')&&c.status==='ACTIVE'?'<button id="closeNutritionCampaign" class="button danger">Cerrar</button>':''}</div></div>
      <div class="grid">${metric('Seleccionados',r.total_members||0,'Checklist de campaña','span-3')}${metric('Confirmados',r.confirmed_count||0,`${Number(r.confirmation_rate||0)} %`,'span-3 ok')}${metric('Asistieron',r.attended_count||0,`${Number(r.attendance_rate||0)} %`,'span-3')}${metric('Nuevos pesajes',r.reweighed_count||0,`${Number(r.reweigh_rate||0)} %`,'span-3 ok')}${metric('Interesados',r.interested_count||0,'Después de la degustación','span-3')}${metric('Ventas',r.sales_count||0,'Atribuidas al seguimiento','span-3 ok')}</div>
      <section class="card card-pad"><div class="page-actions"><div><h3>Criterios de selección</h3><p class="muted compact">${Number(filters.min_days_since_plan||0)} días desde el plan · ${filters.delivered_only!==false?'plan entregado · ':''}${filters.no_measurement_after_plan!==false?'sin pesaje posterior · ':''}${filters.require_marketing_consent!==false?'con autorización WhatsApp':''}</p></div>${has('nutrition_campaigns.create')&&!['CLOSED','CANCELLED'].includes(c.status)?'<button id="searchNutritionCandidates" class="button secondary">Buscar candidatos</button>':''}</div><div id="nutritionCandidateResults"></div></section>
      <section class="card card-pad"><div class="page-actions"><div><h3>Checklist de personas</h3><p class="muted compact">Marca únicamente a quienes deseas invitar. Cada mensaje se procesa de forma individual.</p></div>${has('nutrition_campaigns.invite')&&!['CLOSED','CANCELLED'].includes(c.status)?'<button id="dispatchNutritionInvitations" class="button primary">Enviar seleccionados</button>':''}</div>${nutritionMemberTable(members)}</section>
      <section class="card card-pad"><h3>Pipeline de campaña</h3>${nutritionPipeline(data.pipeline||{},id)}</section>`;
    $('editNutritionCampaign')?.addEventListener('click',()=>openNutritionCampaignForm(c));
    $('activateNutritionCampaign')?.addEventListener('click',async()=>{await request(`/nutrition-campaigns/${encodeURIComponent(id)}/activate`,{method:'POST',body:{}});toast('Campaña activada.');renderNutritionCampaignDetail(id);});
    $('closeNutritionCampaign')?.addEventListener('click',async()=>{if(!confirm('¿Cerrar esta campaña de seguimiento?'))return;await request(`/nutrition-campaigns/${encodeURIComponent(id)}/close`,{method:'POST',body:{}});toast('Campaña cerrada.');renderNutritionCampaignDetail(id);});
    $('searchNutritionCandidates')?.addEventListener('click',async()=>{const button=$('searchNutritionCandidates');button.disabled=true;button.textContent='Buscando…';try{const result=await request('/nutrition-campaigns/candidates/search',{method:'POST',body:{...filters,campaign_id:id,limit:500}});$('nutritionCandidateResults').innerHTML=nutritionCandidateChecklist(result.candidates||[],id);bindNutritionCandidateChecklist(id);}catch(e){$('nutritionCandidateResults').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}finally{button.disabled=false;button.textContent='Buscar candidatos';}});
    $('dispatchNutritionInvitations')?.addEventListener('click',async()=>{const ids=[...document.querySelectorAll('.nutrition-member-check:checked')].map(x=>x.value);if(!ids.length){toast('Selecciona al menos una persona.','bad');return;}if(!confirm(`Se programarán ${ids.length} invitaciones individuales por WhatsApp. ¿Continuar?`))return;const result=await request(`/nutrition-campaigns/${encodeURIComponent(id)}/dispatch`,{method:'POST',body:{member_ids:ids}});toast(`${Number(result.queued_count||0)} invitaciones programadas.`);renderNutritionCampaignDetail(id);});
    bindNutritionMemberActions(id);
  }
  function nutritionCandidateChecklist(rows,campaignId){
    if(!rows.length)return empty('Sin candidatos','No hay personas que cumplan los filtros actuales.');
    return `<div class="checklist-toolbar"><label><input type="checkbox" id="nutritionCandidateAll"> Seleccionar todos</label><button id="addNutritionCandidates" class="button primary">Agregar seleccionados</button></div><div class="table-wrap"><table><thead><tr><th></th><th>Persona</th><th>Plan recomendado</th><th>Último pesaje</th><th>Días</th><th>Entrega</th></tr></thead><tbody>${rows.map(x=>`<tr><td><input type="checkbox" class="nutrition-candidate-check" value="${esc(x.contact_id)}"></td><td class="name-cell"><strong>${esc(x.name)}</strong><small>${esc(x.whatsapp)} · ${esc(x.public_code||'Sin código')}</small></td><td>${dateTime(x.plan_recommended_at)}</td><td>${dateTime(x.last_measurement_at)}</td><td>${Number(x.days_since_plan||0)}</td><td>${badge(x.plan_delivery_status||'PENDING')}</td></tr>`).join('')}</tbody></table></div>`;
  }
  function bindNutritionCandidateChecklist(campaignId){
    $('nutritionCandidateAll')?.addEventListener('change',e=>document.querySelectorAll('.nutrition-candidate-check').forEach(x=>x.checked=e.target.checked));
    $('addNutritionCandidates')?.addEventListener('click',async()=>{const ids=[...document.querySelectorAll('.nutrition-candidate-check:checked')].map(x=>x.value);if(!ids.length){toast('Selecciona al menos una persona.','bad');return;}const result=await request(`/nutrition-campaigns/${encodeURIComponent(campaignId)}/members`,{method:'POST',body:{contact_ids:ids}});toast(`${Number(result.added_count||0)} personas agregadas.`);renderNutritionCampaignDetail(campaignId);});
  }
  function nutritionMemberTable(rows){
    if(!rows.length)return empty('Checklist vacío','Busca candidatos y selecciona a quienes deseas invitar.');
    return `<div class="table-wrap"><table><thead><tr><th></th><th>Persona</th><th>Plan</th><th>Último pesaje</th><th>Responsable</th><th>Etapa</th><th>Invitación</th><th></th></tr></thead><tbody>${rows.map(m=>`<tr><td><input type="checkbox" class="nutrition-member-check" value="${esc(m.id)}" ${['OPTED_OUT','SALE_COMPLETED'].includes(m.stage)?'disabled':''}></td><td class="name-cell"><strong>${esc(m.contact_name)}</strong><small>${esc(m.whatsapp)} · ${esc(m.public_code||'Sin código')}</small></td><td>${dateTime(m.plan_recommended_at)}</td><td>${dateTime(m.last_measurement_at)}</td><td>${esc(m.responsible_name||'Socio')}</td><td>${badge(m.stage)}</td><td>${badge(m.invitation_status)}</td><td><select class="nutrition-stage-change" data-member-id="${esc(m.id)}"><option value="">Cambiar etapa…</option>${nutritionPipelineOrder.filter(x=>x!==m.stage).map(x=>`<option value="${x}">${esc(nutritionStageLabel(x))}</option>`).join('')}</select> ${['SELECTED','INVITATION_PENDING'].includes(m.stage)?`<button class="button small danger nutrition-member-remove" data-member-id="${esc(m.id)}">Retirar</button>`:''}</td></tr>`).join('')}</tbody></table></div>`;
  }
  function nutritionPipeline(pipeline,campaignId){
    const stages=nutritionPipelineOrder.filter(stage=>(pipeline[stage]||[]).length||['SELECTED','INVITATION_SENT','CONFIRMED','ATTENDED','REWEIGHED','INTERESTED','SALE_COMPLETED'].includes(stage));
    return `<div class="pipeline nutrition-pipeline">${stages.map(stage=>`<section class="pipeline-column"><div class="pipeline-head"><span>${esc(nutritionStageLabel(stage))}</span><strong>${Number((pipeline[stage]||[]).length)}</strong></div><div class="pipeline-list">${(pipeline[stage]||[]).length?(pipeline[stage]||[]).map(m=>`<article class="lead-card"><strong>${esc(m.contact_name)}</strong><span class="phone">${esc(m.whatsapp)}</span><div class="lead-meta"><span class="badge blue">Plan ${dateTime(m.plan_recommended_at)}</span></div><div class="lead-actions"><select class="nutrition-stage-change" data-member-id="${esc(m.id)}"><option value="">Mover…</option>${nutritionPipelineOrder.filter(x=>x!==stage).map(x=>`<option value="${x}">${esc(nutritionStageLabel(x))}</option>`).join('')}</select></div></article>`).join(''):'<div class="pipeline-empty">Sin personas</div>'}</div></section>`).join('')}</div>`;
  }
  function bindNutritionMemberActions(campaignId){
    document.querySelectorAll('.nutrition-stage-change').forEach(select=>select.addEventListener('change',async()=>{if(!select.value)return;const note=prompt('Nota opcional para este cambio:','')||null;try{await request(`/nutrition-campaigns/${encodeURIComponent(campaignId)}/members/${encodeURIComponent(select.dataset.memberId)}/stage`,{method:'PATCH',body:{stage:select.value,note}});toast('Etapa actualizada.');renderNutritionCampaignDetail(campaignId);}catch(e){toast(errorMessage(e),'bad');select.value='';}}));
    document.querySelectorAll('.nutrition-member-remove').forEach(button=>button.addEventListener('click',async()=>{if(!confirm('¿Retirar a esta persona del checklist?'))return;await request(`/nutrition-campaigns/${encodeURIComponent(campaignId)}/members/${encodeURIComponent(button.dataset.memberId)}`,{method:'DELETE'});toast('Persona retirada.');renderNutritionCampaignDetail(campaignId);}));
  }

  async function renderContacts(lifecycle){
    const isClient=lifecycle==='CUSTOMER'; setHeader(isClient?'Clientes':'Prospectos',isClient?'Expedientes convertidos y su historial.':'Captación y seguimiento.');
    const [users,teams,campaigns]=await Promise.all([
      teamMode()&&has('users.read')?loadUsers(true):Promise.resolve([]),
      teamMode()&&has('teams.read')?loadTeams(true):Promise.resolve([]),
      has('campaigns.read')?loadCampaigns(true):Promise.resolve([]),
    ]);
    const teamFilters=teamMode()?`<select id="contactAdvisor"><option value="">Todos los responsables</option>${users.map(u=>`<option value="${esc(u.id)}">${esc(u.name)}</option>`).join('')}</select><select id="contactTeam"><option value="">Todos los equipos</option>${teams.map(t=>`<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('')}</select>`:'';
    content.innerHTML=`<div class="page-actions"><div><h2>${isClient?'Clientes':'Prospectos'}</h2><p class="muted compact">${teamMode()?'Información por responsable, equipo y evento.':'Todos los registros quedan bajo tu responsabilidad.'}</p></div>${has('contacts.create')?'<button id="newContact" class="button primary">Nuevo prospecto</button>':''}</div>
      <div class="filters"><input id="contactSearch" placeholder="Buscar por nombre o WhatsApp"><select id="contactStage"><option value="">Todas las etapas</option>${(state.stages.length?state.stages:stageFallback).map(s=>`<option value="${s.id}">${esc(s.label)}</option>`).join('')}</select>${teamFilters}<select id="contactCampaign"><option value="">Todos los eventos</option>${campaigns.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('')}</select><button id="filterContacts" class="button secondary">Filtrar</button></div><div id="contactResults"></div>`;
    $('newContact')?.addEventListener('click',()=>openContactForm());
    $('filterContacts').onclick=()=>loadContactResults(lifecycle);
    $('contactSearch').addEventListener('keydown',e=>{if(e.key==='Enter')loadContactResults(lifecycle);});
    await loadContactResults(lifecycle);
  }
  async function loadContactResults(lifecycle){
    const q=new URLSearchParams({limit:'200',lifecycle});
    const values={
      search:$('contactSearch')?.value||'',
      stage:$('contactStage')?.value||'',
      advisor_id:teamMode()?($('contactAdvisor')?.value||''):'',
      team_id:teamMode()?($('contactTeam')?.value||''):'',
      campaign_id:$('contactCampaign')?.value||''
    };
    Object.entries(values).forEach(([k,v])=>{if(v)q.set(k,v);});
    const data=await request('/contacts?'+q);state.contacts=data.items||[];
    $('contactResults').innerHTML=state.contacts.length?`<div class="table-wrap"><table><thead><tr><th>Contacto</th><th>Etapa</th><th>Responsable</th><th>Pesajes</th><th>Creado</th><th></th></tr></thead><tbody>${state.contacts.map(c=>`<tr><td class="name-cell"><strong>${esc(c.name)}</strong><small>${esc(c.whatsapp)}</small></td><td>${badge(c.sales_stage)}</td><td>${esc(userName(c.assigned_advisor_id))}</td><td>${Number(c.total_measurements||0)}</td><td>${dateTime(c.created_at)}</td><td><button class="button small secondary contact-view" data-id="${esc(c.id)}">Ver</button> ${Number(c.total_measurements||0)>0?`<button class="button small secondary contact-weight" data-id="${esc(c.id)}">Pesaje</button>`:''} ${has('contacts.update')?`<button class="button small secondary contact-edit" data-id="${esc(c.id)}">Editar</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('No hay resultados',lifecycle==='CUSTOMER'?'Los prospectos convertidos aparecerán aquí.':'Registra el primer prospecto para iniciar el seguimiento.');
    document.querySelectorAll('.contact-view').forEach(b=>b.onclick=()=>openContactDetail(b.dataset.id));
    document.querySelectorAll('.contact-weight').forEach(b=>b.onclick=()=>openContactDetail(b.dataset.id,'measurements'));
    document.querySelectorAll('.contact-edit').forEach(b=>b.onclick=()=>openContactForm(state.contacts.find(c=>c.id===b.dataset.id)));
  }
  function userName(id){if(!id)return 'Sin asignar';if(!teamMode()&&id===myUserId())return state.me?.name||'Socio';return state.users.find(u=>u.id===id)?.name || 'Responsable asignado';}
  async function openContactForm(contact=null){
    const [users,teams]=await Promise.all([
      teamMode()&&has('users.read')?loadUsers():Promise.resolve([]),
      teamMode()&&has('teams.read')?loadTeams():Promise.resolve([])
    ]);
    const assignmentFields=teamMode()?`
      <label>Responsable<select name="assigned_advisor_id"><option value="">Sin asignar</option>${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}" ${contact?.assigned_advisor_id===u.id?'selected':''}>${esc(u.name)}</option>`).join('')}</select></label>
      <label>Equipo<select name="team_id"><option value="">Sin equipo</option>${teams.map(t=>`<option value="${esc(t.id)}" ${contact?.origin_team_id===t.id?'selected':''}>${esc(t.name)}</option>`).join('')}</select></label>`
      :`<div class="full inline-notice"><strong>Responsable automático:</strong> ${esc(state.me?.name||'Socio')}</div>`;
    openModal(contact?'Editar contacto':'Nuevo prospecto',contact?'Actualiza la ficha sin perder el historial.':'El prospecto quedará bajo tu responsabilidad.',`<form id="contactForm"><div class="form-grid">
      <label>Nombre<input name="name" value="${esc(contact?.name||'')}" required></label>${phoneField(contact?.whatsapp||'')}
      <label>Edad<input name="age" type="number" min="1" max="130" value="${esc(contact?.age||'')}"></label><label>Género<select name="gender"><option value="">Sin especificar</option>${['FEMALE','MALE','OTHER'].map(x=>`<option ${contact?.gender===x?'selected':''}>${x}</option>`).join('')}</select></label>
      <label>Altura cm<input name="height_cm" type="number" step="0.01" min="30" max="300" value="${esc(contact?.height_cm||'')}"></label><label>Perfil<input name="profile" value="${esc(contact?.profile||'')}"></label>
      ${assignmentFields}
      ${contact?'':`<label class="full">Origen<select name="source"><option value="MANUAL">Registro manual</option><option value="SCALE">Báscula</option><option value="WHATSAPP">WhatsApp</option><option value="CAMPAIGN">Campaña</option><option value="REFERRAL">Recomendación</option></select></label>`}
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">${contact?'Guardar cambios':'Crear prospecto'}</button></div><div class="form-error" id="contactFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('contactForm').onsubmit=async e=>{
      e.preventDefault();const body=Object.fromEntries(new FormData(e.currentTarget));
      body.whatsapp=normalizePhone(body.whatsapp);
      ['age','height_cm','assigned_advisor_id','team_id','gender','profile'].forEach(k=>{if(body[k]==='')body[k]=null;});
      if(!teamMode()){body.assigned_advisor_id=myUserId();body.team_id=null;}
      const btn=e.submitter;btn.disabled=true;
      try{await request(contact?`/contacts/${encodeURIComponent(contact.id)}`:'/contacts',{method:contact?'PATCH':'POST',body});closeModal();toast(contact?'Contacto actualizado.':'Prospecto creado.');route();}
      catch(err){$('contactFormError').textContent=errorMessage(err);}
      finally{btn.disabled=false;}
    };
  }
  async function openContactDetail(id,initialTab='history'){
    openModal('Expediente','Cargando…','<div class="loading">Cargando expediente…</div>');
    try{
      const [c,timeline,measurementDashboard]=await Promise.all([request(`/contacts/${encodeURIComponent(id)}`),request(`/contacts/${encodeURIComponent(id)}/timeline?limit=100`),request(`/contacts/${encodeURIComponent(id)}/measurement-dashboard?limit=30`)]);
      const validTabs=['history','info','measurements',...(has('nutrition.read')?['nutrition']:[])];if(!validTabs.includes(initialTab))initialTab='history';
      const tab=(value,label)=>`<button class="${initialTab===value?'active':''}" data-detail-tab="${value}">${label}</button>`;
      const hidden=value=>initialTab===value?'':' class="hidden"';
      $('modalTitle').textContent=c.name;$('modalSubtitle').textContent=`${c.whatsapp} · ${stageLabel(c.sales_stage)}`;
      $('modalBody').innerHTML=`<div class="kpi-strip"><div class="kpi-chip"><strong>${esc(stageLabel(c.sales_stage))}</strong><small>Etapa comercial</small></div><div class="kpi-chip"><strong>${Number(c.total_measurements||0)}</strong><small>Pesajes</small></div><div class="kpi-chip"><strong>${esc(c.lifecycle_stage)}</strong><small>Ciclo de vida</small></div></div>
        <div class="tabs">${tab('history','Historial')}${tab('info','Información')}${tab('measurements','Pesaje')}${has('nutrition.read')?tab('nutrition','Nutrición'):''}</div>
        <section id="detail-history"${hidden('history')}><form id="noteForm"><div class="form-grid"><label class="full">Agregar nota<textarea name="note" required maxlength="5000" placeholder="Resultado de llamada, acuerdo, observación…"></textarea></label></div><div class="form-actions"><button class="button primary">Guardar nota</button></div></form><div class="timeline">${timeline.length?timeline.map(a=>`<div class="timeline-item"><strong>${esc(a.description)}</strong><p>${esc(stageLabel(a.type)||a.type)}</p><small>${dateTime(a.created_at)}</small></div>`).join(''):empty('Sin actividad','Agrega la primera nota de seguimiento.')}</div></section>
        <section id="detail-info"${hidden('info')}><div class="table-wrap"><table><tbody><tr><th>WhatsApp</th><td>${esc(c.whatsapp)}</td></tr><tr><th>Prospecto registrado por</th><td>${esc(c.registered_by_partner_name||c.registered_by_partner_id||'—')}</td></tr><tr><th>Socio responsable</th><td>${esc(c.responsible_partner_name||c.responsible_partner_id||'—')}</td></tr><tr><th>Responsable</th><td>${esc(userName(c.assigned_advisor_id))}</td></tr><tr><th>Perfil</th><td>${esc(c.profile||'—')}</td></tr><tr><th>Edad</th><td>${esc(c.age||'—')}</td></tr><tr><th>Altura</th><td>${c.height_cm?`${esc(c.height_cm)} cm`:'—'}</td></tr><tr><th>Origen</th><td>${esc(c.contact_source?.type||'—')}</td></tr><tr><th>Creado</th><td>${dateTime(c.created_at)}</td></tr></tbody></table></div></section>
        <section id="detail-measurements"${hidden('measurements')}>${measurementDashboardHtml(measurementDashboard)}</section>
        ${has('nutrition.read')?`<section id="detail-nutrition"${hidden('nutrition')}><div id="contactNutritionPanel" class="loading">${initialTab==='nutrition'?'Cargando nutrición…':'Abre la pestaña para cargar el plan…'}</div></section>`:''}`;
      let nutritionLoaded=false;
      const loadNutrition=async()=>{if(nutritionLoaded||!has('nutrition.read'))return;nutritionLoaded=true;await loadContactNutritionPanel(id);};
      document.querySelectorAll('[data-detail-tab]').forEach(btn=>btn.onclick=async()=>{document.querySelectorAll('[data-detail-tab]').forEach(x=>x.classList.toggle('active',x===btn));validTabs.forEach(x=>{const section=$(`detail-${x}`);if(section)section.classList.toggle('hidden',x!==btn.dataset.detailTab);});if(btn.dataset.detailTab==='nutrition')await loadNutrition();});
      if(initialTab==='nutrition')await loadNutrition();
      $('noteForm').onsubmit=async e=>{e.preventDefault();const note=new FormData(e.currentTarget).get('note');const btn=e.submitter;btn.disabled=true;try{await request(`/contacts/${encodeURIComponent(id)}/timeline`,{method:'POST',body:{note}});toast('Nota guardada.');openContactDetail(id,'history');}catch(err){toast(errorMessage(err),'bad');}finally{btn.disabled=false;}};
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }
  async function loadContactNutritionPanel(contactId){const panel=$('contactNutritionPanel');if(!panel)return;panel.innerHTML='<div class="loading">Cargando nutrición…</div>';try{const [workspace,templates]=await Promise.all([request(`/nutrition-engine/contacts/${encodeURIComponent(contactId)}`),request('/nutrition-engine/templates')]);const templateRows=templates?.items||templates||[];const plan=(workspace.plans||[])[0];panel.innerHTML=`<div class="page-actions"><div><h3>RHINO Nutrición</h3><p class="muted compact">${Number(workspace.contact?.total_measurements||0)} pesaje(s) · ${esc(nutritionSafetyLabel(workspace.safety?.status))}</p></div><button id="openFullNutrition" class="button secondary">Abrir módulo completo</button></div>${plan?`<div class="card card-pad nutrition-current-plan"><div class="page-actions"><div><h3>${esc(plan.plan_snapshot?.title||'Plan alimenticio vigente')}</h3><p class="muted compact">${dateTime(plan.generated_at)} · ${esc(plan.template_name||plan.plan_snapshot?.template_name||'Plantilla automática')}</p></div>${badge(plan.delivery_status)}</div><p><strong>Objetivo:</strong> ${esc(nutritionGoalLabel(plan.plan_snapshot?.goal))}</p><div class="form-actions"><button id="contactViewNutritionPlan" class="button secondary">Ver plan y recetas</button><button id="contactShareNutritionPlan" class="button primary">Compartir PDF</button><button id="contactDirectNutritionPlan" class="button success">Enviar directo</button><button id="contactGenerateNutritionPlan" class="button secondary">Nueva versión</button></div></div>`:`<div class="inline-notice"><strong>Sin plan vigente.</strong> Genera un plan con el último pesaje y la biblioteca aprobada.</div><div class="form-actions"><button id="contactGenerateNutritionPlan" class="button primary" ${workspace.latest_measurement_id?'':'disabled'}>Generar plan alimenticio</button></div>${workspace.latest_measurement_id?'':'<div class="form-error">Primero registra un pesaje.</div>'}`}`;
        $('openFullNutrition').onclick=()=>{closeModal();location.hash=`#nutrition/${encodeURIComponent(contactId)}`;};
        $('contactViewNutritionPlan')?.addEventListener('click',()=>openNutritionPlanDetail(plan.id));
        $('contactShareNutritionPlan')?.addEventListener('click',()=>shareNutritionPlanPdf(plan.id));
        $('contactDirectNutritionPlan')?.addEventListener('click',()=>sendNutritionPlanDirect(plan.id));
        $('contactGenerateNutritionPlan')?.addEventListener('click',()=>openNutritionGenerateForm(contactId,workspace.latest_measurement_id,templateRows));
      }catch(e){panel.innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}}

  async function renderAgenda(){
    setHeader('Agenda','Citas, llamadas y seguimientos de prospectos.');
    const [tasks,contacts,users]=await Promise.all([request('/tasks?limit=200'),request('/contacts?limit=200'),has('users.read')?loadUsers(true):Promise.resolve([])]);state.contacts=contacts.items||[];
    const pending=tasks.filter(t=>t.status==='PENDING'), done=tasks.filter(t=>t.status==='COMPLETED');
    content.innerHTML=`<div class="page-actions"><div><h2>Actividades</h2><p class="muted compact">${pending.length} pendientes · ${pending.filter(t=>t.overdue).length} vencidas.</p></div>${has('tasks.create')?'<button id="newTask" class="button primary">Agendar actividad</button>':''}</div>
      <div class="tabs"><button class="active" data-agenda="pending">Pendientes</button><button data-agenda="completed">Completadas</button></div><div id="agendaList">${taskList(pending,true)}</div>`;
    $('newTask')?.addEventListener('click',openTaskForm);
    document.querySelectorAll('[data-agenda]').forEach(b=>b.onclick=()=>{document.querySelectorAll('[data-agenda]').forEach(x=>x.classList.toggle('active',x===b));$('agendaList').innerHTML=taskList(b.dataset.agenda==='pending'?pending:done,b.dataset.agenda==='pending');bindTaskActions();});bindTaskActions();
  }
  function taskList(tasks,actions=false){return tasks.length?`<div class="list">${tasks.map(t=>`<div class="list-item ${t.overdue?'overdue':isToday(t.due_at)?'today':''}"><div><strong>${esc(t.title)}</strong><div class="meta">${esc(t.contact_name||contactName(t.contact_id))} · ${esc(t.type)} · ${dateTime(t.due_at)}</div>${t.outcome?`<div class="meta">Resultado: ${esc(t.outcome)}</div>`:''}</div><div>${badge(t.status)} ${actions?`<button class="button small success complete-task" data-id="${esc(t.id)}">Completar</button> <button class="button small secondary reschedule-task" data-id="${esc(t.id)}" data-date="${esc(t.due_at)}">Reagendar</button>`:''}</div></div>`).join('')}</div>`:empty('Sin actividades','No hay tareas en este estado.');}
  function contactName(id){return state.contacts.find(c=>c.id===id)?.name || id;}
  function isToday(v){const d=new Date(v),n=new Date();return d.toDateString()===n.toDateString();}
  function bindTaskActions(){
    document.querySelectorAll('.complete-task').forEach(b=>b.onclick=async()=>{const outcome=prompt('Resultado de la actividad:');if(!outcome)return;await request(`/tasks/${encodeURIComponent(b.dataset.id)}/complete`,{method:'POST',body:{outcome}});toast('Actividad completada.');renderAgenda();});
    document.querySelectorAll('.reschedule-task').forEach(b=>b.onclick=async()=>{const due=prompt('Nueva fecha y hora (AAAA-MM-DD HH:MM):',dateInput(b.dataset.date).replace('T',' '));if(!due)return;await request(`/tasks/${encodeURIComponent(b.dataset.id)}/reschedule`,{method:'POST',body:{due_at:due.replace(' ','T')}});toast('Actividad reagendada.');renderAgenda();});
  }
  async function openTaskForm(){
    if(!state.contacts.length){const d=await request('/contacts?limit=200');state.contacts=d.items||[];}
    const users=teamMode()&&has('users.read')?await loadUsers():[];
    const assigneeField=teamMode()?`<label>Responsable<select name="assigned_user_id"><option value="">Responsable del prospecto</option>${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}">${esc(u.name)}</option>`).join('')}</select></label>`:`<div class="inline-notice"><strong>Responsable:</strong> ${esc(state.me?.name||'Socio')}</div>`;
    openModal('Agendar actividad','Crea una cita, llamada o seguimiento.',`<form id="taskForm"><div class="form-grid"><label class="full">Prospecto<select name="contact_id" required><option value="">Seleccione…</option>${state.contacts.filter(c=>c.status==='ACTIVE').map(c=>`<option value="${esc(c.id)}">${esc(c.name)} · ${esc(c.whatsapp)}</option>`).join('')}</select></label><label>Tipo<select name="type"><option value="APPOINTMENT">Cita</option><option value="CALL">Llamada</option><option value="WHATSAPP">WhatsApp</option><option value="FOLLOW_UP">Seguimiento</option><option value="MEASUREMENT">Medición</option><option value="QUOTE">Cotización</option></select></label>${assigneeField}<label class="full">Título<input name="title" required placeholder="Confirmar cita y preparar seguimiento"></label><label class="full">Fecha y hora<input name="due_at" type="datetime-local" value="${dateInput()}" required></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Agendar</button></div><div class="form-error" id="taskFormError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('taskForm').onsubmit=async e=>{e.preventDefault();const body=Object.fromEntries(new FormData(e.currentTarget));if(teamMode()){if(!body.assigned_user_id)delete body.assigned_user_id;}else{body.assigned_user_id=myUserId();}const btn=e.submitter;btn.disabled=true;try{await request('/tasks',{method:'POST',body});closeModal();toast('Actividad agendada.');renderAgenda();}catch(err){$('taskFormError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }

  async function renderPipeline(){
    setHeader('Pipeline','Avanza cada prospecto según el seguimiento comercial.'); const leads=await request('/pipeline?limit=200');
    const stages=state.stages.length?state.stages:stageFallback; const grouped=Object.fromEntries(stages.map(s=>[s.id,[]]));leads.forEach(c=>(grouped[c.sales_stage]||(grouped[c.sales_stage]=[])).push(c));
    content.innerHTML=`<div class="page-actions"><div><h2>Embudo de ventas</h2><p class="muted compact">Mover a Cliente convierte el expediente sin borrar el historial.</p></div><button class="button primary" id="pipelineNew">Nuevo prospecto</button></div><div class="pipeline">${stages.map(s=>`<section class="pipeline-column"><div class="pipeline-head"><span>${esc(s.label)}</span><span class="badge gray">${(grouped[s.id]||[]).length}</span></div><div class="pipeline-list">${(grouped[s.id]||[]).map(c=>leadCard(c,s)).join('')||'<div class="muted">Sin prospectos</div>'}</div></section>`).join('')}</div>`;
    $('pipelineNew').onclick=()=>openContactForm();
    document.querySelectorAll('.lead-open').forEach(b=>b.onclick=()=>openContactDetail(b.dataset.id));
    document.querySelectorAll('.stage-change').forEach(s=>s.onchange=async()=>{if(!s.value)return;const body={stage:s.value};if(s.value==='LOST'){const reason=prompt('Razón de pérdida:');if(!reason){s.value='';return;}body.loss_reason=reason;}s.disabled=true;try{await request(`/pipeline/${encodeURIComponent(s.dataset.id)}/stage`,{method:'PATCH',headers:{'X-Idempotency-Key':uuid()},body});toast('Etapa actualizada.');renderPipeline();}catch(e){toast(errorMessage(e),'bad');s.value='';}finally{s.disabled=false;}});
  }
  function leadCard(c,stage){const allowed=stage.allowed_to?.length?stage.allowed_to:(state.stages.length?[]:(state.stages.find(x=>x.id===stage.id)?.allowed_to||[]));return `<article class="lead-card"><strong>${esc(c.name)}</strong><span class="phone">${esc(c.whatsapp)}</span><div class="lead-meta"><span class="badge blue">${Number(c.total_measurements||0)} mediciones</span></div><div class="lead-actions"><button class="button small secondary lead-open" data-id="${esc(c.id)}">Ver</button><select class="stage-change" data-id="${esc(c.id)}"><option value="">Cambiar etapa…</option>${(allowed.length?allowed:(state.stages.length?state.stages.map(x=>x.id):stageFallback.map(x=>x.id))).filter(x=>x!==stage.id).map(x=>`<option value="${esc(x)}">${esc(stageLabel(x))}</option>`).join('')}</select></div></article>`;}

  async function renderTeams(){
    setHeader('Equipos','Coordinadores, integrantes, metas y productividad.'); const teams=await loadTeams(true);
    content.innerHTML=`<div class="page-actions"><div><h2>Equipos comerciales</h2><p class="muted compact">Organiza responsables y mide prospectos, ventas y seguimientos.</p></div>${has('teams.create')?'<button id="newTeam" class="button primary">Crear equipo</button>':''}</div>${teams.length?`<div class="grid">${teams.map(t=>`<div class="card card-pad span-4"><h3>${esc(t.name)}</h3><p class="muted">Coordinador: ${esc(t.leader_name||'Sin asignar')}</p><div>${badge(t.status)}</div><div class="form-actions"><button class="button small secondary team-open" data-id="${esc(t.id)}">Ver equipo e informe</button></div></div>`).join('')}</div>`:empty('Sin equipos','Crea equipos para organizar a los responsables.')}`;
    $('newTeam')?.addEventListener('click',openTeamForm);document.querySelectorAll('.team-open').forEach(b=>b.onclick=()=>openTeamDetail(b.dataset.id));
  }
  async function openTeamForm(){const users=await loadUsers(true);openModal('Crear equipo','Agrupa responsables y asigna un coordinador opcional.',`<form id="teamForm"><div class="form-grid"><label>Nombre<input name="name" required></label><label>Coordinador<select name="leader_user_id"><option value="">Sin coordinador</option>${users.filter(u=>u.status==='ACTIVE').map(u=>`<option value="${esc(u.id)}">${esc(u.name)}</option>`).join('')}</select></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Crear</button></div><div class="form-error" id="teamFormError"></div></form>`);$('modalBody').querySelector('[data-close]').onclick=closeModal;$('teamForm').onsubmit=async e=>{e.preventDefault();const body=Object.fromEntries(new FormData(e.currentTarget));if(!body.leader_user_id)body.leader_user_id=null;try{await request('/teams',{method:'POST',body});state.teams=[];closeModal();toast('Equipo creado.');renderTeams();}catch(err){$('teamFormError').textContent=errorMessage(err);}};}
  async function openTeamDetail(id){openModal('Equipo','Cargando…','<div class="loading">Cargando…</div>');try{const [team,report,users]=await Promise.all([request(`/teams/${encodeURIComponent(id)}`),request(`/teams/${encodeURIComponent(id)}/report?period=${periodNow()}`),loadUsers(true)]);$('modalTitle').textContent=team.name;$('modalSubtitle').textContent=`Informe ${report.period}`;$('modalBody').innerHTML=`<div class="grid">${metric('Prospectos',report.totals.prospects_generated||0,'Generados','span-4')}${metric('Ventas',report.totals.sales_closed||0,'Cerradas','span-4 ok')}${metric('Ingresos',money(report.totals.revenue_generated_minor),'Periodo','span-4')}</div><h3>Integrantes</h3>${team.members.length?`<div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Rol</th><th>Resultado</th></tr></thead><tbody>${team.members.map(m=>{const r=report.members.find(x=>x.id===m.id)||{};return `<tr><td>${esc(m.name)}</td><td>${esc(m.member_role)}</td><td>${Number(r.prospects_generated||0)} prospectos · ${Number(r.sales_closed||0)} ventas</td></tr>`;}).join('')}</tbody></table></div>`:empty('Sin integrantes','Agrega usuarios al equipo.')} ${has('teams.update')?`<form id="memberForm"><div class="form-grid"><label class="full">Agregar integrante<select name="user_id" required><option value="">Seleccione…</option>${users.filter(u=>u.status==='ACTIVE'&&!team.members.some(m=>m.id===u.id)).map(u=>`<option value="${esc(u.id)}">${esc(u.name)}</option>`).join('')}</select></label></div><div class="form-actions"><button class="button primary">Agregar</button></div></form><form id="goalForm"><h3>Meta del equipo</h3><div class="form-grid"><label>Prospectos<input name="prospects_target" type="number" min="0" value="${esc(report.goal?.prospects_target||0)}"></label><label>Mediciones<input name="measurements_target" type="number" min="0" value="${esc(report.goal?.measurements_target||0)}"></label><label>Ventas<input name="sales_target" type="number" min="0" value="${esc(report.goal?.sales_target||0)}"></label><label>Ingresos MXN<input name="revenue" type="number" min="0" step="0.01" value="${Number(report.goal?.revenue_target_minor||0)/100}"></label></div><div class="form-actions"><button class="button primary">Guardar meta</button></div></form>`:''}`;
      $('memberForm')?.addEventListener('submit',async e=>{e.preventDefault();const user_id=new FormData(e.currentTarget).get('user_id');await request(`/teams/${encodeURIComponent(id)}/members`,{method:'POST',body:{user_id,member_role:'MEMBER'}});toast('Integrante agregado.');openTeamDetail(id);});
      $('goalForm')?.addEventListener('submit',async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));await request(`/teams/${encodeURIComponent(id)}/goals`,{method:'PUT',body:{period:periodNow(),prospects_target:Number(f.prospects_target||0),measurements_target:Number(f.measurements_target||0),sales_target:Number(f.sales_target||0),revenue_target_minor:Math.round(Number(f.revenue||0)*100),currency:'MXN'}});toast('Meta guardada.');openTeamDetail(id);});
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}}


  let metaSignupSession=null,metaSdkPromise=null;
  function metaSignupMessage(event){
    if(!['https://www.facebook.com','https://web.facebook.com'].includes(event.origin))return;
    let payload=event.data;
    if(typeof payload==='string'){try{payload=JSON.parse(payload);}catch(_){return;}}
    if(!payload||payload.type!=='WA_EMBEDDED_SIGNUP')return;
    if(payload.event==='FINISH'){
      metaSignupSession={waba_id:payload.data?.waba_id||payload.data?.wabaId||null,phone_number_id:payload.data?.phone_number_id||payload.data?.phoneNumberId||null};
    }else if(payload.event==='CANCEL'){
      metaSignupSession={cancelled:true};
    }else if(payload.event==='ERROR'){
      metaSignupSession={error:payload.data?.error_message||payload.data?.message||'Meta informó un error durante la vinculación.'};
    }
  }
  window.addEventListener('message',metaSignupMessage);
  function ensureMetaSdk(config){
    if(window.FB){window.FB.init({appId:config.app_id,cookie:true,xfbml:false,version:config.graph_version||'v25.0'});return Promise.resolve(window.FB);}
    if(metaSdkPromise)return metaSdkPromise;
    metaSdkPromise=new Promise((resolve,reject)=>{
      const existing=document.getElementById('facebook-jssdk');
      if(existing){existing.addEventListener('load',()=>{window.FB?.init({appId:config.app_id,cookie:true,xfbml:false,version:config.graph_version||'v25.0'});resolve(window.FB);},{once:true});existing.addEventListener('error',()=>reject(new Error('No se pudo cargar Meta SDK.')),{once:true});return;}
      const script=document.createElement('script');script.id='facebook-jssdk';script.async=true;script.defer=true;script.crossOrigin='anonymous';script.src='https://connect.facebook.net/es_LA/sdk.js';
      script.onload=()=>{if(!window.FB){reject(new Error('Meta SDK no está disponible.'));return;}window.FB.init({appId:config.app_id,cookie:true,xfbml:false,version:config.graph_version||'v25.0'});resolve(window.FB);};
      script.onerror=()=>reject(new Error('No se pudo cargar Meta SDK.'));
      document.head.appendChild(script);
    });
    return metaSdkPromise;
  }
  async function launchPartnerWhatsappEmbeddedSignup(config,partnerId){
    if(!config?.enabled){toast(`Onboarding de Meta no disponible${config?.missing?.length?`: falta ${config.missing.join(', ')}`:''}.`,'bad');return;}
    const FB=await ensureMetaSdk(config);metaSignupSession=null;
    FB.login(async response=>{
      try{
        const code=response?.authResponse?.code;
        if(!code){if(metaSignupSession?.cancelled)return;throw new Error('Meta no devolvió el código de autorización.');}
        await new Promise(resolve=>setTimeout(resolve,350));
        if(metaSignupSession?.error)throw new Error(metaSignupSession.error);
        const body={code};
        if(metaSignupSession?.waba_id)body.waba_id=metaSignupSession.waba_id;
        if(metaSignupSession?.phone_number_id)body.phone_number_id=metaSignupSession.phone_number_id;
        if(isSuper())body.partner_id=partnerId;
        await request('/organization/whatsapp-channel/embedded-signup/complete',{method:'POST',body});
        toast('WhatsApp conectado con Meta.');renderOrganization();
      }catch(err){toast(errorMessage(err),'bad');}
    },{config_id:config.config_id,response_type:'code',override_default_response_type:true,extras:{setup:{}}});
  }


  async function renderOrganization(){
    setHeader(isSuper()?'Organización de socios':'Mi organización','Rangos, patrocinador directo, línea upline, credencial y solicitudes de apoyo.');
    if(isSuper()&&!state.selectedPartner){const partners=await loadPartners();content.innerHTML=partnerSelector(partners,'Selecciona un socio para consultar o actualizar su jerarquía.');bindPartnerSelector('organization');return;}
    const [workspace,ranks,partners,whatsappChannel,whatsappEmbedded]=await Promise.all([
      request('/organization/workspace'+selectedPartnerQuery()),
      loadRanks(true),
      isSuper()?loadPartners():Promise.resolve([]),
      request('/organization/whatsapp-channel'+selectedPartnerQuery()),
      request('/organization/whatsapp-channel/embedded-signup/config'+selectedPartnerQuery())
    ]);
    const p=workspace.partner,credential=workspace.credential,canManageWhatsapp=isSuper()||has('organization.whatsapp.manage');
    const actions=isSuper()?`<button id="editPartnerIdentity" class="button primary">Editar identidad y rango</button><button id="issueCredential" class="button secondary">Emitir credencial</button><button id="transferContacts" class="button secondary">Reasignar prospectos</button><button id="manageRanks" class="button secondary">Catálogo de rangos</button><button id="changePartner" class="button secondary">Cambiar socio</button>`:`${has('organization.support.create')?'<button id="newSupportRequest" class="button primary">Solicitar apoyo</button>':''}`;
    content.innerHTML=`<div class="page-actions"><div><h2>${esc(p.business_name)}</h2><p class="muted compact">Socio ID ${esc(p.partner_number)} · ${esc(p.rank_name||'Sin rango')}</p></div><div class="actions">${actions}</div></div>
      <div class="grid">
        <section class="card card-pad span-5"><h2>Identidad organizacional</h2><div class="table-wrap"><table><tbody>
          <tr><th>Socio ID</th><td><strong>${esc(p.partner_number)}</strong></td></tr>
          <tr><th>Nombre</th><td>${esc(p.business_name)}</td></tr>
          <tr><th>Rango actual</th><td>${esc(p.rank_name||'Sin rango')}</td></tr>
          <tr><th>Patrocinador directo</th><td>${p.sponsor_partner_name?`${esc(p.sponsor_partner_name)} · ${esc(p.sponsor_partner_number||'')}`:'Sin asignar'}</td></tr>
          <tr><th>Estado</th><td>${badge(p.status)}</td></tr>
        </tbody></table></div></section>
        <section class="card card-pad span-7"><h2>Credencial digital</h2>${credential?`<div class="credential-card"><div class="credential-brand">RHINO</div><h3>${esc(credential.partner_name)}</h3><div class="credential-id">Socio ID ${esc(credential.partner_number)}</div><div class="credential-rank">${esc(credential.rank_name||'Sin rango')}</div><div>${badge(credential.status)}</div><small>${esc(credential.credential_number)} · Emitida ${dateTime(credential.issued_at)}</small><div class="form-actions"><a class="button small secondary" href="${esc(credential.verification_path)}" target="_blank" rel="noopener">Verificar credencial</a>${isSuper()&&credential.status==='ACTIVE'?`<button class="button small danger" id="revokeCredential" data-id="${esc(credential.id)}">Revocar</button>`:''}</div></div>`:empty('Sin credencial activa','El administrador general puede emitir una credencial después de registrar el Socio ID oficial.')}</section>
        <section class="card card-pad span-6"><h2>Mi línea upline</h2>${workspace.upline.length?`<div class="list">${workspace.upline.map(u=>`<div class="list-item"><div><strong>Nivel ${u.level}: ${esc(u.business_name)}</strong><div class="meta">${esc(u.partner_number)} · ${esc(u.rank_name||'Sin rango')}</div></div>${u.is_direct_sponsor?'<span class="badge blue">Directo</span>':badge(u.status)}</div>`).join('')}</div>`:empty('Sin línea ascendente','No hay patrocinador asignado.')}</section>
        <section class="card card-pad span-6"><h2>Socios directos</h2>${workspace.direct_partners.length?`<div class="list">${workspace.direct_partners.map(d=>`<div class="list-item"><div><strong>${esc(d.business_name)}</strong><div class="meta">${esc(d.partner_number)} · ${esc(d.rank_name||'Sin rango')}</div></div>${badge(d.status)}</div>`).join('')}</div>`:empty('Sin socios directos','Los socios patrocinados directamente aparecerán aquí.')}</section>
        <section class="card card-pad span-6"><h2>WhatsApp del socio</h2>${partnerWhatsappChannelCard(whatsappChannel,canManageWhatsapp,whatsappEmbedded)}</section>
        <section class="card card-pad span-6"><h2>Historial de rangos</h2>${workspace.rank_history.length?`<div class="timeline">${workspace.rank_history.map(h=>`<div class="timeline-item"><strong>${esc(h.rank_name)}</strong><p>${h.previous_rank_name?`${esc(h.previous_rank_name)} → `:''}${esc(h.rank_name)}</p><small>${dateTime(h.effective_at)}${h.reason?` · ${esc(h.reason)}`:''}</small></div>`).join('')}</div>`:empty('Sin cambios registrados','El historial se llenará con los movimientos de rango.')}</section>
        <section class="card card-pad span-6"><h2>Solicitudes de apoyo</h2>${supportRequestList(workspace.support_requests)}</section>
      </div>`;
    $('changePartner')?.addEventListener('click',()=>{state.selectedPartner='';localStorage.removeItem('rhino_selected_partner');location.hash='#organization';route();});
    $('editPartnerIdentity')?.addEventListener('click',()=>openPartnerIdentityForm(p,ranks,partners));
    $('manageRanks')?.addEventListener('click',()=>openRankManager(ranks));
    $('issueCredential')?.addEventListener('click',async()=>{if(!confirm('Se revocará cualquier credencial activa y se emitirá una nueva. ¿Continuar?'))return;try{await request(`/admin/partners/${encodeURIComponent(p.id)}/credentials`,{method:'POST',body:{}});toast('Credencial emitida.');renderOrganization();}catch(e){toast(errorMessage(e),'bad');}});
    $('revokeCredential')?.addEventListener('click',async e=>{const reason=prompt('Motivo de revocación:');if(!reason)return;await request(`/admin/credentials/${encodeURIComponent(e.currentTarget.dataset.id)}/revoke`,{method:'POST',body:{reason}});toast('Credencial revocada.');renderOrganization();});
    $('transferContacts')?.addEventListener('click',()=>openContactTransfer(p,partners));
    $('connectPartnerWhatsappMeta')?.addEventListener('click',()=>launchPartnerWhatsappEmbeddedSignup(whatsappEmbedded,p.id));
    $('configurePartnerWhatsappAdvanced')?.addEventListener('click',()=>openPartnerWhatsappForm(whatsappChannel,p.id));
    if(whatsappEmbedded?.enabled)ensureMetaSdk(whatsappEmbedded).catch(()=>{});
    $('testPartnerWhatsapp')?.addEventListener('click',async()=>{try{const body=isSuper()?{partner_id:p.id}:{};await request('/organization/whatsapp-channel/test',{method:'POST',body});toast('Conexión WhatsApp verificada.');renderOrganization();}catch(e){toast(errorMessage(e),'bad');}});
    $('disablePartnerWhatsapp')?.addEventListener('click',async()=>{if(!confirm('¿Desactivar el envío por WhatsApp de este socio?'))return;try{const body=isSuper()?{partner_id:p.id}:{};await request('/organization/whatsapp-channel/disable',{method:'POST',body});toast('Canal WhatsApp desactivado.');renderOrganization();}catch(e){toast(errorMessage(e),'bad');}});
    $('newSupportRequest')?.addEventListener('click',openSupportRequestForm);
    bindSupportActions();
  }
  function partnerWhatsappChannelCard(channel,canManage,embedded){
    const configured=!!channel?.configured,status=channel?.status||'NOT_CONFIGURED',method=channel?.connection_method||'MANUAL';
    const autoReady=!!embedded?.enabled;
    const automaticAction=canManage?(autoReady?'<button id="connectPartnerWhatsappMeta" class="button primary">Conectar con Meta</button>':`<button class="button primary" disabled>Conectar con Meta</button>`):'';
    const advanced=isSuper()?'<button id="configurePartnerWhatsappAdvanced" class="button secondary">Configuración avanzada</button>':'';
    if(!configured){
      const setupNote=autoReady?'Meta abrirá una ventana segura para elegir o vincular la cuenta de WhatsApp Business. RHINO configurará los identificadores automáticamente.':(isSuper()?`Onboarding automático pendiente de configurar en RHINO${embedded?.missing?.length?`: ${embedded.missing.join(', ')}`:''}.`:'La administración de RHINO todavía debe habilitar la conexión automática con Meta.');
      return `${empty('WhatsApp no conectado','Conecta el WhatsApp Business de este socio sin copiar tokens ni identificadores.')}<div class="inline-notice"><strong>Conexión automática:</strong> ${esc(setupNote)}</div>${canManage?`<div class="form-actions">${automaticAction}${advanced}</div>`:''}`;
    }
    const expires=channel.access_token_expires_at?dateTime(channel.access_token_expires_at):'Sin vencimiento informado';
    return `<div class="table-wrap"><table><tbody>
      <tr><th>Estado</th><td>${badge(status)}</td></tr>
      <tr><th>Conexión</th><td>${method==='EMBEDDED'?'Meta · automática':'Manual · avanzada'}</td></tr>
      <tr><th>Número emisor</th><td><strong>${esc(channel.display_phone_number||'Pendiente de verificar')}</strong></td></tr>
      ${isSuper()?`<tr><th>Phone Number ID</th><td><code>${esc(channel.phone_number_id||'')}</code></td></tr><tr><th>WABA ID</th><td>${esc(channel.waba_id||'No registrado')}</td></tr>`:''}
      <tr><th>Credencial</th><td>${channel.token_configured?'Protegida en RHINO':'No configurada'}</td></tr>
      <tr><th>Vencimiento</th><td>${esc(expires)}</td></tr>
      <tr><th>Plantilla PDF</th><td>${esc(channel.pdf_template_name||'Pendiente')}</td></tr>
      <tr><th>Plantilla imágenes</th><td>${esc(channel.images_template_name||'Pendiente')}</td></tr>
      <tr><th>Última prueba</th><td>${channel.last_tested_at?dateTime(channel.last_tested_at):'Sin probar'}</td></tr>
    </tbody></table></div>${channel.last_error?`<div class="form-error">${esc(channel.last_error)}</div>`:''}${canManage?`<div class="form-actions">${autoReady?'<button id="connectPartnerWhatsappMeta" class="button primary">Reconectar con Meta</button>':''}<button id="testPartnerWhatsapp" class="button secondary">Probar conexión</button>${advanced}${status==='ACTIVE'?'<button id="disablePartnerWhatsapp" class="button danger">Desactivar</button>':''}</div>`:''}`;
  }
  function openPartnerWhatsappForm(channel,partnerId){
    if(!isSuper()){toast('La configuración manual está reservada para SUPER_ADMIN. Usa Conectar con Meta.','bad');return;}
    const configured=!!channel?.configured;
    openModal('WhatsApp Business · configuración avanzada','Uso exclusivo SUPER_ADMIN. Para socios utiliza Conectar con Meta.',`<form id="partnerWhatsappForm"><div class="form-grid">
      <label>Número emisor<input name="display_phone_number" maxlength="40" value="${esc(channel?.display_phone_number||'')}" placeholder="+52 662 000 0000"></label>
      <label>Phone Number ID<input name="phone_number_id" required maxlength="100" value="${esc(channel?.phone_number_id||'')}"></label>
      <label>WABA ID<input name="waba_id" maxlength="100" value="${esc(channel?.waba_id||'')}"></label>
      <label>Idioma de plantillas<input name="template_language" maxlength="16" value="${esc(channel?.template_language||'es_MX')}" placeholder="es_MX"></label>
      <label class="full">Token de acceso<input name="access_token" type="password" maxlength="4096" autocomplete="new-password" ${configured?'':'required'} placeholder="${configured?'Déjalo vacío para conservar el token actual':'Pega el token de Meta'}"></label>
      <label>Plantilla PDF (DOCUMENT)<input name="pdf_template_name" maxlength="120" value="${esc(channel?.pdf_template_name||'')}" placeholder="rhino_plan_rapido_pdf"></label>
      <label>Plantilla imágenes (IMAGE)<input name="images_template_name" maxlength="120" value="${esc(channel?.images_template_name||'')}" placeholder="rhino_plan_rapido_imagen"></label>
    </div><p class="muted">La credencial se cifra en RHINO y nunca se devuelve al navegador.</p><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Guardar configuración</button></div><div id="partnerWhatsappError" class="form-error"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('partnerWhatsappForm').onsubmit=async e=>{e.preventDefault();const body=Object.fromEntries(new FormData(e.currentTarget));if(!body.access_token)delete body.access_token;body.partner_id=partnerId;const btn=e.submitter;btn.disabled=true;try{await request('/organization/whatsapp-channel',{method:'PUT',body});closeModal();toast('WhatsApp del socio actualizado.');renderOrganization();}catch(err){$('partnerWhatsappError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }
  function supportRequestList(rows){
    return rows.length?`<div class="list">${rows.map(s=>`<div class="list-item"><div><strong>${esc(s.subject)}</strong><div class="meta">${esc(s.assigned_partner_name?`Asignada a ${s.assigned_partner_name}`:'Administración RHINO')} · Nivel ${Number(s.escalation_level||1)} · ${dateTime(s.created_at)}</div></div><div>${badge(s.status)} ${has('organization.support.manage')&&!['RESOLVED','CLOSED'].includes(s.status)?`<button class="button small secondary support-escalate" data-id="${esc(s.id)}">Escalar</button> <button class="button small success support-resolve" data-id="${esc(s.id)}">Resolver</button>`:''}</div></div>`).join('')}</div>`:empty('Sin solicitudes','Las solicitudes se dirigen primero al upline disponible.');
  }
  function bindSupportActions(){
    document.querySelectorAll('.support-escalate').forEach(b=>b.onclick=async()=>{const note=prompt('Motivo del escalamiento:','Sin respuesta del nivel actual');if(note===null)return;await request(`/organization/support/${encodeURIComponent(b.dataset.id)}/escalate`,{method:'POST',body:{note}});toast('Solicitud escalada.');renderOrganization();});
    document.querySelectorAll('.support-resolve').forEach(b=>b.onclick=async()=>{const note=prompt('Nota de resolución:','Atención concluida');if(note===null)return;await request(`/organization/support/${encodeURIComponent(b.dataset.id)}/resolve`,{method:'POST',body:{note}});toast('Solicitud resuelta.');renderOrganization();});
  }
  function openSupportRequestForm(){
    openModal('Solicitar apoyo','La solicitud se asignará al patrocinador directo o al siguiente upline activo.',`<form id="supportForm"><div class="form-grid"><label>Prioridad<select name="priority"><option value="NORMAL">Normal</option><option value="HIGH">Alta</option><option value="URGENT">Urgente</option><option value="LOW">Baja</option></select></label><label class="full">Asunto<input name="subject" required maxlength="180"></label><label class="full">Descripción<textarea name="description" required maxlength="5000"></textarea></label></div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Enviar solicitud</button></div><div class="form-error" id="supportError"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('supportForm').onsubmit=async e=>{e.preventDefault();const body=Object.fromEntries(new FormData(e.currentTarget));const btn=e.submitter;btn.disabled=true;try{await request('/organization/support',{method:'POST',body});closeModal();toast('Solicitud enviada a tu línea upline.');renderOrganization();}catch(err){$('supportError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }
  function openPartnerIdentityForm(partner,ranks,partners){
    openModal('Identidad y jerarquía','El Socio ID, patrocinador y rango quedan auditados mediante un folio de movimiento.',`<form id="partnerIdentityForm"><div class="form-grid">
      <label>Socio ID<input name="partner_number" required inputmode="numeric" pattern="[0-9]+" value="${esc(partner.partner_number||'')}"></label>
      <label>Nombre<input name="business_name" required value="${esc(partner.business_name||'')}"></label>
      <label>Rango<select name="current_rank_id">${ranks.filter(r=>r.active).map(r=>`<option value="${esc(r.id)}" ${r.id===partner.current_rank_id?'selected':''}>${esc(r.name)}</option>`).join('')}</select></label>
      <label>Patrocinador<select name="sponsor_partner_id"><option value="">Sin patrocinador</option>${partners.filter(x=>x.id!==partner.id&&x.status==='ACTIVE').map(x=>`<option value="${esc(x.id)}" ${x.id===partner.sponsor_partner_id?'selected':''}>${esc(x.partner_number||x.id)} · ${esc(x.business_name)}</option>`).join('')}</select></label>
      <label class="full">Motivo<input name="reason" required maxlength="1000" value="Actualización administrativa"></label>
    </div><div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Guardar cambios</button></div><div id="identityError" class="form-error"></div></form>`);
    $('modalBody').querySelector('[data-close]').onclick=closeModal;
    $('partnerIdentityForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));f.sponsor_partner_id=f.sponsor_partner_id||null;const btn=e.submitter;btn.disabled=true;try{await request(`/admin/partners/${encodeURIComponent(partner.id)}/identity`,{method:'PATCH',body:f});state.partners=[];state.ranks=[];closeModal();toast('Identidad organizacional actualizada.');renderOrganization();}catch(err){$('identityError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }
  function openRankManager(ranks){
    openModal('Catálogo de rangos','No incluye porcentajes ni comisiones. El orden define únicamente la jerarquía y la credencial.',`<div class="table-wrap"><table><thead><tr><th>Orden</th><th>Código</th><th>Rango</th><th>Estado</th><th></th></tr></thead><tbody>${ranks.map(r=>`<tr><td>${r.hierarchy_order}</td><td>${esc(r.code)}</td><td>${esc(r.name)}</td><td>${badge(r.active?'ACTIVE':'INACTIVE')}</td><td><button class="button small secondary rank-edit" data-id="${esc(r.id)}">Editar</button></td></tr>`).join('')}</tbody></table></div><hr><form id="rankCreateForm"><div class="form-grid"><label>Orden<input name="hierarchy_order" type="number" min="1" required></label><label>Código<input name="code" required placeholder="ORO_ROSADO"></label><label class="full">Nombre<input name="name" required placeholder="Oro Rosado"></label></div><div class="form-actions"><button class="button primary">Agregar rango</button></div><div id="rankError" class="form-error"></div></form>`);
    document.querySelectorAll('.rank-edit').forEach(b=>b.onclick=async()=>{const rank=ranks.find(r=>r.id===b.dataset.id);const name=prompt('Nombre del rango:',rank.name);if(!name)return;const order=prompt('Orden jerárquico:',rank.hierarchy_order);if(!order)return;try{await request(`/admin/ranks/${encodeURIComponent(rank.id)}`,{method:'PATCH',body:{name,hierarchy_order:Number(order)}});state.ranks=[];closeModal();toast('Rango actualizado.');renderOrganization();}catch(e){toast(errorMessage(e),'bad');}});
    $('rankCreateForm').onsubmit=async e=>{e.preventDefault();const f=Object.fromEntries(new FormData(e.currentTarget));f.hierarchy_order=Number(f.hierarchy_order);const btn=e.submitter;btn.disabled=true;try{await request('/admin/ranks',{method:'POST',body:f});state.ranks=[];closeModal();toast('Rango agregado.');renderOrganization();}catch(err){$('rankError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
  }
  async function openContactTransfer(sourcePartner,partners){
    openModal('Reasignar prospectos','Cargando prospectos del socio…','<div class="loading">Cargando…</div>');
    try{
      const contacts=await request(`/admin/partners/${encodeURIComponent(sourcePartner.id)}/contacts?limit=500`);
      $('modalTitle').textContent='Reasignar prospectos';$('modalSubtitle').textContent='“Prospecto registrado por” permanece igual; solo cambia el socio responsable.';
      $('modalBody').innerHTML=`<form id="contactTransferForm"><div class="form-grid"><label class="full">Nuevo socio responsable<select name="responsible_partner_id" required><option value="">Seleccione…</option>${partners.filter(p=>p.id!==sourcePartner.id&&p.status==='ACTIVE').map(p=>`<option value="${esc(p.id)}">${esc(p.partner_number||p.id)} · ${esc(p.business_name)}</option>`).join('')}</select></label><label class="full">Motivo<input name="reason" required maxlength="1000" value="Reasignación comercial"></label></div>
        <div class="table-wrap"><table><thead><tr><th><input type="checkbox" id="selectAllContacts"></th><th>Prospecto</th><th>Registrado por</th><th>Pesajes</th><th>Último pesaje</th></tr></thead><tbody>${contacts.map(c=>`<tr><td><input class="transfer-contact" type="checkbox" value="${esc(c.id)}"></td><td class="name-cell"><strong>${esc(c.name)}</strong><small>${esc(c.whatsapp)}</small></td><td>${esc(c.registered_by_partner_name||sourcePartner.business_name)}</td><td>${Number(c.total_measurements||0)}</td><td>${dateTime(c.last_measurement_at)}</td></tr>`).join('')}</tbody></table></div>
        <div class="form-actions"><button type="button" class="button secondary" data-close>Cancelar</button><button class="button primary">Reasignar seleccionados</button></div><div id="transferError" class="form-error"></div></form>`;
      $('modalBody').querySelector('[data-close]').onclick=closeModal;
      $('selectAllContacts').onchange=e=>document.querySelectorAll('.transfer-contact').forEach(x=>x.checked=e.target.checked);
      $('contactTransferForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),ids=[...document.querySelectorAll('.transfer-contact:checked')].map(x=>x.value);if(!ids.length){$('transferError').textContent='Selecciona al menos un prospecto.';return;}const btn=e.submitter;btn.disabled=true;try{await request('/admin/contacts/reassign',{method:'POST',body:{contact_ids:ids,responsible_partner_id:fd.get('responsible_partner_id'),reason:fd.get('reason')}});closeModal();toast(`${ids.length} prospectos reasignados.`);renderOrganization();}catch(err){$('transferError').textContent=errorMessage(err);}finally{btn.disabled=false;}};
    }catch(e){$('modalBody').innerHTML=`<div class="form-error">${esc(errorMessage(e))}</div>`;}
  }

  async function renderMovements(){
    setHeader('Movimientos','Folios auditables por código, socio, módulo y fecha.');
    const [types,partners]=await Promise.all([
      state.movementTypes.length?Promise.resolve(state.movementTypes):request('/movements/types').then(x=>state.movementTypes=x),
      isSuper()?loadPartners():Promise.resolve([])
    ]);
    content.innerHTML=`<div class="filters"><input id="movementFolio" placeholder="Folio RH-MOV-…"><select id="movementCode"><option value="">Todos los códigos</option>${types.map(t=>`<option value="${esc(t.code)}">${esc(t.code)} · ${esc(t.name)}</option>`).join('')}</select>${isSuper()?`<select id="movementPartner"><option value="">Todos los socios</option>${partners.map(p=>`<option value="${esc(p.id)}">${esc(p.partner_number||p.id)} · ${esc(p.business_name)}</option>`).join('')}</select>`:''}<input id="movementFrom" type="date"><input id="movementTo" type="date"><button id="movementFilter" class="button primary">Consultar</button></div><div id="movementResults" class="loading">Cargando…</div>`;
    const load=async()=>{const q=new URLSearchParams();if($('movementFolio').value)q.set('folio',$('movementFolio').value);if($('movementCode').value)q.set('movement_code',$('movementCode').value);if(isSuper()&&$('movementPartner')?.value)q.set('partner_id',$('movementPartner').value);if($('movementFrom').value)q.set('date_from',$('movementFrom').value);if($('movementTo').value)q.set('date_to',$('movementTo').value);q.set('limit','250');const data=await request('/movements?'+q);$('movementResults').innerHTML=movementTable(data.items||[]);};
    $('movementFilter').onclick=load;await load();
  }
  function movementTable(rows){return rows.length?`<div class="table-wrap"><table><thead><tr><th>Folio</th><th>Código</th><th>Socio</th><th>Objeto</th><th>Usuario</th><th>Origen</th><th>Fecha</th></tr></thead><tbody>${rows.map(m=>`<tr><td class="name-cell"><strong>${esc(m.folio)}</strong><small>${esc(m.status)}</small></td><td><strong>${esc(m.movement_code)}</strong><small class="block">${esc(m.movement_name)}</small></td><td>${esc(m.partner_number||'—')}<small class="block">${esc(m.partner_name||'')}</small></td><td>${esc(m.target_type)}${m.target_id?`<small class="block">${esc(m.target_id)}</small>`:''}</td><td>${esc(m.actor_name||'Sistema')}</td><td>${esc(m.access_channel||'—')}<small class="block">${esc(m.ip_address||'')}</small></td><td>${dateTime(m.occurred_at)}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin movimientos','No hay movimientos para los filtros seleccionados.');}

  async function renderDevices(){
    setHeader('Dispositivos','Aplicaciones, computadoras, navegadores y básculas vinculadas.');
    if(isSuper()&&!state.selectedPartner){const partners=await loadPartners();content.innerHTML=partnerSelector(partners,'Selecciona un socio para consultar sus dispositivos.');bindPartnerSelector('devices');return;}
    const data=await request('/security/devices-overview'+selectedPartnerQuery());
    const access=data.access_devices||[],scales=data.scales||[];
    content.innerHTML=`<div class="page-actions"><div><h2>Dispositivos del socio</h2><p class="muted compact">La IP y el navegador son datos de auditoría; una misma IP puede corresponder a varios equipos.</p></div>${isSuper()?'<button class="button secondary" id="changePartner">Cambiar socio</button>':''}</div>
      <section class="card card-pad"><h2>Apps, computadoras y navegadores</h2>${access.length?`<div class="table-wrap"><table><thead><tr><th>Dispositivo</th><th>Usuarios</th><th>IP reciente</th><th>Primera conexión</th><th>Última conexión</th><th>Sesiones</th><th>Estado</th></tr></thead><tbody>${access.map(d=>`<tr><td class="name-cell"><strong>${esc(d.device_name||d.browser)}</strong><small>${esc(d.platform)} · ${esc(d.operating_system)}${d.app_version?` · v${esc(d.app_version)}`:''}</small></td><td>${(d.users||[]).map(u=>esc(u.name)).join(', ')||'—'}</td><td>${esc(d.latest_ip||'—')}</td><td>${dateTime(d.first_seen_at)}</td><td>${dateTime(d.last_seen_at)}</td><td>${Number(d.active_sessions||0)} activas / ${Number(d.sessions_count||0)}</td><td>${badge(d.status)}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin accesos registrados','Las conexiones desde CRM web o app aparecerán después del inicio de sesión.')}</section>
      <section class="card card-pad"><h2>Básculas vinculadas</h2><p class="muted compact">La báscula se comunica mediante Kiosco o la integración IoT.</p>${scales.length?`<div class="table-wrap"><table><thead><tr><th>Báscula</th><th>Ubicación</th><th>Mediciones</th><th>Último uso</th><th>Estado</th><th></th></tr></thead><tbody>${scales.map(d=>`<tr><td class="name-cell"><strong>${esc(d.mac_address)}</strong><small>${esc(d.id)}</small></td><td>${esc(d.location_name||'—')}</td><td>${Number(d.total_measurements||0)}</td><td>${dateTime(d.last_measurement_at)}</td><td>${badge(d.status)}</td><td>${d.status==='ACTIVE'?`<button class="button small danger suspend-device" data-id="${esc(d.id)}">Suspender</button>`:`<button class="button small success activate-device" data-id="${esc(d.id)}">Activar</button>`}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin básculas','Las básculas vinculadas aparecerán aquí.')}</section>`;
    $('changePartner')?.addEventListener('click',()=>{state.selectedPartner='';localStorage.removeItem('rhino_selected_partner');renderDevices();});
    document.querySelectorAll('.suspend-device').forEach(b=>b.onclick=async()=>{const reason=prompt('Motivo de suspensión:');if(!reason)return;await request(`/security/devices/${encodeURIComponent(b.dataset.id)}/suspend`,{method:'POST',body:{reason}});toast('Báscula suspendida.');renderDevices();});
    document.querySelectorAll('.activate-device').forEach(b=>b.onclick=async()=>{await request(`/security/devices/${encodeURIComponent(b.dataset.id)}/activate`,{method:'POST',body:{}});toast('Báscula activada.');renderDevices();});
  }
  async function renderKioskVersions(){
    if(!isSuper()) throw {message:'Solo SUPER_ADMIN puede administrar versiones Kiosko.'};
    setHeader('Versiones Kiosko','Publica actualizaciones recomendadas u obligatorias para RHINO Kiosko.');
    const versions=await request('/admin/kiosk-versions');
    content.innerHTML=`<div class="grid two-col">
      <section class="card card-pad span-6">
        <h2>Nueva versión</h2>
        <p class="muted compact">El APK se guarda de forma privada. RHINO calcula SHA-256 al publicarlo y la app vuelve a verificar integridad, package name, versionCode y firma antes de instalar.</p>
        <form id="kioskVersionForm" enctype="multipart/form-data">
          <div class="form-grid">
            <label>Versión<input name="version_name" placeholder="2.7.1" required maxlength="32"></label>
            <label>Version Code<input name="version_code" type="number" min="1" step="1" placeholder="271" required></label>
            <label>Tipo<select name="release_type" required><option value="RECOMMENDED">Recomendada</option><option value="MANDATORY">Obligatoria</option></select></label>
            <label>APK<input name="apk" type="file" accept=".apk,application/vnd.android.package-archive" required></label>
            <label class="full">SHA-256 del certificado de firma <span class="muted">(opcional, recomendado)</span><input name="signing_sha256" maxlength="128" placeholder="Huella SHA-256 del certificado"></label>
            <label class="full">Notas<textarea name="notes" rows="6" maxlength="20000" placeholder="- Mejoras de impresión\n- Nuevos dispositivos"></textarea></label>
          </div>
          <div class="form-actions"><button class="button primary">Publicar versión</button></div>
          <div id="kioskVersionError" class="form-error"></div>
        </form>
      </section>
      <section class="card card-pad span-6">
        <h2>Versiones publicadas</h2>
        ${versions.length?`<div class="table-wrap"><table><thead><tr><th>Versión</th><th>Tipo</th><th>Estado</th><th>Publicada</th><th>SHA-256</th><th></th></tr></thead><tbody>${versions.map(v=>`<tr><td class="name-cell"><strong>${esc(v.version_name)}</strong><small>versionCode ${esc(v.version_code)} · ${esc(v.package_name)}</small></td><td>${esc(v.release_type==='MANDATORY'?'Obligatoria':'Recomendada')}</td><td>${badge(v.status)}</td><td>${dateTime(v.published_at)}</td><td><code title="${esc(v.sha256)}">${esc(String(v.sha256||'').slice(0,12))}…</code></td><td>${v.status==='ACTIVE'?`<button class="button secondary revoke-kiosk-version" data-id="${esc(v.id)}">Revocar</button>`:'—'}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin versiones publicadas','Carga el primer APK para habilitar las actualizaciones internas.')}
      </section>
    </div>`;
    $('kioskVersionForm').onsubmit=async e=>{
      e.preventDefault();
      const form=e.currentTarget, fd=new FormData(form), button=e.submitter;
      button.disabled=true; $('kioskVersionError').textContent='';
      try{
        await request('/admin/kiosk-versions',{method:'POST',body:fd});
        toast('Versión Kiosko publicada.');
        await renderKioskVersions();
      }catch(err){$('kioskVersionError').textContent=errorMessage(err);}finally{button.disabled=false;}
    };
    document.querySelectorAll('.revoke-kiosk-version').forEach(button=>button.onclick=async()=>{
      if(!confirm('¿Revocar esta versión? Dejará de ofrecerse a los Kioskos.'))return;
      button.disabled=true;
      try{await request(`/admin/kiosk-versions/${encodeURIComponent(button.dataset.id)}/revoke`,{method:'POST',body:{}});toast('Versión revocada.');await renderKioskVersions();}
      catch(err){toast(errorMessage(err),'bad');button.disabled=false;}
    });
  }

  async function renderSessions(){
    setHeader('Sesiones','Dispositivos conectados y revocación de acceso.');
    if(isSuper()&&!state.selectedPartner){const partners=await loadPartners();content.innerHTML=partnerSelector(partners,'Selecciona un socio para consultar sus sesiones.');bindPartnerSelector('sessions');return;}
    const rows=await request('/security/sessions'+selectedPartnerQuery());
    content.innerHTML=`<div class="page-actions"><div><h2>Sesiones registradas</h2><p class="muted compact">Puedes cerrar una sesión específica sin afectar las demás.</p></div>${isSuper()?'<button class="button secondary" id="changePartner">Cambiar socio</button>':''}</div>${rows.length?`<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Dispositivo</th><th>IP</th><th>Última actividad</th><th>Estado</th><th></th></tr></thead><tbody>${rows.map(s=>`<tr><td class="name-cell"><strong>${esc(s.user_name)}</strong><small>${esc(s.user_email)}</small></td><td>${esc(s.device_name||s.platform)}</td><td>${esc(s.ip_address||'—')}</td><td>${dateTime(s.last_seen_at)}</td><td>${s.revoked_at?badge('REVOKED'):badge(new Date(s.expires_at)>new Date()?'ACTIVE':'EXPIRED')}</td><td>${!s.revoked_at?`<button class="button small danger revoke-session" data-id="${esc(s.id)}">Cerrar</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Sin sesiones','No hay dispositivos registrados.')}`;
    $('changePartner')?.addEventListener('click',()=>{state.selectedPartner='';localStorage.removeItem('rhino_selected_partner');state.users=[];state.locations=[];renderSessions();});
    document.querySelectorAll('.revoke-session').forEach(b=>b.onclick=async()=>{const reason=prompt('Motivo para cerrar la sesión:','Cierre administrativo');if(!reason)return;await request(`/security/sessions/${encodeURIComponent(b.dataset.id)}/revoke`,{method:'POST',body:{reason}});toast('Sesión cerrada.');renderSessions();});
  }

  window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();state.installPrompt=e;$('installApp').classList.remove('hidden');});
  $('installApp').addEventListener('click',async()=>{if(!state.installPrompt)return;state.installPrompt.prompt();await state.installPrompt.userChoice;state.installPrompt=null;$('installApp').classList.add('hidden');});
  if('serviceWorker' in navigator) window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));

  bootstrap();
})();
