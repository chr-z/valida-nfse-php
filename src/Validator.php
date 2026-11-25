<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse;

use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Checks\AssinaturaCheck;
use ChrZ\ValidaNfse\Checks\Ctx;
use ChrZ\ValidaNfse\Checks\EmitenteCheck;
use ChrZ\ValidaNfse\Checks\EnvelopeCheck;
use ChrZ\ValidaNfse\Checks\IdeCheck;
use ChrZ\ValidaNfse\Checks\ServicoCheck;
use ChrZ\ValidaNfse\Checks\TotaisCheck;
use ChrZ\ValidaNfse\Xml\ParseException;
use ChrZ\ValidaNfse\Xml\Parser;

/**
 * Porta de entrada do pacote.
 *
 * Uso:
 *
 *     $relatorio = Validator::validate($xml);
 *     if (!$relatorio->ok()) {
 *         foreach ($relatorio->errors() as $erro) {
 *             echo $erro->code, ' ', $erro->path, PHP_EOL;
 *         }
 *     }
 *
 * Sem dependencias, sem rede, sem telemetria. Tudo roda na sua maquina.
 */
final class Validator
{
    public const VERSION = '1.0.0';

    /**
     * Valida um XML de NFS-e e devolve um Report.
     *
     * @param bool           $strict             promove todo aviso a erro
     * @param Decimal|null   $tolerancia         limite (em reais) da reconciliacao; padrao 0,01
     * @param Decimal|null   $toleranciaAliquota limite (em pontos percentuais) da aliquota implicita
     * @param \DateTimeImmutable|null $agora     injetavel em teste (relogio)
     * @param int|null       $skew               folga de relogio em segundos (padrao 300)
     */
    public static function validate(
        string $xml,
        bool $strict = false,
        string $source = '-',
        ?Decimal $tolerancia = null,
        ?Decimal $toleranciaAliquota = null,
        ?\DateTimeImmutable $agora = null,
        ?int $skew = null,
    ): Report {
        try {
            $root = Parser::parse($xml);
        } catch (ParseException $e) {
            return new Report([$e->issue], $strict, $source);
        }

        $ctx = new Ctx(
            root: $root,
            source: $source,
            tolerancia: $tolerancia ?? Decimal::fromString('0.01') ?? Decimal::zero(),
            toleranciaAliquota: $toleranciaAliquota ?? Decimal::fromString('0.01') ?? Decimal::zero(),
            agora: $agora,
            skew: $skew,
        );

        self::executar($ctx);

        return new Report($ctx->issues, $strict, $source);
    }

    /**
     * Roda todas as verificacoes, na ordem que importa.
     *
     * A estrutura vem antes da aritmetica: a reconciliacao depende dos itens
     * ja identificados.
     */
    public static function executar(Ctx $ctx): void
    {
        $nfse = EnvelopeCheck::validarEnvelope($ctx);
        $infnfe = EnvelopeCheck::acharInfNfe($ctx);

        IdeCheck::validar($ctx, $infnfe);
        EmitenteCheck::validarEmitente($ctx, $infnfe);
        EmitenteCheck::validarDestinatario($ctx, $infnfe);

        $itens = ServicoCheck::validar($ctx, $infnfe);
        $total = TotaisCheck::validarTotais($ctx, $infnfe, $itens);
        TotaisCheck::reconciliar($ctx, $infnfe, $itens, $total);

        AssinaturaCheck::validar($ctx, $nfse);
    }

    /**
     * Atalho: valida um arquivo e usa o caminho como `source`.
     */
    public static function validateFile(
        string $caminho,
        bool $strict = false,
        ?Decimal $tolerancia = null,
        ?Decimal $toleranciaAliquota = null,
    ): Report {
        $xml = @file_get_contents($caminho);
        if ($xml === false) {
            throw new \RuntimeException(\sprintf('Nao foi possivel ler o arquivo: %s', $caminho));
        }

        return self::validate($xml, $strict, $caminho, $tolerancia, $toleranciaAliquota);
    }
}
