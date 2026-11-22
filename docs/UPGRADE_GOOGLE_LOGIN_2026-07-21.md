# Actualización: login web con Google

Esta actualización cambia únicamente la pantalla de acceso y la configuración pública de Firebase Web. No crea, elimina ni modifica tablas MySQL.

## Diseño

- Google aparece como acceso principal.
- Correo y contraseña permanecen como opción secundaria.
- Firebase entrega un ID token al navegador.
- Laravel verifica el token y genera una sesión Rhino revocable.
- El usuario debe existir previamente en la tabla `users` de Rhino CRM.
- Google no concede permisos, no crea socios y no permite eludir suspensiones.

## Configuración Firebase

1. Firebase Console > Authentication > Método de acceso > Google > Habilitar.
2. Authentication > Configuración > Dominios autorizados > agregar `ventas.activa7.com`.
3. Configuración del proyecto > Tus aplicaciones > Agregar aplicación Web.
4. Copiar los identificadores públicos del objeto `firebaseConfig` al `.env`:

```env
FIREBASE_WEB_GOOGLE_ENABLED=true
FIREBASE_WEB_API_KEY=...
FIREBASE_WEB_AUTH_DOMAIN=rhino-crm.firebaseapp.com
FIREBASE_WEB_APP_ID=...
FIREBASE_WEB_MESSAGING_SENDER_ID=...
FIREBASE_WEB_STORAGE_BUCKET=...
RHINO_VERSION=2026.07.21-google-login.1
```

La configuración web no es la cuenta de servicio. Nunca se debe colocar `private_key` ni el contenido de `firebase-admin.json` en estas variables.

## Despliegue

```bash
cd /home2/manue221/rhinocrm
unzip -o Rhino_CRM_Google_Login_Update_2026-07-21.zip
php artisan optimize:clear
```

No requiere Composer ni migraciones.

## Prueba

1. Abrir una ventana privada en `https://ventas.activa7.com/`.
2. Pulsar **Continuar con Google**.
3. Seleccionar una cuenta cuyo correo ya exista en Rhino CRM.
4. Comprobar en el módulo Sesiones que se creó una sesión `WEB`.
5. Revocar esa sesión y confirmar que el acceso deja de funcionar.

Si la PWA conserva el login anterior, cerrarla por completo y abrirla de nuevo. El service worker de esta actualización utiliza una versión de caché nueva.
