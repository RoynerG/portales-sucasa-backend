# Backend Laravel 11 — Panel Sucasa

API REST que reemplaza la carpeta `vieja/` (PHP legacy). Sirve al frontend moderno
de `../panel/` (Vite + Alpine + Tailwind).

## Lo que resuelve

- **SQL Injection** → Eloquent con `prepare()` automático.
- **Passwords en plano** → bcrypt (`Hash::make`) + Sanctum tokens.
- **CORS abierto `*`** → CORS explícito por origen (`config/cors.php`).
- **2 APIs duplicadas** → 1 sola API REST versionada.
- **30+ variables globales JS** → JSON limpio con shape consistente.
- **Credenciales en código** → todo en `.env`.

## Requisitos

- **XAMPP** (Apache + MySQL/MariaDB) — asumimos XAMPP en `C:\xampp`.
- **PHP 8.2+** (incluido en XAMPP 8.2+).
- **Composer** — https://getcomposer.org/Composer-Setup.exe
- **Node.js 20+** — solo para el panel, no para el backend.

## Instalación paso a paso

### 1. Instalar Composer

Baja el instalador desde https://getcomposer.org/Composer-Setup.exe y ejecútalo.
Te detecta automáticamente el PHP de XAMPP (`C:\xampp\php\php.exe`).

Verifica en PowerShell o CMD:

```bash
composer --version
```

### 2. Crear la base de datos en XAMPP

Arranca XAMPP, abre phpMyAdmin (`http://localhost/phpmyadmin`) y crea:

- **Nombre**: `sucasa_panel`
- **Cotejamiento**: `utf8mb4_unicode_ci`

No necesitas crear tablas: las migraciones lo hacen.

### 3. Copiar el backend a `htdocs` (opcional pero recomendado)

Puedes trabajar desde donde quieras. Si prefieres servirlo con Apache:

```bash
# mover la carpeta backend a C:\xampp\htdocs\sucasa-backend
move backend C:\xampp\htdocs\sucasa-backend
```

### 4. Instalar dependencias

```bash
cd backend
composer install
```

Si pide autenticación con GitHub para `laravel/sanctum`, no es requerida.

### 5. Configurar `.env`

```bash
cp .env.example .env
```

Edita `.env` y ajusta al menos estas líneas para XAMPP por defecto:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sucasa_panel
DB_USERNAME=root
DB_PASSWORD=

# Frontend (el panel Vite en localhost:5173)
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173

# Credenciales reales de portales (opcional para probar local sin publicar)
MERCADOLIBRE_CLIENT_ID=
MERCADOLIBRE_CLIENT_SECRET=
FINCARAIZ_API_KEY=
FINCARAIZ_ENV=qa
FINCARAIZ_CLIENT_ID=
FINCARAIZ_CLIENT_AGENT=
FINCARAIZ_CONTACT_EMAIL=
FINCARAIZ_CONTACT_PHONE=
FINCARAIZ_CONTACT_WHATSAPP=
CIENCUADRAS_EMAIL=
CIENCUADRAS_PASSWORD=
CIENCUADRAS_SHOW_ADDRESS=false
CIENCUADRAS_APPROXIMATE_LOCATION_PRECISION=2
CIENCUADRAS_DEFAULT_LATITUDE=
CIENCUADRAS_DEFAULT_LONGITUDE=
PROPPIT_CLIENT_ID=
PROPPIT_CLIENT_SECRET=
PROPPIT_PUBLISHER_EXTERNAL_ID=
```

### 6. Generar APP_KEY

```bash
php artisan key:generate
```

### 7. Migrar y sembrar datos

```bash
php artisan migrate --seed
```

Esto crea todas las tablas y carga:
- 2 usuarios (admin@sucasa.com / password)
- 5 integraciones
- 10 barrios
- 21 características
- 5 consultores
- 20 propiedades de prueba con imágenes

### 8. Arrancar el servidor

**Opción A — Servidor PHP (recomendado para desarrollo):**

```bash
php artisan serve --port=8000
```

Disponible en `http://localhost:8000`.

**Opción B — Con XAMPP/Apache:**

Si copiaste el backend a `C:\xampp\htdocs\sucasa-backend\`, abre:

```
http://localhost/sucasa-backend/public
```

Y configura un VirtualHost en `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName api.sucasa.local
    DocumentRoot "C:/xampp/htdocs/sucasa-backend/public"
    <Directory "C:/xampp/htdocs/sucasa-backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Y agrega a `C:\Windows\System32\drivers\etc\hosts` (como admin):

```
127.0.0.1 api.sucasa.local
```

### 9. Verificar

```bash
curl http://localhost:8000/api/health
# => {"Datos":{"status":"ok","time":"..."}}
```

Login de prueba:

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@sucasa.com\",\"password\":\"password\"}"
```

Recibirás un token. Úsalo en siguientes requests:

```bash
curl http://localhost:8000/api/properties \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

## Arrancar el panel (frontend)

En otra terminal:

```bash
cd ../panel
npm install
npm run dev
```

Abre `http://localhost:5173`. Login con `admin@sucasa.com` / `password`.

El panel hace `fetch` a `http://localhost:8000/api/*` (configurado en `panel/.env.example`).
Si Laravel corre en otro puerto/host, copia `panel/.env.example` a `panel/.env` y ajusta.

## Integraciones por API

Proppit ya no usa feed XML: publica, actualiza y despublica con la API real-time v2.

Proppit denomina las credenciales `Client ID` y `Client Secret`, pero su endpoint
`POST /token` exige enviarlas como `user` y `password`. El backend hace esa
adaptación. `PROPPIT_PUBLISHER_EXTERNAL_ID` es un identificador independiente:
el backend lo consulta y, si Proppit responde `Publisher not found`, lo crea con
los datos `PROPPIT_DEFAULT_CONTACT_*`. Luego Proppit debe aprobarlo para que
`publishingEnabled` quede en `true` y los anuncios sean visibles en sus portales.

El `referenceId` enviado a Proppit siempre es el código original del inmueble,
sin agregar prefijos. Por ejemplo, el inmueble `53824` se envía como `53824`.

## Programar tareas automáticas (opcional)

Para que las tareas programadas del backend se ejecuten cada minuto:

```bash
# Linux/macOS
crontab -e
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Para Windows con XAMPP, usa el Programador de Tareas apuntando a:

```cmd
C:\xampp\php\php.exe C:\ruta\a\backend\artisan schedule:run
```

cada minuto.

## Estructura

```
backend/
├── app/
│   ├── Http/Controllers/
│   │   ├── Auth/AuthController.php   # login/logout/me (Sanctum)
│   │   ├── Api/                      # CRUD REST
│   │   │   ├── PropertyController.php
│   │   │   ├── IntegrationController.php
│   │   │   ├── ConsultantController.php
│   │   │   ├── CharacteristicController.php
│   │   │   ├── NeighborhoodController.php
│   │   │   └── UserController.php
│   │   └── Portal/                   # Integraciones con portales
│   │       ├── MercadoLibreController.php
│   │       ├── FincaraizController.php
│   │       ├── CiencuadrasController.php
│   │       └── ProppitController.php
│   ├── Models/                       # Eloquent (10 modelos)
│   ├── Providers/AppServiceProvider.php  # singletons de los clients
│   └── Services/Portals/             # Lógica de integración con portales
├── bootstrap/                        # app.php + providers.php (Laravel 11)
├── config/                           # app, database, auth, sanctum, portals, ...
├── database/
│   ├── migrations/                   # 12 migraciones (Eloquent)
│   └── seeders/                      # 6 seeders con datos de prueba
├── public/                           # index.php + .htaccess (front controller)
├── routes/
│   ├── api.php                       # Todas las rutas REST
│   ├── web.php                       # Raíz
│   └── console.php
├── storage/                          # logs, cache, sessions, feeds
├── .env.example                      # Plantilla de configuración
├── artisan                           # CLI
└── composer.json
```

## Endpoints principales

| Verbo  | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| POST   | `/api/auth/login` | no | Login (devuelve Sanctum token) |
| POST   | `/api/auth/logout` | sí | Revoca el token actual |
| GET    | `/api/auth/me` | sí | Datos del usuario actual |
| GET    | `/api/properties` | sí | Listar (pagina, limite, orden, codigo) |
| POST   | `/api/properties` | sí | Crear |
| GET    | `/api/properties/{code}` | sí | Detalle |
| PATCH  | `/api/properties/{code}` | sí | Actualizar |
| DELETE | `/api/properties/{code}` | sí | Soft delete |
| GET    | `/api/properties/stats` | sí | Stats por estado |
| GET    | `/api/properties/distribution` | sí | Propiedades por consultor |
| POST   | `/api/properties/{code}/sync/{portal}` | sí | Actualizar sync_status |
| GET    | `/api/integrations` | sí | Listar portales activos |
| GET    | `/api/consultants` | sí | Listar consultores |
| GET    | `/api/characteristics?type=external` | sí | Características por tipo |
| GET    | `/api/neighborhoods` | sí | Barrios/localidades |
| POST   | `/api/portals/properties/{code}/mercadolibre/publish` | sí | Publicar en ML |
| POST   | `/api/portals/properties/{code}/mercadolibre/update` | sí | Actualizar en ML |
| POST   | `/api/portals/properties/{code}/mercadolibre/pause` | sí | Pausar en ML |
| POST   | `/api/portals/properties/{code}/mercadolibre/verify` | sí | Verificar en ML |
| GET    | `/api/portals/mercadolibre/authorize` | sí | URL de OAuth |
| GET    | `/api/portals/mercadolibre/callback` | no | Callback OAuth (intercambia code→token) |
| POST   | `/api/portals/mercadolibre/webhook` | no | Webhook de notificaciones |
| POST   | `/api/portals/properties/{code}/fincaraiz/publish` | sí | Publicar en FR |
| GET    | `/api/portals/fincaraiz/status` | sí | Estado y ambiente de FR |
| GET    | `/api/portals/fincaraiz/client` | sí | Probar credenciales y consultar cliente/agentes |
| GET    | `/api/portals/fincaraiz/neighborhoods` | sí | Listar homologaciones de barrios FR |
| PATCH  | `/api/portals/fincaraiz/neighborhoods/{id}` | sí | Guardar UUID oficial del barrio FR |
| GET    | `/api/portals/properties/{code}/fincaraiz/payload` | sí | Preflight del payload FR |
| POST   | `/api/portals/properties/{code}/fincaraiz/verify` | sí | Consultar la tarea asíncrona FR |
| POST   | `/api/portals/properties/{code}/fincaraiz/activate` | sí | Activar el aviso FR creado |
| POST   | `/api/portals/fincaraiz/webhook` | no | Recibir resultados asíncronos FR |
| POST   | `/api/portals/properties/{code}/ciencuadras/publish` | sí | Publicar en CC |
| POST   | `/api/portals/properties/{code}/proppit/publish` | sí | Publicar en Proppit |
| POST   | `/api/portals/properties/{code}/proppit/update` | sí | Actualizar en Proppit |
| POST   | `/api/portals/properties/{code}/proppit/pause` | sí | Despublicar en Proppit |
| POST   | `/api/portals/properties/{code}/proppit/verify` | sí | Verificar en Proppit |

La configuración, prueba en QA y promoción segura de Fincaraíz están documentadas en [`docs/FINCARAIZ.md`](docs/FINCARAIZ.md).

## Troubleshooting

| Problema | Solución |
|---|---|
| `SQLSTATE[HY000] [2002] No connection` | XAMPP/MySQL no está corriendo. Arráncalo desde XAMPP Control Panel. |
| `Access denied for user 'root'` | Revisa `DB_USERNAME` y `DB_PASSWORD` en `.env`. XAMPP por defecto no tiene password para `root`. |
| `Class 'Redis' not found` | No es problema. No usamos Redis. Está en `config/queue.php` como no-op. |
| CORS error en el panel | Verifica que `FRONTEND_URL` y `SANCTUM_STATEFUL_DOMAINS` en `.env` coincidan con `http://localhost:5173`. |
| 419 CSRF en POST | Las rutas `/api/*` están exentas. Si ves esto en `/sanctum/csrf-cookie`, limpia cookies. |
| `vite proxy error` | El backend Laravel debe estar corriendo. Lánzalo con `php artisan serve`. |

## Próximos pasos

- [ ] Configurar SMTP real (en `.env`: `MAIL_MAILER=smtp` + datos de Gmail/SendGrid).
- [ ] Implementar tests con PHPUnit/Pest.
- [ ] Configurar GitHub Actions para CI.
- [ ] Desplegar en hosting (compartir `FRONTEND_URL` con el panel desplegado).
- [ ] Importar datos reales desde la BD legacy de `vieja/` (script en `app/Console/Commands/ImportLegacyData.php`, pendiente).
