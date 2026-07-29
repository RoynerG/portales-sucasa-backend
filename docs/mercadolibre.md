# Mercado Libre Inmuebles (MCO)

## Configuración

```dotenv
MERCADOLIBRE_CLIENT_ID=
MERCADOLIBRE_CLIENT_SECRET=
MERCADOLIBRE_REDIRECT_URI=https://api.example.com/api/portals/mercadolibre/callback
MERCADOLIBRE_ACCOUNT_KEY=sucasa-shared
MERCADOLIBRE_DEFAULT_LISTING_TYPE=silver
MERCADOLIBRE_WEBHOOK_QUEUE=mercadolibre
```

En la aplicación de Mercado Libre se debe registrar exactamente el Redirect URI anterior y la URL de notificaciones:

```text
POST https://api.example.com/api/portals/mercadolibre/webhook
```

El vendedor debe pertenecer a MCO, tener un paquete inmobiliario activo y ser conectado desde el panel por un administrador o gerente. Los tokens se guardan cifrados y una rotación inválida desconecta la cuenta para exigir una autorización nueva.

## Operación

1. Conectar la cuenta en **Integraciones**.
2. Ejecutar **Sincronizar catálogo**. El comando `mercadolibre:sync-catalog` también se programa diariamente a las 03:30.
3. En un inmueble, abrir **Preflight**, corregir atributos o ubicación y volver a validar.
4. Publicar únicamente cuando el preflight sea válido. `Arriendo/Venta` genera dos ítems y consume dos cupos.
5. Procesar la cola dedicada:

```bash
php artisan queue:work --queue=mercadolibre,default
```

El cierre es irreversible en Mercado Libre. No existe una acción de eliminación permanente en esta integración.

## Prueba externa segura

Usar primero un usuario de prueba inmobiliario con paquete activo. Validar en este orden: preflight, publicación, descripción, actualización, pausa, reactivación y webhook. No conectar ni publicar con la cuenta real sin confirmación explícita.
