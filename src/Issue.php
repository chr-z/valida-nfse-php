<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse;

/**
 * Um problema encontrado no XML.
 *
 * Imutavel de proposito: o relatorio e um retrato do documento, nao um fluxo
 * mutavel. `code` e estavel e seguro para uso em CI; `path` e um caminho
 * XPath-ish (NFSe/InfNFeSig/InfNFe/Ide) e `line`/`column` sao 1-based.
 */
final class Issue
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $path = '',
        public readonly ?int $line = null,
        public readonly ?int $column = null,
        public readonly string $hint = '',
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    /**
     * Copia do issue com severidade promovida a erro (modo estrito).
     */
    public function promoted(): self
    {
        if ($this->isError()) {
            return $this;
        }

        return new self(
            $this->code,
            self::SEVERITY_ERROR,
            $this->message,
            $this->path,
            $this->line,
            $this->column,
            $this->hint,
        );
    }

    /**
     * @return array{code: string, severity: string, message: string, path: string, line: int|null, column: int|null, hint: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
            'hint' => $this->hint,
        ];
    }

    /**
     * Localizacao legivel: caminho e, quando conhecida, linha/coluna.
     */
    public function location(): string
    {
        if ($this->line === null && $this->column === null) {
            return $this->path !== '' ? $this->path : '-';
        }

        return \sprintf(
            '%s (linha %s, coluna %s)',
            $this->path !== '' ? $this->path : '-',
            $this->line === null ? '?' : (string) $this->line,
            $this->column === null ? '?' : (string) $this->column,
        );
    }
}
