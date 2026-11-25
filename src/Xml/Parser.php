<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Xml;

use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Issue;

/**
 * Parser XML baseado em ext-xml (expat).
 *
 * Por que expat e nao DOM/SimpleXML? Porque nenhum dos dois expoe a posicao
 * (linha/coluna) de cada elemento, e posicao e justamente o que torna um
 * relatorio de XML malformado util para quem vai corrigir o arquivo.
 *
 * O parse roda com namespace processing DESLIGADO, igual ao parser de
 * referencia: assim os qnames mantem o prefixo original e as declaracoes
 * `xmlns` aparecem como atributos comuns, que e como o validador as le.
 *
 * Nenhuma entidade externa e resolvida: expat nao faz rede.
 */
final class Parser
{
    /**
     * Namespaces aceitos no elemento raiz: padrao ABRASF e padrao nacional.
     *
     * @var list<string>
     */
    public const NAMESPACES_ACEITOS = [
        'http://www.abrasf.org.br/nfse.xsd',
        'http://www.portalfiscal.inf.br/nfe',
    ];

    /**
     * Codificacoes que o proprio expat entende.
     *
     * @var list<string>
     */
    private const ENCODINGS_EXPAT = [
        'utf-8', 'utf8', 'us-ascii', 'ascii', 'iso-8859-1', 'iso8859-1', 'latin1',
        'utf-16', 'utf-16le', 'utf-16be', 'utf-16-le', 'utf-16-be',
    ];

    /**
     * @throws ParseException quando o documento nao e well-formed
     */
    public static function parse(string $data): Node
    {
        if (trim($data) === '') {
            throw new ParseException(new Issue(
                code: Codes::XML_VAZIO,
                severity: Issue::SEVERITY_ERROR,
                message: 'Documento XML vazio.',
                path: '',
                line: null,
                column: null,
                hint: 'O arquivo nao contem nenhum conteudo. Verifique se o processo de geracao gravou o XML em disco.',
            ));
        }

        [$payload, $problemaEncoding] = self::prepararCodificacao($data);
        if ($problemaEncoding instanceof Issue) {
            throw new ParseException($problemaEncoding);
        }

        $parser = xml_parser_create();
        // Nomes como vieram: `<abrasf:Ide>` continua `abrasf:Ide`.
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);

        $builder = new Builder($payload);

        self::registrarHandlers($parser, $builder);

        $ok = xml_parse($parser, $payload, true);

        // Declaracao com versao estranha (`version=".0"`, `version="10"`) e
        // aceita pelo expat do Python e recusada pelo do PHP ("XML declaration
        // not finished"). Como versao nao e regra de negocio aqui, tentamos de
        // novo com uma declaracao canonica de MESMO TAMANHO - assim nenhuma
        // posicao do documento se desloca. So acontece depois de uma falha:
        // documento que ja parseia nunca e reescrito.
        if ($ok !== 1) {
            $comCanonica = self::comDeclaracaoCanonica($payload);
            if ($comCanonica !== null) {
                $parser = xml_parser_create();
                xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
                $builder = new Builder($comCanonica);
                self::registrarHandlers($parser, $builder);

                $ok = xml_parse($parser, $comCanonica, true);
                if ($ok === 1) {
                    $payload = $comCanonica;
                }
            }
        }

        if ($ok !== 1) {
            throw new ParseException(self::classificarErro($payload, $parser));
        }

        $root = $builder->root;
        if (!$root instanceof Node) {
            [$linha, $coluna] = DocumentScan::posicao($payload, \strlen($payload));

            throw new ParseException(new Issue(
                code: Codes::XML_SEM_RAIZ,
                severity: Issue::SEVERITY_ERROR,
                message: 'Nenhum elemento raiz encontrado no documento.',
                path: '',
                line: $linha,
                column: $coluna,
                hint: 'Todo XML precisa de exatamente um elemento raiz. Confira se o conteudo comecou a ser gravado.',
            ));
        }

        return $root;
    }

    private static function registrarHandlers(\XMLParser $parser, Builder $builder): void
    {
        xml_set_element_handler(
            $parser,
            static function ($handle, string $name, array $attrs) use ($builder): void {
                $builder->start($handle, $name, $attrs);
            },
            static function ($handle, string $name) use ($builder): void {
                $builder->end();
            },
        );
        xml_set_character_data_handler(
            $parser,
            static function ($handle, string $data) use ($builder): void {
                $builder->characters($data);
            },
        );
    }

    /**
     * Reescreve a declaracao XML para uma canonica de mesmo tamanho.
     *
     * Devolve null quando o documento nao comeca com uma declaracao completa.
     */
    private static function comDeclaracaoCanonica(string $data): ?string
    {
        // So reescreve quando a estrutura da declaracao e exatamente a do
        // padrao XML: versao, encoding e standalone opcionais, nessa ordem.
        // Uma declaracao com atributo invalido (`vrsion=`, aspas soltas) nao
        // casa aqui e continua sendo reportada como erro.
        $padrao = <<<'RE'
/^(\s*)<\?xml\s+version\s*=\s*(["'])[^"']*\2(?:\s+encoding\s*=\s*(["'])[^"']*\3)?(?:\s+standalone\s*=\s*(["'])(?:yes|no)\4)?\s*\?>/
RE;

        if (preg_match($padrao, $data, $m) !== 1) {
            return null;
        }

        // A declaracao canonica nao carrega encoding (o texto ja foi convertido
        // para UTF-8). Se o documento declarava uma codificacao que o expat nao
        // conhece, o erro de encoding tem de aparecer - nao pode ser mascarado
        // pela reescrita.
        if (preg_match('/encoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/', $m[0], $enc) === 1
            && !\in_array(strtolower($enc[1]), self::ENCODINGS_EXPAT, true)) {
            return null;
        }

        $original = $m[0];
        $tamanho = \strlen($original);
        $canonica = '<?xml version="1.0"';

        if ($tamanho < \strlen($canonica) + 2) {
            return null;
        }

        $canonica .= str_repeat(' ', $tamanho - \strlen($canonica) - 2) . '?>';

        return $m[1] . $canonica . substr($data, $tamanho);
    }

    /**
     * Trata a declaracao de codificacao antes de entregar ao expat.
     *
     * O expat so conhece UTF-8/16, ASCII e ISO-8859-1. Documento de prefeitura
     * em ISO-8859-15 ou windows-1252 e comum, entao convertemos para UTF-8
     * quando o mbstring conhece a codificacao; se ninguem conhece, o erro e
     * declarado aqui, com a mensagem do validador de referencia.
     *
     * @return array{0: string, 1: Issue|null}
     */
    private static function prepararCodificacao(string $data): array
    {
        if (preg_match('/^\s*<\?xml[^>]*encoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/', $data, $m) === 1) {
            $declarada = $m[1];

            if (!\in_array(strtolower($declarada), self::ENCODINGS_EXPAT, true) && \function_exists('mb_convert_encoding')) {
                // mb_convert_encoding lanca ValueError (nao retorna false) quando
                // a codificacao e desconhecida nas versoes novas do PHP.
                try {
                    $teste = @mb_convert_encoding('a', 'UTF-8', $declarada);
                    if ($teste !== false && $teste !== '') {
                        $convertido = @mb_convert_encoding($data, 'UTF-8', $declarada);
                        if ($convertido !== false) {
                            $data = $convertido;
                        }
                    }
                } catch (\ValueError) {
                    // Codificacao desconhecida: o expat vai recusar e o erro
                    // vira XML_ENCODING_INVALIDO na classificacao.
                }
            }
        }

        return [$data, null];
    }

    /**
     * Traduz a falha do expat em Issue, com posicao util.
     *
     * A classificacao estrutural (sem raiz / raiz duplicada) vem do
     * DocumentScan, e nao da mensagem do expat, porque o texto do erro muda
     * entre versoes da lib.
     */
    private static function classificarErro(string $data, \XMLParser $parser): Issue
    {
        $codigo = xml_get_error_code($parser);
        $bruto = xml_error_string($codigo);
        $msg = $bruto === null || $bruto === '' ? 'erro desconhecido do parser' : $bruto;

        // Encoding errado e diagnostico inequivoco e vem antes de qualquer
        // inferencia estrutural: sem saber ler o texto, o resto e chute.
        if ($codigo === XML_ERROR_UNKNOWN_ENCODING
            || $codigo === XML_ERROR_INCORRECT_ENCODING
            || (\defined('XML_ERROR_UNSUPPORTED_ENCODING') && $codigo === XML_ERROR_UNSUPPORTED_ENCODING)
            || stripos($msg, 'encoding') !== false) {
            return new Issue(
                code: Codes::XML_ENCODING_INVALIDO,
                severity: Issue::SEVERITY_ERROR,
                message: \sprintf('Declaracao de codificacao invalida: %s.', $msg),
                path: '',
                line: 1,
                column: 1,
                hint: 'Use uma codificacao suportada pelo XML (UTF-8 e o recomendado). Em PHP, converta com mb_convert_encoding($xml, \'UTF-8\', \'UTF-8\').',
            );
        }

        $scan = DocumentScan::analisar($data);

        // Sem raiz pode significar duas coisas bem diferentes: documento que
        // so tem declaracao/comentario (XML_SEM_RAIZ) ou documento cuja
        // declaracao/primeiro token esta quebrado (XML_MALFORMADO). Quem
        // separa e o erro do parser.
        $codigoErro = xml_get_error_code($parser);
        $textoErro = xml_error_string($codigoErro) ?? '';
        $familiaSemRaiz = str_contains($textoErro, 'no element found')
            || str_contains($textoErro, 'Invalid document end')
            || $codigoErro === XML_ERROR_NO_ELEMENTS;

        if (!$scan->temRaiz && !$familiaSemRaiz) {
            return self::issueMalformado($data, $parser);
        }

        if (!$scan->temRaiz) {
            [$linha, $coluna] = DocumentScan::posicao($data, \strlen($data));

            return new Issue(
                code: Codes::XML_SEM_RAIZ,
                severity: Issue::SEVERITY_ERROR,
                message: 'Nenhum elemento raiz encontrado no documento.',
                path: '',
                line: $linha,
                column: $coluna,
                hint: 'Todo XML precisa de exatamente um elemento raiz. O documento tem apenas declaracoes ou comentarios.',
            );
        }

        if ($scan->extraEm !== null && $scan->raizFim !== null) {
            // Antes de afirmar "duas raizes", confirme que a parte ate o fim da
            // primeira raiz e um documento valido. Se ela mesma estiver
            // quebrada, o problema e de mala formacao, nao de raiz duplicada.
            $prefixo = substr($data, 0, $scan->raizFim);
            $teste = xml_parser_create();
            xml_parser_set_option($teste, XML_OPTION_CASE_FOLDING, 0);
            $prefixoOk = xml_parse($teste, $prefixo, true) === 1;

            if (!$prefixoOk) {
                return self::issueMalformado($data, $parser);
            }

            [$linha, $coluna] = DocumentScan::posicao($data, $scan->extraEm);

            return new Issue(
                code: Codes::XML_RAIZES_MULTIPLAS,
                severity: Issue::SEVERITY_ERROR,
                message: 'Ha mais de um elemento raiz no documento.',
                path: '',
                line: $linha,
                column: $coluna,
                hint: 'Um documento XML so pode ter uma raiz. Voce provavelmente concatenou duas NFS-e; gere um arquivo por nota.',
            );
        }

        // O expat do PHP aponta para dentro do token que falhou; o de Python
        // aponta para o '<' que inicia esse token. Recuamos ate o '<' para que
        // os dois relatorios mostrem a mesma coluna.
        $byte = xml_get_current_byte_index($parser);
        $byte = $byte > 0 ? min($byte, \strlen($data)) : 0;
        $antes = substr($data, 0, $byte);
        $ultimoAbre = strrpos($antes, '<');
        $ultimoFecha = strrpos($antes, '>');
        $offset = $byte;
        if ($ultimoAbre !== false
            && ($ultimoFecha === false || $ultimoFecha < $ultimoAbre)
            && !str_contains(substr($data, $ultimoAbre, $byte - $ultimoAbre), "\n")) {
            $offset = $ultimoAbre;
        }

        [$linha, $coluna] = DocumentScan::posicao($data, $offset);

        // Documento que termina antes de fechar a raiz: o expat chama isso de
        // "no element found" (Python) ou "Invalid document end" (PHP). A
        // diferenca que importa: se a ultima coisa no arquivo e um '<' sem
        // '>', o token ficou pela metade e o erro e de mala formacao, nao de
        // ausencia de raiz.
        if ($codigo === XML_ERROR_NO_ELEMENTS
            || str_contains($msg, 'no element found')
            || str_contains($msg, 'Invalid document end')) {
            $ultimaAbertura = strrpos($data, '<');
            $ultimoFechamento = strrpos($data, '>');
            $tagIncompleta = $ultimaAbertura !== false
                && ($ultimoFechamento === false || $ultimaAbertura > $ultimoFechamento);

            if ($tagIncompleta) {
                return new Issue(
                    code: Codes::XML_MALFORMADO,
                    severity: Issue::SEVERITY_ERROR,
                    message: 'XML malformado: unclosed token.',
                    path: '',
                    line: $linha,
                    column: $coluna,
                    hint: 'Ha uma tag aberta que nao foi fechada. Confira o aninhamento de <InfNFe>, <Ide> e <Total>.',
                );
            }

            return new Issue(
                code: Codes::XML_SEM_RAIZ,
                severity: Issue::SEVERITY_ERROR,
                message: 'Nenhum elemento raiz encontrado no documento.',
                path: '',
                line: $linha,
                column: $coluna,
                hint: 'Todo XML precisa de exatamente um elemento raiz. O documento tem apenas declaracoes ou comentarios.',
            );
        }

        return new Issue(
            code: Codes::XML_MALFORMADO,
            severity: Issue::SEVERITY_ERROR,
            message: \sprintf('XML malformado: %s.', $msg),
            path: '',
            line: $linha,
            column: $coluna,
            hint: self::hintMalformado($msg),
        );
    }

    /**
     * Issue de XML malformado a partir do estado atual do parser.
     */
    private static function issueMalformado(string $data, \XMLParser $parser): Issue
    {
        $codigo = xml_get_error_code($parser);
        $bruto = xml_error_string($codigo);
        $msg = $bruto === null || $bruto === '' ? 'erro desconhecido do parser' : $bruto;

        $byte = xml_get_current_byte_index($parser);
        $byte = $byte > 0 ? min($byte, \strlen($data)) : 0;
        [$linha, $coluna] = DocumentScan::posicao($data, $byte);

        return new Issue(
            code: Codes::XML_MALFORMADO,
            severity: Issue::SEVERITY_ERROR,
            message: \sprintf('XML malformado: %s.', $msg),
            path: '',
            line: $linha,
            column: $coluna,
            hint: self::hintMalformado($msg),
        );
    }

    private static function hintMalformado(string $msg): string
    {
        if (str_contains($msg, 'no element found') || str_contains($msg, 'Invalid document end')) {
            return 'O documento nao tem elemento raiz. Verifique se o arquivo foi cortado no meio da escrita ou se a string esta vazia.';
        }
        if (str_contains($msg, 'unclosed token') || str_contains($msg, 'mismatched tag') || str_contains($msg, 'Mismatched tag') || str_contains($msg, 'tag mismatch')) {
            return 'Ha uma tag aberta que nao foi fechada. Confira o aninhamento de <InfNFe>, <Ide> e <Total>.';
        }
        if (str_contains($msg, 'not well-formed') || str_contains($msg, 'syntax error') || str_contains($msg, 'invalid token')) {
            return 'Algum caractere especial (ex.: & ou <) aparece no texto sem escape. Use &amp; e &lt;.';
        }

        return 'Revise o XML gerado: tags balanceadas, atributos com aspas, sem & solto no texto.';
    }
}

/**
 * Constroi a arvore de Node a partir dos eventos do expat.
 *
 * @internal
 */
final class Builder
{
    public ?Node $root = null;

    /** @var list<Node> */
    private array $stack = [];

    /** @var list<array<string, string>> */
    private array $nsStack = [[]];

    /** @var list<int> offsets where each line starts */
    private array $lineStarts = [0];

    /** @var int cursor into the source, so successive lookups stay linear */
    private int $cursor = 0;

    public function __construct(private readonly string $source)
    {
        $offset = 0;
        while (($pos = strpos($this->source, "\n", $offset)) !== false) {
            $this->lineStarts[] = $pos + 1;
            $offset = $pos + 1;
        }
    }

    /**
     * @param array<string, string> $attrs
     */
    public function start(\XMLParser $parser, string $qname, array $attrs): void
    {
        // O expat do PHP reporta a posicao do parser no FIM da tag de abertura,
        // enquanto o validador de referencia reporta o inicio dela. Para os
        // dois concordarem (e para a coluna ser util em documento de uma linha
        // so), localizamos o offset real do '<tag' no texto de origem.
        $posicao = $this->localizar('<' . $qname, $parser);
        $line = $posicao[0];
        $column = $posicao[1];

        [$prefix, $local] = self::splitQname($qname);

        $parentNs = $this->nsStack[\count($this->nsStack) - 1];
        $own = $parentNs;
        $plain = [];
        foreach ($attrs as $key => $value) {
            $name = (string) $key;
            $val = (string) $value;
            if ($name === 'xmlns') {
                $own[''] = $val;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $own[substr($name, 6)] = $val;
            } else {
                $plain[$name] = $val;
            }
        }

        $node = new Node(
            tag: $local,
            prefix: $prefix,
            attributes: $plain,
            text: '',
            line: $line !== null && $line > 0 ? $line : null,
            column: $column > 0 ? $column : null,
            nsDeclarations: $own,
        );

        if ($this->stack !== []) {
            $parent = $this->stack[\count($this->stack) - 1];
            $node->parent = $parent;
            $parent->children[] = $node;
        } elseif ($this->root === null) {
            $this->root = $node;
        }

        $this->stack[] = $node;
        $this->nsStack[] = $own;
    }

    public function end(): void
    {
        if ($this->stack !== []) {
            array_pop($this->stack);
        }
        if (\count($this->nsStack) > 1) {
            array_pop($this->nsStack);
        }
    }

    public function characters(string $data): void
    {
        if ($this->stack === []) {
            return;
        }
        $atual = \count($this->stack) - 1;
        $node = $this->stack[$atual];
        // Node e imutavel para o texto: reconstruimos o no corrente mantendo
        // identidade (mesma instancia) via mutacao controlada.
        $node->appendText($data);
    }

    /**
     * Posicao 1-based (linha, coluna) do inicio da tag no texto de origem.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function localizar(string $agulha, \XMLParser $parser): array
    {
        $pos = strpos($this->source, $agulha, $this->cursor);
        if ($pos === false) {
            // Fallback: posicao do parser (fim da tag), melhor que nada.
            $linha = xml_get_current_line_number($parser);
            $coluna = xml_get_current_column_number($parser) + 1;

            return [$linha > 0 ? $linha : null, $coluna > 0 ? $coluna : null];
        }

        $this->cursor = $pos + \strlen($agulha);

        $indice = $this->binaria($pos);
        $linha = $indice + 1;
        $coluna = $pos - $this->lineStarts[$indice] + 1;

        return [$linha, $coluna];
    }

    private function binaria(int $offset): int
    {
        $baixo = 0;
        $alto = \count($this->lineStarts) - 1;

        while ($baixo < $alto) {
            $meio = intdiv($baixo + $alto + 1, 2);
            if ($this->lineStarts[$meio] <= $offset) {
                $baixo = $meio;
            } else {
                $alto = $meio - 1;
            }
        }

        return $baixo;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitQname(string $qname): array
    {
        $pos = strpos($qname, ':');
        if ($pos === false) {
            return ['', $qname];
        }

        return [substr($qname, 0, $pos), substr($qname, $pos + 1)];
    }
}
