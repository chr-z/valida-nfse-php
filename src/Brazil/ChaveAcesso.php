<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Brazil;

/**
 * Chave de acesso da NFS-e (44 digitos) e o digito verificador mod-11.
 *
 * Layout: cUF(2) AAMM(4) CNPJ/CPF(14) modelo(2) serie(3) nNF(9) tpEmis(1)
 * cDV(1) cIDE(1) cNF(8) e o DV final. Aqui validamos tamanho, DV, UF e mes;
 * a coerencia do ano com a data de emissao e regra do check de <Ide>.
 */
final class ChaveAcesso
{
    /**
     * Codigo IBGE da UF, usado nas duas primeiras posicoes da chave.
     *
     * @var array<string, int>
     */
    public const UF_CODIGO = [
        'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17,
        'MA' => 18, 'PI' => 19, 'CE' => 21, 'RN' => 22, 'PB' => 23, 'PE' => 25, 'AL' => 27,
        'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41,
        'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53,
    ];

    /**
     * @return array<int, string>
     */
    public static function codigoParaUf(): array
    {
        $invertido = [];
        foreach (self::UF_CODIGO as $uf => $codigo) {
            $invertido[$codigo] = $uf;
        }

        return $invertido;
    }

    /**
     * Digito verificador mod-11 dos 43 primeiros digitos.
     */
    public static function digitoVerificador(string $chave43): int
    {
        $peso = 2;
        $total = 0;
        for ($i = \strlen($chave43) - 1; $i >= 0; --$i) {
            $total += ((int) $chave43[$i]) * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $total % 11;
        $dv = 11 - $resto;

        return \in_array($dv, [0, 10, 11], true) ? 0 : $dv;
    }

    public static function validar(?string $chave, ?int $anoEsperado = null): bool
    {
        $d = Documentos::somenteDigitos($chave);
        if (preg_match('/^[0-9]{44}$/', $d) !== 1) {
            return false;
        }

        if ((int) $d[43] !== self::digitoVerificador(substr($d, 0, 43))) {
            return false;
        }

        $uf = self::codigoParaUf()[(int) substr($d, 0, 2)] ?? null;
        if ($uf === null) {
            return false;
        }

        $ano = (int) substr($d, 2, 2);
        $mes = (int) substr($d, 4, 2);
        if ($mes < 1 || $mes > 12) {
            return false;
        }

        if ($anoEsperado !== null && $ano !== $anoEsperado % 100) {
            return false;
        }

        return true;
    }

    /**
     * Monta uma chave sintetica com DV correto (somente fixtures e testes).
     */
    public static function gerar(
        string $uf = 'SP',
        string $cnpj = '11222333000181',
        int $serie = 1,
        int $numero = 1,
        int $modelo = 55,
        int $tpEmis = 1,
        int $codigoMunicipio = 3550308,
        int $cnf = 12345678,
        int $ano = 2026,
        int $mes = 1,
        int $cide = 1,
    ): string {
        $codigo = self::UF_CODIGO[strtoupper($uf)] ?? 35;

        $corpo = \sprintf(
            '%02d%02d%02d%s%02d%03d%09d%01d%01d%07d',
            $codigo,
            $ano % 100,
            $mes,
            str_pad(substr(Documentos::somenteDigitos($cnpj), 0, 14), 14, '0', \STR_PAD_LEFT),
            $modelo,
            $serie,
            $numero,
            $tpEmis,
            $cide,
            $cnf % 10_000_000,
        );

        $corpo = str_pad(substr($corpo, 0, 43), 43, '0');

        return $corpo . self::digitoVerificador($corpo);
    }
}
