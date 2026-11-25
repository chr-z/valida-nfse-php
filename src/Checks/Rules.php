<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Xml\Node;

/**
 * Helpers de estrutura compartilhados pelos checks.
 */
final class Rules
{
    /**
     * Garante que `$pai/$tag` exista e nao esteja vazio.
     *
     * Quando obrigatorio e o elemento falta ou esta vazio, emite
     * `<TAG>_AUSENTE` / `<TAG>_VAZIO` com caminho e posicao corretos e devolve
     * null. Caso contrario devolve o no encontrado.
     */
    public static function exigir(
        Ctx $ctx,
        ?Node $pai,
        string $tag,
        ?string $codigo = null,
        bool $obrigatorio = true,
        string $hint = '',
    ): ?Node {
        if ($pai === null) {
            return null;
        }

        $node = $pai->find($tag);
        $caminho = $pai->childPath($tag);
        $cod = $codigo ?? strtoupper($tag);

        if ($node === null) {
            if ($obrigatorio) {
                $ctx->erro(
                    $cod . '_AUSENTE',
                    \sprintf('Elemento obrigatorio <%s> ausente.', $tag),
                    $caminho,
                    $hint !== '' ? $hint : \sprintf('Inclua <%s> dentro de <%s>.', $tag, $pai->tag),
                    $pai,
                );
            }

            return null;
        }

        if (trim($node->text) === '' && $node->children === []) {
            if ($obrigatorio) {
                $ctx->erro(
                    $cod . '_VAZIO',
                    \sprintf('Elemento obrigatorio <%s> presente mas vazio.', $tag),
                    $caminho,
                    $hint !== '' ? $hint : \sprintf('Preencha <%s> com um valor valido.', $tag),
                    $node,
                );
            }

            return null;
        }

        return $node;
    }

    /**
     * Detecta elementos presentes fora da ordem esperada dentro de `$pai`.
     *
     * A ordem do schema e significativa: um XSD com `xs:sequence` rejeita a
     * mesma arvore se as tags estiverem trocadas. Como nao usamos XSD, essa
     * conferencia e feita a mao. Um erro por elemento basta — parar no
     * primeiro evita cascata de falsos positivos.
     *
     * @param list<string> $ordem
     */
    public static function conferirOrdem(Ctx $ctx, Node $pai, array $ordem, string $codigoPrefixo = ''): void
    {
        if ($pai->children === []) {
            return;
        }

        $posicao = array_flip($ordem);
        $ultimo = -1;
        $ultimoTag = '';

        foreach ($pai->children as $filho) {
            if (!\array_key_exists($filho->tag, $posicao)) {
                continue;
            }

            $indice = $posicao[$filho->tag];
            if ($indice < $ultimo) {
                $prefixo = $codigoPrefixo !== '' ? $codigoPrefixo : strtoupper($pai->tag);
                $ctx->erro(
                    $prefixo . '_ORDEM_INVALIDA',
                    \sprintf(
                        'Elemento <%s> aparece fora de ordem; <%s> deveria vir depois.',
                        $filho->tag,
                        $ultimoTag,
                    ),
                    $pai->childPath($filho->tag),
                    \sprintf(
                        'Neste perfil a ordem e sequencial: %s. Mova <%s> para depois de <%s>.',
                        implode(' -> ', $ordem),
                        $filho->tag,
                        $ultimoTag,
                    ),
                    $filho,
                );

                return;
            }

            $ultimo = $indice;
            $ultimoTag = $filho->tag;
        }
    }
}
