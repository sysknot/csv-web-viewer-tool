# Arquitectura y extensión futura

La aplicación es un monolito PHP intencionadamente pequeño. El punto de entrada (`public/index.php`) compone servicios y controladores; los controladores contienen reglas HTTP y autorización; los servicios aplican reglas de negocio; los repositorios concentran SQL y las vistas solo presentan datos escapados.

## Límites de responsabilidad

- `Services/Csv`: parsing, inferencia de tipo y normalización reversible.
- `Services/Import`: staging, claves lógicas, actualización de fotografía y métricas.
- `Repositories`: persistencia de dataset, settings y auditoría.
- `Security`: sesión, autenticación y CSRF.
- `Controllers`: rutas, validación de petición y respuestas.

Al añadir una función, evita incorporar SQL en controladores y evita que las vistas modifiquen datos. Las migraciones deben ser aditivas y numeradas en `Database/Migrator.php`.

## Cambios previsibles

- **Filtros por columna o FTS5:** ampliar `DatasetRepository`; nunca filtrar todas las filas en JavaScript.
- **Usuarios individuales:** sustituir las dos credenciales de entorno por una tabla de usuarios y conservar los roles `reader` y `admin`.
- **Procesos de importación grandes:** mover `ImportService` a un comando de cola local, conservando staging y la misma transacción final.
- **Más volumen o concurrencia de escritura:** mantener el modelo de dominio y sustituir el adaptador SQLite por PostgreSQL; no hay SQL distribuido por las vistas ni controladores.

Los valores originales del CSV no deben sobrescribirse por motivos de formato. Cualquier nuevo formato debe usar los campos normalizados existentes o añadir metadatos de presentación a `dataset_columns`.
