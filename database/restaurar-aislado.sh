#!/bin/bash
# Restaura un respaldo de database/respaldo.sh en un entorno aislado: otro proyecto de Docker con sus propios volúmenes
# y puertos, sin tocar el entorno en uso. Sirve para ensayar la recuperación y revisar los datos antes de reemplazar nada.
#   bash database/restaurar-aislado.sh CARPETA_DEL_RESPALDO [PROYECTO]   (por omisión myposlite-restauracion)
#   bash database/restaurar-aislado.sh --retirar [PROYECTO]              (detiene el entorno aislado y borra sus volúmenes)
# Usa el .env de este equipo: MYPOSLITE_DTE_SECRET debe ser la misma llave del entorno respaldado.
# El programador no arranca: el entorno aislado no envía nada al SII hasta que alguien lo decida.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "${1:-}" = --retirar ]; then
  proyecto=${2:-myposlite-restauracion}
  [ "$proyecto" = myposlite ] && { echo 'El entorno en uso no se retira con este script.' >&2; exit 1; }
  docker compose -p "$proyecto" --profile storage down -v --remove-orphans
  exit 0
fi
respaldo=${1:?Indique la carpeta del respaldo.}
proyecto=${2:-myposlite-restauracion}
[ "$proyecto" = myposlite ] && { echo 'Restaure en un proyecto aislado, nunca sobre el entorno en uso.' >&2; exit 1; }
[ -f "$respaldo/base.sql.gz" ] && [ -f "$respaldo/manifiesto.json" ] || { echo 'La carpeta no tiene base.sql.gz y manifiesto.json.' >&2; exit 1; }
# Puertos propios, para convivir con el entorno en uso.
export MYPOSLITE_BACKEND_PORT=${MYPOSLITE_RESTAURACION_BACKEND_PORT:-18097}
export MYPOSLITE_STORAGE_PORT=${MYPOSLITE_RESTAURACION_STORAGE_PORT:-19097}
export MYPOSLITE_FRONTEND_PORT=${MYPOSLITE_RESTAURACION_FRONTEND_PORT:-5187}
dc() { MSYS_NO_PATHCONV=1 docker compose -p "$proyecto" --profile storage "$@"; }

python - "$(cygpath -w "$respaldo" 2>/dev/null || echo "$respaldo")" <<'PY'
import hashlib, json, os, sys
carpeta = sys.argv[1]
manifiesto = json.load(open(os.path.join(carpeta, 'manifiesto.json'), encoding='utf-8'))
for nombre, huella in manifiesto['huellas'].items():
    if hashlib.sha256(open(os.path.join(carpeta, nombre), 'rb').read()).hexdigest() != huella:
        sys.exit(f'{nombre} no coincide con la huella del manifiesto: el respaldo está dañado.')
PY

dc up -d --build database
if [ -f "$respaldo/objetos.tar.gz" ]; then
  # Los archivos se copian al volumen nuevo antes de que MinIO arranque, con el tar de la imagen del backend.
  dc create --build storage backend >/dev/null
  dc run --rm --no-deps -T -v "${proyecto}_lite_objects:/data" --entrypoint tar backend -C /data -xzf - < "$respaldo/objetos.tar.gz"
fi
until dc exec -T database healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done
gunzip -c "$respaldo/base.sql.gz" | dc exec -T database sh -c 'exec mariadb -umyposlite -p"$MARIADB_PASSWORD" myposlite'
dc up -d --build storage backend frontend
until curl -sf "http://127.0.0.1:$MYPOSLITE_BACKEND_PORT/health.php" >/dev/null; do sleep 2; done
until dc exec -T storage curl -sf http://127.0.0.1:9000/minio/health/ready >/dev/null 2>&1; do sleep 2; done
# Mismas migraciones que el código actual: si el respaldo es anterior, se aplican las que falten.
dc exec -T backend php database/migrate.php >/dev/null
echo "Restaurado en el proyecto $proyecto: http://127.0.0.1:$MYPOSLITE_FRONTEND_PORT"
