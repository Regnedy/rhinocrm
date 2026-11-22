# Rhino CRM — Google Identity Services

Esta actualización reemplaza `signInWithPopup()` y `signInWithRedirect()` por el botón oficial de Google Identity Services. El token de Google se convierte en una credencial Firebase mediante `signInWithCredential()`, y luego Laravel crea la sesión Rhino revocable.

Requiere un OAuth Client ID de tipo **Web application** del mismo proyecto Google Cloud/Firebase.

Variable nueva:

```env
FIREBASE_WEB_GOOGLE_CLIENT_ID=...apps.googleusercontent.com
```

No contiene migraciones ni modifica MySQL.
