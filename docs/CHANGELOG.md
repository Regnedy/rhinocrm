# Registro de cambios


## 2026-07-21 — eventos y minicampañas

- Simplificada la operación para que el socio sea la unidad principal; las sucursales quedan ocultas.
- Añadida ubicación técnica automática `Principal` para compatibilidad.
- Añadido módulo de eventos, jornadas de pesaje y Home Parties.
- Añadidos integrantes, responsables, equipos, básculas, metas y presupuesto por evento.
- Añadido registro manual de participantes sin duplicar contactos por WhatsApp.
- Añadida atribución automática de mediciones cuando una báscula está vinculada a un evento activo.
- Añadidos seguimientos, conversiones, ventas e ingresos atribuidos al evento.
- Añadidos informes y filtros de prospectos por campaña.
- Añadida migración exclusivamente aditiva y no destructiva.


## 2026-07-20 — consola de mantenimiento segura

- Añadido panel web `/admin/developer-console` exclusivo para `SUPER_ADMIN`.
- Añadido visor depurado de logs Laravel y seguridad.
- Añadido diagnóstico de MySQL, colas, outbox, cron, migraciones, permisos de carpetas y extensiones PHP.
- Añadida lista cerrada de acciones Artisan; no existe shell ni entrada de comandos arbitrarios.
- Añadida reautenticación y frase exacta para acciones que modifican estado.
- Añadida lectura de código limitada a carpetas y extensiones autorizadas.
- Añadida integración opcional con OpenAI Responses API para análisis, sin escritura ni ejecución automática.
- Añadidas tablas aditivas `maintenance_action_logs` y `ai_diagnostic_requests`.
- Añadido heartbeat del scheduler y auditoría de cada operación técnica.
- Añadida restricción opcional por dirección IP y límites de frecuencia.

## 2026-07-20 — edición MySQL con instalador

- Eliminada la dependencia de Firestore, gRPC, Protobuf y Cloud Storage.
- Añadidos repositorios SQL y migración aditiva de negocio.
- Añadida autenticación local con sesiones opacas revocables.
- Preparado el intercambio de identidad Firebase en modo opcional.
- Añadidos controles de suspensión para socios, usuarios, sesiones, dispositivos y contactos.
- Añadido módulo de equipos, integrantes, líderes, metas e informes.
- Añadida trazabilidad de prospecto generado y asignado.
- Convertidas ventas, inventario, finanzas y outbox a transacciones MySQL.
- Añadidos reportes CSV privados.
- Añadido `public/install.php` con bloqueo posterior a instalación.
- Convertidas migraciones de infraestructura a política no destructiva.
