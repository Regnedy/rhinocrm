const SDK_VERSION = '12.15.0';
const FIREBASE_APP_URL = `https://www.gstatic.com/firebasejs/${SDK_VERSION}/firebase-app.js`;
const FIREBASE_AUTH_URL = `https://www.gstatic.com/firebasejs/${SDK_VERSION}/firebase-auth.js`;
const GOOGLE_IDENTITY_URL = 'https://accounts.google.com/gsi/client';

const meta = name => document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';
const container = document.getElementById('googleIdentityButton');
const status = document.getElementById('googleLoginStatus');

const googleClientId = meta('firebase-google-client-id');
const config = {
  apiKey: meta('firebase-api-key'),
  authDomain: meta('firebase-auth-domain'),
  projectId: meta('firebase-project-id'),
  appId: meta('firebase-app-id'),
  messagingSenderId: meta('firebase-messaging-sender-id'),
  storageBucket: meta('firebase-storage-bucket'),
};
Object.keys(config).forEach(key => { if (!config[key]) delete config[key]; });

const enabled = meta('firebase-google-enabled') === 'true';
let firebaseAuth = null;
let authApi = null;
let exchanging = false;

function setStatus(message = '') {
  if (status) status.textContent = message;
}

function friendlyError(error) {
  const code = String(error?.code || error?.errorCode || error?.status || '');
  const messages = {
    'auth/invalid-credential': 'Google no entregó una credencial válida para Firebase.',
    'auth/operation-not-allowed': 'El proveedor Google todavía no está habilitado en Firebase.',
    'auth/network-request-failed': 'No fue posible conectar con Google o Firebase. Revisa tu conexión.',
    'auth/user-disabled': 'Esta cuenta fue deshabilitada.',
    'auth/account-exists-with-different-credential': 'La cuenta ya utiliza otro método de acceso. Ingresa con el método existente y solicita vincular Google.',
    'USER_NOT_PROVISIONED': 'La cuenta de Google no está registrada en Rhino CRM. Un administrador debe crearla primero.',
    'FIREBASE_IDENTITY_CONFLICT': 'La cuenta de Rhino ya está vinculada a otra identidad de Firebase.',
    'FIREBASE_EMAIL_NOT_VERIFIED': 'Google debe proporcionar un correo verificado para ingresar.',
    'PARTNER_SUSPENDED': 'El socio está suspendido y no puede iniciar sesión.',
    'USER_SUSPENDED': 'El usuario está suspendido y no puede iniciar sesión.',
  };
  return messages[code] || error?.message || 'No fue posible iniciar sesión con Google.';
}

async function bridge() {
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (window.RhinoCRM?.completeFirebaseLogin) return window.RhinoCRM;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error('El módulo principal de Rhino CRM no terminó de cargar.');
}

function loadGoogleIdentity() {
  if (window.google?.accounts?.id) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${GOOGLE_IDENTITY_URL}"]`);
    if (existing) {
      existing.addEventListener('load', resolve, { once: true });
      existing.addEventListener('error', () => reject(new Error('No fue posible cargar Google Identity Services.')), { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = GOOGLE_IDENTITY_URL;
    script.async = true;
    script.defer = true;
    script.onload = resolve;
    script.onerror = () => reject(new Error('No fue posible cargar Google Identity Services.'));
    document.head.appendChild(script);
  });
}

async function handleGoogleCredential(response) {
  if (exchanging || !response?.credential || !firebaseAuth || !authApi) return;
  exchanging = true;
  const rhino = await bridge().catch(() => null);
  rhino?.setLoginError?.('');
  setStatus('Verificando tu cuenta…');

  try {
    const googleCredential = authApi.GoogleAuthProvider.credential(response.credential);
    const result = await authApi.signInWithCredential(firebaseAuth, googleCredential);
    const firebaseIdToken = await result.user.getIdToken(true);
    await rhino.completeFirebaseLogin(firebaseIdToken, {
      persistent: rhino.rememberLogin(),
    });
    await authApi.signOut(firebaseAuth).catch(() => {});
    setStatus('');
  } catch (error) {
    await authApi.signOut(firebaseAuth).catch(() => {});
    rhino?.setLoginError?.(friendlyError(error));
    setStatus('');
  } finally {
    exchanging = false;
  }
}

function renderGoogleButton() {
  if (!container || !window.google?.accounts?.id) return;
  container.innerHTML = '';
  const width = Math.max(240, Math.min(400, Math.floor(container.getBoundingClientRect().width || 360)));
  window.google.accounts.id.renderButton(container, {
    type: 'standard',
    theme: 'outline',
    size: 'large',
    text: 'continue_with',
    shape: 'rectangular',
    logo_alignment: 'left',
    width,
    locale: 'es',
  });
}

async function initialize() {
  if (!container) return;

  if (!enabled || !googleClientId || !config.apiKey || !config.authDomain || !config.projectId || !config.appId) {
    setStatus('El acceso con Google está pendiente de configuración por el administrador.');
    return;
  }

  try {
    const [appApi, loadedAuthApi] = await Promise.all([
      import(FIREBASE_APP_URL),
      import(FIREBASE_AUTH_URL),
      loadGoogleIdentity(),
    ]);

    authApi = loadedAuthApi;
    const app = appApi.getApps().length ? appApi.getApp() : appApi.initializeApp(config);
    firebaseAuth = authApi.getAuth(app);
    authApi.useDeviceLanguage(firebaseAuth);
    await authApi.setPersistence(firebaseAuth, authApi.browserSessionPersistence);

    window.google.accounts.id.initialize({
      client_id: googleClientId,
      callback: handleGoogleCredential,
      auto_select: false,
      cancel_on_tap_outside: true,
      context: 'signin',
      use_fedcm_for_prompt: true,
    });

    renderGoogleButton();
    window.addEventListener('resize', renderGoogleButton, { passive: true });
    setStatus('');
  } catch (error) {
    setStatus('No fue posible inicializar el acceso con Google.');
    const rhino = await bridge().catch(() => null);
    rhino?.setLoginError?.(friendlyError(error));
  }
}

initialize();
