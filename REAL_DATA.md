# Datos reales Sucasa

Este panel puede trabajar con dos fuentes:

- `local`: usa las tablas normalizadas de Laravel (`properties`, `cities`, etc.).
- `wordpress`: lee los datos reales de WordPress / JetEngine / CCT.

Para usar datos reales, en `backend/.env`:

```dotenv
PROPERTIES_SOURCE=wordpress
AUTH_SOURCE=wordpress_funcionarios
SEED_DEMO_DATA=false

WORDPRESS_DB_HOST=...
WORDPRESS_DB_PORT=3306
WORDPRESS_DB_DATABASE=u350704768_M4FvI
WORDPRESS_DB_USERNAME=...
WORDPRESS_DB_PASSWORD=...
```

Después:

```bash
cd backend
php artisan optimize:clear
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

El `DB_CONNECTION` principal puede seguir en `sqlite`: esa base local guarda los tokens
Sanctum, sesiones e integraciones del panel nuevo. Las propiedades, branding y login de
funcionarios se leen por la conexión `wordpress`.

Si quieres que también la base interna del panel sea MySQL, usa esto en el mismo
`backend/.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

Si esa base MySQL es la misma donde está WordPress, puedes repetir los mismos valores en
`WORDPRESS_DB_*`, pero lo más limpio es usar una base separada para las tablas internas
del panel y dejar `WORDPRESS_DB_*` solo para leer `wp_jet_cct_*`.

## Tablas reales usadas

No hace falta crear estas tablas si ya existen en WordPress:

| Tabla | Uso en el panel nuevo |
|---|---|
| `wp_jet_cct_inmuebles` | Propiedades reales. En el MCP hay 2722 registros. |
| `wp_posts` | Resolver URLs de imágenes por IDs en `foto_portada` y `galeria`. |
| `wp_jet_cct_confi_sistema` | Branding: `portal_logo_url`, `portal_favicon_url`. |
| `wp_jet_cct_funcionarios` | Consultores / asesores y login real de funcionarios. |
| `wp_jet_cct_barrios` | Barrios y localidades. |
| `wp_jet_cct_ciudades` | Ciudades. |
| `wp_jet_cct_caract_internas` | Características internas. |
| `wp_jet_cct_caract_externas` | Características externas. |
| `wp_jet_cct_alrededores` | Alrededores. |

## Tablas locales del panel nuevo

Estas sí las crea Laravel con migraciones:

| Tabla | Uso |
|---|---|
| `users` | Identidad local sincronizada para Sanctum. Si `AUTH_SOURCE=wordpress_funcionarios`, no valida password aquí. |
| `personal_access_tokens` | Tokens Sanctum. |
| `integrations` | Lista de portales disponibles. |
| `portal_credentials` | Tokens/API keys cuando se conecten portales reales. |
| `sessions`, `cache`, `jobs` | Infraestructura Laravel. |

Con `SEED_DEMO_DATA=false`, Laravel no carga propiedades ficticias.

## Login de funcionarios

Con `AUTH_SOURCE=wordpress_funcionarios`, el panel valida contra:

| `wp_jet_cct_funcionarios` | Uso |
|---|---|
| `user_others_apss` | Usuario que se escribe en el login. |
| `pass_others_apss` | Contraseña legacy de Other Apps. |
| `id_empleado` | Identidad real que se guarda en `users.legacy_employee_id`. |
| `nombre`, `correo`, `celular`, `rol`, `gestion` | Perfil sincronizado al usuario local. |
| `activo` | Solo se permite login cuando está activo. |

El panel crea o actualiza un registro local en `users` para poder emitir tokens Sanctum,
pero la contraseña real nunca se copia a la tabla local.

## Mapeo principal de inmuebles

| `wp_jet_cct_inmuebles` | Panel nuevo |
|---|---|
| `codigo` | `code` |
| `estado` | `status` (`Publico` → `active`, `Arrendado` → `rented`, `Vendido` → `sold`) |
| `tipo_inmueble` | `property_type` |
| `tipo_negocio` | `transaction_type` |
| `precio_venta` | `sale_price` |
| `precio_arriendo` | `rent_price` |
| `precio_admin` | `admin_price` |
| `area_construida` | `area_built` |
| `area_privada` | `area_private` |
| `area_terreno` | `area_land` |
| `habitaciones` | `bedrooms` |
| `banos` | `bathrooms` |
| `parqueaderos` | `parking_spaces` |
| `barrio`, `ciudad`, `direccion` | ubicación |
| `foto_portada`, `galeria` | imágenes, resueltas contra `wp_posts.guid` |
| `funcionario`, `id_funcionario` | asesor |

## Branding

El panel lee:

```sql
SELECT funcion, valor, imagen
FROM wp_jet_cct_confi_sistema
WHERE funcion IN ('portal_logo_url', 'portal_favicon_url');
```

Valores vistos por MCP:

- `portal_logo_url`: logo blanco horizontal.
- `portal_favicon_url`: isologo web.

Por eso el login y sidebar usan una placa azul institucional para que el logo blanco
tenga contraste.
