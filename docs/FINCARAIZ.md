# Integración Fincaraíz

Implementación basada en el contrato OpenAPI `Fincaraiz.com.co/Integradores` 1.0.0 publicado en SwaggerHub.

## Ambientes

| `FINCARAIZ_ENV` | Base URL | Uso |
|---|---|---|
| `mock` | `https://virtserver.swaggerhub.com/Fincaraiz.com.co/Integradores/1.0.0` | Valida transporte y forma del contrato; no crea avisos reales. |
| `qa` | `https://kong-qa.frcol.io/management/api/1.0` | Pruebas con credenciales QA de Fincaraíz. Es el valor predeterminado. |
| `production` | `https://msi-infofinca.fincaraiz.com.co/management/api/1.0` | Producción; activar solo después de aprobar QA. |

`FINCARAIZ_API_URL` permite sobrescribir la URL, pero normalmente no debe definirse.

## Configuración

```dotenv
FINCARAIZ_ENV=qa
FINCARAIZ_API_KEY=
FINCARAIZ_CLIENT_ID=
FINCARAIZ_CLIENT_AGENT=

FINCARAIZ_CONTACT_EMAIL=
FINCARAIZ_CONTACT_PHONE=
FINCARAIZ_CONTACT_WHATSAPP=
FINCARAIZ_SHOW_EXACT_ADDRESS=false
FINCARAIZ_DUAL_OFFER=sale
FINCARAIZ_WEBHOOK_ID=
FINCARAIZ_WEBHOOK_VERIFY_TOKEN=
FINCARAIZ_WEBHOOK_URL=https://portal-core.example.com/api/portals/fincaraiz/webhook
```

- `API_KEY`: token del integrador; se envía exclusivamente en el encabezado `apikey`.
- `CLIENT_ID`: UUID del cliente asociado al integrador, obtenido con `GET /client`.
- `CLIENT_AGENT`: identificador numérico de la sucursal, obtenido con `GET /client/{client_id}/agent`. Es opcional si Fincaraíz tiene un agente predeterminado.
- Los datos de contacto globales tienen prioridad sobre los del asesor del inmueble.
- Para inmuebles “venta o arriendo”, Fincaraíz admite una sola oferta por aviso. `DUAL_OFFER` elige `sale` o `rent`.
- La activación automática queda deshabilitada durante pruebas. Después de crear y verificar el aviso se usa la acción **Activar**.

Después de cambiar variables:

```bash
php artisan config:clear
php artisan route:clear
```

Las mismas credenciales de QA también se pueden guardar desde **Integraciones → Fincaraíz → Configurar QA**. La API key queda cifrada en `portal_credentials.access_token`; el panel nunca vuelve a mostrarla y permite conservarla dejando el campo vacío. Las variables de entorno siguen funcionando como configuración predeterminada.

## Reglas importantes del contrato

1. Todos los endpoints del portal, salvo el receptor de webhook propio, requieren el encabezado `apikey`.
2. Toda consulta `GET` incluye un parámetro dinámico (`sucasa-cache=<uuid>`) para evitar respuestas antiguas del caché de Fincaraíz.
3. `POST /listing`, `PATCH /listing` y `PATCH /listing/status` reciben arreglos, incluso cuando se envía un solo inmueble.
4. Crear, actualizar o cambiar estado es asíncrono: la primera respuesta solo entrega `task.id`.
5. La operación termina cuando `GET /task/{task_id}` reporta `COMPLETED`, `ERROR` o `FORWARDED`.
6. `listing_id` se obtiene del contenido de la tarea y es el identificador requerido para actualizar, desactivar o activar.
7. Un inmueble creado queda pendiente. Se activa posteriormente con `PATCH /listing/status` y estado `ACTIVE`.
8. El contrato enumera `ACTIVE` y `DELETED`, mientras la descripción oficial también documenta `DISABLED`. El botón **Despublicar** usa `DISABLED` para no eliminar permanentemente el aviso.
9. `POST /validate-listing` elimina en Fincaraíz los avisos activos que no estén incluidos en el inventario enviado. El cliente lo soporta, pero no se expone en el panel para evitar borrados accidentales.
10. `/category` está marcado como obsoleto. Las características usadas por el mapper provienen del catálogo documentado en `ListingPOST`.

## Payload de inmueble

El preflight valida antes de enviar los campos obligatorios del contrato:

- `external_code`, `client_id`, `offer`, `property_type`, `description` y `price`;
- `area`, `address`, latitud y longitud;
- `listing_contact` con correo y teléfono;
- códigos enumerados para condición, antigüedad, habitaciones, baños, piso y garajes;
- hasta 30 imágenes con una sola principal.

El UUID del barrio (`location_main_id`) se obtiene con `GET /location/{name}`. En el panel, abre el inmueble, entra a **Portales → Fincaraíz → Preflight** y pulsa **Buscar y homologar barrio**. Busca por nombre, confirma ciudad/departamento y elige preferiblemente un resultado de tipo `NEIGHBOURHOOD`. El panel guarda el UUID para todos los inmuebles del mismo barrio local. Si aún no está homologado, el payload sigue siendo válido con coordenadas y muestra una advertencia. Los IDs ficticios del seeder no se envían.

También se puede administrar todo el catálogo desde **Barrios → Fincaraíz**. La migración agrega en WordPress:

- `wp_jet_cct_barrios.fincaraiz_location_id`;
- `wp_jet_cct_barrios.fincaraiz_location_name`;
- `wp_jet_cct_barrios.fincaraiz_location_type`.

La selección solo permite guardar resultados oficiales de tipo `NEIGHBOURHOOD` y sincroniza el UUID con `portal_mappings`, que es la homologación consumida por el preflight de todos los inmuebles del mismo barrio.

## Flujo de prueba recomendado

1. Solicitar a Fincaraíz una API key, Client ID y, si aplica, Agent ID de **QA**.
2. Configurar las variables con `FINCARAIZ_ENV=qa`.
3. En Integraciones, pulsar **Probar API**. Debe mostrar el cliente, cupo y agentes.
4. Revisar `GET /api/portals/properties/{code}/fincaraiz/payload`; los errores bloquean el envío y las advertencias no.
5. Publicar un solo inmueble de prueba. La respuesta debe quedar `pending` con un `task_id`.
6. Pulsar **Verificar** hasta que la tarea termine. Al crear, quedará pendiente de activación y almacenará `listing_id`.
7. Pulsar **Activar** y verificar la nueva tarea.
8. Confirmar el aviso con `GET /api/portals/fincaraiz/listings?search={code}`.
9. Probar **Actualizar**, **Despublicar** y **Activar** antes de promover a producción.

Para una prueba sin credenciales ni datos reales se puede usar temporalmente `FINCARAIZ_ENV=mock`, una API key cualquiera y UUIDs de prueba. El mock de SwaggerHub comprueba la forma HTTP, pero no sustituye QA: no procesa imágenes, cupos, moderación ni tareas reales.

## Webhook

El receptor público es:

```text
POST /api/portals/fincaraiz/webhook
```

Valida los encabezados `HUB.ID` y `VERIFY-TOKEN` con comparación segura y actualiza el estado por `external_code`. La suscripción se solicita desde:

```text
POST /api/portals/fincaraiz/webhook/subscribe
```

La URL debe ser HTTPS y accesible públicamente. Durante QA también se puede verificar manualmente con `/verify` sin habilitar el webhook.

## Paso a producción

1. Completar el flujo de QA con creación, consulta, actualización, despublicación y reactivación.
2. Confirmar con Fincaraíz el API key, Client ID, Agent ID, Webhook ID y cupos de producción.
3. Cambiar únicamente `FINCARAIZ_ENV=production` y las credenciales correspondientes; no reutilizar credenciales QA.
4. Limpiar la caché de configuración y comprobar que `/fincaraiz/status` reporta `production` antes de publicar.
5. Publicar y verificar un solo inmueble controlado.
6. Suscribir el webhook y comprobar sus encabezados.
7. Habilitar automatización o cargas masivas solo después de esa validación.

No se debe ejecutar `validate-listing` durante la puesta en marcha inicial.
