# Base de datos — Guía de poblado

Esta guía explica **cada tabla**, **qué campos son obligatorios**, **con qué valores
se llenan** y **en qué orden poblar** la base de datos del nuevo sistema.

> **TL;DR**: corre `php artisan migrate --seed` y tendrás 5 ciudades, 12 tipos de
> propiedad, 4 transacciones, 41 características, 5 integraciones, 5 asesores y
> 10 propiedades de muestra. Después personaliza con tus datos reales.

---

## Diagrama de relaciones

```
                                       ┌──────────────┐
                                       │   cities     │  Catálogo
                                       │ (Ciudades)   │◄──────────────┐
                                       └──────┬───────┘               │
                                              │                       │
                                              │ 1:N                   │
                                              ▼                       │
                                       ┌──────────────┐               │
                                       │neighborhoods │  Catálogo     │
                                       │  (Barrios)   │               │
                                       └──────┬───────┘               │
                                              │                       │
                                              │ N:1                   │
                                              ▼                       │
   ┌──────────┐    ┌──────────────┐    ┌──────────────┐               │
   │  users   │───►│  properties  │◄───┤consultants   │               │
   │(Usuarios)│ N:M│(Propiedades) │ N:1│ (Asesores)   │               │
   └────┬─────┘    └──────┬───────┘    └──────────────┘               │
        │                 │                                           │
        │ 1:N             │ N:M (features)                           │
        │                 ▼                                           │
        │          ┌──────────────┐                                   │
        │          │   features   │  Catálogo                         │
        │          │(Característ.)│                                   │
        │          └──────────────┘                                   │
        │                                                             │
        │ 1:N             │ 1:N                                       │
        ▼                 ▼                                           │
   ┌──────────────┐  ┌──────────────────┐                             │
   │portal_       │  │property_sync_    │                             │
   │credentials   │  │statuses          │ N:1 ──► integrations ────────┘
   │(Tokens)      │  │(Sincronización)  │
   └──────────────┘  └──────────────────┘
                          │
                          │ 1:N
                          ▼
                   ┌──────────────────┐
                   │portal_mappings   │ (Homologaciones)
                   │(IDs en portales) │
                   └──────────────────┘

Auditoría:                      Media:
┌──────────────┐                ┌──────────────────┐
│audit_logs    │                │property_images   │
│property_     │                │property_videos   │
│status_       │                │property_floor_   │
│history       │                │plans             │
└──────────────┘                └──────────────────┘
```

---

## Orden de poblado

1. **`cities`** — primero las ciudades donde operas.
2. **`neighborhoods`** — barrios de cada ciudad.
3. **`property_types`** — los 12 tipos base ya vienen, podés agregar más.
4. **`transaction_types`** — los 4 tipos base ya vienen.
5. **`features`** — las 41 características base ya vienen, podés agregar/quitar.
6. **`users`** — usuarios (admin, gerentes, agentes).
7. **`consultants`** — asesores. Cada uno puede (no obligatorio) tener un `user_id`.
8. **`integrations`** — los 5 portales ya vienen. Después configurás las credenciales por usuario.
9. **`portal_credentials`** — credenciales OAuth de cada usuario en cada portal.
10. **`portal_mappings`** — IDs externos de tipos, barrios y features en cada portal.
11. **`properties`** — las propiedades en sí.
12. **`property_images` / `property_videos` / `property_floor_plans`** — multimedia.
13. **`property_feature`** (pivot) — qué características tiene cada propiedad.
14. **`property_sync_statuses`** — estado de publicación por portal.
15. **`property_status_history`** — se llena automáticamente al cambiar el `status`.

---

## Tabla por tabla

### 1. `cities` — Ciudades

Catálogo de ciudades donde tienes propiedades. **No elimines ciudades con propiedades asociadas** (FK restrict).

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `dane_code` | char(8) | ✅ | `70001` | Código DANE del DANE Colombia. Único. |
| `name` | string(100) | ✅ | `Sincelejo` | |
| `department` | string(100) | ✅ | `Sucre` | |
| `country_code` | char(2) | ✅ | `CO` | ISO 3166-1 alfa-2. |
| `lat` | decimal(10,7) | ❌ | `9.3047000` | Para mapas. |
| `lng` | decimal(10,7) | ❌ | `-75.3978000` | |
| `active` | boolean | ❌ | `true` | Default `true`. |

**Cómo obtener el DANE**: https://www.dane.gov.co/index.php/estadisticas-por-tema/regalias/dane-geocodificador

**Cómo poblarlo**:
```sql
-- En phpMyAdmin o con tinker
INSERT INTO cities (dane_code, name, department, country_code, lat, lng)
VALUES ('70001', 'Sincelejo', 'Sucre', 'CO', 9.3047, -75.3978);
```

---

### 2. `neighborhoods` — Barrios

Pertenece a una ciudad. **Único por ciudad**: no se puede repetir el nombre en la misma ciudad.

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `city_id` | FK | ✅ | `1` | Apunta a `cities.id`. |
| `name` | string(150) | ✅ | `Centro` | Único por ciudad. |
| `zone` | string(50) | ❌ | `Norte` | Para agrupar en mapas. |
| `postal_code` | string(10) | ❌ | `700001` | Código postal. |
| `lat` | decimal | ❌ | `9.3050` | Opcional. |
| `lng` | decimal | ❌ | `-75.3970` | Opcional. |
| `active` | boolean | ❌ | `true` | |

**Cómo poblarlo** (script):
```php
// php artisan tinker
use App\Models\Neighborhood;
use App\Models\City;

$sincelejo = City::where('dane_code', '70001')->first();

$barrios = [
    ['Centro', 'Centro', '700001'],
    ['La Ford', 'Centro', '700001'],
    ['Pioneros', 'Norte', '700002'],
    // ...
];

foreach ($barrios as [$nombre, $zona, $cp]) {
    Neighborhood::create([
        'city_id' => $sincelejo->id,
        'name' => $nombre,
        'zone' => $zona,
        'postal_code' => $cp,
    ]);
}
```

---

### 3. `property_types` — Tipos de Propiedad

Catálogo. Los 12 base ya vienen. **No elimines tipos con propiedades asociadas**.

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `slug` | string(50) | ✅ | `apartamento` | Único, sin espacios, lowercase. |
| `name` | string(100) | ✅ | `Apartamento` | Para mostrar. |
| `icon` | string(50) | ❌ | `fa-building` | Clase FontAwesome. |
| `color` | char(7) | ❌ | `#3b82f6` | Hex color. |
| `is_building` | bool | ❌ | `true` | Si tiene unidades (aptos, oficinas). |
| `is_land` | bool | ❌ | `false` | Si es terreno (lote, finca). |
| `is_commercial` | bool | ❌ | `false` | Si es comercial. |
| `active` | bool | ❌ | `true` | |
| `order` | int | ❌ | `1` | Para ordenar listados. |

**Cómo agregar uno nuevo**:
```php
PropertyType::create([
    'slug' => 'habitacion',
    'name' => 'Habitación',
    'icon' => 'fa-bed',
    'color' => '#ec4899',
    'is_building' => true,
    'is_land' => false,
    'is_commercial' => false,
]);
```

---

### 4. `transaction_types` — Tipos de Transacción

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `slug` | string | ✅ | `sale` | `sale`, `rent`, `sale_rent`, `vacation`. |
| `name` | string | ✅ | `Venta` | |
| `has_sale_price` | bool | ❌ | `true` | Si esta tx usa precio de venta. |
| `has_rent_price` | bool | ❌ | `true` | Si usa canon de arriendo. |
| `has_admin_price` | bool | ❌ | `false` | Si usa cuota de administración. |

**Importante**: los flags `has_*_price` controlan qué campos se piden en el formulario.

---

### 5. `features` — Características

Las **características agrupadas por tipo**:

| Grupo (`group`) | Significado | Ejemplos |
|---|---|---|
| `internal` | Dentro del inmueble | Cocina integral, aire acondicionado |
| `external` | Del conjunto/edificio | Piscina, gimnasio, ascensor |
| `surrounding` | Alrededores | Cerca a colegio, transporte |
| `rule` | Reglas/condiciones | Acepta mascotas, solo familias |

| Campo | Tipo | Obligatorio | Ejemplo |
|---|---|---|---|
| `group` | enum | ✅ | `internal` |
| `slug` | string | ✅ | `piscina` (único por grupo) |
| `name` | string | ✅ | `Piscina` |
| `icon` | string | ❌ | `fa-person-swimming` |
| `description` | text | ❌ | `Piscina para adultos y niños` |
| `active` | bool | ❌ | `true` |
| `order` | int | ❌ | `1` |

**Cómo agregar**:
```php
Feature::create(['group' => 'internal', 'slug' => 'jacuzzi', 'name' => 'Jacuzzi', 'icon' => 'fa-hot-tub-person']);
```

---

### 6. `users` — Usuarios

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `email` | string | ✅ | `admin@sucasa.com` | Único, es el login. |
| `password` | string | ✅ | (hash bcrypt) | **Nunca** plano. Mínimo 8 caracteres. |
| `name` | string | ✅ | `Juan Pérez` | |
| `role` | enum | ✅ | `admin` | `admin`, `manager`, `agent`, `viewer`. |
| `active` | bool | ❌ | `true` | Default `true`. |
| `phone` | string | ❌ | `+57 300 000 0000` | |
| `avatar_path` | string | ❌ | `/avatars/1.jpg` | |
| `bio` | text | ❌ | `Director comercial` | |
| `last_login_at` | timestamp | (auto) | | |
| `last_login_ip` | ip | (auto) | | |
| `preferences` | json | ❌ | `{"lang": "es", "theme": "dark"}` | |

**Roles y permisos** (sugerencia):
- `admin` — todo
- `manager` — ve todas las propiedades, edita asignaciones
- `agent` — solo sus propiedades
- `viewer` — solo lectura

**Cómo crear el primer admin**:
```bash
php artisan tinker
>>> \App\Models\User::create([
...     'email' => 'tu@email.com',
...     'password' => bcrypt('tu-password-seguro'),
...     'name' => 'Tu Nombre',
...     'role' => 'admin',
...     'active' => true,
... ]);
```

---

### 7. `consultants` — Asesores inmobiliarios

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `user_id` | FK | ❌ | `2` | Si tiene login. |
| `name` | string | ✅ | `Carlos Pérez` | |
| `email` | string | ❌ | `carlos@sucasa.com` | |
| `phone` | string | ❌ | `+57 300 222 2222` | |
| `whatsapp` | string | ❌ | `+573002222222` | **Con prefijo país, sin espacios**. |
| `avatar_path` | string | ❌ | `/avatars/carlos.jpg` | |
| `position` | string | ❌ | `Asesor Senior` | Cargo. |
| `department` | string | ❌ | `Ventas` | |
| `license_number` | string | ❌ | `SC-001` | Matrícula inmobiliaria. |
| `properties_limit` | int | ❌ | `30` | Máximo de propiedades asignables. |
| `featured_limit` | int | ❌ | `5` | Máximo de destacadas. |
| `active` | bool | ❌ | `true` | |

**Nota**: `properties_limit` y `featured_limit` son **soft limits**. El sistema los respeta al asignar, pero no son FK ni triggers.

---

### 8. `integrations` — Portales integrados

Los 5 portales base ya están seedeados. **No elimines** sin antes quitar las FK de `property_sync_statuses`.

| Campo | Ejemplo | Notas |
|---|---|---|
| `name` | `MercadoLibre` | |
| `slug` | `mercadolibre` | Único, lowercase. |
| `api_class` | `App\Services\Portals\MercadoLibreClient` | FQCN de la clase que lo maneja. |
| `icon` | `fa-solid fa-store` | |
| `color` | `#ffc107` | |
| `config_schema` | `{"fields":["client_id","client_secret"]}` | JSON. |
| `active` | `true` | |

---

### 9. `portal_credentials` — Credenciales por usuario y portal

⚠️ **Datos sensibles**. Los `access_token` y `refresh_token` están ocultos en JSON por defecto.

| Campo | Tipo | Obligatorio | Ejemplo |
|---|---|---|---|
| `user_id` | FK | ✅ | `1` |
| `integration_id` | FK | ✅ | `1` |
| `access_token` | text | ❌ | (generado por OAuth) |
| `refresh_token` | text | ❌ | |
| `access_token_expires_at` | timestamp | ❌ | `2025-01-15 10:30:00` |
| `data` | json | ❌ | `{"email":"...","password":"..."}` |

**Cómo se llena**:
- **MercadoLibre**: automáticamente al hacer OAuth (botón "Conectar" en integraciones).
- **Fincaraíz**: manualmente con el API key que te dio el portal.
- **Ciencuadras**: con el login, el backend hace el `POST /login` y guarda el token.
- **Proppit / Google**: no requieren credenciales (son feeds estáticos).

```php
PortalCredential::create([
    'user_id' => 1,
    'integration_id' => 2, // fincaraiz
    'data' => ['api_key' => 'tu-api-key-real'],
]);
```

---

### 10. `properties` — Propiedades (tabla principal)

⚠️ **Es la tabla más importante.** Acá va cada inmueble que publiques.

| Campo | Tipo | Obligatorio | Ejemplo | Notas |
|---|---|---|---|---|
| `code` | string(32) | ✅ | `SC-0001` | **Único, público, legible**. Usa prefijos por ciudad. |
| `title` | string(200) | ✅ | `Apartamento moderno en Centro` | |
| `description` | text | ❌ | `Excelente apartamento...` | |
| `condition` | enum | ❌ | `used` | `new`, `used`, `remodeled`, `under_construction`. |
| `city_id` | FK | ✅ | `1` | **No se puede borrar** (restrict). |
| `neighborhood_id` | FK | ❌ | `1` | nullOnDelete. |
| `address` | string | ❌ | `Calle 23 # 20-15` | |
| `address_extra` | string | ❌ | `Apto 501, Torre 2` | |
| `lat` / `lng` | decimal | ❌ | `9.3047` | Para el mapa. |
| `show_exact_address` | bool | ❌ | `true` | Si false, oculta la dirección exacta al publicar. |
| `property_type_id` | FK | ✅ | `1` | De `property_types`. |
| `transaction_type_id` | FK | ✅ | `1` | De `transaction_types`. |
| `sale_price` | decimal(18,2) | ⚠️ | `250000000.00` | Obligatorio **si la tx lo requiere**. |
| `rent_price` | decimal(18,2) | ⚠️ | `1800000.00` | Obligatorio **si la tx lo requiere**. |
| `admin_price` | decimal(18,2) | ❌ | `250000.00` | Solo arriendos. |
| `currency` | char(3) | ❌ | `COP` | Default `COP`. |
| `price_negotiable` | bool | ❌ | `false` | |
| `area_total` | decimal(10,2) | ❌ | `85.00` | m² totales. |
| `area_built` | decimal(10,2) | ❌ | `78.00` | m² construidos. |
| `area_private` | decimal(10,2) | ❌ | `72.00` | m² privados (aptos). |
| `area_land` | decimal(10,2) | ❌ | `200.00` | Para lotes/fincas. |
| `bedrooms` | tinyint | ❌ | `3` | Habitaciones. |
| `bathrooms` | tinyint | ❌ | `2` | Baños completos. |
| `half_bathrooms` | tinyint | ❌ | `1` | Medios baños. |
| `parking_spaces` | tinyint | ❌ | `1` | |
| `parking_type` | enum | ❌ | `covered` | `private`, `public`, `covered`, `uncovered`. |
| `floor_number` | tinyint | ❌ | `5` | Piso. |
| `age_years` | smallint | ❌ | `8` | Años de construido. |
| `year_built` | smallint | ❌ | `2017` | Año de construcción. |
| `stratum` | tinyint | ❌ | `4` | `1-6` en Colombia, `0` = no aplica. |
| `furnished` | bool | ❌ | `false` | |
| `project_name` | string | ❌ | `Torre Central` | Si está en un proyecto/edificio. |
| `in_closed_complex` | bool | ❌ | `true` | |
| **`status`** | enum | ❌ | `active` | Ver abajo. |
| `featured` | bool | ❌ | `false` | Destacada (boost). |
| `exclusive` | bool | ❌ | `false` | Exclusividad con la inmobiliaria. |
| `published_at` | timestamp | ❌ | `2024-12-15 10:00:00` | Cuándo se publicó. |
| `expires_at` | timestamp | ❌ | `2025-03-15` | Caducidad automática. |
| `consultant_id` | FK | ❌ | `1` | Asesor asignado. |
| `created_by` | FK | ❌ | `1` | Usuario que creó. |
| `updated_by` | FK | ❌ | `1` | Último que editó. |
| `contact_name` | string | ❌ | `Carlos Pérez` | Para mostrar al público. |
| `contact_phone` | string | ❌ | `+57 300 222 2222` | |
| `contact_whatsapp` | string | ❌ | `+573002222222` | |
| `contact_email` | string | ❌ | `carlos@sucasa.com` | |
| `views_count` | int | (auto) | `142` | Contador de vistas. |
| `leads_count` | int | (auto) | `5` | Contador de leads. |

#### Estados posibles (`status`)

| Valor | Significado | ¿Visible en portales? |
|---|---|---|
| `draft` | Borrador | ❌ |
| `active` | Publicada | ✅ |
| `paused` | Pausada temporalmente | ❌ |
| `reserved` | Reservada (señal recibida) | ❌ |
| `sold` | Vendida | ❌ |
| `rented` | Arrendada | ❌ |
| `expired` | Caducó la publicación | ❌ |
| `archived` | Archivada (histórico) | ❌ |

**Cómo cargar una propiedad** (vía API o tinker):

```php
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\TransactionType;
use App\Models\City;
use App\Models\Neighborhood;

$apartamento = PropertyType::where('slug', 'apartamento')->first();
$sincelejo = City::where('dane_code', '70001')->first();
$centro = Neighborhood::where('city_id', $sincelejo->id)->where('name', 'Centro')->first();
$venta = TransactionType::where('slug', 'sale')->first();

$prop = Property::create([
    'code' => 'SC-9999',
    'title' => 'Mi primera propiedad',
    'description' => 'Apartamento amplio y luminoso...',
    'condition' => 'used',
    'city_id' => $sincelejo->id,
    'neighborhood_id' => $centro->id,
    'address' => 'Calle 23 # 20-15',
    'lat' => 9.3047,
    'lng' => -75.3978,
    'property_type_id' => $apartamento->id,
    'transaction_type_id' => $venta->id,
    'sale_price' => 250000000,
    'currency' => 'COP',
    'area_built' => 85,
    'area_private' => 78,
    'bedrooms' => 3,
    'bathrooms' => 2,
    'half_bathrooms' => 1,
    'parking_spaces' => 1,
    'parking_type' => 'covered',
    'floor_number' => 5,
    'age_years' => 5,
    'stratum' => 4,
    'furnished' => false,
    'in_closed_complex' => true,
    'project_name' => 'Torre Central',
    'status' => 'active',
    'featured' => false,
    'published_at' => now(),
    'contact_name' => 'Carlos Pérez',
    'contact_phone' => '+57 300 222 2222',
    'contact_whatsapp' => '+573002222222',
    'contact_email' => 'carlos@sucasa.com',
    'consultant_id' => 1,
    'created_by' => 1,
]);
```

---

### 11. `property_images` — Imágenes de la propiedad

**Separada de la propiedad** (no es JSON en la tabla principal).

| Campo | Tipo | Obligatorio | Ejemplo |
|---|---|---|---|
| `property_id` | FK | ✅ | `1` |
| `url` | string(500) | ✅ | `https://ejemplo.com/foto.jpg` |
| `thumbnail_url` | string | ❌ | versión miniatura |
| `alt_text` | string | ❌ | descripción para SEO/accesibilidad |
| `width` | int | ❌ | `1920` |
| `height` | int | ❌ | `1080` |
| `file_size` | int | ❌ | bytes |
| `is_cover` | bool | ❌ | `true` (solo una por propiedad) |
| `order` | int | ❌ | `0` |

**Cómo agregar imágenes**:
```php
$prop = Property::find(1);

$prop->images()->create([
    'url' => 'https://ejemplo.com/foto1.jpg',
    'is_cover' => true,
    'order' => 0,
    'alt_text' => 'Sala principal',
]);

$prop->images()->create([
    'url' => 'https://ejemplo.com/foto2.jpg',
    'is_cover' => false,
    'order' => 1,
    'alt_text' => 'Cocina integral',
]);
```

**Recomendaciones**:
- Mínimo 4 fotos, ideal 8-12.
- La primera (`order=0` o `is_cover=true`) es la portada.
- Resolución mínima 1200x800 px.
- Formato JPG o WebP.

---

### 12. `property_videos` y `property_floor_plans` — Videos y Planos

Misma estructura que `property_images`, sin `is_cover`.

```php
$prop->videos()->create([
    'url' => 'https://youtube.com/watch?v=...',
    'provider' => 'youtube',
    'title' => 'Recorrido 360°',
    'order' => 0,
]);

$prop->floorPlans()->create([
    'url' => 'https://ejemplo.com/plano.pdf',
    'label' => 'Plano primer piso',
    'order' => 0,
]);
```

---

### 13. `property_feature` — Pivot de características

Relación N:M entre `properties` y `features`. **El `value` permite especificar un valor**: `"2 unidades"`, `"marca Samsung"`, etc.

| Campo | Tipo | Ejemplo |
|---|---|---|
| `property_id` | FK | `1` |
| `feature_id` | FK | `5` |
| `value` | string(200) | `"2 unidades"` (opcional) |

```php
$prop = Property::find(1);
$features = Feature::whereIn('slug', ['piscina', 'gimnasio', 'porteria-24-7'])->get();
$prop->features()->sync($features->pluck('id'));
```

---

### 14. `property_sync_statuses` — Sincronización con portales

| Campo | Tipo | Ejemplo | Notas |
|---|---|---|---|
| `property_id` | FK | `1` | |
| `integration_id` | FK | `1` | (MercadoLibre) |
| `sync_status` | enum | `synced` | Ver abajo. |
| `external_id` | string | `MLC1234567890` | ID que devuelve el portal. |
| `external_url` | string | `https://mercadolibre.com.co/MLC-123` | URL pública. |
| `last_response` | json | `{"id":"MLC-123","status":"active"}` | Última respuesta cruda. |
| `last_error` | text | `Error 400: campo inválido` | |
| `last_synced_at` | timestamp | `2024-12-15 10:30:00` | |
| `last_attempt_at` | timestamp | | |
| `attempts` | smallint | `1` | Contador de reintentos. |

**Estados** (`sync_status`):
- `not_synced` — nunca se intentó
- `pending` — en cola
- `syncing` — publicando ahora
- `synced` — publicado OK ✅
- `error` — falló
- `paused` — pausado en el portal

**Se llena automáticamente** cuando publicás desde el panel.

---

### 15. `property_status_history` — Auditoría de cambios de estado

**Se llena automáticamente** (o vía `$property->update(['status' => 'sold'])` si usas el observer).

---

### 16. `audit_logs` — Auditoría general

Para activar logging automático, agregá un Observer de Eloquent o usá un package como `spatie/laravel-activitylog`.

---

### 17. `portal_mappings` — Homologación de IDs externos

Esta tabla resuelve un problema clave: **el mismo barrio tiene IDs distintos en MercadoLibre, Fincaraíz, Ciencuadras y Proppit**.

| Campo | Tipo | Ejemplo |
|---|---|---|
| `integration_id` | FK | `1` (MercadoLibre) |
| `mappable_type` | string | `App\Models\Neighborhood` |
| `mappable_id` | bigint | `1` (el barrio "Centro") |
| `external_id` | string | `TUxNQ0NFTjI2NmM` (el ID que usa ML) |
| `external_name` | string | `Centro` (cómo lo llama ML) |
| `extra` | json | metadata adicional |

**Cómo poblar** (ejecutar una vez por portal):

```php
use App\Models\PortalMapping;
use App\Models\Integration;
use App\Models\Neighborhood;

$ml = Integration::where('slug', 'mercadolibre')->first();
$centro = Neighborhood::where('name', 'Centro')->first();

PortalMapping::create([
    'integration_id' => $ml->id,
    'mappable_type' => Neighborhood::class,
    'mappable_id' => $centro->id,
    'external_id' => 'TUxNQ0NFTjI2NmM', // ID que devuelve la API de ML
    'external_name' => 'Centro',
]);
```

**¿De dónde sacar los IDs externos?**
- **MercadoLibre**: `GET https://api.mercadolibre.com/classified_locations/cities/{city_id}` y luego `neighborhoods`.
- **Fincaraíz**: `GET /location?query={nombre}` desde el panel de Kong.
- **Ciencuadras**: `GET /locations` con auth.
- **Proppit**: lo da el equipo de Proppit al registrarse.

El seeder `PortalMappingSeeder` ya carga mapeos para los **tipos de propiedad** y los **barrios seedeados** con IDs placeholder. Después reemplazá con los reales.

---

## Reglas de negocio implementadas

| Regla | Dónde |
|---|---|
| Una ciudad no se puede borrar si tiene propiedades | `restrictOnDelete` en `properties.city_id` |
| Un barrio no se puede borrar si tiene propiedades | `nullOnDelete` en `properties.neighborhood_id` (se pone a NULL) |
| Solo una imagen es portada | `is_cover=true` (lógicamente) |
| Soft delete de propiedades | `SoftDeletes` trait (no se borra de la BD) |
| `views_count` y `leads_count` se actualizan al ver | manual o con un middleware |
| Las credenciales OAuth se ocultan en JSON | `$hidden` en `PortalCredential` |
| Solo los roles admin y manager ven todas las propiedades | filtrar con `whereHas('user.role')` |

---

## Mantenimiento

### Auditoría rápida
```sql
-- ¿Cuántas propiedades hay por estado?
SELECT status, COUNT(*) FROM properties GROUP BY status;

-- ¿Cuáles propiedades no se han sincronizado nunca?
SELECT p.code, p.title FROM properties p
LEFT JOIN property_sync_statuses pss ON pss.property_id = p.id
WHERE pss.id IS NULL AND p.status = 'active';

-- Asesores con más propiedades
SELECT c.name, COUNT(p.id) FROM consultants c
LEFT JOIN properties p ON p.consultant_id = c.id
GROUP BY c.id ORDER BY 2 DESC;

-- Token de MercadoLibre próximos a expirar
SELECT u.email, i.name, pc.access_token_expires_at
FROM portal_credentials pc
JOIN users u ON u.id = pc.user_id
JOIN integrations i ON i.id = pc.integration_id
WHERE pc.access_token_expires_at < DATE_ADD(NOW(), INTERVAL 1 DAY);
```

### Backup
```bash
# Backup completo
mysqldump -u root -p sucasa_panel > backup_$(date +%Y%m%d).sql

# Backup con Laravel
php artisan backup:run   # si instalás spatie/laravel-backup
```

### Regenerar feeds XML
```bash
php artisan portals:generate-feeds
```

---

## Próximo paso: importar tus datos reales

Una vez que confirmes que la BD nueva funciona, podemos armar un script de
**migración de datos desde `vieja/`** que lea las 3 bases de datos legacy
(`u338001637_sofempire`, `u350704768_panelapi`, `almacenb_bcmarke` + tablas WP)
y los inserte en el nuevo schema con la normalización correcta.

Avisame y te lo armo.
