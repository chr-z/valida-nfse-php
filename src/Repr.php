<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse;

/**
 * Representacao de string para mensagens, no estilo do `repr()` do Python.
 *
 * Escolhe a aspa simples quando o texto nao tem aspa simples, e a dupla
 * quando tem (para nao encher a mensagem de escape). Escapa barra invertida,
 * quebra de linha, tabulacao e caracteres de controle, para que a mensagem
 * caiba em uma linha de terminal/CI.
 */
final class Repr
{
    public static function de(string $valor): string
    {
        $aspa = str_contains($valor, "'") && !str_contains($valor, '"') ? '"' : "'";

        $saida = '';
        $len = \strlen($valor);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $valor[$i];

            if ($ch === '\\') {
                $saida .= '\\\\';
            } elseif ($ch === "\n") {
                $saida .= '\\n';
            } elseif ($ch === "\r") {
                $saida .= '\\r';
            } elseif ($ch === "\t") {
                $saida .= '\\t';
            } elseif ($ch === $aspa) {
                $saida .= '\\' . $ch;
            } elseif (\ord($ch) < 32 || \ord($ch) === 127) {
                $saida .= \sprintf('\\x%02x', \ord($ch));
            } else {
                $saida .= $ch;
            }
        }

        return $aspa . $saida . $aspa;
    }
}
