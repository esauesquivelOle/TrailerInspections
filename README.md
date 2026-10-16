# Módulo de Transportación (Inspections)

Control del ciclo de vida de cada camión en planta: llegada del conductor, inspección del tráiler,
asignación de gate y órdenes por Almacén, carga por Shipping y cierre por Logística.

- **URL:** `<portal>/proyectos/Inspections/index.php` (IIS)
- **Base de datos:** SQL Server, tablas `transp_*` en el esquema `dbo` de la base que define la conexión del portal
- **Stack:** PHP 8.3 + `sqlsrv`, Bootstrap 4.5, jQuery 3.5, DataTables 1.10, SheetJS 0.18.5 (carga bajo demanda)
- **Idiomas:** inglés por defecto, español con el selector **EN | ES**

> **Este repositorio es solo el módulo.** Se despliega dentro de un portal PHP, en
> `<portal>/proyectos/Inspections/`, y usa dos archivos del portal que **no** están aquí:
> `includes/config.ini.php` (configuración y ruta de sesiones) e `includes/clases/conexion.php`
> (`$serverName` y `$connectionInfo` para `sqlsrv`). También usa el login del portal (`/login.php`) y su sesión
> (`$_SESSION['user_name']`, `user_firstname`, `user_lastname`, `user_login_status`).

---

## 1. Flujo y responsables

```
 Fase 1            Fase 2                 Fase 3                                   Fase 4
 Check-in    →     Inspección       →     Gate y carga                       →     Documentación
 (Seguridad)       (Inspección)           Almacén asigna gate + órdenes            (Logística)
                    │                      Shipping: confirmar → validar → cerrar
                    └─ rechazo → Zona de espera / reparación → re-inspección
```

| Paso | Rol responsable | Acción en el API |
|---|---|---|
| Registrar camión, avanzar a inspección | Seguridad | `register_truck`, `advance_to_phase2` |
| Inspección del tráiler y fotos | Inspección | `submit_inspection`, `upload_inspection_photo` |
| Zona de espera / re-inspección | Seguridad o Inspección | `send_to_waiting_zone`, `recall_from_waiting` |
| Bloquear gates y asignar gate + órdenes | Almacén | `gate_block`, `gate_unblock`, `warehouse_assign`, `warehouse_unassign`, `assign_gate` |
| Confirmar en gate → validar órdenes → cerrar carga | Shipping | `confirm_gate`, `validate_orders`, `close_loading` |
| Schedules, órdenes, importación Excel, cierre y cancelación | Logística | `create_schedule`, `import_schedules`, `close_truck`, `cancel_truck`, … |
| Reportes | Logística, Almacén, Shipping | `report_history`, `report_kpis`, `report_rejections` |
| Asignar roles a usuarios | Administrador | `roles_list`, `roles_search_users`, `roles_set` |

**Supervisor** y **Administrador** pueden cubrir cualquier paso. **Sin rol = solo lectura.**

Reglas que valida el servidor (no solo la pantalla):

- Un gate bloqueado o con otro camión no se puede asignar. El gate se bloquea con `UPDLOCK` dentro de la transacción para que dos personas no tomen el mismo.
- No se puede bloquear un gate con un camión adentro.
- Solo se cargan órdenes **con PIO** que no estén en otro camión.
- Mover un camión de gate pide motivo y queda en la auditoría.
- Shipping sigue el orden **confirmar → validar → cerrar**.
- El gate se libera cuando Shipping cierra la carga. Cancelar un camión libera su gate y sus órdenes.

---

## 2. Estructura

```
proyectos/Inspections/
├── index.php                  Página única (vistas: Dashboard, Truck, Schedules, Warehouse, Reports, Roles)
├── api.php                    API JSON: ?action=… (GET lectura, POST escritura)
├── config.sample.php          Plantilla de configuración del servidor (copiar a config.php)
├── assets/
│   ├── css/transportation.css Tema OCDS (variables --ocds-*), almacén, candados, sincronización
│   └── js/transportation.js   Toda la lógica del cliente
├── src/
│   ├── Lang/en.php, es.php    Textos (mismas llaves en ambos archivos)
│   ├── Repository/            Acceso a datos: Schedule, Truck, Inspection, Gate, Report
│   └── Support/
│       ├── auth.php           Sesión y login del portal
│       ├── config.php         Lee config.php / config.sample.php
│       ├── csrf.php           Validación de peticiones POST
│       ├── db.php             Conexión sqlsrv (UTF-8)
│       ├── i18n.php           transp_t() / transp_e(), cookie de idioma
│       ├── json.php           json_out()
│       └── permissions.php    Roles, capacidades y candados
├── sql/                       Migraciones 001–004 (correr en orden)
└── tools/                     Scripts CLI (no accesibles por web)
    ├── run_migration.php      Ejecuta un .sql separado por GO
    ├── grant_role.php         Asigna un rol (por ejemplo, el primer Administrador)
    └── seed_test_schedules.php Datos de prueba (schedules y órdenes TEST)
```

---

## 3. Instalación / despliegue

### 3.1 Base de datos

Normalmente la identidad del sitio en IIS solo lee y escribe datos (`db_datareader` / `db_datawriter`) y **no puede crear tablas ni columnas**.
Por eso las migraciones se corren una vez, desde la línea de comandos, con una cuenta **db_owner** sobre la base que usa la conexión del portal:

```bash
cd proyectos/Inspections/tools
php run_migration.php ../sql/001_create_transportation_tables.sql
php run_migration.php ../sql/002_add_photos_waiting_zone.sql
php run_migration.php ../sql/003_warehouse_gates.sql
php run_migration.php ../sql/004_user_roles.sql
```

Todas son idempotentes (`IF NOT EXISTS`), así que se pueden volver a correr sin problema.
Ninguna trae el nombre de la base: las tablas se crean en la base de la conexión.

Después, el primer Administrador del módulo:

```bash
php grant_role.php <usuario> ADMIN
```

| Migración | Contenido |
|---|---|
| 001 | Gates (45), órdenes BI, schedules, camiones, inspecciones, asignaciones de gate, auditoría |
| 002 | Fotos de inspección y zona de espera |
| 003 | Bloqueo de gates (`transp_Gate.IsBlocked…`), `transp_GateBlockLog`, órdenes por camión (`transp_ScheduleOrder.TruckID`) |
| 004 | `transp_UserRole` (varios roles por usuario) |

### 3.2 Configuración del servidor (`config.php`)

Copiar `config.sample.php` a `config.php` y ajustarlo. `config.php` **no se sube a Git**.

| Clave | Uso | Valor por defecto |
|---|---|---|
| `photo_dir` | Carpeta de fotos de inspección | `uploads/inspections/` dentro del módulo |

Recomendaciones para las fotos:
- Usar una carpeta **fuera del sitio web**.
- La identidad de IIS necesita permiso de escritura en ella.
- Las fotos se sirven solo a través de `api.php?action=view_inspection_photo`. Si se usa la carpeta por defecto, `uploads/web.config` bloquea el acceso directo.
- Límite de 15 MB; se aceptan jpg, png, gif, webp y heic.

### 3.3 Requisitos del servidor

- PHP 8.3 con la extensión `sqlsrv` (ODBC Driver 17).
- `includes/config.ini.php` e `includes/clases/conexion.php` del portal. El módulo no guarda credenciales de base de datos.
- Acceso desde el navegador a los CDN: code.jquery.com, cdn.jsdelivr.net, cdnjs.cloudflare.com, maxcdn.bootstrapcdn.com, cdn.datatables.net y Google Fonts (Nunito).

---

## 4. Roles y candados

### Asignar roles

1. Entrar como Administrador → botón **🛡 Usuarios y Roles**.
2. Buscar a la persona por nombre o usuario (tabla `Users`, solo activos).
3. Marcar los círculos de los roles que necesita. Cada cambio se guarda al momento y queda en `transp_AuditLog` (`SET_ROLES`).

Un administrador no puede quitarse su propio rol de Administrador.
Los perfiles `Transp*` en `AppWithProfile` (app `Transportation`) también se respetan y se traducen al rol equivalente.

### Cómo se ven

- Lo que no le toca a un usuario **sigue visible con 🔒** y el aviso de quién es responsable ("Bloqueado: solo Shipping puede hacer esto"). Así la gente entiende el flujo completo.
- Si tocan algo bloqueado, el clic se intercepta en fase de captura y nunca llega a la acción.
- Las secciones completas que no aplican (Reportes, Usuarios y Roles) se ocultan con `data-perm-mode="hide"`.

### Agregar un candado nuevo

1. Si es una capacidad nueva, agregarla en `TRANSP_CAPS` de `src/Support/permissions.php`.
2. En el API, poner `transp_require($perms, 'canX');` al inicio de la acción.
3. En el HTML, agregar `data-perm="canX"` al botón o contenedor. `applyPermissions()` lo bloquea solo.

---

## 5. Almacén — Control de Gates

- **Mapa de gates** con colores: verde = disponible, azul = ocupado (camión, transportista, órdenes, tiempo, pasos de Shipping), rojo con rayas = bloqueado (motivo).
- **Filtros** Todos / Disponibles / Ocupados / Bloqueados y buscador por gate, camión o transportista.
- **Fila "Listos para gate"**: camiones en Fase 3 sin gate. Abajo aparecen los que vienen en camino.
- **Asignar un gate**: arrastrar el camión a un gate verde, tocar el camión y luego el gate, o tocar el gate y elegir el camión.
- **Ventana de asignación**: elegir el gate y marcar las órdenes a cargar. Vienen marcadas las del schedule del camión, y en "Otras órdenes del día" se pueden agregar órdenes de otro schedule.
- **Gate ocupado**: editar órdenes, mover de gate (pide motivo), quitar del gate o abrir el camión.
- **Bloqueo por seguridad**: elegir el motivo, escribir detalles y desbloquear con notas de la solución. Cada gate muestra su historial.
- Todos pueden ver el mapa; solo Almacén lo modifica.

---

## 6. Sincronización en vivo

- El navegador consulta `sync_version` cada **4 s**. Es una huella mínima de la base: último `LogID` de auditoría y de bloqueos, más los cambios en schedules y órdenes.
- Solo si la huella cambia se recarga la vista abierta: camión, tablero, almacén o schedules.
- En la vista del camión:
  - Se conserva lo que el usuario está capturando: comentarios, checklist y motivo.
  - Sale un aviso de qué cambió y quién lo hizo, y la fila afectada parpadea.
- Si hay una ventana abierta o la pestaña está oculta, la actualización espera.
- Indicador **● EN VIVO** en la barra: verde si está conectado, rojo si no.
- Si una acción nueva debe disparar la sincronización, basta con que escriba en `transp_AuditLog` (`logAudit()`).

---

## 7. Importar schedules desde Excel

En **Schedules → Importar Excel**: archivo `.xlsx`, `.xls` o `.csv`, **una fila por orden**. Se puede descargar la plantilla desde el mismo botón.

| Columna (EN / ES) | Requerida | Formato |
|---|---|---|
| Date / Fecha | Sí* | `YYYY-MM-DD`, fecha de Excel o `M/D/YYYY` (`D/M/YYYY` en español) |
| Carrier / Transportista | Sí | Texto (máx. 200) |
| Truck Type / Tipo de Camión | | Texto |
| Start Time / Hora Inicio, End Time / Hora Fin | | `HH:MM` 24 h, hora de Excel o `2:30 PM` |
| Order ID / ID de Orden | | Texto |
| PIO Number / Número PIO | | Sin PIO la orden se importa, pero no se puede cargar |
| % Progress / % Avance | | 0–100 (acepta `50%`) |
| Notes / Notas | | Texto (máx. 500) |

\* Si la fecha está vacía, se usa la fecha seleccionada en pantalla.

- Las filas con la misma fecha, transportista y horario forman **un schedule**. Si ya existe uno activo igual, se reutiliza.
- Una orden repetida se omite, así que reimportar el mismo archivo no duplica nada.
- El navegador lee el archivo y muestra una vista previa con los errores.
- El servidor vuelve a validar todo e importa dentro de **una transacción**: se importa todo o nada.

---

## 8. Idiomas

- El idioma se elige con `?lang=en|es` y se guarda en la cookie `transp_lang`, limitada a la carpeta del módulo.
- PHP usa `transp_t('llave', ['var' => …])` y `transp_e()`, que escapa el HTML.
- JS usa `t('llave', {var})`. El diccionario se envía en `window.TRANSP_I18N`.
- Los mensajes de error del API también salen en el idioma del usuario.
- **Para agregar un texto, ponerlo en `en.php` y en `es.php` con la misma llave.** Para verificar que ambos tienen las mismas llaves:

```bash
php -r '$e=require "src/Lang/en.php";$s=require "src/Lang/es.php";var_dump(array_keys(array_diff_key($e,$s)+array_diff_key($s,$e)));'
```

---

## 9. Datos de prueba

```bash
cd proyectos/Inspections/tools
php seed_test_schedules.php              # hoy y mañana
php seed_test_schedules.php --days=5     # hoy + 4 días
php seed_test_schedules.php --date=2026-10-20
php seed_test_schedules.php --clean      # borra los schedules TEST (cancela los que ya usa un camión)
```

Todo lo de prueba queda marcado: transportistas con prefijo `TEST - ` y órdenes `TEST-AAAAMMDD-NNN`.
El script usa la misma lógica que la importación de Excel (`ScheduleRepository::importRows`).

---

## 10. Notas para mantenimiento

- **UTF-8 obligatorio**: `db.php` conecta con `CharacterSet => UTF-8`. Sin eso, los acentos y símbolos (`°`, `ñ`) rompen `json_encode` y el API responde vacío. Los scripts CLI deben hacer lo mismo.
- **Zona horaria**: `America/New_York` en PHP. El JS usa fechas locales; no se usa `toISOString()` para la fecha del día.
- **Transacciones**: la asignación de gate y órdenes, la importación y los roles van en `sqlsrv_begin_transaction`.
- **Auditoría**: toda acción sobre camiones, gates y roles escribe en `transp_AuditLog`, y eso también dispara la sincronización en vivo.
- **Caché del navegador**: CSS y JS se cargan con `?v=filemtime`, así que al desplegar no hace falta limpiar caché.
- **Nombre de la base**: el código no lo menciona. Los repositorios usan `[dbo].[transp_*]` sobre la base de la conexión del portal.

---

## 11. Historial de cambios

### 2026-10-15/16
- Interfaz bilingüe EN/ES, incluidos los mensajes del API.
- Tema visual OCDS: paleta, fuente Nunito y barra superior azul marino.
- Diálogos de confirmación y notificaciones propios, en lugar de `confirm()` / `prompt()` del navegador.
- Importación de schedules desde Excel/CSV con vista previa y plantilla.
- Script de datos de prueba.
- Sección **Almacén**: mapa de gates, bloqueo por seguridad, asignación de gate + órdenes con arrastrar y soltar.
- **Roles y candados** por paso, con la pantalla **Usuarios y Roles**.
- **Sincronización en vivo** y orden obligatorio de los pasos de Shipping.
- Correcciones:
  - Fechas UTC: después de las 8 pm se mostraba el día siguiente.
  - La exportación a CSV solo traía la página visible.
  - Había auto-refresh duplicado.
  - Se podía asignar un gate ocupado.
  - Se perdían el % de avance y las notas de las órdenes.
  - Faltaba UTF-8 en la conexión.
  - Se podía confirmar el gate de un camión sin gate.
- Portabilidad para migrar a otro portal:
  - Sin nombre de servidor ni de base de datos en el código, las migraciones ni la documentación.
  - Carpeta de fotos en `config.php`.
  - Primer Administrador con `tools/grant_role.php`.
