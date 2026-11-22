# RHINO 2.7.0 — Instalación de la actualización

## Alcance

Esta entrega actualiza **RHINO Kiosko Android 2.6.0 → 2.7.0** y **RHINO CRM** sin reconstruir el sistema. Se conservan los flujos existentes de báscula BLE, impresión térmica, prospectos, eventos, nutrición, WhatsApp, usuarios y permisos.

La actualización agrega:

- **Mis dispositivos** para báscula e impresora personales.
- Asistente de configuración en máximo 3 pasos.
- Dispositivos disponibles temporalmente dentro de un evento sin cambiar propietario.
- Separación entre **Capturado por**, **Responsable actual** y **Propietario del dispositivo**.
- Historial de reasignaciones de prospectos.
- Privacidad post-evento por responsable.
- Estado visual **Finalizado / FINISHED**, conservando `CLOSED` internamente para compatibilidad.
- Historial del prospecto y pipeline del evento.
- Administración SUPER ADMIN de versiones Kiosko.
- Actualizador Android interno con verificación de integridad e identidad del APK.

## 1. Antes de actualizar el CRM

1. Haz un respaldo de la base de datos MySQL.
2. Haz un respaldo del código actual de RHINO CRM.
3. Confirma que el servidor ejecuta la misma versión de PHP/Laravel con la que ya funciona RHINO.
4. No borres tablas ni ejecutes un rollback destructivo.

La migración 2.7.0 es aditiva y conserva los datos actuales.

## 2. Instalar `RHINO_CRM_V2_7_0_UPDATE.zip`

Descomprime el ZIP sobre la raíz actual de RHINO CRM respetando las rutas de carpetas. El paquete sólo contiene archivos nuevos o modificados y documentación; no incluye `.env`, `vendor`, cachés ni logs.

Desde la raíz del CRM ejecuta:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan route:list --path=api/v1
```

No se agregaron dependencias Composer nuevas para RHINO 2.7.0, por lo que esta actualización no requiere cambiar `composer.json`.

Después de migrar, valida que el usuario SUPER ADMIN vea la opción:

**Versiones Kiosko**

Desde allí se pueden publicar APK recomendados u obligatorios.

## 3. Compilar RHINO Kiosko 2.7.0

Usa el proyecto:

`RHINO_Kiosko_V2_7_0_Source.zip`

La aplicación ya está configurada como:

- `applicationId`: `com.activa7.rhinocrm`
- `versionName`: `2.7.0`
- `versionCode`: `270`
- `compileSdk`: `35`
- `targetSdk`: `35`
- Java/JVM: `17`
- Gradle wrapper: `8.9`

Consulta `COMPILAR_ANDROID.md` para los pasos exactos.

## 4. Firma del APK

Para que el actualizador interno acepte futuras versiones, el APK nuevo debe conservar:

- el package `com.activa7.rhinocrm`;
- un `versionCode` superior al instalado;
- la **misma firma/certificado** que la aplicación RHINO actualmente instalada.

Si se firma con otra clave, Android y RHINO rechazarán la actualización, que es el comportamiento esperado de seguridad.

## 5. Publicar una actualización desde SUPER ADMIN

En RHINO CRM abre:

**SUPER ADMIN → Versiones Kiosko → Nueva versión**

Ejemplo de una futura versión:

- Versión: `2.7.1`
- Version Code: `271`
- Tipo: `Recomendada` u `Obligatoria`
- APK: APK compilado y firmado
- Notas: cambios visibles para el socio
- SHA-256 de certificado: opcional, recomendado

El servidor calcula automáticamente el SHA-256 del archivo APK almacenado.

## 6. Comportamiento del actualizador

Una actualización **RECOMMENDED** puede posponerse.

Una actualización **MANDATORY** abre la pantalla de actualización al entrar o regresar al flujo principal/Kiosko y no permite continuar con “Más tarde” mientras el servidor siga marcando esa versión como obligatoria.

Antes de instalar, Android verifica:

1. HTTPS y dominio CRM autorizado.
2. Ruta de descarga interna RHINO.
3. SHA-256 del APK.
4. `packageName`.
5. `versionCode`.
6. Firma del APK frente a la aplicación instalada.
7. Huella de certificado publicada, cuando SUPER ADMIN la proporciona.

El CRM registra `INSTALL_REQUESTED` antes de abrir el instalador y `INSTALLED` cuando la nueva versión arranca correctamente.

## 7. Prueba funcional recomendada

Prueba este escenario después de actualizar:

1. Emma crea un evento y lo activa.
2. Manuel configura su báscula en **Mis dispositivos**.
3. Manuel escanea el QR de Emma y marca su báscula como dispositivo disponible.
4. El Party debe mostrar a Manuel con su báscula.
5. Emma captura un prospecto usando esa báscula.
6. El prospecto debe mostrar Emma como **Capturado por / Responsable actual** y Manuel únicamente como **Propietario del dispositivo**.
7. Reasigna un prospecto y revisa **Ver historial**.
8. Finaliza el evento.
9. Verifica que la báscula vuelva a `Disponible` y que cada colaborador sólo pueda consultar sus prospectos asignados después del cierre.

## 8. Compatibilidad

El estado histórico del backend sigue siendo `CLOSED`. RHINO 2.7.0 lo presenta a la interfaz como `FINISHED / Finalizado` para no romper consultas, reportes o integraciones que ya dependían de `CLOSED`.

