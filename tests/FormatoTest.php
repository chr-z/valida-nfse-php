<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Brazil\Formato;
use PHPUnit\Framework\TestCase;

final class FormatoTest extends TestCase
{
    public function testUf(): void
    {
        foreach (['SP', 'RJ', 'MG', 'DF', 'AC', 'TO'] as $uf) {
            self::assertTrue(Formato::ufValida($uf), $uf);
        }
        foreach (['XX', 'ZZ', 'S', 'SPP', '1', '', 'spp'] as $uf) {
            self::assertFalse(Formato::ufValida($uf), $uf);
        }
        self::assertTrue(Formato::ufValida('sp'));
    }

    public function testCep(): void
    {
        self::assertTrue(Formato::cepValido('01001000'));
        self::assertFalse(Formato::cepValido('0100100'));
        self::assertFalse(Formato::cepValido('01001 000'));
        self::assertTrue(Formato::cepValido('  01001000  '));
    }

    public function testFone(): void
    {
        self::assertTrue(Formato::foneValido('1133334444'));
        self::assertTrue(Formato::foneValido('11987654321'));
        self::assertFalse(Formato::foneValido('123'));
        self::assertFalse(Formato::foneValido('119876543210'));
        // 11 digitos sem o 9 na posicao do celular nao passa.
        self::assertFalse(Formato::foneValido('11887654321'));
    }

    public function testEmail(): void
    {
        self::assertTrue(Formato::emailValido('contato@empresa.com.br'));
        self::assertFalse(Formato::emailValido('contato@empresa'));
        self::assertFalse(Formato::emailValido('contatoempresa.com'));
        self::assertFalse(Formato::emailValido(''));
        self::assertFalse(Formato::emailValido('a@@b.com'));
        self::assertFalse(Formato::emailValido('a..b@c.com'));
    }

    public function testIbge(): void
    {
        self::assertTrue(Formato::ibgeMunicipioValido('3550308'));
        self::assertTrue(Formato::ibgeMunicipioValido('5300108'));
        self::assertFalse(Formato::ibgeMunicipioValido('355030'));
        self::assertFalse(Formato::ibgeMunicipioValido('9990308'));
    }
}
