# OPERATIONS — Imperio Comercial (Sistema de Créditos)

Guía de referencia para administradores de sistema y sysadmins nuevos.

---

## 1. Requisitos del entorno

| Componente | Versión mínima |
|---|---|
| PHP | 8.0 |
| MySQL / MariaDB | 8.0 |
| Servidor web | Apache / Nginx con mod_rewrite |
| Composer | 2.x (solo para tests) |

Extensiones PHP requeridas: `pdo_mysql`, `mbstring`, `iconv`, `curl`, `json`, `session`.

---

## 2. Primer despliegue

1. Clonar o copiar el repositorio al directorio del servidor web.

2. Crear la base de datos y cargar el esquema inicial:
   ```bash
   mysql -u root -p -e "CREATE DATABASE creditos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p creditos < sql/schema.sql
   ```

3. Copiar la plantilla de credenciales:
   ```bash
   cp config/conexion.example.php config/conexion.local.php
   ```
   Editar `config/conexion.local.php` con los valores reales (host, nombre BD, usuario, contraseña).

4. Verificar que `config/conexion.local.php` esté en `.gitignore` (ya está incluido).

5. **Alternativa con variables de entorno** (recomendado en producción):
   Definir en el entorno del proceso web:
   ```
   DB_HOST=localhost
   DB_NAME=creditos
   DB_USER=usuario
   DB_PASS=contraseña_segura
   DB_CHARSET=utf8mb4
   ```
   El archivo `config/conexion.php` prioriza las env vars sobre `conexion.local.php`.

6. Asegurarse que el directorio `logs/` exista y sea escribible por el servidor web:
   ```bash
   mkdir -p logs && chmod 775 logs
   ```

7. Verificar acceso visitando `http://servidor/creditos/` — debe redirigir al login.

---

## 3. Migraciones de base de datos

Las migraciones están en `sql/archive/migration_*.sql` y se aplican manualmente en orden cronológico.

**Aplicar una migración:**
```bash
mysql -u root -p creditos < sql/archive/migration_NOMBRE.sql
```

**Migraciones disponibles:**

| Archivo | Descripción |
|---|---|
| `migration_reversa_rendiciones.sql` | Agrega columnas `revertido`, `fecha_reversa`, `reverso_por`, `motivo_reversa` a `ic_pagos_confirmados`. Extiende ENUM de `ic_pagos_temporales.estado` con `REVERTIDO`. |

**Antes de aplicar en producción:**
- Hacer backup completo (ver sección 6).
- Probar en una copia de la BD de producción.
- Verificar que no haya transacciones activas (`SHOW PROCESSLIST;`).

---

## 4. Rotación de credenciales

Cuando se cambia la contraseña de la base de datos:

1. Cambiar la contraseña en MySQL:
   ```sql
   ALTER USER 'usuario'@'localhost' IDENTIFIED BY 'nueva_contraseña';
   FLUSH PRIVILEGES;
   ```

2. Actualizar `config/conexion.local.php` (o la variable de entorno `DB_PASS`).

3. Reiniciar el servidor web si usa variables de entorno en un archivo de configuración de proceso (`.env`, `systemd`, `php-fpm pool`).

4. Verificar que la aplicación conecte correctamente visitando el login.

5. Si las credenciales estuvieron en el historial de git:
   ```bash
   # Opción segura: rotar (cambiar el password) y aceptar que el historial viejo queda inutilizable.
   # Opción destructiva (requiere coordinación con todo el equipo):
   git filter-repo --path config/conexion.php --invert-paths
   # Forzar push y que todos los colaboradores clonen de nuevo.
   ```

---

## 5. Reversa de rendiciones

La reversa deshace todos los movimientos de una rendición aprobada:
- Marca `ic_pagos_confirmados.revertido = 1` con trazabilidad completa.
- Devuelve los pagos temporales a estado `PENDIENTE` para re-aprobación.
- Recalcula saldos de cuotas y estado del crédito.

**Acceso:** Admin → Historial de Rendiciones → botón "Revertir".

**Cuándo usarla:**
- Pago aprobado por error (monto incorrecto, cobrador equivocado).
- Necesidad de corregir el pago antes de procesar la rendición definitiva.

**Qué bloquea la reversa:**
- La cuota tiene otro pago confirmado posterior (hay que revertir ese primero).
- El crédito está en estado `CANCELADO`.
- La cuota pertenece a un crédito refinanciado después de la fecha del cobro.

**Auditoría:**
```sql
SELECT * FROM ic_log_actividades
WHERE accion = 'RENDICION_REVERTIDA'
ORDER BY created_at DESC;
```
El campo `detalle` contiene: `cobrador=X fecha=YYYY-MM-DD origen=cobrador|manual cuotas=N motivo=...`

---

## 6. Backup

### Script automatizado

El script `scripts/backup.ps1` realiza un `mysqldump` completo y almacena el archivo en `backups/`.
Lee credenciales desde `config/conexion.local.php` — **no tiene contraseñas hardcodeadas**.

**Ejecutar manualmente:**
```powershell
powershell -ExecutionPolicy Bypass -File scripts\backup.ps1
```

**Programar en Windows Task Scheduler:**

1. Abrir "Programador de tareas" → "Crear tarea básica".
2. Nombre: `Backup Creditos`.
3. Desencadenador: Diario, hora: 03:00.
4. Acción: Iniciar un programa → `powershell.exe`.
5. Argumentos: `-ExecutionPolicy Bypass -NonInteractive -File "C:\wamp64\www\creditos\scripts\backup.ps1"`.
6. Directorio de inicio: `C:\wamp64\www\creditos`.
7. Activar "Ejecutar independientemente de si el usuario ha iniciado sesión".

### Rotación automática

El script `backup.bat` borra automáticamente archivos `.sql` en `backups/` con más de 30 días de antigüedad.

### Restore desde dump

```bash
mysql -u root -p creditos < backups/creditos_YYYYMMDD_HHMM.sql
```

---

## 7. WhatsApp (Meta Cloud API)

El envío de mensajes de WhatsApp se activa mediante el archivo de configuración `config/whatsapp.php` (no incluido en el repo — debe crearse manualmente).

**Variables de configuración:**

| Constante | Descripción |
|---|---|
| `WA_ENABLED` | `true` para activar envíos, `false` para desactivar globalmente |
| `WA_PHONE_ID` | Phone Number ID de la app de Meta for Developers |
| `WA_TOKEN` | Token de acceso permanente (Bearer token de la API) |
| `WA_API_VERSION` | Versión de la API (ej: `v22.0`) |
| `WA_TPL_PAGO` | Nombre del template aprobado para notificar pagos |
| `WA_TPL_LANG` | Código de idioma del template (ej: `es`) |

**Plantilla `config/whatsapp.php` (crear localmente):**
```php
<?php
define('WA_ENABLED',      true);
define('WA_PHONE_ID',     'PHONE_NUMBER_ID_DE_META');
define('WA_TOKEN',        'TOKEN_PERMANENTE_DE_META');
define('WA_API_VERSION',  'v22.0');
define('WA_TPL_PAGO',     'nombre_del_template_de_pago');
define('WA_TPL_LANG',     'es');
```

**Template requerido en Meta Business Manager** (para el cron de mora):
- Nombre: `recordatorio_mora` (o el que esté en `cron/recordatorio_mora.php`)
- Categoría: Utility
- Parámetros: `{{1}}` = nombre cliente, `{{2}}` = número cuota, `{{3}}` = artículo, `{{4}}` = monto con mora

**Cron de recordatorio de mora** (ejecutar diariamente a las 9:00):
```
# En Linux:
0 9 * * * /usr/bin/php /ruta/proyecto/cron/recordatorio_mora.php >> /ruta/proyecto/logs/cron.log 2>&1

# En Windows Task Scheduler: apuntar a php.exe con argumento cron/recordatorio_mora.php
```

---

## 8. Seguridad

- Las credenciales de BD **nunca** deben estar en el código fuente. Usar `config/conexion.local.php` (gitignored) o variables de entorno.
- El token de WhatsApp es equivalente a una contraseña. Guardarlo en `config/whatsapp.php` (gitignored, no en repo).
- Contraseña por defecto del admin (`admin / password`) — **cambiarla antes del primer uso en producción** desde Admin → Usuarios.
- Todas las acciones financieras quedan registradas en `ic_log_actividades`. Revisar periódicamente con:
  ```sql
  SELECT * FROM ic_log_actividades ORDER BY created_at DESC LIMIT 50;
  ```

---

## 9. Tests

```bash
# Instalar dependencias de test (solo primera vez)
composer install

# Ejecutar suite completa
php vendor/bin/phpunit --configuration phpunit.xml
```

Los tests están en `tests/` y cubren: cálculos financieros, mora, reversa de rendiciones, y firma de funciones críticas.
