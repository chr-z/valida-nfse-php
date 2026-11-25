<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Console\Application;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nfse-php-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        file_put_contents($this->dir . '/valido.xml', Fixtures::documentoValido());
        file_put_contents($this->dir . '/quebrado.xml', Fixtures::documentoQuebrado('vservico_divergente'));
        file_put_contents($this->dir . '/aviso.xml', Fixtures::documentoQuebrado('aliquota_divergente'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function rodar(array $argv, string $stdin = ''): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertIsResource($in);

        fwrite($in, $stdin);
        rewind($in);

        $app = new Application($out, $err, $in);
        $codigo = $app->run($argv);

        rewind($out);
        rewind($err);

        return [
            $codigo,
            (string) stream_get_contents($out),
            (string) stream_get_contents($err),
        ];
    }

    public function testArquivoValidoSaiZero(): void
    {
        [$codigo, $out] = $this->rodar([$this->dir . '/valido.xml']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('OK', $out);
    }

    public function testArquivoInvalidoSaiUm(): void
    {
        [$codigo, $out] = $this->rodar([$this->dir . '/quebrado.xml']);

        self::assertSame(1, $codigo);
        self::assertStringContainsString('SOMA_VSERVICO_DIVERGENTE', $out);
    }

    public function testJsonValido(): void
    {
        [$codigo, $out] = $this->rodar(['--json', $this->dir . '/valido.xml']);

        self::assertSame(0, $codigo);
        $dados = json_decode($out, true);
        self::assertIsArray($dados);
        self::assertTrue($dados['ok']);
        self::assertArrayHasKey('issues', $dados);
        self::assertArrayHasKey('summary', $dados);
    }

    public function testJsonDeArquivoQuebradoListaOCodigo(): void
    {
        [$codigo, $out] = $this->rodar(['--json', $this->dir . '/quebrado.xml']);

        self::assertSame(1, $codigo);
        $dados = json_decode($out, true);
        self::assertIsArray($dados);
        self::assertFalse($dados['ok']);

        $codigos = array_map(static fn (array $i): string => (string) $i['code'], $dados['issues']);
        self::assertContains('SOMA_VSERVICO_DIVERGENTE', $codigos);
    }

    public function testErroDeIo(): void
    {
        [$codigo, , $err] = $this->rodar(['/caminho/que/nao/existe.xml']);

        self::assertSame(2, $codigo);
        self::assertStringContainsString('erro de IO', $err);
    }

    public function testSemArgumentosEhErroDeUso(): void
    {
        [$codigo, , $err] = $this->rodar([]);

        self::assertSame(2, $codigo);
        self::assertStringContainsString('informe um caminho', $err);
    }

    public function testQuietNaoImprimeNada(): void
    {
        [$codigo, $out] = $this->rodar(['--quiet', $this->dir . '/valido.xml']);

        self::assertSame(0, $codigo);
        self::assertSame('', trim($out));
    }

    public function testQuietEmArquivoQuebrado(): void
    {
        [$codigo, $out] = $this->rodar(['-q', $this->dir . '/quebrado.xml']);

        self::assertSame(1, $codigo);
        self::assertSame('', trim($out));
    }

    public function testStrictMudaCodigoDeSaidaComAviso(): void
    {
        [$codigo] = $this->rodar([$this->dir . '/aviso.xml']);
        self::assertSame(0, $codigo);

        [$codigoEstrito] = $this->rodar(['--strict', $this->dir . '/aviso.xml']);
        self::assertSame(1, $codigoEstrito);
    }

    public function testFailOnWarning(): void
    {
        [$codigo] = $this->rodar(['--fail-on', 'warning', $this->dir . '/aviso.xml']);

        self::assertSame(1, $codigo);
    }

    public function testFailOnNoneNuncaFalha(): void
    {
        [$codigo] = $this->rodar(['--fail-on', 'none', $this->dir . '/quebrado.xml']);

        self::assertSame(0, $codigo);
    }

    public function testBatchAgrega(): void
    {
        [$codigo, $out] = $this->rodar(['--batch', $this->dir]);

        self::assertSame(1, $codigo);
        self::assertStringContainsString('arquivo(s)', $out);
        self::assertStringContainsString('valido.xml', $out);
    }

    public function testBatchJsonELista(): void
    {
        [$codigo, $out] = $this->rodar(['--json', '--batch', $this->dir]);

        self::assertSame(1, $codigo);
        $dados = json_decode($out, true);
        self::assertIsArray($dados);
        self::assertGreaterThanOrEqual(2, \count($dados));

        foreach ($dados as $item) {
            self::assertArrayHasKey('ok', $item);
        }
    }

    public function testBatchEmDiretorioInexistente(): void
    {
        [$codigo] = $this->rodar(['--batch', '/nao/existe/xyz']);

        self::assertSame(2, $codigo);
    }

    public function testCaminhoEBatchConflitam(): void
    {
        [$codigo, , $err] = $this->rodar(['--batch', $this->dir, $this->dir . '/valido.xml']);

        self::assertSame(2, $codigo);
        self::assertStringContainsString('use --batch OU um caminho', $err);
    }

    public function testLeDoStdin(): void
    {
        [$codigo, $out] = $this->rodar(['-'], Fixtures::documentoValido());

        self::assertSame(0, $codigo);
        self::assertStringContainsString('OK', $out);
    }

    public function testVersion(): void
    {
        [$codigo, $out] = $this->rodar(['--version']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('valida-nfse', $out);
    }

    public function testOpcaoDesconhecida(): void
    {
        [$codigo, , $err] = $this->rodar(['--nao-existe']);

        self::assertSame(2, $codigo);
        self::assertStringContainsString('opcao desconhecida', $err);
    }

    public function testBinarioExecutavel(): void
    {
        $bin = \dirname(__DIR__) . '/bin/valida-nfse';
        $saida = shell_exec(\sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($bin),
            escapeshellarg($this->dir . '/valido.xml'),
        ));

        self::assertIsString($saida);
        self::assertStringContainsString('OK', $saida);
    }
}
