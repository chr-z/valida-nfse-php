<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Brazil;

/**
 * Aritmetica decimal exata para dinheiro.
 *
 * Nunca use float para valor monetario: `0.1 + 0.2 !== 0.3` em binario, e num
 * validador fiscal essa diferenca vira falso positivo. Como o pacote nao tem
 * dependencia de runtime (nem ext-bcmath), o valor e guardado como inteiro
 * escalado em 10^6 (micro-unidades).
 *
 * A escala original do texto (`0` tem escala 0, `1000.00` tem escala 2) e
 * preservada, como no `Decimal` do Python, para que as mensagens mostrem o
 * mesmo numero que o documento trazia. Soma e subtracao usam a maior escala
 * dos operandos.
 *
 * Precisao: 6 casas decimais. Valor com mais casas e arredondado (meio para
 * cima) nessa escala; valores absurdos (mais de 13 digitos inteiros) sao
 * rejeitados como nao numericos em vez de estourar o inteiro.
 */
final class Decimal
{
    public const SCALE = 6;
    private const FACTOR = 1_000_000;
    private const MAX_INTEGER_DIGITS = 13;

    /**
     * @param int $micros valor em micro-unidades (10^-6). `new Decimal(10000)`
     *                    equivale a 0,01.
     * @param int $scale  casas decimais com que o valor deve ser exibido
     */
    public function __construct(
        public readonly int $micros,
        public readonly int $scale = 2,
    ) {
    }

    public static function ofMicros(int $micros, int $scale = 2): self
    {
        return new self($micros, $scale);
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    /**
     * Converte texto monetario em Decimal. `null` quando invalido.
     *
     * Aceita virgula como separador decimal (`1000,00`), igual ao validador de
     * referencia, porque planilha e CSV brasileiro exportam assim. Aceita
     * notacao cientifica (`1e3`), que o `Decimal` do Python tambem aceita.
     */
    public static function fromString(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $texto = str_replace(',', '.', trim($raw));
        if ($texto === '' || $texto === '-' || $texto === '--') {
            return null;
        }

        // Aceita: 1000, 1000.00, 1000., .5, 1e3, -1.5E-2 - a mesma gramatica
        // que o Decimal do Python aceita.
        if (preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/', $texto, $m) !== 1) {
            return null;
        }

        $sinal = $m[1] === '-' ? -1 : 1;
        $inteiro = ($m[2] ?? '') !== '' ? $m[2] : '0';
        $comPonto = ($m[3] ?? '') !== '';
        $fracao = $comPonto ? $m[3] : ($m[4] ?? '');
        $expoente = ($m[5] ?? '') !== '' ? (int) $m[5] : 0;

        // Normaliza o expoente movendo a virgula: 1e3 -> 1000, 1.5e-2 -> 0.015.
        if ($expoente > 0) {
            $mover = min($expoente, \strlen($fracao));
            $inteiro .= substr($fracao, 0, $mover);
            $fracao = substr($fracao, $mover);
            $inteiro .= str_repeat('0', $expoente - $mover);
        } elseif ($expoente < 0) {
            $mover = min(-$expoente, \strlen($inteiro));
            $fracao = str_repeat('0', -$expoente - $mover) . substr($inteiro, \strlen($inteiro) - $mover) . $fracao;
            $inteiro = substr($inteiro, 0, \strlen($inteiro) - $mover);
            if ($inteiro === '') {
                $inteiro = '0';
            }
        }

        if (\strlen(ltrim($inteiro, '0')) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $escala = \strlen($fracao);
        $arredondar = false;

        if ($escala > self::SCALE) {
            $proximo = (int) $fracao[self::SCALE];
            $fracao = substr($fracao, 0, self::SCALE);
            $escala = self::SCALE;
            $arredondar = $proximo >= 5;
        }

        $micros = ((int) $inteiro) * self::FACTOR + (int) str_pad($fracao, self::SCALE, '0');
        if ($arredondar) {
            $micros += 1;
        }

        return new self($sinal * $micros, $escala);
    }

    public function add(self $other): self
    {
        return new self($this->micros + $other->micros, max($this->scale, $other->scale));
    }

    public function sub(self $other): self
    {
        return new self($this->micros - $other->micros, max($this->scale, $other->scale));
    }

    public function abs(): self
    {
        return new self(abs($this->micros), $this->scale);
    }

    public function isZero(): bool
    {
        return $this->micros === 0;
    }

    public function isNegative(): bool
    {
        return $this->micros < 0;
    }

    public function compare(self $other): int
    {
        return $this->micros <=> $other->micros;
    }

    public function greaterThan(self $other): bool
    {
        return $this->micros > $other->micros;
    }

    public function lessThan(self $other): bool
    {
        return $this->micros < $other->micros;
    }

    /**
     * `(valor / base) * 100` com 6 casas de precisao.
     *
     * Usado para comparar a aliquota implicita (vISS / vBC) com a declarada.
     * A divisao e feita em inteiros, com reducao por decimos quando o produto
     * passaria do limite do inteiro - o que so afeta digitos muito alem da
     * quarta casa decimal, irrelevantes para uma tolerancia de 0.01 p.p.
     */
    public static function percentOf(self $value, self $base): self
    {
        if ($base->isZero()) {
            return self::zero();
        }

        $num = $value->micros;
        $den = $base->micros;
        // Queremos micros do resultado: (num/den)*100*1e6 = num*1e8/den.
        $power = 8;

        while ($power > 0 && abs($num) > intdiv(\PHP_INT_MAX, 10 ** $power)) {
            $num = intdiv($num, 10);
            $den = intdiv($den, 10);
            --$power;
            if ($den === 0) {
                return self::zero();
            }
        }

        $produto = $num * (10 ** $power);
        $quociente = intdiv($produto, $den);
        $resto = $produto % $den;

        // Arredonda meio para fora, como um humano faria na conferencia.
        if (abs($resto) * 2 >= abs($den)) {
            $quociente += $produto < 0 ? -1 : 1;
        }

        return new self($quociente, self::SCALE);
    }

    /**
     * Texto com `$decimals` casas, arredondando.
     */
    public function format(int $decimals = 2): string
    {
        $decimals = max(0, min(self::SCALE, $decimals));
        $negativo = $this->micros < 0;
        $absoluto = abs($this->micros);

        $divisor = 10 ** (self::SCALE - $decimals);
        if ($divisor > 1) {
            $escalado = intdiv($absoluto + intdiv($divisor, 2), $divisor);
        } else {
            $escalado = $absoluto;
        }

        if ($decimals === 0) {
            return ($negativo && $escalado !== 0 ? '-' : '') . (string) $escalado;
        }

        $fator = 10 ** $decimals;
        $inteiro = intdiv($escalado, $fator);
        $fracao = str_pad((string) ($escalado % $fator), $decimals, '0', \STR_PAD_LEFT);

        return ($negativo && $escalado !== 0 ? '-' : '') . $inteiro . '.' . $fracao;
    }

    /**
     * Representacao para mensagens: usa a escala do valor original.
     *
     * `Decimal("0")` imprime `0`; `Decimal("1000.00")` imprime `1000.00`. Isso
     * mantem o relatorio fiel ao documento, em vez de inventar casas.
     */
    public function __toString(): string
    {
        return $this->format($this->scale);
    }
}
