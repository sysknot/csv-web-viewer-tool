# CSV Web Viewer

Aplicación ligera para importar fotografías completas de datos CSV y consultarlas de forma segura. Los usuarios de consulta solo pueden leer; el backoffice permite importar, revisar el historial y configurar cómo se muestran las columnas.

No depende de servicios externos, Node.js ni un framework PHP. Se ejecuta en un único contenedor Docker con PHP, Apache y SQLite.

## Características

- Acceso público protegido por contraseña independiente del administrador.
- Importación en dos pasos: análisis y confirmación.
- CSV procesado en streaming: UTF-8/BOM, comillas, comas internas, saltos de línea escapados y delimitador detectado.
- Importación transaccional con staging: un fallo conserva íntegra la fotografía anterior.
- Clave lógica simple o compuesta, comprobada contra valores vacíos y duplicados.
- Columnas dinámicas: alias, orden, visibilidad, tipo detectado o manual y columnas históricas.
- Paginación, búsqueda y ordenación en servidor; no descarga el dataset completo al navegador.
- Vista de detalle de solo lectura y reordenación temporal de columnas con `localStorage`.
- Historial de importaciones, registro de auditoría, logs y backups consistentes descargables solo por administración.

## Instalación rápida

1. Copia el fichero de ejemplo:

   ```bash
   cp .env.example .env
   ```

   En PowerShell: `Copy-Item .env.example .env`.

2. Genera hashes bcrypt para las dos contraseñas:

   ```bash
   docker compose run --rm app php bin/password-hash.php "una-contraseña-larga"
   ```

3. Edita `.env`. Configura al menos `APP_SECRET`, `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` y `FRONTEND_PASSWORD_HASH`. Conserva los hashes bcrypt entre comillas simples para que Docker Compose no interprete sus símbolos `$`.

4. Arranca la aplicación:

   ```bash
   docker compose up -d --build
   ```

5. Abre `http://localhost:8080` para consulta y `http://localhost:8080/admin/login` para administración.

No subas `.env` a Git. El `.gitignore` ya lo excluye.

## Variables de entorno

| Variable | Uso |
|---|---|
| `APP_ENV` | `production` o `development`. En desarrollo se muestran excepciones. |
| `APP_SECRET` | Reserva un secreto aleatorio largo para futuras integraciones criptográficas. |
| `APP_URL` | URL pública prevista de la aplicación. |
| `APP_TIMEZONE` | Zona horaria de la presentación. |
| `ADMIN_USERNAME` | Usuario del backoffice. |
| `ADMIN_PASSWORD_HASH` | Hash bcrypt, nunca la contraseña en texto plano. |
| `FRONTEND_PASSWORD_HASH` | Hash bcrypt de acceso consultivo. |
| `DB_PATH` | Ruta SQLite dentro del contenedor. Normalmente no se modifica. |
| `UPLOAD_MAX_SIZE` | Límite lógico de subida en bytes; el valor por defecto son 50 MB. |
| `SESSION_TIMEOUT` | Inactividad máxima de sesión en segundos. |
| `APP_PORT` | Puerto del host publicado por Docker. |

El límite de PHP para archivos se define en `docker/php.ini` (64 MB). Si aumentas `UPLOAD_MAX_SIZE`, aumenta también `upload_max_filesize` y `post_max_size` de forma coherente.

## Primera importación

1. Entra como administrador y abre **Importar**.
2. Sube un CSV.
3. Revisa cabecera, filas, tipos detectados y candidatos de clave.
4. Selecciona la clave lógica. Puede ser una combinación de columnas.
5. Confirma la importación.
6. En **Columnas**, ajusta aliases, orden, tipo y visibilidad.

La clave lógica debe ser estable y única. Si el CSV no tiene una identidad fiable, se puede usar el modo explícito de reemplazo completo; en ese modo no es posible saber con precisión qué registro concreto cambió entre fotografías.

## Cómo se actualizan los datos

Cada CSV se considera una fotografía completa de la fuente:

- Una clave no existente crea un registro.
- Una clave existente actualiza el registro y sus valores.
- Un registro activo que no aparece en la nueva fotografía queda inactivo y desaparece de la consulta.
- Una columna nueva se incorpora automáticamente y es visible por defecto.
- Una columna ausente se conserva como histórica, con su configuración y valores previos.

Antes de modificar datos, el importador valida la estructura. Al confirmar, vuelve a leer el CSV y lo almacena temporalmente dentro de una transacción SQLite. Solo al final se confirma el cambio. Cualquier error hace `ROLLBACK`, por lo que nunca queda una importación parcial.

Consulta [docs/importer.md](docs/importer.md) para los detalles técnicos.

La organización interna y puntos de extensión se describen en [docs/architecture.md](docs/architecture.md).

## Backups y restauración

Desde **Administración → Ajustes** se puede descargar una copia consistente de SQLite. El fichero se genera en un directorio privado y se elimina del servidor tras descargarlo.

Para una copia de emergencia por consola:

```bash
docker compose exec app php -r '$db=new PDO("sqlite:".getenv("DB_PATH")); $db->exec("VACUUM INTO \"/var/www/storage/backups/manual.sqlite\"");'
```

Para restaurar:

1. Detén la aplicación: `docker compose stop`.
2. Conserva una copia del fichero SQLite actual del volumen `csv_data`.
3. Sustituye `app.sqlite` por la copia de seguridad en `/var/www/storage/database/` dentro del volumen.
4. Arranca de nuevo: `docker compose up -d`.

Haz siempre una copia del fichero actual antes de restaurar y verifica que el backup procede de una instancia compatible.

## Actualización

```bash
git pull
docker compose up -d --build
```

Las migraciones se ejecutan automáticamente al iniciar la aplicación. El volumen `csv_data` conserva la base y los datos al recrear el contenedor.

## Tests

Las pruebas están libres de dependencias externas y se ejecutan con PHP dentro del contenedor:

```bash
docker compose run --rm app php tests/run.php
```

Cubren parser, tipos, candidatos de clave, importación inicial, actualización, registros inactivos, columnas desaparecidas, duplicados, rollback y autenticación básica.

## Estructura

```text
public/       Punto de entrada web y assets
src/          Controladores, servicios, repositorios, seguridad y vistas
config/       Configuración por entorno
storage/      Base SQLite, cargas temporales, backups y logs (no versionado)
tests/        Fixtures y pruebas de integración
docs/         Documentación técnica
docker/       Configuración PHP para la imagen
```

## Seguridad

- Contraseñas con `password_hash` y `password_verify`.
- Sesiones diferenciadas para lector y administrador.
- Cookies HttpOnly, SameSite y `Secure` al servir por HTTPS.
- Regeneración de sesión tras login y caducidad por inactividad.
- CSRF en todas las acciones administrativas y cierres de sesión.
- Consultas preparadas, escape HTML y listas blancas para ordenación.
- CSV tratado siempre como datos no confiables y almacenado fuera de `public/`.
- Sin trazas de errores en producción.

Pon la aplicación detrás de HTTPS en producción y limita el acceso de red según tus necesidades.

## Límites conocidos

SQLite es apropiado para una importación administrativa ocasional y decenas o cientos de miles de registros. Tiene un único escritor: mientras una importación grande está en curso, otra importación tendrá que esperar. Los lectores continúan viendo una fotografía coherente gracias al modo WAL.

La búsqueda global usa `LIKE` sobre las columnas visibles. Para varios millones de valores o búsquedas de texto intensivas, la evolución natural será añadir un índice FTS5 de SQLite, manteniendo el resto de la arquitectura.
