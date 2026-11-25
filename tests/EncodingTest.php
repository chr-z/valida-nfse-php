<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Declaracao de codificacao: documento de prefeitura em ISO-8859-1,
 * windows-1252 e o caso de charset que nao existe.
 */
final class EncodingTest extends TestCase
{
    private function comEncoding(string $encoding, string $acento = 'Servico de teste'): string
    {
        $xml = Fixtures::documentoValido();
        $xml = str_replace('Servicos de teste sintetico', $acento, $xml);
        $xml = str_replace('encoding="UTF-8"', "encoding=\"{$encoding}\"", $xml);

        return $xml;
    }

    public function testUtf8PadraoPassa(): void
    {
        self::assertTrue(Validator::validate($this->comEncoding('UTF-8', 'Serviços de teste'))->ok());
    }

    public function testIso88591Passa(): void
    {
        $utf8 = $this->comEncoding('ISO-8859-1', 'Serviços de teste');
        $latin1 = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');

        self::assertTrue(Validator::validate($latin1)->ok(), 'documento ISO-8859-1 deveria validar');
    }

    public function testWindows1252EConvertidoParaUtf8(): void
    {
        $utf8 = $this->comEncoding('windows-1252', 'Serviços de teste');
        $cp1252 = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

        $relatorio = Validator::validate($cp1252);
        self::assertTrue($relatorio->ok(), 'codigos: ' . implode(',', $relatorio->codes()));
    }

    public function testEncodingInexistenteERejeitado(): void
    {
        // Passa pelo XML antes da validacao: o parser tem de recusar.
        $relatorio = Validator::validate('<?xml version="1.0" encoding="XINVALID-9"?><NFSe></NFSe>');

        self::assertFalse($relatorio->ok());
        self::assertContains('XML_ENCODING_INVALIDO', $relatorio->codes());
    }

    public function testVersaoEstranhaNaDeclaracaoNaoDerrubaOParse(): void
    {
        $xml = $this->comEncoding('UTF-8');
        $xml = str_replace('version="1.0"', 'version=".0"', $xml);

        $relatorio = Validator::validate($xml);
        self::assertTrue($relatorio->ok(), 'codigos: ' . implode(',', $relatorio->codes()));
    }

    public function testDeclaracaoComAtributoInvalidoContinuaSendoErro(): void
    {
        $xml = $this->comEncoding('UTF-8');
        $xml = str_replace('version="1.0"', 'vrsion="1.0"', $xml);

        self::assertFalse(Validator::validate($xml)->ok());
    }

    public function testDocumentoMultilinhaMantemLinhaCorreta(): void
    {
        $xml = str_replace('><', ">\n  <", Fixtures::documentoQuebrado('ide_ausente'));
        $relatorio = Validator::validate($xml);

        $erro = $relatorio->errors()[0];
        self::assertSame('IDE_AUSENTE', $erro->code);
        self::assertGreaterThan(1, $erro->line ?? 0);
    }

    public function testPrefixoDeNamespaceNaoMudaARegra(): void
    {
        $xml = Fixtures::documentoValido();
        $xml = str_replace(
            ['<NFSe ', '</NFSe>', '<InfNFeSig>', '</InfNFeSig>', '<InfNFe>', '</InfNFe>', '<Ide>', '</Ide>'],
            ['<abrasf:NFSe ', '</abrasf:NFSe>', '<abrasf:InfNFeSig>', '</abrasf:InfNFeSig>', '<abrasf:InfNFe>', '</abrasf:InfNFe>', '<abrasf:Ide>', '</abrasf:Ide>'],
            $xml,
        );

        $relatorio = Validator::validate($xml);
        self::assertTrue($relatorio->ok(), 'codigos: ' . implode(',', $relatorio->codes()));
    }
}
