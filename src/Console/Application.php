<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Console;

use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Issue;
use ChrZ\ValidaNfse\Report;
use ChrZ\ValidaNfse\Validator;

/**
 * CLI do validador.
 *
 * Saidas: 0 = limpo, 1 = erros de validacao, 2 = erro de uso ou de IO.
 * A saida e identica, em formato, a do validador de referencia em Python --
 * inclusive o JSON -- para que os dois possam ser usados no mesmo pipeline.
 */
final class Application
{
    public const EXIT_OK = 0;
    public const EXIT_INVALIDO = 1;
    public const EXIT_USO = 2;

    private const CORES = [
        Issue::SEVERITY_ERROR => "\033[31m",
        Issue::SEVERITY_WARNING => "\033[33m",
        'ok' => "\033[32m",
        'reset' => "\033[0m",
        'dim' => "\033[2m",
        'bold' => "\033[1m",
    ];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var resource */
    private $stdin;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        $this->stdout = $stdout ?? \STDOUT;
        $this->stderr = $stderr ?? \STDERR;
        $this->stdin = $stdin ?? \STDIN;
    }

    /**
     * @param list<string> $argv argumentos sem o nome do programa
     */
    public function run(array $argv): int
    {
        $opcoes = [
            'caminho' => null,
            'json' => false,
            'batch' => null,
            'strict' => false,
            'quiet' => false,
            'failOn' => 'error',
            'noColor' => false,
        ];

        $i = 0;
        $total = \count($argv);
        while ($i < $total) {
            $arg = $argv[$i];

            switch (true) {
                case $arg === '--json':
                    $opcoes['json'] = true;
                    break;
                case $arg === '--strict':
                    $opcoes['strict'] = true;
                    break;
                case $arg === '-q' || $arg === '--quiet':
                    $opcoes['quiet'] = true;
                    break;
                case $arg === '--no-color':
                    $opcoes['noColor'] = true;
                    break;
                case $arg === '--version' || $arg === '-V':
                    $this->writeln('valida-nfse ' . Validator::VERSION);

                    return self::EXIT_OK;
                case $arg === '--help' || $arg === '-h':
                    $this->imprimirAjuda($this->stdout);

                    return self::EXIT_OK;
                case $arg === '--batch':
                    $valor = $argv[$i + 1] ?? null;
                    if ($valor === null) {
                        return $this->erroUso('erro: --batch exige um alvo (diretorio, arquivo ou glob)');
                    }
                    $opcoes['batch'] = $valor;
                    ++$i;
                    break;
                case str_starts_with($arg, '--batch='):
                    $opcoes['batch'] = substr($arg, 8);
                    break;
                case $arg === '--fail-on':
                    $valor = $argv[$i + 1] ?? null;
                    if ($valor === null || !\in_array($valor, ['error', 'warning', 'none'], true)) {
                        return $this->erroUso("erro: --fail-on aceita 'error', 'warning' ou 'none'");
                    }
                    $opcoes['failOn'] = $valor;
                    ++$i;
                    break;
                case str_starts_with($arg, '--fail-on='):
                    $valor = substr($arg, 10);
                    if (!\in_array($valor, ['error', 'warning', 'none'], true)) {
                        return $this->erroUso("erro: --fail-on aceita 'error', 'warning' ou 'none'");
                    }
                    $opcoes['failOn'] = $valor;
                    break;
                case $arg === '-':
                    // hifen sozinho e stdin, nao opcao.
                    if ($opcoes['caminho'] !== null) {
                        return $this->erroUso('erro: informe apenas um caminho (use --batch para varios)');
                    }
                    $opcoes['caminho'] = $arg;
                    break;
                case str_starts_with($arg, '-'):
                    return $this->erroUso(\sprintf('erro: opcao desconhecida: %s', $arg));
                default:
                    if ($opcoes['caminho'] !== null) {
                        return $this->erroUso('erro: informe apenas um caminho (use --batch para varios)');
                    }
                    $opcoes['caminho'] = $arg;
                    break;
            }

            ++$i;
        }

        /** @var string|null $batch */
        $batch = $opcoes['batch'];
        /** @var string|null $caminho */
        $caminho = $opcoes['caminho'];
        /** @var string $failOn */
        $failOn = $opcoes['failOn'];
        $strict = (bool) $opcoes['strict'];
        $json = (bool) $opcoes['json'];
        $quiet = (bool) $opcoes['quiet'];

        $usarCor = !$opcoes['noColor'] && !$json && !$quiet && $this->ehTerminal($this->stdout);

        if ($batch !== null && $caminho !== null) {
            return $this->erroUso('erro: use --batch OU um caminho, nao os dois');
        }

        if ($batch === null && $caminho === null) {
            $this->imprimirAjuda($this->stderr);

            return $this->erroUso('erro: informe um caminho ou use --batch');
        }

        if ($batch !== null) {
            return $this->rodarBatch($batch, $json, $quiet, $strict, $failOn, $usarCor);
        }

        try {
            $dados = $this->ler($caminho);
        } catch (\RuntimeException $e) {
            $this->writeln(\sprintf('erro de IO: %s: %s', $caminho, $e->getMessage()), $this->stderr);

            return self::EXIT_USO;
        }

        $relatorio = Validator::validate($dados, $strict, $caminho);

        if ($json) {
            $this->writeln($this->json($relatorio->toArray()));
        } elseif (!$quiet) {
            $this->imprimirRelatorio($relatorio, $usarCor);
        }

        return $this->codigoSaida([$relatorio], $failOn);
    }

    private function rodarBatch(string $alvo, bool $json, bool $quiet, bool $strict, string $failOn, bool $usarCor): int
    {
        $arquivos = $this->expandir($alvo);
        if ($arquivos === []) {
            return $this->erroUso(\sprintf('erro: nenhum XML encontrado em %s', var_export($alvo, true)));
        }

        /** @var list<Report> $relatorios */
        $relatorios = [];
        $falhasIo = false;

        foreach ($arquivos as $arquivo) {
            try {
                $dados = $this->ler($arquivo);
            } catch (\RuntimeException $e) {
                $falhasIo = true;
                $this->writeln(\sprintf('erro de IO: %s: %s', $arquivo, $e->getMessage()), $this->stderr);
                continue;
            }

            $relatorios[] = Validator::validate($dados, $strict, $arquivo);
        }

        if ($json) {
            $this->writeln($this->json(array_map(static fn (Report $r): array => $r->toArray(), $relatorios)));
        } elseif (!$quiet) {
            $totalErros = 0;
            $totalAvisos = 0;

            foreach ($relatorios as $relatorio) {
                $erros = \count($relatorio->errors());
                $avisos = \count($relatorio->warnings());
                $totalErros += $erros;
                $totalAvisos += $avisos;

                $marcacao = $relatorio->ok()
                    ? $this->cor('OK  ', 'ok', $usarCor)
                    : $this->cor('FALHA', Issue::SEVERITY_ERROR, $usarCor);

                $this->writeln(\sprintf('%s %s  (%d erro(s), %d aviso(s))', $marcacao, $relatorio->source, $erros, $avisos));
            }

            $this->writeln('');
            $this->writeln(\sprintf('%d arquivo(s): %d erro(s), %d aviso(s).', \count($relatorios), $totalErros, $totalAvisos));
        }

        $codigo = $this->codigoSaida($relatorios, $failOn);

        return $falhasIo ? self::EXIT_USO : $codigo;
    }

    /**
     * @return list<string>
     */
    private function expandir(string $alvo): array
    {
        if (is_dir($alvo)) {
            $encontrados = [];
            $iterador = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($alvo, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterador as $arquivo) {
                \assert($arquivo instanceof \SplFileInfo);
                if ($arquivo->isFile() && str_ends_with(strtolower($arquivo->getFilename()), '.xml')) {
                    $encontrados[] = $arquivo->getPathname();
                }
            }
            sort($encontrados);

            return $encontrados;
        }

        if (is_file($alvo)) {
            return [$alvo];
        }

        $matches = glob($alvo);
        if ($matches === false) {
            return [];
        }
        sort($matches);

        return array_values(array_filter($matches, 'is_file'));
    }

    /**
     * @throws \RuntimeException quando o arquivo nao pode ser lido
     */
    private function ler(string $caminho): string
    {
        if ($caminho === '-') {
            $dados = stream_get_contents($this->stdin);
            if ($dados === false) {
                throw new \RuntimeException('falha ao ler da entrada padrao');
            }

            return $dados;
        }

        if (!is_file($caminho)) {
            throw new \RuntimeException('arquivo nao encontrado');
        }

        $dados = @file_get_contents($caminho);
        if ($dados === false) {
            throw new \RuntimeException('falha ao ler o arquivo');
        }

        return $dados;
    }

    /**
     * @param list<Report> $relatorios
     */
    private function codigoSaida(array $relatorios, string $failOn): int
    {
        if ($failOn === 'none') {
            return self::EXIT_OK;
        }

        $temErro = false;
        $temAviso = false;
        foreach ($relatorios as $relatorio) {
            if (!$relatorio->ok()) {
                $temErro = true;
            }
            if ($relatorio->warnings() !== []) {
                $temAviso = true;
            }
        }

        if ($failOn === 'error') {
            return $temErro ? self::EXIT_INVALIDO : self::EXIT_OK;
        }

        return ($temErro || $temAviso) ? self::EXIT_INVALIDO : self::EXIT_OK;
    }

    private function imprimirRelatorio(Report $relatorio, bool $usarCor): void
    {
        if ($relatorio->ok() && $relatorio->warnings() === []) {
            $this->writeln(\sprintf('%s - nenhum problema encontrado: %s', $this->cor('OK', 'ok', $usarCor), $relatorio->source));

            return;
        }

        foreach ($relatorio->issues() as $issue) {
            $this->writeln($this->formatarIssue($issue, $usarCor));
        }

        $this->writeln('');
        $this->writeln($relatorio->summary());
    }

    private function formatarIssue(Issue $issue, bool $usarCor): string
    {
        $rotulo = $issue->isError() ? 'ERRO' : 'AVISO';
        $linha = '  ' . $this->cor($rotulo, $issue->severity, $usarCor) . ' ';
        $linha .= $this->cor($issue->code, 'bold', $usarCor) . ': ' . $issue->message;

        $loc = $issue->location();
        if ($loc !== '') {
            $linha .= "\n    " . $this->cor('em', 'dim', $usarCor) . ' ' . $loc;
        }

        if ($issue->hint !== '') {
            $linha .= "\n    " . $this->cor('->', 'dim', $usarCor) . ' ' . $issue->hint;
        }

        return $linha;
    }

    private function cor(string $texto, string $chave, bool $usarCor): string
    {
        if (!$usarCor || !isset(self::CORES[$chave])) {
            return $texto;
        }

        return self::CORES[$chave] . $texto . self::CORES['reset'];
    }

    /**
     * @param resource $stream
     */
    private function imprimirAjuda($stream): void
    {
        $texto = <<<'TXT'
        Uso: valida-nfse [opcoes] <caminho>

        Validador offline de XML de NFS-e (Nota Fiscal de Servico eletronica).
        Nao faz nenhuma chamada de rede.

        Argumentos:
          caminho               arquivo XML a validar, ou '-' para stdin

        Opcoes:
          --json                saida em JSON
          --batch ALVO          valida varios arquivos (diretorio, arquivo ou glob)
          --strict              promove todo warning a error
          -q, --quiet           imprime apenas o codigo de saida
          --fail-on MODO        quando falhar: error (padrao), warning ou none
          --no-color            desliga as cores no terminal
          -V, --version         mostra a versao
          -h, --help            mostra esta ajuda

        Codigos de saida: 0 limpo, 1 erro de validacao, 2 erro de uso/IO
        TXT;

        $this->writeln($texto, $stream);
    }

    private function erroUso(string $mensagem): int
    {
        $this->writeln($mensagem, $this->stderr);

        return self::EXIT_USO;
    }

    private function json(mixed $dados): string
    {
        return (string) json_encode($dados, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param resource|null $stream
     */
    private function writeln(string $texto, $stream = null): void
    {
        fwrite($stream ?? $this->stdout, $texto . PHP_EOL);
    }

    /**
     * @param resource $stream
     */
    private function ehTerminal($stream): bool
    {
        return \function_exists('stream_isatty') && @stream_isatty($stream);
    }
}
