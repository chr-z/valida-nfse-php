<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Repr;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Verificacoes de `<Total>` e reconciliacao aritmetica com os itens.
 *
 * Toda comparacao usa Decimal (inteiro escalado), nunca float. A tolerancia
 * padrao e de um centavo: exatamente 0,01 passa, 0,02 falha.
 */
final class TotaisCheck
{
    /** @var list<string> */
    public const ORDEM_TOTAL_ISS = [
        'vBC', 'vServicos', 'vServicosNaoIncididos', 'vISS', 'vPIS', 'vCOFINS', 'dCompet',
    ];

    /** @var list<string> */
    public const ORDEM_TOTAL_ISS_RETIDO = [
        'vBC', 'vServicos', 'vServico', 'vProd',
        'vDescIncond', 'vDescCond', 'vDeducao',
        'vISS', 'vISSRetido', 'vPIS', 'vCOFINS', 'vCPP', 'vCSLL', 'vIR', 'vINSS',
        'vOutro', 'vRet', 'vTotTrib', 'vTotTribFed', 'vTotTribMun',
        'vLiq', 'vNF', 'cRegTrib', 'indIncentivo',
    ];

    /**
     * @param list<Node> $itens
     */
    public static function validarTotais(Ctx $ctx, ?Node $infnfe, array $itens): ?Node
    {
        if (!$infnfe instanceof Node) {
            return null;
        }

        $total = $infnfe->find('Total');
        if (!$total instanceof Node) {
            $ctx->erro(
                Codes::TOTAL_AUSENTE,
                'Elemento <Total> ausente dentro de <InfNFe>.',
                $infnfe->childPath('Total'),
                'Todo InfNFe precisa de <Total> com os valores consolidados.',
                $infnfe,
            );

            return null;
        }

        if ($total->isBlank()) {
            $ctx->erro(
                Codes::TOTAL_VAZIO,
                'Elemento <Total> presente mas vazio.',
                $total->path(),
                'Preencha <Total> com <ISS> e <ISSRetido>.',
                $total,
            );

            return null;
        }

        $iss = $total->find('ISS');
        if (!$iss instanceof Node) {
            $ctx->erro(
                Codes::TOTAL_ISS_TRIBUTARIO_AUSENTE,
                'Elemento <ISS> ausente dentro de <Total>.',
                $total->childPath('ISS'),
                'Inclua <ISS><vServicos> e <vISS>.',
                $total,
            );
        } else {
            Rules::conferirOrdem($ctx, $iss, self::ORDEM_TOTAL_ISS, 'TOTAL');
            foreach (['vServicos', 'vISS'] as $tag) {
                if ($iss->isEmpty($tag)) {
                    $ctx->erro(
                        Codes::TOTAL_DECIMAL_INVALIDO,
                        \sprintf('Campo <%s> ausente ou vazio em <Total><ISS>.', $tag),
                        $iss->childPath($tag),
                        \sprintf('Preencha <%s> com o valor total.', $tag),
                        $iss,
                    );
                }
            }
        }

        $issRetido = $total->find('ISSRetido');
        if (!$issRetido instanceof Node) {
            $ctx->erro(
                Codes::TOTAL_ISS_RECOLHIDO_AUSENTE,
                'Elemento <ISSRetido> ausente dentro de <Total>.',
                $total->childPath('ISSRetido'),
                'Inclua <ISSRetido>. Se o ISS nao foi retido, envie <vISSRetido>0.00.',
                $total,
            );
        } else {
            Rules::conferirOrdem($ctx, $issRetido, self::ORDEM_TOTAL_ISS_RETIDO, 'TOTAL');
        }

        return $total;
    }

    /**
     * Confere se os totais batem com a soma dos itens.
     *
     * @param list<Node> $itens
     */
    public static function reconciliar(Ctx $ctx, ?Node $infnfe, array $itens, ?Node $total): void
    {
        if (!$infnfe instanceof Node || !$total instanceof Node) {
            return;
        }

        $iss = $total->find('ISS') ?? $total;
        $issRetido = $total->find('ISSRetido') ?? $total;

        $somaVServico = self::soma($ctx, $itens, ['vServ', 'vServico', 'vProd']);
        $somaVBc = self::soma($ctx, $itens, ['vBC']);
        $somaVIss = self::soma($ctx, $itens, ['vISS']);
        $somaVTotTrib = self::soma($ctx, $itens, ['vTotTrib']);

        // 1. vServicos == soma dos itens
        $declarado = self::valor($ctx, $iss, 'vServicos');
        if ($declarado !== null && $somaVServico !== null) {
            self::comparar($ctx, $declarado, $somaVServico, 'vServicos', Codes::SOMA_VSERVICO_DIVERGENTE, $iss, 'Soma dos valores dos itens de servico.');
        }

        // 2. vBC == soma das bases
        $declarado = self::valor($ctx, $iss, 'vBC');
        if ($declarado !== null && $somaVBc !== null) {
            self::comparar($ctx, $declarado, $somaVBc, 'vBC', Codes::SOMA_VBC_DIVERGENTE, $iss, 'Soma das bases de calculo dos itens.');
        }

        // 3. vISS == soma dos ISS dos itens
        $declarado = self::valor($ctx, $iss, 'vISS');
        if ($declarado !== null && $somaVIss !== null) {
            self::comparar($ctx, $declarado, $somaVIss, 'vISS', Codes::SOMA_VISS_DIVERGENTE, $iss, 'Soma dos valores de ISS dos itens.');
        }

        // 4. vTotTrib
        $declarado = self::valor($ctx, $issRetido, 'vTotTrib');
        if ($declarado !== null && $somaVTotTrib !== null) {
            self::comparar($ctx, $declarado, $somaVTotTrib, 'vTotTrib', Codes::SOMA_VTOTTRIB_DIVERGENTE, $issRetido, 'Soma dos tributos sobre servicos (LC 116).');
        }

        // 5. Liquido: base - vDescIncond - vDeducao == vLiq
        $baseLiquido = self::valor($ctx, $issRetido, 'vServico');
        if ($baseLiquido === null || $baseLiquido->isZero()) {
            $baseLiquido = self::valor($ctx, $issRetido, 'vServicos');
        }
        if ($baseLiquido === null || $baseLiquido->isZero()) {
            $baseLiquido = self::valor($ctx, $iss, 'vServicos');
        }

        if ($baseLiquido !== null) {
            $desconto = self::valor($ctx, $issRetido, 'vDescIncond');
            if ($desconto === null || $desconto->isZero()) {
                $desconto = Decimal::zero();
            }
            $deducao = self::valor($ctx, $issRetido, 'vDeducao');
            if ($deducao === null || $deducao->isZero()) {
                $deducao = Decimal::zero();
            }

            $esperado = $baseLiquido->sub($desconto)->sub($deducao);
            $liquido = self::valor($ctx, $issRetido, 'vLiq');
            if ($liquido !== null) {
                self::comparar(
                    $ctx,
                    $liquido,
                    $esperado,
                    'vLiq',
                    Codes::LIQUIDO_NAO_CONFERE,
                    $issRetido,
                    'Valor liquido = base de calculo menos vDescIncond menos vDeducao.',
                );
            }
        }

        // 6. ISS retido so e comparado quando ha valor a reter.
        $declaradoRetido = self::valor($ctx, $issRetido, 'vISSRetido');
        if ($declaradoRetido !== null && $somaVIss !== null && !$declaradoRetido->isZero()) {
            self::comparar(
                $ctx,
                $declaradoRetido,
                $somaVIss,
                'vISSRetido',
                Codes::TOTAL_ISS_RECOLHIDO_DIVERGENTE,
                $issRetido,
                'Total de ISS retido na fonte.',
            );
        }

        // 8. Sem itens declarados, os totais precisam ser zero.
        if ($itens === []) {
            self::exigirZero($ctx, $iss, 'vServicos', $somaVServico);
            self::exigirZero($ctx, $iss, 'vISS', $somaVIss);
            self::exigirZero($ctx, $iss, 'vBC', $somaVBc);

            $ctx->erro(
                Codes::TOTAL_SEM_ITENS,
                'Nenhum item de servico declarado, mas ha totais consolidados no XML.',
                $total->path(),
                'Uma NFS-e sem itens so faz sentido com tudo zerado. Confira se a geracao de <ListaServico> falhou.',
                $total,
            );
        }
    }

    /**
     * Le um valor monetario de `$parent/$tag`, reportando lixo numerico.
     */
    private static function valor(Ctx $ctx, Node $parent, string $tag): ?Decimal
    {
        $bruto = $parent->value($tag);
        if ($bruto === '') {
            return null;
        }

        $valor = Decimal::fromString($bruto);
        if ($valor === null) {
            $ctx->erro(
                Codes::TOTAL_DECIMAL_INVALIDO,
                \sprintf('Valor nao numerico em <%s>: %s.', $tag, Repr::de($bruto)),
                $parent->childPath($tag),
                'Valores monetarios usam ponto decimal, ex.: 1500.00.',
                $parent->find($tag),
            );
        }

        return $valor;
    }

    /**
     * @param list<Node>   $itens
     * @param list<string> $tags
     */
    private static function soma(Ctx $ctx, array $itens, array $tags): ?Decimal
    {
        $total = Decimal::zero();
        $achou = false;

        foreach ($itens as $item) {
            foreach ($tags as $tag) {
                $bruto = $item->value($tag);
                if ($bruto === '') {
                    continue;
                }

                $valor = Decimal::fromString($bruto);
                if ($valor === null) {
                    $ctx->erro(
                        Codes::VALOR_NAO_NUMERICO,
                        \sprintf('Valor nao numerico em <%s>: %s.', $tag, Repr::de($bruto)),
                        $item->childPath($tag),
                        'Valores monetarios usam ponto decimal, ex.: 1500.00.',
                        $item->find($tag),
                    );
                    continue;
                }

                $total = $total->add($valor);
                $achou = true;
                break;
            }
        }

        return $achou ? $total : null;
    }

    private static function comparar(
        Ctx $ctx,
        Decimal $declarado,
        Decimal $esperado,
        string $campo,
        string $codigo,
        Node $node,
        string $oQueE,
    ): void {
        $diff = $declarado->sub($esperado);
        if ($diff->abs()->compare($ctx->tolerancia) <= 0) {
            return;
        }

        $ctx->erro(
            $codigo,
            \sprintf(
                '<%s> declarado (%s) difere da soma (%s). Diferenca de %s.',
                $campo,
                (string) $declarado,
                (string) $esperado,
                (string) $diff,
            ),
            $node->childPath($campo),
            \sprintf('%s A diferenca esta acima da tolerancia de %s (centavo).', $oQueE, (string) $ctx->tolerancia),
            $node->find($campo),
        );
    }

    private static function exigirZero(Ctx $ctx, Node $node, string $rotulo, ?Decimal $soma): void
    {
        $bruto = $node->value($rotulo);
        if ($bruto === '') {
            return;
        }

        $valor = Decimal::fromString($bruto);
        if ($valor === null || $valor->isZero()) {
            return;
        }

        $ctx->erro(
            Codes::TOTAL_SEM_ITENS_NAO_ZERO,
            \sprintf('<%s> e %s mas <ListaServico> esta sem itens: totais deveriam ser zero.', $rotulo, (string) $valor),
            $node->childPath($rotulo),
            'Sem itens nao ha valor a consolidar. Zere o total ou corrija a lista de servicos.',
            $node->find($rotulo),
        );
    }
}
