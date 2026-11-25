<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Brazil;

/**
 * CPF e CNPJ: normalizacao e digitos verificadores.
 *
 * Nada de dados reais: os geradores existem para fixtures e testes. Os
 * validadores recusam sequencias repetidas (000..., 111...), que passam no
 * calculo mod-11 mas nao sao documentos validos.
 */
final class Documentos
{
    /** @var list<array{5,4,3,2,9,8,7,6,5,4,3,2}>|array<int, array<int, int>> */
    private const PESOS_CNPJ = [
        [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
    ];

    public static function somenteDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', $valor ?? '') ?? '';
    }

    private static function todosIguais(string $digitos): bool
    {
        return $digitos !== '' && strspn($digitos, $digitos[0]) === \strlen($digitos);
    }

    /**
     * @param list<int> $base
     */
    private static function dvCpf(array $base, int $pesoInicial): int
    {
        $soma = 0;
        foreach ($base as $i => $digito) {
            $soma += $digito * ($pesoInicial - $i);
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /**
     * @param list<int>      $base
     * @param array<int,int> $pesos
     */
    private static function dvCnpj(array $base, array $pesos): int
    {
        $soma = 0;
        foreach ($base as $i => $digito) {
            $soma += $digito * $pesos[$i];
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /**
     * @return list<int>
     */
    private static function digitosDe(string $valor, int $limite): array
    {
        $out = [];
        foreach (str_split(substr(self::somenteDigitos($valor), 0, $limite)) as $ch) {
            $out[] = (int) $ch;
        }

        return $out;
    }

    public static function validarCpf(?string $cpf): bool
    {
        $d = self::somenteDigitos($cpf);
        if (preg_match('/^\d{11}$/', $d) !== 1 || self::todosIguais($d)) {
            return false;
        }

        $base = self::digitosDe($d, 9);
        $d1 = self::dvCpf($base, 10);
        $d2 = self::dvCpf([...$base, $d1], 11);

        return (int) $d[9] === $d1 && (int) $d[10] === $d2;
    }

    public static function validarCnpj(?string $cnpj): bool
    {
        $d = self::somenteDigitos($cnpj);
        if (preg_match('/^\d{14}$/', $d) !== 1 || self::todosIguais($d)) {
            return false;
        }

        $base = self::digitosDe($d, 12);
        $d1 = self::dvCnpj($base, self::PESOS_CNPJ[0]);
        $d2 = self::dvCnpj([...$base, $d1], self::PESOS_CNPJ[1]);

        return (int) $d[12] === $d1 && (int) $d[13] === $d2;
    }

    /**
     * Gera um CPF sintetico valido (somente fixtures e testes).
     */
    public static function gerarCpf(?string $base = null): string
    {
        $digitos = substr(self::somenteDigitos($base ?? '123456789'), 0, 9);
        $digitos = str_pad($digitos, 9, '1');
        $nums = self::digitosDe($digitos, 9);

        $d1 = self::dvCpf($nums, 10);
        $d2 = self::dvCpf([...$nums, $d1], 11);

        return implode('', $nums) . $d1 . $d2;
    }

    /**
     * Gera um CNPJ sintetico valido (somente fixtures e testes).
     */
    public static function gerarCnpj(?string $base = null): string
    {
        $digitos = substr(self::somenteDigitos($base ?? '112223330001'), 0, 12);
        $digitos = str_pad($digitos, 12, '0');
        $nums = self::digitosDe($digitos, 12);

        $d1 = self::dvCnpj($nums, self::PESOS_CNPJ[0]);
        $d2 = self::dvCnpj([...$nums, $d1], self::PESOS_CNPJ[1]);

        return implode('', $nums) . $d1 . $d2;
    }
}
