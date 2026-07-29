<?php

namespace Tests\Unit;

use App\Services\Portals\CiencuadrasPropertyMapper;
use ReflectionMethod;
use Tests\TestCase;

class CiencuadrasContactTest extends TestCase
{
    public function test_it_uses_the_same_configured_contact_for_every_property(): void
    {
        config()->set('portals.ciencuadras.contact_name', 'Contacto Central');
        config()->set('portals.ciencuadras.contact_phone', '300 123 4567');
        config()->set('portals.ciencuadras.contact_email', 'contacto@example.test');
        config()->set('portals.ciencuadras.contact_whatsapp', '');

        $method = new ReflectionMethod(CiencuadrasPropertyMapper::class, 'advisorContact');
        $contact = $method->invoke(app(CiencuadrasPropertyMapper::class));

        $this->assertSame([
            'name' => 'Contacto Central',
            'phone' => '+573001234567',
            'email' => 'contacto@example.test',
            'whatsapp' => '+573001234567',
        ], $contact);
    }

    public function test_it_rejects_an_invalid_global_contact_email(): void
    {
        config()->set('portals.ciencuadras.contact_email', 'correo-invalido');

        $method = new ReflectionMethod(CiencuadrasPropertyMapper::class, 'advisorContact');
        $contact = $method->invoke(app(CiencuadrasPropertyMapper::class));

        $this->assertNull($contact['email']);
    }
}
