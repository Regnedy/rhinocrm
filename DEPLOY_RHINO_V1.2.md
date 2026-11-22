# Despliegue RHINO V1.2

## Orden obligatorio

1. Hacer backup de archivos y base de datos del CRM actual.
2. Instalar primero el parche backend.
3. Ejecutar migraciones.
4. Limpiar cachés Laravel.
5. Verificar endpoints V1.2.
6. Compilar/instalar Android V1.2.

## Backend

Descomprime `RhinoCRM_Backend_Eventos_V1.2_PATCH_2026-08-10.zip` sobre la raíz de tu instalación RHINO CRM conservando las rutas.

Ejecuta desde la raíz Laravel:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Si tu hosting no permite `queue:restart`, omite únicamente ese comando y reinicia el worker desde el panel que utilices.

### Comprobaciones mínimas

```bash
php artisan route:list --path=campaigns --except-vendor
php artisan route:list --path=tasks --except-vendor
```

Confirma que aparecen `pipeline`, `collaborators`, `contact-started`, `responded`, `commercial-status`, `assignee` y `send-whatsapp`.

## Android

El ZIP de Android se entrega sin `local.properties`, builds antiguos, cachés ni llaves privadas. Conserva/coloca tu `app/google-services.json` correspondiente a `com.activa7.rhinocrm.mobile`.

Compilación:

```bash
./gradlew clean assembleDebug
```

Para distribución real utiliza tu proceso de firma habitual con la misma llave de la versión ya instalada.

## Prueba funcional recomendada

Usa un evento de prueba y valida en este orden:

1. Abrir evento y cambiar entre filtros.
2. Abrir Pipeline y tocar cada etapa.
3. Abrir un prospecto con pesaje y sin WhatsApp.
4. Confirmar WhatsApp y verificar cambio visual.
5. Pulsar SALUDO y volver a RHINO; debe quedar CONTACTO INICIADO.
6. Marcar respuesta o recibir una respuesta real por Meta.
7. Generar plan.
8. Enviar plan directo; solo en éxito debe aparecer PLAN ENVIADO.
9. Cambiar a Interesado/Evaluando/No interesado por ahora.
10. Reasignar a un colaborador del evento.
11. Registrar una venta y comprobar Compra realizada.
12. Revisar Timeline/Actividad.

## Importante sobre WhatsApp

El envío directo depende de la configuración existente del canal WhatsApp del socio y de las reglas que ya aplica `NutritionPlanDeliveryService`. Si no está disponible, Android ofrece compartir manualmente; ese fallback no cambia automáticamente el estado a PLAN ENVIADO.

## Rollback

Restaura los archivos backend desde tu backup y vuelve a instalar la APK anterior si fuera necesario. La migración V1.2 no elimina sus columnas al hacer rollback para preservar la trazabilidad comercial; si alguna vez fuera necesario eliminarlas, debe hacerse mediante una migración destructiva explícita y con backup previo.
