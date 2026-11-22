# Limitaciones conocidas

- Esta entrega es el backend/API y el instalador. No contiene una aplicación Android ni un panel web visual completo.
- Firebase Authentication está preparado, pero permanece deshabilitado hasta proporcionar proyecto y credenciales.
- FCM para notificaciones móviles requiere configuración adicional y registro de tokens.
- El modo offline de Android deberá implementarse en la aplicación móvil, normalmente con Room y sincronización por API.
- El hosting compartido no ofrece un proceso de cola residente; el proyecto usa ejecuciones cortas iniciadas por cron.
- Los reportes actuales son CSV privados. PDF y dashboards visuales pueden agregarse como módulos posteriores.
- El instalador no descarga Composer ni crea la base MySQL; ambos deben existir previamente.
- Una base existente con tablas del mismo nombre pero estructura ajena a Rhino no es compatible y debe separarse.
- La entrega fue validada estáticamente. Las pruebas de integración deben ejecutarse después de instalar `vendor/` y disponer de MySQL o SQLite compatible.


## Consola de mantenimiento

- No es una shell y no puede instalar paquetes del sistema, Composer ni extensiones PHP.
- No modifica archivos ni aplica parches automáticamente.
- No ejecuta migraciones desde la web; las actualizaciones de esquema continúan por `php artisan migrate --force` después de respaldo y revisión.
- La salida de comandos se limita y puede truncarse.
- El asistente IA requiere salida HTTPS desde el hosting, clave de API y un modelo configurado.
- La calidad del diagnóstico depende de los logs y archivos autorizados seleccionados.
- La consola no sustituye un entorno `staging` ni una copia de seguridad.
