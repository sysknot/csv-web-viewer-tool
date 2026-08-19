# Diseño del importador

Cada CSV es una fotografía completa de la fuente. El proceso tiene dos fases separadas: el análisis no modifica el dataset; la confirmación carga los datos en staging y aplica los cambios dentro de una única transacción SQLite.

## Análisis previo

El parser usa `fgetcsv` en modo secuencial, por lo que no carga el CSV completo en memoria. Acepta BOM UTF-8, delimitadores comunes, comillas, valores con delimitadores y saltos de línea escapados.

Se validan cabecera, nombres duplicados o vacíos, número de campos, UTF-8, filas inconsistentes y tipos aproximados. Los candidatos convencionales (`id`, `uuid`, `codigo`, `code`) se comprueban contra un índice SQLite temporal en disco: así su unicidad se puede validar sin guardar todos sus valores en PHP.

La detección de tipos es deliberadamente conservadora. Solo se asigna entero, decimal, booleano, fecha o fecha/hora cuando todos los valores no vacíos de la muestra son compatibles. Fechas ambiguas permanecen como texto hasta que un administrador fije un tipo manual.

## Confirmación atómica

1. Se registra la operación como `running`.
2. Se vuelve a leer el archivo, fila por fila, en una tabla temporal `staging_records`.
3. Se calcula y valida la clave lógica de cada fila; duplicados, claves vacías o estructura inconsistente abortan el proceso.
4. Se sincronizan metadatos de columnas y se insertan o actualizan registros y valores.
5. Los registros no vistos en la nueva fotografía se marcan inactivos.
6. Se registra el resultado y se confirma la transacción.

Ante cualquier excepción se revierte la transacción. El registro de importación se marca como `failed` fuera de ella, de modo que el fallo queda auditado pero los datos anteriores no cambian.

## Evolución del esquema

La tabla `dataset_columns` almacena nombres originales y presentación; `record_values` almacena valores originales y sus formas normalizadas para buscar y ordenar. No se crean tablas físicas por CSV.

Las columnas nuevas se añaden visibles por defecto. Las columnas ausentes no se eliminan ni pierden aliases o tipos manuales: se marcan como históricas. Si una columna vuelve a aparecer, recupera su configuración previa.

Un valor vacío de una columna presente actualiza esa celda como vacío. En cambio, una columna ausente de toda la fotografía no borra valores históricos.
