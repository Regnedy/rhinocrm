# Actualización Firebase Authentication híbrida

Esta actualización incorpora Firebase Authentication al backend sin usar Firestore, gRPC ni Protobuf. MySQL sigue siendo la base de datos del CRM.

## Flujo de acceso

1. Android inicia sesión con Firebase Authentication.
2. Android obtiene un Firebase ID Token.
3. Android envía el ID Token a `POST /api/v1/auth/firebase/exchange`.
4. Laravel valida firma, vigencia, revocación, usuario y correo verificado.
5. Laravel vincula el UID por correo verificado si el usuario ya existe en Rhino.
6. Laravel crea una sesión Rhino revocable y la devuelve a Android.
7. Android usa únicamente el token `rhino_...` para las consultas siguientes.

Los Firebase ID Tokens directos no son aceptados por la API de negocio de forma predeterminada. Esto conserva el control individual de sesiones y dispositivos.

## Instalación

Antes de modificar `.env`, instala el SDK:

```bash
composer update kreait/firebase-php --with-all-dependencies --no-dev --prefer-dist --optimize-autoloader
```

Configura:

```dotenv
RHINO_AUTH_DRIVER=hybrid
FIREBASE_AUTH_ENABLED=true
FIREBASE_PROJECT_ID=tu-project-id
FIREBASE_CREDENTIALS=/home2/manue221/rhinocrm/storage/app/private/firebase/firebase-admin.json
FIREBASE_REQUIRE_EMAIL_VERIFIED=true
FIREBASE_AUTO_LINK_BY_EMAIL=true
FIREBASE_CHECK_REVOKED_TOKENS=true
FIREBASE_ALLOW_DIRECT_API_TOKENS=false
FIREBASE_SYNC_CUSTOM_CLAIMS_ON_LOGIN=true
RHINO_VERSION=2026.07.21-firebase.1
```

Después:

```bash
php artisan optimize:clear
php artisan rhino:firebase-check
```

## Provisionar el administrador existente

```bash
php artisan rhino:firebase-provision administrador@dominio.com --send-verification
```

La contraseña se solicita de forma oculta. Para una identidad ya comprobada, puede usarse `--trust-email` en lugar de enviar verificación.

## Contrato Android

```http
POST /api/v1/auth/firebase/exchange
Authorization: Bearer FIREBASE_ID_TOKEN
Content-Type: application/json
Accept: application/json
```

```json
{
  "device_name": "Samsung Galaxy S24",
  "device_fingerprint": "identificador-local-hasheable",
  "platform": "ANDROID",
  "app_version": "1.0.0",
  "fcm_token": null
}
```

La respuesta contiene `access_token`, `session_id` y `expires_at`. El `access_token` Rhino se usa en las siguientes peticiones.

## Reversión segura

Para desactivar Firebase sin tocar datos:

```dotenv
RHINO_AUTH_DRIVER=local
FIREBASE_AUTH_ENABLED=false
```

Luego:

```bash
php artisan optimize:clear
```

Los `firebase_uid` ya vinculados permanecen almacenados y pueden reutilizarse más adelante.
