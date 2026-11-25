<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Brazil\Documentos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentosTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function basesCpf(): array
    {
        return [
            'sequencial' => ['123456789'],
            'decrescente' => ['987654321'],
            'repetitivo' => ['111222333'],
            'com zeros' => ['000111222'],
        ];
    }

    #[DataProvider('basesCpf')]
    public function testCpfGeradoEValido(string $base): void
    {
        $cpf = Documentos::gerarCpf($base);
        self::assertSame(11, \strlen($cpf));
        self::assertTrue(Documentos::validarCpf($cpf), "{$cpf} deveria ser valido");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cpfsInvalidos(): array
    {
        return [
            'zeros' => ['00000000000'],
            'uns' => ['11111111111'],
            'dois' => ['22222222222'],
            'noves' => ['99999999999'],
            'padrao repetido' => ['12312312312'],
            'dv errado' => ['12345678900'],
            'dv errado 2' => ['12345678910'],
            'curto' => ['1234567890'],
            'longo' => ['123456789091'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('cpfsInvalidos')]
    public function testCpfInvalidoERejeitado(string $cpf): void
    {
        self::assertFalse(Documentos::validarCpf($cpf), "{$cpf} deveria ser rejeitado");
    }

    public function testCpfConhecidoEValido(): void
    {
        self::assertTrue(Documentos::validarCpf('12345678909'));
    }

    public function testCpfAceitaMascara(): void
    {
        self::assertTrue(Documentos::validarCpf('123.456.789-09'));
        self::assertTrue(Documentos::validarCpf('123 456 789 09'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function basesCnpj(): array
    {
        return [
            'padrao' => ['112223330001'],
            'outro' => ['998877665501'],
            'um' => ['000000000001'],
        ];
    }

    #[DataProvider('basesCnpj')]
    public function testCnpjGeradoEValido(string $base): void
    {
        $cnpj = Documentos::gerarCnpj($base);
        self::assertSame(14, \strlen($cnpj));
        self::assertTrue(Documentos::validarCnpj($cnpj), "{$cnpj} deveria ser valido");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cnpjsInvalidos(): array
    {
        return [
            'zeros' => ['00000000000000'],
            'uns' => ['11111111111111'],
            'dois' => ['22222222222222'],
            'curto' => ['1122233300018'],
            'longo' => ['112223330001811'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('cnpjsInvalidos')]
    public function testCnpjInvalidoERejeitado(string $cnpj): void
    {
        self::assertFalse(Documentos::validarCnpj($cnpj), "{$cnpj} deveria ser rejeitado");
    }

    public function testCnpjComUltimoDigitoTrocadoFalha(): void
    {
        $cnpj = Documentos::gerarCnpj('112223330001');
        self::assertTrue(Documentos::validarCnpj($cnpj));

        $quebra = substr($cnpj, 0, -1) . (string) (((int) substr($cnpj, -1) + 1) % 10);
        self::assertFalse(Documentos::validarCnpj($quebra));
    }

    public function testSomenteDigitosLimpaFormatacao(): void
    {
        self::assertSame('11222333000181', Documentos::somenteDigitos('11.222.333/0001-81'));
        self::assertSame('', Documentos::somenteDigitos(null));
    }

    public function testCnpjSinteticoDoFixtureEValido(): void
    {
        self::assertTrue(Documentos::validarCnpj(Fixtures::cnpjSintetico()));
        self::assertTrue(Documentos::validarCpf(Fixtures::cpfSintetico()));
    }
}
