# Ciencuadras

## Ambiente de prueba

Configurar en `backend/.env`:

```env
CIENCUADRAS_ENV=pre
CIENCUADRAS_API_URL=https://pre-ws-api.ciencuadras.com
CIENCUADRAS_PAGE_URL=https://pre.ciencuadras.com
CIENCUADRAS_USERNAME=
CIENCUADRAS_PASSWORD=
CIENCUADRAS_INTEGRATOR=SUCASA
CIENCUADRAS_PROPERTY_CODE_PREFIX=22130-
CIENCUADRAS_DEFAULT_CITY_ID=13001
CIENCUADRAS_DEFAULT_LOCALITY_ID=
CIENCUADRAS_CONTACT_NAME="Su Casa Inmobiliaria"
CIENCUADRAS_CONTACT_PHONE=
CIENCUADRAS_CONTACT_EMAIL=
CIENCUADRAS_CONTACT_WHATSAPP=
```

Despues de cambiar variables:

```bash
php artisan optimize:clear
```

## Ambiente de produccion

Cambiar solo variables de entorno:

```env
CIENCUADRAS_ENV=production
CIENCUADRAS_API_URL=https://ws-api.ciencuadras.com
CIENCUADRAS_PAGE_URL=https://www.ciencuadras.com
CIENCUADRAS_USERNAME=
CIENCUADRAS_PASSWORD=
CIENCUADRAS_CONTACT_NAME="Su Casa Inmobiliaria"
CIENCUADRAS_CONTACT_PHONE=
CIENCUADRAS_CONTACT_EMAIL=
CIENCUADRAS_CONTACT_WHATSAPP=
```

## Datos que usa el sistema

El publicador toma los inmuebles reales desde `wp_jet_cct_inmuebles`:

- datos principales: `codigo`, `estado`, `tipo_inmueble`, `tipo_negocio`, `precio_venta`, `precio_arriendo`, `precio_admin`
- ubicacion: `ciudad`, `barrio`, `direccion`, `latitud`, `longitud`, `estrato`
- areas y caracteristicas: `area_construida`, `area_privada`, `area_terreno`, `habitaciones`, `banos`, `parqueaderos`, `edad`, `interiores`, `exteriores`, `alrededores`, `zonas_sociales`
- multimedia: `foto_portada`, `galeria`, `video`, resueltos contra `wp_posts.guid`
- asesor: `id_funcionario`, resuelto contra `wp_jet_cct_funcionarios`

## Tablas locales

El estado de sincronizacion queda en `property_sync_statuses`.

Se agrego el campo:

- `environment`: permite separar resultados de `pre` y `production`.

## Homologaciones pendientes

La base original no traia IDs propios de Ciencuadras para ciudades/localidades/barrios. El sistema ahora puede leer estas columnas si existen:

- `wp_jet_cct_ciudades.ciencuadras_city_id`
- `wp_jet_cct_barrios.ciencuadras_locality_id`

SQL sugerido:

```sql
ALTER TABLE wp_jet_cct_ciudades
  ADD COLUMN ciencuadras_city_id INT NULL;

ALTER TABLE wp_jet_cct_barrios
  ADD COLUMN ciencuadras_locality_id INT NULL;
```

Ejemplo:

```sql
UPDATE wp_jet_cct_ciudades
SET ciencuadras_city_id = 13001
WHERE LOWER(TRIM(ciudad)) = 'cartagena';

UPDATE wp_jet_cct_barrios
SET ciencuadras_locality_id = 3662
WHERE LOWER(TRIM(ciudad)) = 'cartagena'
  AND LOWER(TRIM(barrio)) = 'el cabrero';
```

Si no tienes el ID real de localidad de Ciencuadras, dejalo `NULL`; el sistema omitira `localityId`.

Si las columnas no existen o estan vacias, se usan estas variables:

- `CIENCUADRAS_DEFAULT_CITY_ID`: codigo DANE de la ciudad, por ejemplo Cartagena `13001`
- `CIENCUADRAS_DEFAULT_LOCALITY_ID`
- `CIENCUADRAS_CONTACT_NAME`, `CIENCUADRAS_CONTACT_PHONE`, `CIENCUADRAS_CONTACT_EMAIL`
  y `CIENCUADRAS_CONTACT_WHATSAPP`: contacto publico unico enviado en todos los inmuebles.
  Si `CIENCUADRAS_CONTACT_WHATSAPP` esta vacio, se reutiliza el telefono.

`neighborhoodName` se envia por texto porque el manual indica que ya no es obligatorio homologarlo por ID.
