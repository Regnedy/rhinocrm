RHINO CRM — HOTFIX GOOGLE LOGIN POPUP
Fecha: 2026-07-21

Objetivo
--------
Sustituye signInWithRedirect por signInWithPopup en todos los dispositivos.
Esto evita el problema de almacenamiento entre dominios que afecta al flujo
de redirección cuando la aplicación está alojada fuera de Firebase Hosting.

Instalación
-----------
1. Suba este ZIP a /home2/manue221/rhinocrm
2. Ejecute:

   cd /home2/manue221/rhinocrm
   unzip -o Rhino_CRM_Google_Login_Popup_Hotfix_2026-07-21.zip
   php artisan optimize:clear

3. Cierre la PWA o pestaña.
4. En Chrome, borre los datos del sitio o desregistre el service worker.
5. Abra una ventana privada y pruebe https://ventas.activa7.com/

No contiene migraciones, Composer, .env ni credenciales.
