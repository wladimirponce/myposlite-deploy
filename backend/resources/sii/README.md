# Esquemas del SII

`EnvioBOLETA_v11.xsd` y su dependencia `xmldsignature_v10.xsd`, copiados de Farmalider (`api/tests/Fixtures/sii/`, revisión 0c27acd), que a su vez los tomó de `central/boletas/schemas` de MyPOS.

La copia de MyPOS tenía dos restricciones de porcentaje con mínimo 0.00 sobre `PctType`, cuyo mínimo es 0.01, y libxml no compila ese esquema. Farmalider ajustó ambos mínimos a 0.01, una restricción más estricta. Lite usa esa copia.

Validar contra el esquema detecta errores de forma antes de enviar, pero no reemplaza la aceptación del SII. Referencia oficial: https://www.sii.cl/servicios_online/3532-instructivo_tecnico_be-3811.html

`ConsumoFolio_v10.xsd` (RCOF), `SiiTypes_v10.xsd`, `DTE_v10.xsd` y `EnvioDTE_v10.xsd`, copiados de MyPOS `admin/schemas/` en la revisión 0db4a67. El RCOF usa la estructura `Resumen/TipoDocumento` de este esquema: es la que el SII aceptó en el set de Farmacéutica Libertad, distinta del constructor RCOF operativo de Farmalider (`ResumenPeriodo`).

`DTE_v10.xsd` de MyPOS tiene dos restricciones `minInclusive` de 0.0000 sobre `Dec14_4Type` (montos en otra moneda), cuyo mínimo es 0.0001, y libxml no compila el esquema con ellas. Lite las sube a 0.0001, una restricción más estricta, con el mismo criterio que Farmalider aplicó a `PctType`. Ningún documento de Lite usa montos en otra moneda.
