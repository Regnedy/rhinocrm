# Política de seguridad de la base de datos

## Principio

Los datos productivos se consideran permanentes. Una actualización no debe reconstruir la base ni borrar tablas existentes.

## Operaciones prohibidas en producción

```text
php artisan migrate:fresh
php artisan migrate:reset
php artisan migrate:rollback
php artisan db:wipe
DROP DATABASE
TRUNCATE de tablas de negocio
```

## Migraciones permitidas

- Crear tablas nuevas cuando no existan.
- Añadir columnas inicialmente nulas o con un valor predeterminado compatible.
- Añadir índices después de revisar tamaño y bloqueo esperado.
- Copiar datos en lotes fuera de una migración larga.
- Activar funciones nuevas mediante feature flags.

Las migraciones incluidas usan comprobaciones `Schema::hasTable()` y sus métodos `down()` no eliminan información.

## Cambios de estructura

Para sustituir una estructura se usa el patrón:

1. Expandir: añadir la nueva tabla o columna.
2. Compatibilizar: publicar código que soporte ambas estructuras.
3. Migrar: copiar datos en lotes y verificar conteos.
4. Cambiar lectura: activar la nueva estructura gradualmente.
5. Retirar: únicamente con respaldo y autorización expresa.

## Antes de desplegar

- Copia SQL completa.
- Copia de `.env` en ubicación segura.
- Registro de la versión del código.
- Prueba en una copia de la base.
- Comprobación de espacio disponible.

## Reversión

La reversión normal consiste en restaurar el código anterior. No debe ejecutarse automáticamente el `down()` de las migraciones. Cuando un cambio de datos requiera restauración, debe usarse el respaldo validado.
