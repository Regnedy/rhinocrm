RHINO CRM - LOGIN WEB CON GOOGLE 2026-07-21

1. En Firebase habilitar Authentication > Google.
2. Agregar ventas.activa7.com a Dominios autorizados.
3. Registrar una Aplicación Web y copiar su firebaseConfig.
4. Respaldar los archivos actuales.
5. Extraer este ZIP en /home2/manue221/rhinocrm conservando .env, vendor y storage.
6. Agregar al .env:

   FIREBASE_WEB_GOOGLE_ENABLED=true
   FIREBASE_WEB_API_KEY=VALOR_DE_FIREBASE_WEB
   FIREBASE_WEB_AUTH_DOMAIN=rhino-crm.firebaseapp.com
   FIREBASE_WEB_APP_ID=VALOR_DE_FIREBASE_WEB
   FIREBASE_WEB_MESSAGING_SENDER_ID=VALOR_DE_FIREBASE_WEB
   FIREBASE_WEB_STORAGE_BUCKET=VALOR_DE_FIREBASE_WEB
   RHINO_VERSION=2026.07.21-google-login.1

7. Ejecutar:

   php artisan optimize:clear

No usa Firestore. No requiere gRPC, Protobuf, Composer ni migraciones.
El usuario debe existir previamente en Rhino CRM.
