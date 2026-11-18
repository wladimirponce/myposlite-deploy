#!/bin/bash
# Respaldo local de MyPOS Lite: base de datos y archivos de ingreso (MinIO), con un manifiesto para verificar la restauración.
#   bash database/respaldo.sh CARPETA_DESTINO [PROYECTO]
# Deja en CARPETA_DESTINO/AAAAMMDD-HHMMSS: base.sql.gz, objetos.tar.gz y manifiesto.json.
# No incluye el .env: sin MYPOSLITE_DTE_SECRET los certificados y CAF de la base no se pueden abrir, así que esa llave
# se guarda aparte, en un lugar seguro, y la restauración la recibe del .env del equipo que restaura.
set -euo pipefail
destino=${1:?Indique la carpeta de destino del respaldo.}
proyecto=${2:-myposlite}
cd "$(dirname "$0")/.."
dc() { MSYS_NO_PATHCONV=1 docker compose -p "$proyecto" "$@"; }

carpeta="$destino/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$carpeta"
# Una transacción consistente de InnoDB, sin bloquear la operación. --hex-blob conserva byte a byte los XML firmados en
# ISO-8859-1: un volcado que los recodifique invalida sus firmas.
dc exec -T database sh -c 'exec mariadb-dump -umyposlite -p"$MARIADB_PASSWORD" --single-transaction --quick --routines --triggers --hex-blob \
  --default-character-set=utf8mb4 myposlite' | gzip -9 > "$carpeta/base.sql.gz"
# Todo /data de MinIO: buckets, objetos, usuarios y políticas por empresa, y reglas de vencimiento.
# La imagen de MinIO no trae tar: se lee el volumen desde la imagen del backend, en solo lectura.
if docker volume inspect "${proyecto}_lite_objects" >/dev/null 2>&1; then
  dc run --rm --no-deps -T -v "${proyecto}_lite_objects:/data:ro" --entrypoint tar backend -C /data -czf - . > "$carpeta/objetos.tar.gz"
fi

# Manifiesto: versión en ejecución, migraciones, filas por tabla y huella de cada archivo de ingreso.
dc exec -T backend php -r '
require "backend/config/database.php";
$db = conectarBaseLite();
$tablas = [];
foreach ($db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \"BASE TABLE\" ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $tablas[$t] = (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$migraciones = $db->query("SELECT nombre FROM lite_migraciones ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(["tablas" => $tablas, "migraciones" => $migraciones,
    "documentosAceptados" => (int) $db->query("SELECT COUNT(*) FROM dte_documentos WHERE estado IN (\"aceptado\", \"reparo\")")->fetchColumn(),
    "archivos" => (int) $db->query("SELECT COUNT(*) FROM archivos_recibidos")->fetchColumn()]);
' > "$carpeta/estado.json"
python - "$(cygpath -w "$carpeta" 2>/dev/null || echo "$carpeta")" "$(git rev-parse --short HEAD 2>/dev/null || echo desconocida)" <<'PY'
import hashlib, json, os, sys
carpeta, version = sys.argv[1], sys.argv[2]
estado = json.load(open(os.path.join(carpeta, 'estado.json'), encoding='utf-8'))
os.remove(os.path.join(carpeta, 'estado.json'))
huellas = {}
for nombre in ('base.sql.gz', 'objetos.tar.gz'):
    ruta = os.path.join(carpeta, nombre)
    if os.path.exists(ruta):
        huellas[nombre] = hashlib.sha256(open(ruta, 'rb').read()).hexdigest()
json.dump({'version': version, 'creado': os.path.basename(carpeta), 'huellas': huellas, **estado},
          open(os.path.join(carpeta, 'manifiesto.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
PY
echo "$carpeta"
