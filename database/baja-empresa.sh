#!/bin/bash
# Paso del operador en la baja de una empresa: elimina sus archivos del almacenamiento y luego sus datos, solo si el
# administrador ya descargó la exportación y confirmó la baja en la aplicación. Deja el registro en empresa_bajas.
#   bash database/baja-empresa.sh ID_EMPRESA
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
empresa=${1:?Indique el ID de la empresa.}
case "$empresa" in ''|0*|*[!0-9]*) echo 'Indique el ID numérico de la empresa.' >&2; exit 1 ;; esac
estado=$(docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php";
  $q = conectarBaseLite()->prepare("SELECT estado FROM empresa_bajas WHERE empresa_id = ?"); $q->execute([$argv[1]]); echo $q->fetchColumn() ?: "sin baja";' "$empresa")
if [ "$estado" != confirmada ]; then
  echo "La empresa $empresa no tiene una baja confirmada (estado: $estado). No se elimina nada." >&2
  exit 1
fi
# Primero los archivos: si esto falla, la base sigue intacta y se puede reintentar.
docker compose -p myposlite exec -T storage sh /opt/lite-storage/retirar-empresa.sh "$empresa"
docker compose -p myposlite exec -T backend php -r 'require "backend/config/database.php"; require "backend/src/BajaEmpresa.php";
  echo json_encode(eliminarEmpresaBaja(conectarBaseLite(), $argv[1])), "\n";' "$empresa"
echo "Empresa $empresa eliminada. El registro de la baja queda en empresa_bajas."
