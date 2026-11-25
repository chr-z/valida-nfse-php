<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Brazil\ChaveAcesso;
use PHPUnit\Framework\TestCase;

final class ChaveAcessoTest extends TestCase
{
    public function testChaveGeradaEValida(): void
    {
        $chave = ChaveAcesso::gerar();
        self::assertSame(44, \strlen($chave));
        self::assertTrue(ChaveAcesso::validar($chave), $chave);
    }

    public function testDvIncorretoERejeitado(): void
    {
        $chave = ChaveAcesso::gerar();
        $quebra = substr($chave, 0, -1) . (string) (((int) substr($chave, -1) + 1) % 10);
        self::assertFalse(ChaveAcesso::validar($quebra));
    }

    public function testTamanhoErradoERejeitado(): void
    {
        self::assertFalse(ChaveAcesso::validar(str_repeat('1', 43)));
        self::assertFalse(ChaveAcesso::validar(str_repeat('1', 45)));
        self::assertFalse(ChaveAcesso::validar(''));
    }

    public function testUfInexistenteERejeitada(): void
    {
        $chave = ChaveAcesso::gerar();
        $corpo = '00' . substr($chave, 2);
        $nova = substr($corpo, 0, 43) . ChaveAcesso::digitoVerificador(substr($corpo, 0, 43));
        self::assertFalse(ChaveAcesso::validar($nova));
    }

    public function testMesInvalidoERejeitado(): void
    {
        $chave = ChaveAcesso::gerar();
        $corpo = substr($chave, 0, 4) . '99' . substr($chave, 6);
        $nova = substr($corpo, 0, 43) . ChaveAcesso::digitoVerificador(substr($corpo, 0, 43));
        self::assertFalse(ChaveAcesso::validar($nova));
    }

    public function testAnoEsperado(): void
    {
        $chave = ChaveAcesso::gerar(ano: 2026, mes: 1);
        self::assertTrue(ChaveAcesso::validar($chave, 2026));
        self::assertFalse(ChaveAcesso::validar($chave, 2025));
    }

    public function testDigitoVerificadorMod11(): void
    {
        // Chave sintetica conhecida: o DV e o ultimo digito calculado dos 43.
        $corpo = str_repeat('1', 43);
        $dv = ChaveAcesso::digitoVerificador($corpo);
        self::assertGreaterThanOrEqual(0, $dv);
        self::assertLessThanOrEqual(9, $dv);
        self::assertTrue(ChaveAcesso::validar($corpo . $dv));
    }

    public function testMapaDeUfCobreAs27(): void
    {
        self::assertCount(27, ChaveAcesso::UF_CODIGO);
        self::assertSame(35, ChaveAcesso::UF_CODIGO['SP']);
        self::assertSame(53, ChaveAcesso::UF_CODIGO['DF']);
        self::assertCount(27, ChaveAcesso::codigoParaUf());
    }
}
