#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

find app bootstrap config database routes tests public -type f -name '*.php' -print0 \
  | xargs -0 -P 4 -n1 php -l

python3 -m json.tool composer.json >/dev/null

if grep -RInE 'Google\\Cloud\\Firestore|FirestoreClient|ext-grpc|ext-protobuf|pecl install grpc|pecl install protobuf' \
  app bootstrap config database routes tests public composer.json Dockerfile .env.example 2>/dev/null; then
  echo "Se encontraron dependencias Firestore/gRPC no permitidas." >&2
  exit 1
fi

if grep -RInE "Artisan::call\(['\"](migrate:fresh|migrate:reset|migrate:rollback|db:wipe)|\$signature[[:space:]]*=[[:space:]]*['\"](migrate:fresh|migrate:reset|migrate:rollback|db:wipe)" \
  app bootstrap config database routes public 2>/dev/null; then
  echo "Se encontró una operación destructiva en código ejecutable." >&2
  exit 1
fi

if grep -RInE '\b(exec|shell_exec|system|passthru|proc_open|popen)[[:space:]]*\(' \
  app/Modules/DeveloperConsole app/Http/Middleware/EnsureDeveloperConsoleAccess.php resources/views/developer-console.blade.php 2>/dev/null; then
  echo "La consola contiene una función de shell no autorizada." >&2
  exit 1
fi

echo "Static audit completed: PHP syntax, composer JSON, no Firestore/gRPC, no destructive DB command and no shell function in the developer console."
