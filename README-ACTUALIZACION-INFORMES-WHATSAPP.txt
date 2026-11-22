NOTA DE ACTUALIZACIÓN
---------------------
Este paquete fue ampliado con alimentación recomendada. Lee primero README-ALIMENTACION-RECOMENDADA.txt.

RHINO CRM — INFORMES PERSONALIZADOS POR EVENTO Y WHATSAPP
Versión: 2026.07.22-personal-reports.1

OBJETIVO
--------
Al cerrar un evento, el socio pulsa un solo botón en Rhino CRM.
El sistema genera un PDF individual y programa un envío independiente para
cada participante elegible. No envía a toda la lista del evento.

Un participante es elegible únicamente cuando:

1. Tiene un pesaje vinculado al evento.
2. Tiene un código público RHINO.
3. Envió ese código desde el mismo número guardado en su expediente.
4. El webhook oficial de WhatsApp recibió el mensaje.
5. El texto contiene autorización para recibir el informe.
6. El consentimiento sigue vigente.
7. El informe de ese pesaje no fue enviado anteriormente.
8. La conversación está habilitada para respuesta o existe una plantilla
   aprobada con encabezado DOCUMENT.

Cada participante se procesa mediante un trabajo de cola separado. Un error
con una persona no detiene los demás informes.

CONTENIDO DEL PDF
-----------------
- Nombre y código RHINO.
- Evento y fecha del pesaje.
- Resultado actual.
- Comparación con el primer pesaje del expediente.
- Número de pesajes acumulados.
- Orientación alimentaria general.
- Invitación a consultar a un médico o profesional de nutrición.
- Aviso de que las estimaciones no constituyen diagnóstico.

Esta versión NO prescribe una dieta clínica. El generador nutricional completo
seguirá siendo un módulo independiente con cuestionario y reglas de revisión.

REQUISITOS
----------
- PHP 8.3.
- Extensiones DOM y Mbstring.
- Cron de Laravel activo cada minuto.
- Cola database operativa.
- WhatsApp Cloud API configurada.
- Webhook de WhatsApp configurado en:
  https://ventas.activa7.com/api/v1/webhooks/whatsapp
- Suscripción de webhook para mensajes y estados.
- Número oficial de WhatsApp en formato internacional, solo dígitos.

INSTALACIÓN
-----------
1. Respaldar MySQL y el directorio /home2/manue221/rhinocrm.
2. Subir el ZIP de actualización a la raíz del CRM.
3. Ejecutar:

   cd /home2/manue221/rhinocrm

   cp .env ".env.backup-personal-reports-$(date +%Y%m%d-%H%M%S)"

   unzip -o Rhino_CRM_Event_Personal_Reports_WhatsApp_Update_2026-07-22.zip

   php -m | grep -iE 'dom|mbstring'

   composer update dompdf/dompdf \
     --with-all-dependencies \
     --no-dev \
     --prefer-dist \
     --optimize-autoloader

   php artisan migrate --force
   php artisan optimize:clear
   php artisan rhino:contacts-backfill-codes

4. No ejecutar migrate:fresh, migrate:reset, migrate:rollback ni db:wipe.

VARIABLES .ENV
--------------
Conservar los valores secretos fuera de archivos públicos.

RHINO_VERSION=2026.07.22-personal-reports.1
RHINO_PERSONAL_REPORT_DISK=local
RHINO_PERSONAL_REPORT_DIRECTORY=personal-reports
RHINO_PERSONAL_REPORT_ALGORITHM=rhino-orientation-v1
RHINO_WHATSAPP_BUSINESS_PHONE=521XXXXXXXXXX
RHINO_WHATSAPP_WINDOW_HOURS=24
RHINO_WHATSAPP_REPORT_TEMPLATE=
RHINO_WHATSAPP_REPORT_TEMPLATE_LANGUAGE=es_MX

META_WHATSAPP_API_URL=https://graph.facebook.com/v25.0
META_PHONE_NUMBER_ID=
META_ACCESS_TOKEN=
META_WEBHOOK_VERIFY_TOKEN=
META_APP_SECRET=

Si se utilizará envío cuando la conversación ya no permita una respuesta
directa, configurar RHINO_WHATSAPP_REPORT_TEMPLATE con el nombre exacto de una
plantilla aprobada que tenga encabezado DOCUMENT y dos variables de cuerpo:

{{1}} nombre de la persona
{{2}} código RHINO

Luego ejecutar:

   php artisan optimize:clear

CRON Y COLA
-----------
El paquete corrige la forma de programar queue:work para que
--stop-when-empty se envíe como bandera y no como valor.

Cron de cPanel, cada minuto:

cd /home2/manue221/rhinocrm && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1

Verificar:

php artisan schedule:list
php artisan queue:failed

PRUEBA FUNCIONAL
----------------
1. Configurar RHINO_WHATSAPP_BUSINESS_PHONE.
2. Compilar/instalar RHINO Kiosco Android V2.6.
3. Crear y activar un evento.
4. Realizar un pesaje.
5. En la aplicación, solicitar el informe.
6. La app debe abrir el chat oficial con el código RHINO.
7. La persona debe pulsar Enviar.
8. Confirmar que el webhook registra el mensaje y consentimiento.
9. Cerrar el evento.
10. Abrir el evento en Rhino CRM.
11. Revisar la cantidad de elegibles.
12. Pulsar Enviar informes.
13. Revisar enviados, entregados, leídos, fallidos y no elegibles.

COMANDOS DE DIAGNÓSTICO
-----------------------
php artisan migrate:status --no-ansi | grep 000600
php artisan route:list --path=api/v1/campaigns
php artisan rhino:contacts-backfill-codes --limit=10
php artisan schedule:list
php artisan queue:failed

tail -n 150 storage/logs/laravel.log

SEGURIDAD
---------
- Los PDF se almacenan fuera de public/.
- El código RHINO no es una contraseña ni concede acceso por sí solo.
- El número del remitente debe coincidir con el expediente.
- El consentimiento puede revocarse enviando BAJA, STOP, CANCELAR o NO QUIERO.
- El PDF es una orientación, no un diagnóstico.
- No incluir firebase-admin.json, google-services.json, .env ni tokens en ZIP
  públicos.

ALERTA SOBRE EL PROTOTIPO README.ZIP
------------------------------------
El prototipo nutricional adjunto anteriormente contiene un token de Telegram
en texto plano. Debe revocarse y sustituirse. No reutilizar su calculadora
clínica ni sus secretos en producción.
