<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Xml;

/**
 * Leitura estrutural leve do documento, independente da versao do expat.
 *
 * Existe por um motivo pratico: a mensagem e o codigo de erro do expat para
 * "tem lixo depois do elemento raiz" mudam entre versoes ("junk after document
 * element" em uma, "Reserved XML Name" em outra quando o lixo comeca com
 * `<?xml`). Se o validador dependesse so do texto do erro, o mesmo arquivo
 * daria codigos diferentes em PHP e em Python.
 *
 * Aqui a pergunta e respondida pelo documento em si: existe elemento raiz? O
 * que sobra depois dele? Isso e estavel em qualquer versao.
 */
final class DocumentScan
{
    public function __construct(
        public readonly bool $temRaiz,
        public readonly ?int $raizInicio = null,
        public readonly ?int $raizFim = null,
        public readonly ?int $extraEm = null,
        /** True quando, fora declaracao/comentarios/PI, nao sobra nada. */
        public readonly bool $soMisc = false,
    ) {
    }

    public static function analisar(string $data): self
    {
        $len = \strlen($data);
        $i = self::pularMisc($data, 0);

        // Nada alem de declaracao/comentario/PI: o documento nao tem raiz.
        if ($i >= $len) {
            return new self(false, null, null, null, true);
        }

        if ($data[$i] !== '<') {
            return new self(false);
        }

        $fim = self::fimDoElemento($data, $i);
        if ($fim === null) {
            // A raiz comecou mas nao fechou: o documento tem raiz (o erro e de
            // well-formedness, nao de ausencia de raiz).
            return new self(true, $i, null, null);
        }

        // Para "sobrou conteudo depois da raiz" interessa o primeiro byte nao
        // branco, mesmo que seja um comentario ou uma declaracao XML.
        $depois = $fim;
        while ($depois < $len && \in_array($data[$depois], [' ', "\t", "\r", "\n"], true)) {
            ++$depois;
        }
        $extra = $depois < $len ? $depois : null;

        return new self(true, $i, $fim, $extra);
    }

    /**
     * Posicao 1-based (linha, coluna) de um offset em bytes.
     *
     * @return array{0: int, 1: int}
     */
    public static function posicao(string $data, int $offset): array
    {
        $offset = max(0, min($offset, \strlen($data)));
        $antes = substr($data, 0, $offset);
        $linha = substr_count($antes, "\n") + 1;
        $ultimaQuebra = strrpos($antes, "\n");
        $coluna = $ultimaQuebra === false ? $offset + 1 : $offset - $ultimaQuebra;

        return [$linha, $coluna];
    }

    /**
     * Avanca por espaco em branco, comentarios, processing instructions e
     * doctype. Devolve o offset do proximo conteudo relevante.
     */
    private static function pularMisc(string $data, int $i): int
    {
        $len = \strlen($data);

        while ($i < $len) {
            $ch = $data[$i];

            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                ++$i;
                continue;
            }

            if (substr($data, $i, 4) === '<!--') {
                $fim = strpos($data, '-->', $i + 4);
                $i = $fim === false ? $len : $fim + 3;
                continue;
            }

            if (substr($data, $i, 2) === '<?') {
                $fim = strpos($data, '?>', $i + 2);
                $i = $fim === false ? $len : $fim + 2;
                continue;
            }

            if (substr($data, $i, 9) === '<![CDATA[') {
                $fim = strpos($data, ']]>', $i + 9);
                $i = $fim === false ? $len : $fim + 3;
                continue;
            }

            if (substr($data, $i, 2) === '<!') {
                $fim = self::fimDaTag($data, $i);
                $i = $fim === null ? $len : $fim + 1;
                continue;
            }

            break;
        }

        return $i;
    }

    /**
     * Devolve o offset logo apos o fechamento do elemento que comeca em `$i`.
     */
    private static function fimDoElemento(string $data, int $i): ?int
    {
        $len = \strlen($data);
        $profundidade = 0;

        while ($i < $len) {
            $abre = strpos($data, '<', $i);
            if ($abre === false) {
                return null;
            }

            if (substr($data, $abre, 4) === '<!--') {
                $fim = strpos($data, '-->', $abre + 4);
                if ($fim === false) {
                    return null;
                }
                $i = $fim + 3;
                continue;
            }

            if (substr($data, $abre, 9) === '<![CDATA[') {
                $fim = strpos($data, ']]>', $abre + 9);
                if ($fim === false) {
                    return null;
                }
                $i = $fim + 3;
                continue;
            }

            if (substr($data, $abre, 2) === '<?') {
                $fim = strpos($data, '?>', $abre + 2);
                if ($fim === false) {
                    return null;
                }
                $i = $fim + 2;
                continue;
            }

            if (substr($data, $abre, 2) === '<!') {
                $fim = self::fimDaTag($data, $abre);
                if ($fim === null) {
                    return null;
                }
                $i = $fim + 1;
                continue;
            }

            $fim = self::fimDaTag($data, $abre);
            if ($fim === null) {
                return null;
            }

            $autoFechado = $data[$fim - 1] === '/';
            $fechando = $data[$abre + 1] === '/';

            if ($fechando) {
                --$profundidade;
            } elseif (!$autoFechado) {
                ++$profundidade;
            }

            $i = $fim + 1;

            if ($profundidade <= 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Offset do '>' que fecha a tag iniciada em `$abre`, respeitando aspas.
     */
    private static function fimDaTag(string $data, int $abre): ?int
    {
        $len = \strlen($data);
        $i = $abre + 1;
        $aspas = null;

        while ($i < $len) {
            $ch = $data[$i];
            if ($aspas !== null) {
                if ($ch === $aspas) {
                    $aspas = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $aspas = $ch;
            } elseif ($ch === '>') {
                return $i;
            }
            ++$i;
        }

        return null;
    }
}
