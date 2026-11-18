#!/bin/bash
# Aprovisiona en MinIO el bucket y el usuario de una o más empresas, con la contraseña derivada del secreto local.
# Uso: bash database/aprovisionar-almacenamiento.sh ID [ID...]   (requiere el perfil storage levantado)
# Volver a ejecutarlo es seguro: reaplica la política y la regla de 365 días.
cd "$(dirname "$0")/.." || exit 1
# Git Bash en Windows convertiría /opt/... del contenedor en una ruta de Windows.
export MSYS_NO_PATHCONV=1
[ $# -gt 0 ] || { echo "Indique el ID de al menos una empresa." >&2; exit 1; }
for empresa in "$@"; do
  clave=$(docker compose -p myposlite exec -T backend php backend/bin/clave-almacenamiento.php "$empresa") || exit 1
  LITE_OBJECT_PASSWORD="$clave" docker compose -p myposlite exec -T -e LITE_OBJECT_PASSWORD storage \
    sh /opt/lite-storage/provision-company.sh "$empresa" >/dev/null || { echo "No se pudo aprovisionar la empresa $empresa." >&2; exit 1; }
  echo "Almacenamiento aprovisionado para la empresa $empresa."
done
