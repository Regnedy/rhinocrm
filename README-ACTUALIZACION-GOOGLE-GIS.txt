RHINO CRM — LOGIN GOOGLE IDENTITY SERVICES

1. En Google Cloud Console, proyecto rhino-crm:
   Google Auth Platform > Clients.
2. Use o cree un cliente OAuth 2.0 de tipo Web application.
3. Authorized JavaScript origins:
   https://ventas.activa7.com
4. Copie el Client ID terminado en .apps.googleusercontent.com.
5. Agregue al .env:
   FIREBASE_WEB_GOOGLE_CLIENT_ID=EL_CLIENT_ID
   RHINO_VERSION=2026.07.21-google-gis.1
6. Extraiga el ZIP en la raíz del CRM.
7. Ejecute:
   php artisan optimize:clear
8. Borre los datos del sitio/service worker y pruebe en incógnito.

No requiere Composer ni migraciones.
