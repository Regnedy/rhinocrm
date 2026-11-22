RHINO CRM - ACTUALIZACION FIREBASE AUTH 2026-07-21

1. Respaldar archivos y MySQL.
2. Extraer este ZIP en la raiz del CRM, conservando .env, vendor y storage.
3. Ejecutar:

   cd /home2/manue221/rhinocrm
   composer update kreait/firebase-php --with-all-dependencies --no-dev --prefer-dist --optimize-autoloader

4. Configurar .env:

   RHINO_AUTH_DRIVER=hybrid
   FIREBASE_AUTH_ENABLED=true
   FIREBASE_PROJECT_ID=TU_PROJECT_ID
   FIREBASE_CREDENTIALS=/home2/manue221/rhinocrm/storage/app/private/firebase/firebase-admin.json
   FIREBASE_REQUIRE_EMAIL_VERIFIED=true
   FIREBASE_AUTO_LINK_BY_EMAIL=true
   FIREBASE_CHECK_REVOKED_TOKENS=true
   FIREBASE_ALLOW_DIRECT_API_TOKENS=false
   FIREBASE_SYNC_CUSTOM_CLAIMS_ON_LOGIN=true
   RHINO_VERSION=2026.07.21-firebase.1

5. Ejecutar:

   php artisan optimize:clear
   php artisan rhino:firebase-check

6. Vincular el administrador actual:

   php artisan rhino:firebase-provision TU_CORREO --send-verification

No usa Firestore. No requiere gRPC ni Protobuf. No contiene migraciones destructivas.
