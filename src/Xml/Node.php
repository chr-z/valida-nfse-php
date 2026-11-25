<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Xml;

/**
 * Um elemento XML com posicao e caminho resolvidos.
 *
 * A arvore e minimalista de proposito: tag local, prefixo, atributos, texto,
 * filhos, pai e posicao. Nao tentamos ser um DOM completo — so o suficiente
 * para dizer *onde* esta o problema, que e o que torna o relatorio util.
 *
 * A comparacao e sempre por tag local (sem prefixo): `<abrasf:Ide>` e `<Ide>`
 * respondem igual, porque o namespace nao muda a regra de negocio.
 */
final class Node
{
    /** @var list<Node> */
    public array $children = [];

    public ?Node $parent = null;

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $nsDeclarations
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $prefix = '',
        public readonly array $attributes = [],
        public string $text = '',
        public readonly ?int $line = null,
        public readonly ?int $column = null,
        public readonly array $nsDeclarations = [],
    ) {
    }

    /**
     * Caminho XPath-ish do elemento (`NFSe/InfNFeSig/InfNFe/Ide`).
     */
    public function path(): string
    {
        $partes = [];
        $atual = $this;
        while ($atual instanceof self) {
            $partes[] = ($atual->prefix !== '' ? $atual->prefix . ':' : '') . $atual->tag;
            $atual = $atual->parent;
        }

        return implode('/', array_reverse($partes));
    }

    public function childPath(string $tag): string
    {
        return $this->path() . '/' . ($this->prefix !== '' ? $this->prefix . ':' : '') . $tag;
    }

    public function find(string $tag): ?self
    {
        foreach ($this->children as $child) {
            if ($child->tag === $tag) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<Node>
     */
    public function findAll(string $tag): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (self $c): bool => $c->tag === $tag,
        ));
    }

    /**
     * Texto limpo do primeiro filho direto `$tag`.
     */
    public function value(string $tag, string $default = ''): string
    {
        $node = $this->find($tag);
        if ($node === null) {
            return $default;
        }

        return trim($node->text);
    }

    /**
     * True quando o filho `$tag` nao existe ou tem texto vazio.
     */
    public function isEmpty(string $tag): bool
    {
        $node = $this->find($tag);

        return $node === null || trim($node->text) === '';
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Soma texto ao elemento (usado pelo parser durante o SAX).
     *
     * @internal
     */
    public function appendText(string $data): void
    {
        $this->text .= $data;
    }

    /**
     * True quando o elemento nao tem texto util nem filhos.
     */
    public function isBlank(): bool
    {
        return trim($this->text) === '' && $this->children === [];
    }

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }

    public function namespaceUri(): string
    {
        return $this->nsDeclarations[''] ?? '';
    }

    public function __toString(): string
    {
        return $this->path();
    }
}
