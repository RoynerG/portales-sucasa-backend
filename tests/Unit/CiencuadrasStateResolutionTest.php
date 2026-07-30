<?php

namespace Tests\Unit;

use App\Console\Commands\AutoSyncCiencuadras;
use App\Console\Commands\VerifyPendingCiencuadras;
use App\Http\Controllers\Portal\CiencuadrasController;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CiencuadrasStateResolutionTest extends TestCase
{
    public function test_manual_action_trusts_active_property_over_timeout_response(): void
    {
        $method = new ReflectionMethod(CiencuadrasController::class, 'syncState');
        $controller = new CiencuadrasController(
            Mockery::mock(CiencuadrasClient::class),
            Mockery::mock(CiencuadrasPropertyMapper::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
        );

        $this->assertSame('synced', $method->invoke(
            $controller,
            ['ok' => false, 'data' => ['error' => 'Idle timeout reached for update-property']],
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            'A',
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]]
        ));
    }

    public function test_pending_verification_trusts_active_property_over_status_error(): void
    {
        $method = new ReflectionMethod(VerifyPendingCiencuadras::class, 'syncState');

        $this->assertSame('synced', $method->invoke(
            new VerifyPendingCiencuadras(),
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]],
            'pending',
            'A',
            1
        ));
    }

    public function test_auto_sync_trusts_active_property_over_timeout_response(): void
    {
        $method = new ReflectionMethod(AutoSyncCiencuadras::class, 'syncState');

        $this->assertSame('synced', $method->invoke(
            new AutoSyncCiencuadras(),
            ['ok' => false, 'data' => ['error' => 'Idle timeout reached for update-property']],
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            'A',
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]]
        ));
    }
}
