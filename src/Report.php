<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse;

/**
 * Resultado de uma validacao.
 *
 * Os issues saem sempre ordenados: erros antes de avisos, depois por caminho,
 * linha, coluna e codigo. Ordem estavel e deterministica e o que permite
 * comparar dois relatorios (ou duas implementacoes) com um diff.
 */
final class Report
{
    /** @var list<Issue> */
    private array $issues;

    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        array $issues = [],
        public readonly bool $strict = false,
        public readonly string $source = '-',
    ) {
        if ($strict) {
            $issues = array_map(static fn (Issue $i): Issue => $i->promoted(), $issues);
        }
        $this->issues = self::sortIssues($issues);
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<Issue>
     */
    private static function sortIssues(array $issues): array
    {
        $decorated = [];
        foreach ($issues as $index => $issue) {
            $decorated[] = [$index, $issue];
        }

        usort($decorated, static function (array $a, array $b): int {
            /** @var Issue $x */
            $x = $a[1];
            /** @var Issue $y */
            $y = $b[1];

            return [$x->isError() ? 0 : 1, $x->path, $x->line ?? 0, $x->column ?? 0, $x->code, $a[0]]
                <=> [$y->isError() ? 0 : 1, $y->path, $y->line ?? 0, $y->column ?? 0, $y->code, $b[0]];
        });

        return array_map(static fn (array $pair): Issue => $pair[1], $decorated);
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $i): bool => $i->isError()));
    }

    /**
     * @return list<Issue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $i): bool => $i->isWarning()));
    }

    public function ok(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Issue $i): string => $i->code, $this->issues);
    }

    public function hasCode(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function summary(): string
    {
        $errors = \count($this->errors());
        $warnings = \count($this->warnings());

        if ($errors === 0 && $warnings === 0) {
            return \sprintf('%s: OK - nenhum problema encontrado.', $this->source);
        }

        $partes = [];
        if ($errors !== 0) {
            $partes[] = $errors . ' erro' . ($errors === 1 ? '' : 's');
        }
        if ($warnings !== 0) {
            $partes[] = $warnings . ' aviso' . ($warnings === 1 ? '' : 's');
        }

        return \sprintf(
            '%s: %s encontrado%s.',
            $this->source,
            implode(' e ', $partes),
            ($errors + $warnings) === 1 ? '' : 's',
        );
    }

    /**
     * @return array{ok: bool, strict: bool, source: string, counts: array{errors: int, warnings: int}, issues: list<array<string, mixed>>, summary: string}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'strict' => $this->strict,
            'source' => $this->source,
            'counts' => [
                'errors' => \count($this->errors()),
                'warnings' => \count($this->warnings()),
            ],
            'issues' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues),
            'summary' => $this->summary(),
        ];
    }
}
