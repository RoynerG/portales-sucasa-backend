<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;

class WordPressHighlightNotificationService
{
    public function enqueueCompletion(stdClass $property, stdClass $request, string $marketLabel): array
    {
        if (! Schema::connection('wordpress')->hasTable('skc_notification_queue')) {
            return ['queued' => 0, 'skipped' => ['cola compartida no disponible']];
        }

        $propertyCode = trim((string) (($property->codigo ?? '') ?: ($property->_ID ?? '')));
        $requestId = (string) ($request->id ?? '');
        $employeeId = trim((string) ($request->solicitado_por_id ?? ''));
        $employeeName = trim((string) ($request->solicitado_por_nombre ?? '')) ?: 'Funcionario';
        $portal = trim((string) ($request->portal ?? ''));
        $banner = $this->bannerUrl();
        $queued = 0;
        $skipped = [];

        $owner = $this->ownerFor($property);
        $ownerEmail = trim((string) ($owner->correo ?? ''));
        if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            $ownerName = trim((string) ($property->propietario ?? ($owner->nombre ?? ($owner->nombre_juridico ?? '')))) ?: 'propietario';
            $queued += $this->enqueueEmail(
                $ownerEmail,
                $ownerName,
                "Su inmueble #{$propertyCode} ha sido destacado",
                $this->ownerHtml($propertyCode, $ownerName, $employeeName, $banner),
                "portales-sucasa:destacado:{$requestId}:propietario",
                'inmueble-destacado',
                [
                    'event' => 'inmueble_destacado',
                    'portal' => $portal,
                    'portal_label' => $marketLabel,
                    'id_inmueble' => $propertyCode,
                    'inmueble_id' => (int) ($property->_ID ?? 0),
                    'id_propietario' => (string) ($property->id_propietario ?? ''),
                    'id_empleado' => $employeeId,
                    'empleado' => $employeeName,
                    'request_id' => $requestId,
                ]
            );
        } else {
            $skipped[] = 'propietario sin correo válido';
        }

        $employeeEmail = $this->employeeEmail($employeeId);
        if (filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
            $queued += $this->enqueueEmail(
                $employeeEmail,
                $employeeName,
                "Tu destacado del inmueble #{$propertyCode} fue confirmado",
                $this->employeeHtml($propertyCode, $employeeName, $marketLabel, $banner),
                "portales-sucasa:destacado:{$requestId}:funcionario",
                'destacado-funcionario',
                [
                    'event' => 'funcionario_destacado_confirmado',
                    'portal' => $portal,
                    'portal_label' => $marketLabel,
                    'id_inmueble' => $propertyCode,
                    'inmueble_id' => (int) ($property->_ID ?? 0),
                    'id_empleado' => $employeeId,
                    'empleado' => $employeeName,
                    'request_id' => $requestId,
                ]
            );
        } else {
            $skipped[] = 'funcionario sin correo válido';
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private function enqueueEmail(string $email, string $name, string $subject, string $html, string $dedupeKey, string $module, array $meta): int
    {
        try {
            $connection = DB::connection('wordpress');
            if ($connection->table('skc_notification_queue')->where('dedupe_key', $dedupeKey)->exists()) {
                return 0;
            }

            $now = now('UTC')->format('Y-m-d H:i:s');
            $connection->table('skc_notification_queue')->insert([
                'project_code' => 'portales-sucasa',
                'source_module' => $module,
                'channel' => 'email',
                'provider' => 'email_smtp',
                'destination' => $email,
                'destination_name' => $name,
                'subject' => mb_substr($subject, 0, 255),
                'message_text' => trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')),
                'message_html' => $html,
                'template_name' => '',
                'template_language' => '',
                'payload_json' => json_encode([
                    'from_name' => 'SUCASA INMOBILIARIA',
                    'from_email' => 'sucasainfo@sucasainmobiliaria.com.co',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'priority' => 100,
                'attempts' => 0,
                'max_attempts' => 3,
                'dedupe_key' => $dedupeKey,
                'scheduled_at' => $now,
                'next_attempt_at' => null,
                'locked_at' => null,
                'locked_by' => null,
                'last_attempt_at' => null,
                'sent_at' => null,
                'last_error' => null,
                'created_by' => 'portales-sucasa',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 1;
        } catch (Throwable $exception) {
            Log::warning('No fue posible encolar un correo de destacado.', [
                'destination' => $email,
                'dedupe_key' => $dedupeKey,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    private function ownerFor(stdClass $property): ?stdClass
    {
        if (! Schema::connection('wordpress')->hasTable('wp_jet_cct_propietarios')) {
            return null;
        }

        $ownerId = trim((string) ($property->id_propietario ?? ''));
        if ($ownerId === '') {
            return null;
        }

        return DB::connection('wordpress')->table('wp_jet_cct_propietarios')->where('id_propietario', $ownerId)->first();
    }

    private function employeeEmail(string $employeeId): string
    {
        if ($employeeId === '' || ! Schema::connection('wordpress')->hasTable('wp_jet_cct_funcionarios')) {
            return '';
        }

        return trim((string) DB::connection('wordpress')
            ->table('wp_jet_cct_funcionarios')
            ->where('id_empleado', $employeeId)
            ->where('activo', 'Si')
            ->value('correo'));
    }

    private function bannerUrl(): string
    {
        try {
            $banner = trim((string) DB::connection('wordpress')
                ->table('wp_jet_cct_confi_sistema')
                ->where('funcion', 'banner')
                ->value('imagen'));
            if ($banner === '' || ! ctype_digit($banner) || ! Schema::connection('wordpress')->hasTable('wp_posts')) {
                return $banner;
            }

            return trim((string) DB::connection('wordpress')->table('wp_posts')->where('ID', (int) $banner)->value('guid'));
        } catch (Throwable) {
            return '';
        }
    }

    private function ownerHtml(string $code, string $owner, string $employee, string $banner): string
    {
        $url = 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-'.rawurlencode($code);

        return $this->emailShell($banner, '<h3>Apreciado/a '.$this->escape($owner).'</h3><p>Su inmueble <strong>'.$this->escape($code).'</strong> ha sido destacado en nuestros portales para lograr mayor visibilidad por nuestro funcionario <strong>'.$this->escape($employee).'</strong>.</p><p><a href="'.$this->escape($url).'" style="background:#404041;color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;display:inline-block">Ver inmueble</a></p>');
    }

    private function employeeHtml(string $code, string $employee, string $market, string $banner): string
    {
        $url = 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-'.rawurlencode($code);

        return $this->emailShell($banner, '<h3>Hola '.$this->escape($employee).'</h3><p>Se confirmó el destacado del inmueble <strong>'.$this->escape($code).'</strong> en <strong>'.$this->escape($market).'</strong>.</p><p><a href="'.$this->escape($url).'" style="background:#404041;color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;display:inline-block">Ver inmueble</a></p>');
    }

    private function emailShell(string $banner, string $body): string
    {
        $image = $banner !== '' ? '<img src="'.$this->escape($banner).'" alt="SUCASA" style="width:100%;height:auto;display:block">' : '';

        return '<!doctype html><html lang="es"><body style="margin:0;background:#f5f5f5;font-family:Arial,sans-serif"><table align="center" width="800" cellpadding="0" cellspacing="0" style="max-width:800px;width:100%;background:#fff;border:3px solid #ebecec"><tr><td>'.$image.'</td></tr><tr><td style="padding:24px;text-align:center;color:#061d49">'.$body.'</td></tr><tr><td style="background:#f59120;color:#fff;text-align:center;font-weight:600;padding:18px">Una empresa para lograr sus sueños.</td></tr></table></body></html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
