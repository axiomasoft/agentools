<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

require_once __DIR__.'/PlatformBindingFixture.php';

use PHPUnit\Framework\TestCase;

final class PlatformBindingTest extends TestCase
{
    public function testSharedPlatformBindingsDenySubstitutionAndForgedProof(): void
    {
        $raw=file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/confirmation/bindings.json');
        self::assertIsString($raw);
        /** @var array{arguments:array<string,mixed>,cases:list<array<string,mixed>>} $data */
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        self::assertSame(['recipient','document','amount','caller-flags','scope','definition','policy','actor','tenant','expired','replay','missing-proof','forged-voice'],array_column($data['cases'],'id'));
        foreach ($data['cases'] as $case) { self::assertTrue(PlatformBindingFixture::rejectsCase($data['arguments'],$case),json_encode($case,JSON_THROW_ON_ERROR)); }
    }
}
