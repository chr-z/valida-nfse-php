<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Xml;

use ChrZ\ValidaNfse\Issue;

/**
 * Documento XML que nao pode ser lido como arvore.
 *
 * Carrega o Issue ja montado, para que o validador devolva um relatorio em vez
 * de estourar excecao na cara de quem chamou.
 */
final class ParseException extends \RuntimeException
{
    public function __construct(public readonly Issue $issue)
    {
        parent::__construct($issue->message);
    }
}
