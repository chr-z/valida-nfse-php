<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Brazil;

/**
 * Formatos brasileiros: UF, CEP, telefone, e-mail e codigo IBGE de municipio.
 */
final class Formato
{
    /**
     * @var list<string>
     */
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public static function ufValida(?string $uf): bool
    {
        return \in_array(strtoupper(trim($uf ?? '')), self::UFS, true);
    }

    /**
     * CEP tem 8 digitos. Nao validamos faixa de regiao (CEP e sequencial).
     *
     * Diferente de CPF/CNPJ, aqui nao aceitamos formatacao: espaco no meio
     * normalmente indica montagem errada da string, e o provedor rejeita.
     * Espaco nas pontas e tolerado.
     */
    public static function cepValido(?string $cep): bool
    {
        $texto = trim($cep ?? '');
        if (str_contains($texto, ' ')) {
            return false;
        }

        return preg_match('/^\d{8}$/', $texto) === 1;
    }

    /**
     * Telefone: 10 digitos (fixo) ou 11 (celular, com o 9 apos o DDD).
     */
    public static function foneValido(?string $fone): bool
    {
        $d = Documentos::somenteDigitos($fone);
        if (preg_match('/^\d{10,11}$/', $d) !== 1) {
            return false;
        }

        if (\strlen($d) === 11) {
            return $d[2] === '9';
        }

        return true;
    }

    public static function emailValido(?string $email): bool
    {
        $e = trim($email ?? '');
        if ($e === '' || \strlen($e) > 254 || str_contains($e, '..')) {
            return false;
        }

        return preg_match('/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/', $e) === 1;
    }

    /**
     * Codigo IBGE de municipio: 7 digitos, primeiro digito de 1 a 5.
     */
    public static function ibgeMunicipioValido(?string $codigo): bool
    {
        $d = Documentos::somenteDigitos($codigo);
        if (preg_match('/^\d{7}$/', $d) !== 1) {
            return false;
        }

        return \in_array($d[0], ['1', '2', '3', '4', '5'], true);
    }
}
