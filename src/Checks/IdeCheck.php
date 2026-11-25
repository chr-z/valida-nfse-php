<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Brazil\ChaveAcesso;
use ChrZ\ValidaNfse\Brazil\Documentos;
use ChrZ\ValidaNfse\Brazil\Formato;
use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Repr;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Verificacoes de `<Ide>`: identificacao da nota, data e chave de acesso.
 */
final class IdeCheck
{
    /**
     * Elementos de <Ide> na ordem do schema ABRASF (xs:sequence).
     *
     * @var list<string>
     */
    public const ORDEM_IDE = [
        'cStat', 'cServ', 'cMunFG', 'nNF', 'cVerif', 'dhEmi', 'dhSai', 'tpNF',
        'idDest', 'cMun', 'tpImp', 'tpEmis', 'cDV', 'tpAmb', 'finNFe', 'indFinal',
        'indPres', 'indIntermed', 'procEmi', 'verProc',
    ];

    private const RE_ISO8601 = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public static function validar(Ctx $ctx, ?Node $infnfe): void
    {
        if (!$infnfe instanceof Node) {
            return;
        }

        $ide = $infnfe->find('Ide');
        if (!$ide instanceof Node) {
            $ctx->erro(
                Codes::IDE_AUSENTE,
                'Elemento <Ide> ausente dentro de <InfNFe>.',
                $infnfe->childPath('Ide'),
                'Todo InfNFe precisa de <Ide> com os dados de identificacao da nota.',
                $infnfe,
            );

            return;
        }

        if ($ide->isBlank()) {
            $ctx->erro(
                Codes::IDE_VAZIO,
                'Elemento <Ide> presente mas vazio.',
                $ide->path(),
                'Preencha <Ide>.',
                $ide,
            );

            return;
        }

        Rules::conferirOrdem($ctx, $ide, self::ORDEM_IDE, 'IDE');

        self::checarCStat($ctx, $ide);
        self::checarNumero($ctx, $ide);

        $anoEmissao = self::checarData($ctx, $ide);
        self::checarChave($ctx, $ide, $anoEmissao);
        $cMunFg = self::checarMunicipio($ctx, $ide);
        self::checarMunicipioPrestacao($ctx, $ide, $cMunFg);
        self::checarAmbiente($ctx, $ide);
    }

    private static function checarCStat(Ctx $ctx, Node $ide): void
    {
        if ($ide->isEmpty('cStat')) {
            $ctx->erro(
                Codes::IDE_CSTAT_OBRIGATORIO,
                'Campo obrigatorio <cStat> ausente ou vazio em <Ide>.',
                $ide->childPath('cStat'),
                'Use <cStat>1</cStat> para NFS-e normal (emissao de servico efetuada).',
                $ide,
            );

            return;
        }

        $cstat = $ide->value('cStat');
        if ($cstat !== '1') {
            $ctx->erro(
                Codes::IDE_CSTAT_INVALIDO,
                \sprintf('Valor invalido em <cStat>: %s. Para NFS-e so o codigo 1 e valido.', Repr::de($cstat)),
                $ide->childPath('cStat'),
                'Em NFS-e, <cStat> deve ser 1. Se voce esta enviando 2, 6 ou 9, este XML e de NF-e, nao de NFS-e.',
                $ide->find('cStat'),
            );
        }
    }

    private static function checarNumero(Ctx $ctx, Node $ide): void
    {
        if ($ide->isEmpty('nNF')) {
            $ctx->erro(
                Codes::IDE_NNF_OBRIGATORIO,
                'Campo obrigatorio <nNF> (numero da nota) ausente ou vazio.',
                $ide->childPath('nNF'),
                'Inclua <nNF> com um numero inteiro positivo.',
                $ide,
            );

            return;
        }

        $nnf = $ide->value('nNF');
        if (Documentos::somenteDigitos($nnf) === '') {
            $ctx->erro(
                Codes::IDE_NNF_NAO_NUMERICO,
                \sprintf('Valor nao numerico em <nNF>: %s.', Repr::de($nnf)),
                $ide->childPath('nNF'),
                '<nNF> e um inteiro sem zeros a esquerda obrigatorios.',
                $ide->find('nNF'),
            );

            return;
        }

        // Mesma semantica do validador de referencia: se a string nao for um
        // inteiro puro, nao ha o que comparar e nao emitimos IDE_NNF_NAO_POSITIVO.
        $limpo = str_replace('_', '', trim($nnf));
        if (preg_match('/^[+-]?\d+$/', $limpo) === 1 && (int) $limpo <= 0) {
            $ctx->erro(
                Codes::IDE_NNF_NAO_POSITIVO,
                \sprintf('<nNF> deve ser maior que zero, encontrado %s.', Repr::de($nnf)),
                $ide->childPath('nNF'),
                'Numeracao de NFS-e comeca em 1.',
                $ide->find('nNF'),
            );
        }
    }

    private static function checarData(Ctx $ctx, Node $ide): ?int
    {
        if ($ide->isEmpty('dhEmi') && $ide->isEmpty('dEmi')) {
            $ctx->erro(
                Codes::IDE_DTEMI_OBRIGATORIO,
                'Campo obrigatorio <dhEmi> (ou <dEmi>) ausente em <Ide>.',
                $ide->childPath('dhEmi'),
                'Inclua <dhEmi> no formato ISO-8601 com fuso, ex.: 2026-01-15T10:30:00-03:00.',
                $ide,
            );

            return null;
        }

        $nodeDt = $ide->find('dhEmi') ?? $ide->find('dEmi');
        if (!$nodeDt instanceof Node) {
            return null;
        }

        $bruto = trim($nodeDt->text);
        $dt = self::parseIso($bruto);

        if ($dt === null) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $bruto) === 1) {
                // Tem data e hora, mas o fuso (Z ou +hh:mm) nao fecha: e o caso
                // classico de <dEmi>/<dhEmi> sem deslocamento.
                if (preg_match('/(Z|[+-]\d{2}:\d{2})$/', $bruto) !== 1) {
                    $ctx->erro(
                        Codes::IDE_DTEMI_SEM_FUSO,
                        \sprintf('<dEmi> sem fuso horario: %s.', Repr::de($bruto)),
                        $nodeDt->path(),
                        'Inclua o deslocamento, ex.: 2026-01-15T10:30:00-03:00.',
                        $nodeDt,
                    );
                } else {
                    $ctx->erro(
                        Codes::IDE_DTEMI_INVALIDO,
                        \sprintf('Data/hora invalida em <dhEmi>: %s.', Repr::de($bruto)),
                        $nodeDt->path(),
                        'O formato esperado e ISO-8601: 2026-01-15T10:30:00-03:00.',
                        $nodeDt,
                    );
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $bruto) === 1) {
                $ctx->erro(
                    Codes::IDE_DTEMI_SEM_FUSO,
                    \sprintf('Data sem hora em <dEmi>: %s.', Repr::de($bruto)),
                    $nodeDt->path(),
                    'Use data e hora com fuso, ex.: 2026-01-15T10:30:00-03:00.',
                    $nodeDt,
                );
            } else {
                $ctx->erro(
                    Codes::IDE_DTEMI_INVALIDO,
                    \sprintf('Data/hora invalida em <dhEmi>: %s.', Repr::de($bruto)),
                    $nodeDt->path(),
                    'O formato esperado e ISO-8601: 2026-01-15T10:30:00-03:00.',
                    $nodeDt,
                );
            }

            return null;
        }

        self::conferirJanela($ctx, $nodeDt, $dt);

        return (int) $dt->format('Y');
    }

    private static function checarChave(Ctx $ctx, Node $ide, ?int $anoEmissao): void
    {
        $chaveNode = $ide->find('chNFe');
        if (!$chaveNode instanceof Node || trim($chaveNode->text) === '') {
            $ctx->erro(
                Codes::IDE_CHAVE_ACESSO_OBRIGATORIA,
                'Campo obrigatorio <chNFe> (chave de acesso) ausente ou vazio.',
                $ide->childPath('chNFe'),
                'Inclua <chNFe> com os 44 digitos da chave de acesso.',
                $ide,
            );

            return;
        }

        $chave = Documentos::somenteDigitos($chaveNode->text);
        if (preg_match('/^[0-9]{44}$/', $chave) !== 1) {
            $ctx->erro(
                Codes::IDE_CHAVE_ACESSO_INVALIDA,
                \sprintf('Chave de acesso invalida: %s (esperado 44 digitos).', Repr::de(trim($chaveNode->text))),
                $chaveNode->path(),
                'A chave de acesso tem 44 digitos, apenas numeros, sem pontuacao.',
                $chaveNode,
            );

            return;
        }

        // A chave e validada em dois passos independentes: primeiro o
        // formato/DV, depois a coerencia do ano com a data de emissao. Assim
        // um ano divergente vira aviso especifico em vez de ser engolido pelo
        // erro de DV.
        if (!ChaveAcesso::validar($chave)) {
            $ctx->erro(
                Codes::IDE_CHAVE_ACESSO_INVALIDA,
                'Chave de acesso invalida: digito verificador mod-11 ou posicoes de UF/mes nao conferem.',
                $chaveNode->path(),
                'A chave tem 44 digitos com DV mod-11. Confira os 43 primeiros digitos e recalcule o ultimo.',
                $chaveNode,
            );

            return;
        }

        if ($anoEmissao !== null && (int) substr($chave, 2, 2) !== $anoEmissao % 100) {
            $ctx->aviso(
                Codes::IDE_CHAVE_ACESSO_ANO_DIVERGENTE,
                \sprintf(
                    'Ano da chave de acesso (%s) difere do ano de emissao (%d).',
                    substr($chave, 2, 2),
                    $anoEmissao,
                ),
                $chaveNode->path(),
                'A posicao 3-4 da chave traz AAMM. Emissao e chave devem concordar.',
                $chaveNode,
            );
        }
    }

    private static function checarMunicipio(Ctx $ctx, Node $ide): ?string
    {
        if ($ide->isEmpty('cMunFG')) {
            $ctx->erro(
                Codes::IDE_CMUNFG_OBRIGATORIO,
                'Campo obrigatorio <cMunFG> (codigo IBGE do municipio de geracao) ausente ou vazio.',
                $ide->childPath('cMunFG'),
                'Inclua <cMunFG> com 7 digitos do codigo IBGE do municipio emissor.',
                $ide,
            );

            return null;
        }

        $cmunfg = $ide->value('cMunFG');
        if (!Formato::ibgeMunicipioValido($cmunfg)) {
            $ctx->erro(
                Codes::IDE_CMUNFG_INVALIDO,
                \sprintf('Codigo de municipio invalido em <cMunFG>: %s.', Repr::de($cmunfg)),
                $ide->childPath('cMunFG'),
                'Use o codigo IBGE com 7 digitos (ex.: 3550308 para Sao Paulo/SP).',
                $ide->find('cMunFG'),
            );
        }

        return $cmunfg;
    }

    private static function checarMunicipioPrestacao(Ctx $ctx, Node $ide, ?string $cMunFg): void
    {
        if ($ide->isEmpty('cMun') || $cMunFg === null || $cMunFg === '') {
            return;
        }

        $cmun = $ide->value('cMun');
        if (!Formato::ibgeMunicipioValido($cmun)) {
            $ctx->erro(
                Codes::IDE_CMUN_INVALIDO,
                \sprintf('Codigo de municipio invalido em <cMun>: %s.', Repr::de($cmun)),
                $ide->childPath('cMun'),
                'Use o codigo IBGE com 7 digitos.',
                $ide->find('cMun'),
            );

            return;
        }

        if ($cmun !== $cMunFg) {
            $ctx->aviso(
                Codes::IDE_CMUN_CMUNFG_DIVERGEM,
                \sprintf('<cMun> (%s) difere de <cMunFG> (%s).', $cmun, $cMunFg),
                $ide->childPath('cMun'),
                'Em geral cMunFG e cMun sao iguais. Divergencia so faz sentido se o servico for prestado em outro municipio.',
                $ide->find('cMun'),
            );
        }
    }

    private static function checarAmbiente(Ctx $ctx, Node $ide): void
    {
        if ($ide->isEmpty('tpAmb')) {
            return;
        }

        $tpAmb = $ide->value('tpAmb');
        if ($tpAmb !== '1' && $tpAmb !== '2') {
            $ctx->aviso(
                Codes::IDE_TPAMB_INVALIDO,
                \sprintf('Valor inesperado em <tpAmb>: %s (esperado 1 para producao ou 2 para homologacao).', Repr::de($tpAmb)),
                $ide->childPath('tpAmb'),
                'tpAmb=1 producao, tpAmb=2 homologacao.',
                $ide->find('tpAmb'),
            );
        }
    }

    private static function parseIso(string $valor): ?\DateTimeImmutable
    {
        if (preg_match(self::RE_ISO8601, trim($valor), $m) !== 1) {
            return null;
        }

        [$ano, $mes, $dia, $hora, $minuto, $segundo] = [
            (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6],
        ];

        if (!checkdate($mes, $dia, $ano) || $hora > 23 || $minuto > 59 || $segundo > 59) {
            return null;
        }

        $tz = $m[8];
        $fuso = $tz === 'Z'
            ? 'UTC'
            : 'GMT' . $tz;

        $texto = \sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $ano, $mes, $dia, $hora, $minuto, $segundo);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $texto, new \DateTimeZone($fuso));

        return $dt instanceof \DateTimeImmutable ? $dt : null;
    }

    private static function conferirJanela(Ctx $ctx, Node $node, \DateTimeImmutable $dt): void
    {
        $agora = $ctx->agora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $skewSegundos = $ctx->skew ?? 300;

        $limiteFuturo = $agora->modify(\sprintf('+%d seconds', $skewSegundos));
        if ($dt > $limiteFuturo) {
            $ctx->aviso(
                Codes::IDE_DTEMI_FUTURO,
                \sprintf(
                    'Data de emissao no futuro: %s (agora: %s).',
                    $dt->format('Y-m-d\TH:i:sP'),
                    $agora->format('Y-m-d\TH:i:sP'),
                ),
                $node->path(),
                'O relogio do servidor emissor pode estar adiantado, ou o XML foi gerado com data trocada.',
                $node,
            );

            return;
        }

        $limiteAntigo = $agora->modify('-30 days');
        if ($dt < $limiteAntigo) {
            $ctx->aviso(
                Codes::IDE_DTEMI_ANTIGO,
                \sprintf('Data de emissao muito antiga: %s.', $dt->format('Y-m-d\TH:i:sP')),
                $node->path(),
                'Emissao com mais de 30 dias pode ser rejeitada pelo provedor.',
                $node,
            );
        }
    }
}
