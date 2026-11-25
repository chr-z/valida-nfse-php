<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Repr;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Verificacoes de `<Servico>` / `<ListaServico>`.
 */
final class ServicoCheck
{
    /**
     * Ordem do item de servico (perfil nacional ABRASF, tcDeclaracaoServico).
     *
     * Em `<Servico>` o mesmo nome pode reaparecer em grupos distintos; a ordem
     * aqui cobre a sequencia mais comum (codTributacao -> xServ -> valores -> ISS).
     *
     * @var list<string>
     */
    public const ORDEM_SERVICO = [
        'codTributacao', 'codServico', 'cCodTributacao', 'cServico',
        'xServ', 'xServico', 'tributacao',
        'codMun', 'cMun', 'cMunFG', 'xMun',
        'vServ', 'vServico', 'vProd', 'qUnid', 'vAliq', 'pAliq',
        'vBC', 'vBcd', 'vBCPIS', 'vBCCOFINS', 'vBCPrev',
        'aliq', 'aliqServico', 'pISS',
        'vISS', 'vISSRetido', 'vOutro',
        'vPIS', 'vCOFINS', 'vINSS', 'vIR', 'vCSLL',
        'vTotTrib', 'vTotTribFed', 'vTotTribMun',
        'vDesc', 'vDescIncond', 'vDescCond', 'vDeducao',
        'indISS', 'retISS', 'indISSRetido', 'issRetido',
        'indIncentivo', 'cBeneficio', 'cServicoRef',
        'xListServ', 'cListServ',
    ];

    /**
     * Valida a estrutura do servico e devolve os itens encontrados.
     *
     * @return list<Node>
     */
    public static function validar(Ctx $ctx, ?Node $infnfe): array
    {
        if (!$infnfe instanceof Node) {
            return [];
        }

        $servicoPai = $infnfe->find('Servico');
        if (!$servicoPai instanceof Node) {
            $ctx->erro(
                Codes::SERVICO_AUSENTE,
                'Elemento <Servico> ausente dentro de <InfNFe>.',
                $infnfe->childPath('Servico'),
                'Todo InfNFe precisa de <Servico> descrevendo o servico prestado.',
                $infnfe,
            );

            return [];
        }

        if ($servicoPai->isBlank()) {
            $ctx->erro(
                Codes::SERVICO_VAZIO,
                'Elemento <Servico> presente mas vazio.',
                $servicoPai->path(),
                'Preencha <Servico> com <ListaServico>.',
                $servicoPai,
            );

            return [];
        }

        $lista = $servicoPai->find('ListaServico');
        if (!$lista instanceof Node) {
            $ctx->erro(
                Codes::LISTA_SERVICO_AUSENTE,
                'Elemento <ListaServico> ausente dentro de <Servico>.',
                $servicoPai->childPath('ListaServico'),
                'Inclua <ListaServico> com ao menos um <Servico>.',
                $servicoPai,
            );

            return [];
        }

        if ($lista->children === []) {
            $ctx->erro(
                Codes::LISTA_SERVICO_VAZIA,
                '<ListaServico> sem nenhum item <Servico>.',
                $lista->path(),
                'Adicione ao menos um <Servico> com codigo e valor.',
                $lista,
            );

            return [];
        }

        $itens = $lista->findAll('Servico');
        $cMunServico = $servicoPai->value('cMun');

        $indice = 1;
        foreach ($itens as $item) {
            self::validarItem($ctx, $item, $indice, $cMunServico);
            ++$indice;
        }

        return $itens;
    }

    private static function validarItem(Ctx $ctx, Node $item, int $idx, string $cMunServico = ''): void
    {
        Rules::conferirOrdem($ctx, $item, self::ORDEM_SERVICO, 'ITEM_SERVICO');

        $codigo = $item->value('codTributacao');
        if ($codigo === '') {
            $codigo = $item->value('codServico');
        }
        if ($codigo === '') {
            $codigo = $item->value('cServico');
        }
        if ($codigo === '') {
            $codigo = $item->value('cCodTributacao');
        }

        if ($codigo === '') {
            $ctx->erro(
                Codes::ITEM_SERVICO_SEM_CODIGO,
                \sprintf('Item %d de <ListaServico> sem codigo de tributacao.', $idx),
                $item->childPath('codTributacao'),
                'Inclua <codTributacao> (ou <codServico>) com o codigo da LC 116/2003.',
                $item,
            );
        } elseif (preg_match('/\d/', $codigo) !== 1) {
            $ctx->erro(
                Codes::ITEM_SERVICO_CODIGO_INVALIDO,
                \sprintf('Codigo de servico do item %d sem digitos: %s.', $idx, Repr::de($codigo)),
                $item->path(),
                'Codigos da LC 116 sao numericos, como 01.07, 14.01 ou 17.01.',
                $item,
            );
        }

        $vRaw = $item->value('vServ');
        if ($vRaw === '') {
            $vRaw = $item->value('vServico');
        }
        if ($vRaw === '') {
            $vRaw = $item->value('vProd');
        }

        if ($vRaw === '') {
            $ctx->erro(
                Codes::ITEM_SERVICO_SEM_VALOR,
                \sprintf('Item %d de <ListaServico> sem valor (<vServ> vazio).', $idx),
                $item->childPath('vServ'),
                'Informe o valor do servico em <vServ>, com ponto decimal.',
                $item,
            );
        } else {
            $valor = Decimal::fromString($vRaw);
            if ($valor === null) {
                $ctx->erro(
                    Codes::VALOR_NAO_NUMERICO,
                    \sprintf('Valor nao numerico em <vServ> do item %d: %s.', $idx, Repr::de($vRaw)),
                    $item->childPath('vServ'),
                    'Valores monetarios usam ponto decimal e dois digitos, ex.: 1500.00.',
                    $item->find('vServ'),
                );
            } elseif ($valor->isNegative()) {
                $ctx->erro(
                    Codes::VALOR_NEGATIVO,
                    \sprintf('Valor negativo em <vServ> do item %d: %s.', $idx, Repr::de($vRaw)),
                    $item->childPath('vServ'),
                    'Valor de servico nao pode ser negativo. Use <vDesc> para abatimentos.',
                    $item->find('vServ'),
                );
            }
        }

        if ($item->value('vBC') === '') {
            if ($item->value('codTributacao') !== '' || $item->value('vServ') !== '' || $item->value('vServico') !== '') {
                $ctx->erro(
                    Codes::ITEM_SERVICO_SEM_BC,
                    \sprintf('Item %d de <ListaServico> sem base de calculo (<vBC>).', $idx),
                    $item->childPath('vBC'),
                    'Quando o ISS e devido sobre a base, informe <vBC>.',
                    $item,
                );
            }
        }

        self::validarAliquota($ctx, $item, $idx);
        self::validarIssRetido($ctx, $item, $idx);

        $cMunItem = $item->value('cMun');
        if ($cMunServico !== '' && $cMunItem !== '' && $cMunItem !== $cMunServico) {
            $ctx->aviso(
                Codes::LISTA_SERVICO_CODIGO_MUN_DIVERGENTE,
                \sprintf(
                    'Municipio do item %d (%s) difere do municipio de <Servico> (%s).',
                    $idx,
                    $cMunItem,
                    $cMunServico,
                ),
                $item->childPath('cMun'),
                'Em geral cMun do item e igual ao cMun do servico. Divergencia so faz sentido para servico prestado em outro municipio.',
                $item->find('cMun'),
            );
        }
    }

    private static function validarAliquota(Ctx $ctx, Node $item, int $idx): void
    {
        $aliqRaw = $item->value('aliq');
        if ($aliqRaw === '') {
            $aliqRaw = $item->value('pISS');
        }
        if ($aliqRaw === '') {
            return;
        }

        $aliq = Decimal::fromString($aliqRaw);
        $nodeAliq = $item->find('aliq') ?? $item->find('pISS');

        if ($aliq === null) {
            $ctx->erro(
                Codes::ITEM_SERVICO_ALIQUOTA_INVALIDA,
                \sprintf('Aliquota nao numerica no item %d: %s.', $idx, Repr::de($aliqRaw)),
                $item->childPath('aliq'),
                'Informe a aliquota em percentual, ex.: 2.00 para 2%.',
                $nodeAliq,
            );

            return;
        }

        $cem = Decimal::fromString('100');

        if ($aliq->isNegative() || ($cem instanceof Decimal && $aliq->greaterThan($cem))) {
            $ctx->erro(
                Codes::ITEM_SERVICO_ALIQUOTA_INVALIDA,
                \sprintf('Aliquota fora da faixa 0-100 no item %d: %s.', $idx, $aliqRaw),
                $item->childPath('aliq'),
                'A aliquota do ISS e um percentual: use 5.00 para 5%.',
                $nodeAliq,
            );

            return;
        }

        self::conferirAliquotaImplicita($ctx, $item, $aliq, $idx);
    }

    private static function validarIssRetido(Ctx $ctx, Node $item, int $idx): void
    {
        $issRetido = $item->value('retISS');
        if ($issRetido === '' || $issRetido === '1' || $issRetido === '2') {
            return;
        }

        $ctx->erro(
            Codes::ITEM_SERVICO_ISS_RETIDO_INVALIDO,
            \sprintf('Valor invalido em <retISS> no item %d: %s (esperado 1 ou 2).', $idx, Repr::de($issRetido)),
            $item->childPath('retISS'),
            'retISS=1 ISS retido na fonte, retISS=2 ISS nao retido.',
            $item->find('retISS'),
        );
    }

    /**
     * vISS / base implicita x aliquota declarada.
     *
     * Quando o item traz `<vISS>` e base (vBC ou vServ), a aliquota implicita
     * precisa bater com `<aliq>` dentro da tolerancia configurada. Diferenca
     * menor que a tolerancia e arredondamento normal de centavo.
     */
    private static function conferirAliquotaImplicita(Ctx $ctx, Node $item, Decimal $aliq, int $idx): void
    {
        $vIss = Decimal::fromString($item->value('vISS'));
        if ($vIss === null) {
            return;
        }

        $base = Decimal::fromString($item->value('vBC'));
        if ($base === null || $base->isZero()) {
            $base = Decimal::fromString($item->value('vServ'));
        }
        if ($base === null || $base->isZero()) {
            $base = Decimal::fromString($item->value('vServico'));
        }
        if ($base === null || $base->isZero() || $base->isNegative()) {
            return;
        }

        $implicita = Decimal::percentOf($vIss, $base);
        $diff = $implicita->sub($aliq);

        if ($diff->abs()->greaterThan($ctx->toleranciaAliquota)) {
            $ctx->aviso(
                Codes::ITEM_SERVICO_ALIQUOTA_DIVERGENTE,
                \sprintf(
                    'Item %d: aliquota implicita em vISS/vBC e %s%% mas <aliq> declara %s%% (diferenca %s p.p.).',
                    $idx,
                    $implicita->format(4),
                    (string) $aliq,
                    $diff->format(4),
                ),
                $item->path(),
                'Calcule o ISS a partir da aliquota e da base, arredondando para centavos.',
                $item,
            );
        }
    }
}
