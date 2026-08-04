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
    public function test_fincaraiz_missing_listing_with_a_reference_is_activated(): void
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
            ->method('activate')
            ->willReturn(new JsonResponse(['Datos' => ['ok' => true]]));
        $sync = new PropertySyncStatus(['external_id' => '11111111-1111-4111-8111-111111111111']);

        [$action] = $controller->exposedRecover(new Request(), '59678', $sync, 'missing', $fincaraiz);

        $this->assertSame('activate', $action);
    }

    public function test_fincaraiz_error_uses_update_when_linked_and_publish_when_unlinked(): void
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
            ->method('update')
            ->willReturn(new JsonResponse(['Datos' => ['ok' => true]]));
        $fincaraiz->expects($this->once())
            ->method('publish')
            ->willReturn(new JsonResponse(['Datos' => ['ok' => true]]));
        $linked = new PropertySyncStatus(['external_id' => '11111111-1111-4111-8111-111111111111']);

        [$linkedAction] = $controller->exposedRecover(new Request(), '59678', $linked, 'error', $fincaraiz);
        [$unlinkedAction] = $controller->exposedRecover(new Request(), '59679', null, 'error', $fincaraiz);

        $this->assertSame('update', $linkedAction);
        $this->assertSame('publish', $unlinkedAction);
    }
}
