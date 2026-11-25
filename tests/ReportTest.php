<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Issue;
use ChrZ\ValidaNfse\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testOrdenaErrosAntesDeAvisos(): void
    {
        $relatorio = new Report([
            new Issue('Z_WARN', Issue::SEVERITY_WARNING, 'aviso', 'B'),
            new Issue('A_ERR', Issue::SEVERITY_ERROR, 'erro', 'C'),
            new Issue('B_ERR', Issue::SEVERITY_ERROR, 'erro', 'A'),
        ]);

        self::assertSame(['B_ERR', 'A_ERR', 'Z_WARN'], $relatorio->codes());
    }

    public function testOkQuandoNaoHaErro(): void
    {
        $relatorio = new Report([new Issue('X', Issue::SEVERITY_WARNING, 'aviso')]);

        self::assertTrue($relatorio->ok());
        self::assertCount(1, $relatorio->warnings());
        self::assertSame([], $relatorio->errors());
    }

    public function testStrictPromoveAvisos(): void
    {
        $relatorio = new Report([new Issue('X', Issue::SEVERITY_WARNING, 'aviso')], strict: true);

        self::assertFalse($relatorio->ok());
        self::assertSame([], $relatorio->warnings());
        self::assertTrue($relatorio->errors()[0]->isError());
    }

    public function testSummaryPluralESingular(): void
    {
        $limpo = new Report([], false, 'nota.xml');
        self::assertSame('nota.xml: OK - nenhum problema encontrado.', $limpo->summary());

        $umErro = new Report([new Issue('X', Issue::SEVERITY_ERROR, 'erro')], false, 'nota.xml');
        self::assertSame('nota.xml: 1 erro encontrado.', $umErro->summary());

        $misto = new Report([
            new Issue('X', Issue::SEVERITY_ERROR, 'erro'),
            new Issue('Y', Issue::SEVERITY_WARNING, 'aviso'),
            new Issue('Z', Issue::SEVERITY_WARNING, 'aviso'),
        ], false, 'nota.xml');
        self::assertSame('nota.xml: 1 erro e 2 avisos encontrados.', $misto->summary());
    }

    public function testLocacaoComLinhaEColuna(): void
    {
        $issue = new Issue('X', Issue::SEVERITY_ERROR, 'erro', 'NFSe/InfNFe/Ide', 4, 12);

        self::assertSame('NFSe/InfNFe/Ide (linha 4, coluna 12)', $issue->location());
    }

    public function testLocacaoSemPosicao(): void
    {
        $issue = new Issue('X', Issue::SEVERITY_ERROR, 'erro', 'NFSe');

        self::assertSame('NFSe', $issue->location());
    }

    public function testSerializacaoJson(): void
    {
        $relatorio = new Report([new Issue('X', Issue::SEVERITY_ERROR, 'erro', 'A', 1, 2, 'dica')], false, 'a.xml');
        $dados = $relatorio->toArray();

        self::assertFalse($dados['ok']);
        self::assertSame(['errors' => 1, 'warnings' => 0], $dados['counts']);
        self::assertSame('a.xml', $dados['source']);
        self::assertSame('X', $dados['issues'][0]['code']);
        self::assertSame(1, $dados['issues'][0]['line']);
        self::assertSame('dica', $dados['issues'][0]['hint']);
        self::assertStringContainsString('1 erro', $dados['summary']);
    }

    public function testJsonSobreviveAEscrita(): void
    {
        $json = json_encode((new Report([], false, 'nota.xml'))->toArray(), \JSON_UNESCAPED_UNICODE);

        self::assertIsString($json);
        self::assertSame(JSON_ERROR_NONE, json_last_error());

        /** @var array<string, mixed> $volta */
        $volta = json_decode($json, true);
        self::assertTrue($volta['ok']);
    }
}
