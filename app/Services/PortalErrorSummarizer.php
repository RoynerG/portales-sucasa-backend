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

        if ($field === 'numBedRooms' || str_contains($text, 'numbedrooms')) {
            return $this->payload(
                'Falta corregir habitaciones',
                'Ciencuadras exige que el número de habitaciones esté entre 1 y 15.',
                'Edita el inmueble y completa habitaciones con un valor válido. Luego vuelve a actualizar o publicar.',
                $field ?: 'numBedRooms',
                $statusCode,
                $propertyCode
            );
        }

        if (str_contains($text, 'foto') || str_contains($text, 'imagen')) {
            return $this->payload(
                'Fotos no aceptadas por Ciencuadras',
                'El portal no pudo leer una o más fotos del inmueble.',
                'Revisa que las fotos abran públicamente en el navegador y sean JPG, JPEG, PNG o GIF. Después vuelve a actualizar el inmueble.',
                $field,
                $statusCode,
                $propertyCode
            );
        }

        if (str_contains($text, 'latitude') || str_contains($text, 'longitude') || str_contains($text, 'coordenada')) {
            return $this->payload(
                'Faltan coordenadas válidas',
                'El inmueble no tiene latitud y longitud correctas.',
                'Completa la ubicación del inmueble con coordenadas numéricas y vuelve a publicar.',
                $field,
                $statusCode,
                $propertyCode
            );
        }

        if (str_contains($text, 'no existe') || str_contains($text, 'not found')) {
            return $this->payload(
                $syncStatus === 'pending' ? 'Ciencuadras aún no lo confirma' : 'El portal no encontró el inmueble',
                $syncStatus === 'pending'
                    ? 'La solicitud fue enviada, pero el inmueble todavía no aparece al consultar el portal.'
                    : 'Ciencuadras respondió que el inmueble no existe con ese código.',
                $syncStatus === 'pending'
                    ? 'Espera la siguiente verificación automática. Si dura mucho, revisa el detalle técnico.'
                    : 'Vuelve a publicar el inmueble o revisa si el código fue eliminado en Ciencuadras.',
                $field,
                $statusCode,
                $propertyCode
            );
        }

        if ($text !== '') {
            return $this->payload(
                'El portal rechazó la solicitud',
                $this->humanizeMessage($text),
                'Revisa el dato indicado, corrígelo en el inmueble y vuelve a intentar.',
                $field,
                $statusCode,
                $propertyCode
            );
        }

        return $this->payload(
            'Sin error detallado',
            'No hay una explicación clara registrada por el portal.',
            'Abre el detalle técnico o intenta verificar nuevamente.',
            null,
            $statusCode,
            $propertyCode
        );
    }

    protected function payload(string $title, string $message, string $action, ?string $field, mixed $statusCode, mixed $propertyCode): array
    {
        return [
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'field' => $field,
            'status_code' => is_scalar($statusCode) ? (string) $statusCode : null,
            'portal_code' => is_scalar($propertyCode) ? (string) $propertyCode : null,
        ];
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
