# RHINO CRM — CONTEXTO DE CONTINUIDAD DEL PROYECTO

**Corte:** 24 de noviembre de 2026  
**Repositorio:** https://github.com/Regnedy/rhinocrm  
**Funcionalidad documentada:** desvinculación y reasignación de básculas por `SUPER_ADMIN`.

> Este documento conserva contexto técnico reutilizable. No contiene credenciales, nombres de bases de datos, nombres de usuario SSH ni rutas privadas del hosting. Verifica siempre el estado actual del repositorio y del entorno antes de ejecutar cambios.

## Instrucciones para nuevas conversaciones

Lee este archivo completo antes de proponer cambios. Continúa desde el estado descrito y verifica qué ha cambiado. Trabaja en español y guía al usuario por SSH paso a paso, con bloques pequeños y esperando el resultado antes del siguiente paso. No ejecutes operaciones destructivas ni despliegues sin respaldo y confirmación.

## 1. Arquitectura conocida

- Rhino CRM es un CRM multitenant para socios, con Laravel, PHP, MySQL/MariaDB, app Android/Kiosko y básculas ESP32.
- Repositorio: `Regnedy/rhinocrm` (público).
- Arquitectura observada: Laravel 13; módulos en `app/Modules`; rutas API en `routes/api_v1.php`; interfaz principal en `resources/views/crm.blade.php`; JavaScript principal en `public/assets/rhino-crm.js`.
- El entorno de hosting observado ejecutaba Laravel Framework 13.20.0 y PHP compatible con el proyecto.
- Node.js no estaba instalado en el hosting observado; el instalador omitió `node --check`. Validar JavaScript en un entorno que disponga de Node cuando sea necesario.
- No se agregaron dependencias Composer para la funcionalidad de desvinculación.

## 2. Reglas para trabajar por SSH

- Confirmar siempre la raíz del proyecto con `pwd` antes de copiar archivos o ejecutar instaladores.
- Extraer paquetes de actualización en una carpeta externa al CRM, nunca directamente sobre la raíz de producción.
- No pedir ni publicar contraseñas. Las credenciales de base de datos se leen desde la configuración Laravel y `.env`.
- En algunas sesiones el usuario pega comandos concatenados. Enviar un comando o bloque pequeño por turno y esperar el prompt y la salida antes de continuar.
- No recomendar `Ctrl+C` salvo que un proceso esté realmente bloqueado; cancela el comando actual.

## 3. Respaldo previo a despliegues

Antes de cada actualización:

1. Crear un respaldo de código fuera de la raíz del CRM.
2. Crear un dump completo de MySQL/MariaDB fuera de la raíz del CRM.
3. Verificar que el archivo de código se puede listar con `tar -tzf` y que el dump SQL no está vacío y termina correctamente.
4. Registrar los nombres y tamaños de los respaldos en las notas privadas del despliegue, no en este repositorio público.

En hosting compartido, `mysqldump` puede fallar por falta del privilegio `PROCESS` al intentar exportar tablespaces. En ese caso, `--no-tablespaces` resolvió el problema. No incluir contraseñas en argumentos visibles, historial de shell, documentación o chat.

## 4. Actualización de desvinculación de básculas

Paquete utilizado durante el despliegue inicial:

`RhinoCRM_Device_Unassignment_SuperAdmin_Update_2026-11-23_v2.zip`

SHA-256 comprobado durante ese despliegue:

`88088b9f390748cf898f9cd44879f63a736801e433030cd8b4b9a4f714a50112`

El ZIP pasó `unzip -t` y los archivos incluidos en `SHA256SUMS.txt` verificaron correctamente.

Resultado reportado por el instalador:

- Migración `2026_11_23_000100_add_device_unassignment_support`: aplicada.
- Ruta registrada: `POST api/v1/admin/devices/{id}/unassign`.
- Cachés Laravel limpiadas.
- `php -l` sin errores en los archivos PHP incluidos.
- `DeviceUnassignmentContractTest OK`.
- Node.js no estaba disponible en el hosting, por lo que no se ejecutó `node --check`.

La validación de instalación no equivale a una prueba funcional de punta a punta con MySQL real y báscula ESP32 física.

## 5. Comportamiento funcional previsto

- La desvinculación es una operación exclusiva de `SUPER_ADMIN`.
- Endpoint: `POST /api/v1/admin/devices/{id}/unassign`.
- La báscula queda en estado `UNASSIGNED` y sin socio/ubicación asignados (`partner_id` y `location_id` nulos).
- Se rota el secreto IoT para invalidar la autenticación anterior.
- Se liberan asociaciones operativas de eventos según las reglas de la actualización, conservando asociaciones históricas de eventos cerrados/cancelados.
- Las filas de dispositivos personales relacionados se marcan como retiradas; no se borran.
- Se registra auditoría y movimiento administrativo.
- La misma MAC puede registrarse posteriormente con otro socio, reutilizando el identificador del dispositivo y emitiendo un secreto nuevo, sin duplicar la báscula.
- No borrar, trasladar ni reescribir mediciones históricas.

**Precaución:** probar primero con un dispositivo de prueba o en staging. No utilizar inicialmente una báscula con historial importante. Suspender, activar, revocar y desvincular son operaciones distintas.

## 6. Estado de interfaz observado tras el despliegue inicial

En la primera revisión, SUPER ADMIN → Dispositivos → socio → Básculas vinculadas mostraba `Suspender`/`Activar`, pero no `Desvincular`.

Comprobaciones realizadas:

- `resources/views/crm.blade.php` incluyó la referencia a `/assets/rhino-device-unassignment.js`.
- `public/assets/rhino-device-unassignment.js` existía (aprox. 2.9 KB).
- Inicialmente tenía permisos `600`; se corrigieron a `644` con `chmod 644`.
- La ruta API de desvinculación estaba registrada.
- El script auxiliar buscaba el texto exacto `Administrador global` en el rol y los botones `.suspend-device` / `.activate-device`.

**Pendiente en el último estado observado:** confirmar mediante captura después del cambio de permisos y recarga forzada si aparece el botón. Si no aparece:

1. Comprobar que el navegador recibe el JS con HTTP 200, no 403/404.
2. Revisar la consola del navegador.
3. Verificar el texto real del rol y los selectores de botones.
4. Si el script auxiliar sigue siendo frágil, integrar la acción directamente en `renderDevices()` del JavaScript principal, manteniendo la autorización `SUPER_ADMIN` en backend.
5. Volver a validar permisos, cachés y carga sin caché.

No declarar la interfaz terminada hasta comprobarla.

## 7. Git y publicación

El despliegue se realizó inicialmente en un hosting conectado al remoto `origin` de GitHub. Antes de publicar:

1. Ejecutar `git fetch origin`.
2. Revisar `git status -sb` y `git rev-list --left-right --count HEAD...origin/main`.
3. Comparar los diffs con `origin/main`; no hacer `reset --hard` ni `pull` a ciegas.
4. Crear una rama feature y añadir sólo archivos intencionales; evitar `git add .`.
5. Ejecutar `git diff --check`, pruebas disponibles y revisar `git diff --cached` antes del commit.
6. Publicar la rama y abrir un Pull Request hacia `main`.

En una sesión se observó el remoto SSH `origin` apuntando al repositorio GitHub. Eso no garantiza que el servidor tenga autorización de escritura; comprobar el acceso de push antes de publicar.

### Archivos temporales y sensibles que no se deben publicar

- `.env`, secretos, tokens, contraseñas, archivos de configuración privados.
- `vendor/`, logs, cachés, dumps SQL, archivos TAR/ZIP de respaldo.
- Archivos de respaldo de parches como `*.orig`.
- Archivos temporales de CLI/cache que no formen parte intencional del código.

La eliminación de un archivo ya versionado debe revisarse y no incluirse accidentalmente.

## 8. Pruebas y seguridad

- Las migraciones deben ser incrementales, compatibles y no destructivas.
- La autorización real debe existir en backend; ocultar un botón en frontend no es seguridad suficiente.
- Mantener los límites multitenant.
- No ejecutar `migrate:fresh`, `db:wipe`, rollback destructivo ni `git reset --hard` en producción.
- Probar desvinculación y reasignación con un dispositivo de prueba y verificar que los pesajes históricos permanecen intactos.
- No tocar dispositivos con historial importante hasta completar la prueba controlada.

## 9. Mensaje de continuidad

> Continúa mi proyecto Rhino CRM. Lee `docs/CONTEXTO_CONTINUIDAD_RHINO_CRM.md` completo. Primero verifica el estado actual de GitHub, SSH y los pendientes de la funcionalidad de desvinculación. No repitas el análisis inicial ni ejecutes cambios destructivos. Guíame paso a paso y actualiza este contexto al terminar cada nueva versión.
