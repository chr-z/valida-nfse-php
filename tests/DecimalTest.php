<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Brazil\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function testAceitaPontoEVirgula(): void
    {
        self::assertSame('1000.00', (string) Decimal::fromString('1000.00'));
        self::assertSame('1000.00', (string) Decimal::fromString('1000,00'));
        self::assertSame('1000.00', (string) Decimal::fromString('  1000.00  '));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valoresInvalidos(): array
    {
        return [
            'texto' => ['abc'],
            'vazio' => [''],
            'hifen' => ['-'],
            'hifen duplo' => ['--'],
            'dois pontos' => ['1.234,56'],
            'espaco no meio' => ['10 00'],
        ];
    }

    #[DataProvider('valoresInvalidos')]
    public function testValorInvalidoViraNull(string $valor): void
    {
        self::assertNull(Decimal::fromString($valor));
    }

    public function testNullViraNull(): void
    {
        self::assertNull(Decimal::fromString(null));
    }

    public function testNotacaoCientifica(): void
    {
        $valor = Decimal::fromString('1e3');
        self::assertNotNull($valor);
        self::assertSame('1000', (string) $valor);

        $negativo = Decimal::fromString('-1.5E-2');
        self::assertNotNull($negativo);
        self::assertSame('-0.015', (string) $negativo);
    }

    public function testPontoFinalSemCasas(): void
    {
        // Decimal("1000.e00") e valido no Python e precisa ser valido aqui.
        $valor = Decimal::fromString('1000.e00');
        self::assertNotNull($valor);
        self::assertSame('1000', (string) $valor);
    }

    public function testSomaExataSemFloat(): void
    {
        $a = Decimal::fromString('0.1');
        $b = Decimal::fromString('0.2');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertSame('0.3', (string) $a->add($b));
        self::assertSame('0.30', (string) Decimal::fromString('0.30'));
    }

    public function testSomaPreservaMaiorEscala(): void
    {
        $a = Decimal::fromString('0');
        $b = Decimal::fromString('1000.00');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertSame('1000.00', (string) $a->add($b));
    }

    public function testSubtracao(): void
    {
        $a = Decimal::fromString('1500.00');
        $b = Decimal::fromString('1000.00');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertSame('500.00', (string) $a->sub($b));
        self::assertTrue($a->sub($b)->isZero() === false);
    }

    public function testComparacoes(): void
    {
        $dez = Decimal::fromString('10.00');
        $vinte = Decimal::fromString('20.00');
        self::assertNotNull($dez);
        self::assertNotNull($vinte);

        self::assertTrue($dez->lessThan($vinte));
        self::assertTrue($vinte->greaterThan($dez));
        self::assertSame(0, $dez->compare(Decimal::fromString('10')));
    }

    public function testPercentualImplicito(): void
    {
        $iss = Decimal::fromString('50.00');
        $base = Decimal::fromString('1000.00');
        self::assertNotNull($iss);
        self::assertNotNull($base);

        self::assertSame('5.0000', Decimal::percentOf($iss, $base)->format(4));
    }

    public function testPercentualComBaseZeroNaoEstoura(): void
    {
        $iss = Decimal::fromString('50.00');
        $zero = Decimal::fromString('0.00');
        self::assertNotNull($iss);
        self::assertNotNull($zero);

        self::assertTrue(Decimal::percentOf($iss, $zero)->isZero());
    }

    public function testFormatacaoComCasasFixas(): void
    {
        $valor = Decimal::fromString('2.5');
        self::assertNotNull($valor);
        self::assertSame('2.50', $valor->format(2));
        self::assertSame('2.5000', $valor->format(4));
        self::assertSame('3', $valor->format(0), 'arredonda meio para cima');
    }

    public function testValorAbsurdoERejeitadoEmVezDeEstourar(): void
    {
        self::assertNull(Decimal::fromString(str_repeat('9', 20)));
    }

    public function testZero(): void
    {
        self::assertTrue(Decimal::zero()->isZero());
        self::assertSame('0', (string) Decimal::zero());
    }
}

