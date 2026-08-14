<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\FincaraizController;
use App\Http\Controllers\Portal\PortalRecoveryController;
use App\Models\PropertySyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class PortalRecoveryControllerTest extends TestCase
{
    public function test_fincaraiz_recovery_uses_the_action_decided_from_the_remote_listing(): void
    {
        $controller = new class extends PortalRecoveryController
        {
            public function exposedRecover(
                Request $request,
                string $code,
                ?PropertySyncStatus $sync,
                string $mode,
                FincaraizController $fincaraiz
            ): array {
                return $this->recoverFincaraiz($request, $code, $sync, $mode, $fincaraiz);
            }
        };
        $fincaraiz = $this->createMock(FincaraizController::class);
        $fincaraiz->expects($this->once())
            ->method('recover')
            ->willReturn(new JsonResponse(['Datos' => [
                'ok' => true,
                'recovery_action' => 'update_activate',
            ]]));
        $sync = new PropertySyncStatus(['external_id' => '11111111-1111-4111-8111-111111111111']);

        [$action] = $controller->exposedRecover(new Request, '59678', $sync, 'error', $fincaraiz);

        $this->assertSame('update_activate', $action);
    }

    public function test_fincaraiz_recovery_has_a_safe_fallback_action(): void
    {
        $controller = new class extends PortalRecoveryController
        {
            public function exposedRecover(
                Request $request,
                string $code,
                ?PropertySyncStatus $sync,
                string $mode,
                FincaraizController $fincaraiz
            ): array {
                return $this->recoverFincaraiz($request, $code, $sync, $mode, $fincaraiz);
            }
        };
        $fincaraiz = $this->createMock(FincaraizController::class);
        $fincaraiz->expects($this->once())
            ->method('recover')
            ->willReturn(new JsonResponse(['Datos' => ['ok' => true]]));

        [$action] = $controller->exposedRecover(new Request, '59679', null, 'error', $fincaraiz);

        $this->assertSame('recover', $action);
    }
}
