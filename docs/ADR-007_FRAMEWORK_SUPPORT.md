# ADR-007: Laravel y hosting compartido

## Decisión

Mantener Laravel como API modular y utilizar MySQL/MariaDB como persistencia primaria. Firebase se limita a autenticación opcional y notificaciones futuras.

## Razón

El hosting compartido no permite instalar extensiones PHP gRPC/Protobuf. MySQL, cron y PHP estándar sí están disponibles y cubren el modelo transaccional del CRM.

## Consecuencias

- Android consume JSON por HTTPS y nunca se conecta directamente a MySQL.
- Los bloqueos administrativos se aplican centralmente en Laravel.
- El tiempo real móvil se resolverá con FCM y sincronización, no con listeners de Firestore.
- Las actualizaciones usan migraciones aditivas y API versionada.
