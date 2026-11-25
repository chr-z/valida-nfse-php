<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Issue;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Estado compartilhado entre as verificacoes.
 *
 * Carrega o documento ja parseado, a configuracao e o acumulador de issues.
 * Cada check recebe o Ctx e devolve (ou acumula) problemas.
 */
final class Ctx
{
    /** @var list<Issue> */
    public array $issues = [];

    public function __construct(
        public readonly Node $root,
        public readonly string $source = '-',
        public readonly Decimal $tolerancia = new Decimal(10000),
        public readonly Decimal $toleranciaAliquota = new Decimal(10000),
        /** Injetavel em teste; quando nulo, usa o relogio do sistema. */
        public readonly ?\DateTimeImmutable $agora = null,
        /** Tolerancia de relogio, em segundos. */
        public readonly ?int $skew = null,
    ) {
    }

    public function erro(string $code, string $message, string $path, string $hint = '', ?Node $node = null): Issue
    {
        return $this->add(new Issue($code, Issue::SEVERITY_ERROR, $message, $path, $node?->line, $node?->column, $hint));
    }

    public function aviso(string $code, string $message, string $path, string $hint = '', ?Node $node = null): Issue
    {
        return $this->add(new Issue($code, Issue::SEVERITY_WARNING, $message, $path, $node?->line, $node?->column, $hint));
    }

    private function add(Issue $issue): Issue
    {
        $this->issues[] = $issue;

        return $issue;
    }

    public function decimalDe(?Node $node, string $tag): ?Decimal
    {
        if ($node === null) {
            return null;
        }

        return Decimal::fromString($node->value($tag));
    }

    public function textoDe(?Node $node, string $tag): string
    {
        return $node?->value($tag) ?? '';
    }

    public function opcional(?Node $node, string $tag): ?Node
    {
        return $node?->find($tag);
    }
}
