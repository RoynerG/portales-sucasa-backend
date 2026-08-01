<?php

namespace App\Services;

class PortalErrorSummarizer
{
    public function summarize(?string $lastError, array $response, ?string $syncStatus): array
    {
        $portalError = $this->findPortalError($response);
        $text = $this->compactText($portalError['text'] ?? $lastError ?? '');
        $field = $portalError['field'] ?? null;
        $statusCode = $portalError['statusCode'] ?? null;
        $propertyCode = $portalError['propertyCode'] ?? $this->findScalar($response, 'propertyCode');
        $searchText = $this->searchText($lastError, $response);

        if ($field === 'numBedRooms' || str_contains($searchText, 'numbedrooms')) {
            return $this->payload(
                'Falta corregir habitaciones',
                'Ciencuadras exige que el número de habitaciones esté entre 1 y 15.',
                'Edita el inmueble y completa habitaciones con un valor válido. Luego vuelve a actualizar o publicar.',
                $field ?: 'numBedRooms',
                $statusCode,
                $propertyCode,
                'property_data'
            );
        }

        if (str_contains($searchText, 'foto') || str_contains($searchText, 'imagen')) {
            return $this->payload(
                'Fotos no aceptadas por el portal',
                'El portal no pudo leer una o más fotos del inmueble.',
                'Revisa que las fotos abran públicamente en el navegador y sean JPG, JPEG, PNG o GIF. Después vuelve a actualizar el inmueble.',
                $field,
                $statusCode,
                $propertyCode,
                'images'
            );
        }

        if (str_contains($searchText, 'latitude') || str_contains($searchText, 'longitude') || str_contains($searchText, 'coordenada')) {
            return $this->payload(
                'Faltan coordenadas válidas',
                'El inmueble no tiene latitud y longitud correctas.',
                'Completa la ubicación del inmueble con coordenadas numéricas y vuelve a publicar.',
                $field,
                $statusCode,
                $propertyCode,
                'location'
            );
        }

        if (str_contains($searchText, 'totalarea') || str_contains($searchText, 'área total') || str_contains($searchText, 'area total')) {
            return $this->payload(
                'Falta el área total',
                'El portal necesita el área total del inmueble para poder publicarlo.',
                'Completa el área total con un valor mayor que cero y vuelve a publicar.',
                $field ?: 'totalArea',
                $statusCode,
                $propertyCode,
                'property_data'
            );
        }

        if (str_contains($searchText, 'sellandrentsupported')
            || str_contains($searchText, 'sellandrentnotsupported')
            || str_contains($searchText, 'sell and rent at the same time')) {
            return $this->payload(
                'Venta y arriendo no se pueden enviar juntos',
                'Proppit solo acepta una operación por anuncio: venta o arriendo.',
                'Selecciona una sola gestión para este portal y vuelve a publicar el inmueble.',
                $field ?: 'operation',
                $statusCode,
                $propertyCode,
                'business_rule'
            );
        }

        if (str_contains($searchText, 'publisher') && (str_contains($searchText, 'disabled') || str_contains($searchText, 'approval') || str_contains($searchText, 'publishingenabled'))) {
            return $this->payload(
                'Publisher pendiente de aprobación',
                'El portal recibió las credenciales, pero el publisher todavía no está habilitado para publicar.',
                'Solicita la aprobación del publisher al portal y verifica que publishingEnabled esté activo.',
                null,
                $statusCode,
                $propertyCode,
                'account'
            );
        }

        if (str_contains($searchText, 'unauthorized')
            || str_contains($searchText, 'forbidden')
            || str_contains($searchText, 'credencial')
            || str_contains($searchText, 'invalid token')
            || str_contains($searchText, 'token expired')
            || str_contains($searchText, 'token inválido')) {
            return $this->payload(
                'Problema de acceso al portal',
                'La integración no pudo autenticarse o no tiene permisos suficientes.',
                'Revisa las credenciales, el ambiente configurado y los permisos de la cuenta del portal.',
                null,
                $statusCode,
                $propertyCode,
                'account'
            );
        }

        if (str_contains($searchText, 'timeout') || str_contains($searchText, 'timed out') || str_contains($searchText, 'connection')) {
            return $this->payload(
                'El portal tardó demasiado en responder',
                'La solicitud no pudo confirmarse por un problema temporal de comunicación.',
                'Espera la siguiente verificación automática. Si continúa, revisa la disponibilidad del portal.',
                null,
                $statusCode,
                $propertyCode,
                'connection'
            );
        }

        if (str_contains($searchText, 'no existe') || str_contains($searchText, 'not found')) {
            return $this->payload(
                $syncStatus === 'pending' ? 'El portal aún no lo confirma' : 'El portal no encontró el inmueble',
                $syncStatus === 'pending'
                    ? 'La solicitud fue enviada, pero el inmueble todavía no aparece al consultar el portal.'
                    : 'El portal respondió que el inmueble no existe con ese código.',
                $syncStatus === 'pending'
                    ? 'Espera la siguiente verificación automática. Si dura mucho, revisa el detalle técnico.'
                    : 'Vuelve a publicar el inmueble o revisa si el código fue eliminado en el portal.',
                $field,
                $statusCode,
                $propertyCode,
                'not_found'
            );
        }

        if ($text !== '') {
            return $this->payload(
                'El portal rechazó la solicitud',
                $this->humanizeMessage($text),
                'Revisa el dato indicado, corrígelo en el inmueble y vuelve a intentar.',
                $field,
                $statusCode,
                $propertyCode,
                'portal_rejected'
            );
        }

        return $this->payload(
            'Sin error detallado',
            'No hay una explicación clara registrada por el portal.',
            'Abre el detalle técnico o intenta verificar nuevamente.',
            null,
            $statusCode,
            $propertyCode,
            'unknown'
        );
    }

    protected function payload(
        string $title,
        string $message,
        string $action,
        ?string $field,
        mixed $statusCode,
        mixed $propertyCode,
        string $type
    ): array
    {
        $typeLabels = [
            'property_data' => 'Datos del inmueble',
            'images' => 'Fotos',
            'location' => 'Ubicación',
            'business_rule' => 'Regla del portal',
            'not_found' => 'Código no encontrado',
            'account' => 'Cuenta o permisos',
            'connection' => 'Conexión',
            'portal_rejected' => 'Solicitud rechazada',
            'unknown' => 'Sin clasificar',
        ];

        return [
            'type' => $type,
            'type_label' => $typeLabels[$type] ?? 'Sin clasificar',
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'field' => $field,
            'status_code' => is_scalar($statusCode) ? (string) $statusCode : null,
            'portal_code' => is_scalar($propertyCode) ? (string) $propertyCode : null,
        ];
    }

    protected function searchText(?string $lastError, array $response): string
    {
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $text = ($lastError ?? '').' '.($encoded ?: '');
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;

        return mb_strtolower($text, 'UTF-8');
    }

    protected function findPortalError(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        $hasErrorCode = isset($data['statusCode']) && (int) $data['statusCode'] >= 300;
        if ($status === 'error' || $hasErrorCode) {
            $message = $data['message'] ?? $data['importantInfo'] ?? null;
            $field = null;
            if (is_array($message)) {
                $field = array_key_first($message);
                $message = $field ? $message[$field] : json_encode($message, JSON_UNESCAPED_UNICODE);
            }

            return [
                'text' => $this->compactText((string) ($message ?: $data['importantInfo'] ?? '')),
                'field' => $field,
                'statusCode' => $data['statusCode'] ?? null,
                'propertyCode' => $data['propertyCode'] ?? null,
            ];
        }

        foreach ($data as $value) {
            $found = $this->findPortalError($value);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    protected function findScalar(array $data, string $key): mixed
    {
        foreach ($data as $currentKey => $value) {
            if (strtolower((string) $currentKey) === strtolower($key) && is_scalar($value)) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->findScalar($value, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function compactText(string $text): string
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $portalError = $this->findPortalError($decoded);
            if ($portalError && ! empty($portalError['text'])) {
                return $this->compactText($portalError['text']);
            }
        }

        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_strtolower($text, 'UTF-8');
    }

    protected function humanizeMessage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'El portal no entregó un mensaje específico.';
        }

        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, 260, 'UTF-8');
    }
}
