<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Regras de validacao sobre o XML sintetico: cada teste parte de um documento
 * valido e quebra exatamente uma coisa.
 */
final class ValidacaoTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function codigos(string $xml): array
    {
        return Validator::validate($xml)->codes();
    }

    public function testDocumentoValidoPassaSemIssues(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoValido());

        self::assertTrue($relatorio->ok(), 'erros: ' . implode(', ', $relatorio->codes()));
        self::assertSame([], $relatorio->errors());
        self::assertSame([], $relatorio->warnings());
    }

    public function testInfNfeApareceUmaVezSo(): void
    {
        $xml = Fixtures::documentoValido();
        self::assertSame(1, substr_count($xml, '<InfNFe>'));
        self::assertSame(1, substr_count($xml, '<InfNFeSig>'));
    }

    public function testIdeAusente(): void
    {
        self::assertContains('IDE_AUSENTE', self::codigos(Fixtures::documentoQuebrado('ide_ausente')));
    }

    public function testInfNfeSigAusente(): void
    {
        self::assertContains('INFNFESIG_AUSENTE', self::codigos(Fixtures::documentoQuebrado('infnsig_ausente')));
    }

    public function testOrdemIncorreta(): void
    {
        self::assertContains('IDE_ORDEM_INVALIDA', self::codigos(Fixtures::documentoQuebrado('ordem_incorreta')));
    }

    public function testXmlMalformadoTrazPosicao(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('xml_malformado'));

        self::assertContains('XML_MALFORMADO', $relatorio->codes());
        $erro = $relatorio->errors()[0];
        self::assertNotNull($erro->line);
        self::assertGreaterThan(0, $erro->line);
    }

    public function testRaizDuplicada(): void
    {
        self::assertContains('XML_RAIZES_MULTIPLAS', self::codigos(Fixtures::documentoQuebrado('raiz_duplicada')));
    }

    public function testInfNfeDuplicado(): void
    {
        $xml = Fixtures::documentoValido();
        $inicio = strpos($xml, '<InfNFe>');
        self::assertNotFalse($inicio);
        $trecho = substr($xml, $inicio);
        $fim = strpos($trecho, '</InfNFe>');
        self::assertNotFalse($fim);
        $infnfe = substr($trecho, 0, $fim + \strlen('</InfNFe>'));

        $duplicado = str_replace('</InfNFeSig>', '</InfNFeSig>' . $infnfe, $xml);

        self::assertContains('ENV_INFNFE_DUPLICADO', self::codigos($duplicado));
    }

    public function testDocumentoVazio(): void
    {
        $relatorio = Validator::validate('');

        self::assertContains('XML_VAZIO', $relatorio->codes());
        self::assertFalse($relatorio->ok());
    }

    public function testTodoErroTrazHint(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('ide_ausente'));

        foreach ($relatorio->errors() as $erro) {
            self::assertNotSame('', $erro->hint, 'todo erro deve trazer uma dica de correcao');
        }
    }

    public function testCstatInvalido(): void
    {
        self::assertContains('IDE_CSTAT_INVALIDO', self::codigos(Fixtures::documentoQuebrado('cstat_invalido')));
    }

    public function testNumeroZero(): void
    {
        self::assertContains('IDE_NNF_NAO_POSITIVO', self::codigos(Fixtures::documentoQuebrado('nnf_zero')));
    }

    public function testCnpjComDvInvalido(): void
    {
        self::assertContains('EMITENTE_CNPJ_INVALIDO', self::codigos(Fixtures::documentoQuebrado('cnpj_dv_invalido')));
    }

    public function testCpfComDvInvalido(): void
    {
        self::assertContains('DESTINATARIO_CPF_INVALIDO', self::codigos(Fixtures::documentoQuebrado('cpf_dv_invalido')));
    }

    public function testCnpjComCpfNoCampoErrado(): void
    {
        self::assertContains('EMITENTE_CNPJ_E_CPF', self::codigos(Fixtures::documentoQuebrado('cnpj_com_cpf')));
    }

    public function testUfInvalida(): void
    {
        self::assertContains('EMITENTE_UF_INVALIDA', self::codigos(Fixtures::documentoQuebrado('uf_invalida')));
    }

    public function testChaveInvalida(): void
    {
        self::assertContains('IDE_CHAVE_ACESSO_INVALIDA', self::codigos(Fixtures::documentoQuebrado('chave_invalida')));
    }

    public function testDataFuturaEAvisoNaoErro(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('dtemi_futuro'));

        $codigosAviso = array_map(
            static fn ($i): string => $i->code,
            $relatorio->warnings(),
        );
        self::assertContains('IDE_DTEMI_FUTURO', $codigosAviso);
        self::assertNotContains('IDE_DTEMI_FUTURO', array_map(static fn ($i): string => $i->code, $relatorio->errors()));
    }

    public function testSomaDeServicosDivergente(): void
    {
        self::assertContains('SOMA_VSERVICO_DIVERGENTE', self::codigos(Fixtures::documentoQuebrado('vservico_divergente')));
    }

    public function testSomaDeIssDivergente(): void
    {
        self::assertContains('SOMA_VISS_DIVERGENTE', self::codigos(Fixtures::documentoQuebrado('viss_divergente')));
    }

    public function testListaVaziaComTotaisNaoZerados(): void
    {
        $codigos = self::codigos(Fixtures::documentoQuebrado('lista_vazia_com_total'));

        self::assertContains('LISTA_SERVICO_VAZIA', $codigos);
        self::assertContains('TOTAL_SEM_ITENS', $codigos);
        self::assertContains('TOTAL_SEM_ITENS_NAO_ZERO', $codigos);
    }

    public function testDiferencaDeUmCentavoPassa(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('diferenca_um_centavo'));

        self::assertNotContains('SOMA_VSERVICO_DIVERGENTE', $relatorio->codes());
    }

    public function testDiferencaDeDoisCentavosFalha(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('diferenca_dois_centavos'));

        self::assertContains('SOMA_VSERVICO_DIVERGENTE', $relatorio->codes());
    }

    public function testAliquotaImplicitaDivergenteEAviso(): void
    {
        $relatorio = Validator::validate(Fixtures::documentoQuebrado('aliquota_divergente'));

        $codigos = array_map(static fn ($i): string => $i->code, $relatorio->warnings());
        self::assertContains('ITEM_SERVICO_ALIQUOTA_DIVERGENTE', $codigos);
    }

    public function testUriDeReferenceInvalida(): void
    {
        self::assertContains('ASSINATURA_URI_INVALIDA', self::codigos(Fixtures::documentoQuebrado('assinatura_uri')));
    }

    public function testAssinaturaSemSignatureValue(): void
    {
        self::assertContains('ASSINATURA_SIGNATUREVALUE_AUSENTE', self::codigos(Fixtures::documentoQuebrado('assinatura_incompleta')));
    }

    public function testStrictPromoveAvisoAErro(): void
    {
        $xml = Fixtures::documentoQuebrado('aliquota_divergente');

        $normal = Validator::validate($xml);
        self::assertTrue($normal->ok());
        self::assertSame('ITEM_SERVICO_ALIQUOTA_DIVERGENTE', $normal->warnings()[0]->code);

        $estrito = Validator::validate($xml, strict: true);
        self::assertFalse($estrito->ok());
        self::assertSame('ITEM_SERVICO_ALIQUOTA_DIVERGENTE', $estrito->errors()[0]->code);
        self::assertSame([], $estrito->warnings());
    }

    public function testStrictNaoMudaDocumentoValido(): void
    {
        self::assertTrue(Validator::validate(Fixtures::documentoValido(), strict: true)->ok());
    }

    public function testToleranciaCustomizada(): void
    {
        $xml = Fixtures::documentoQuebrado('diferenca_dois_centavos');

        $padrao = Validator::validate($xml);
        self::assertContains('SOMA_VSERVICO_DIVERGENTE', $padrao->codes());

        $folgada = Validator::validate($xml, tolerancia: \ChrZ\ValidaNfse\Brazil\Decimal::fromString('0.05'));
        self::assertNotContains('SOMA_VSERVICO_DIVERGENTE', $folgada->codes());
    }

    public function testRelogioInjetavel(): void
    {
        $xml = Fixtures::documentoBase(dtEmi: '2026-01-15T10:30:00-03:00');

        $em2026 = Validator::validate($xml, agora: new \DateTimeImmutable('2026-01-16T00:00:00+00:00'));
        self::assertTrue($em2026->ok(), implode(',', $em2026->codes()));

        $em2027 = Validator::validate($xml, agora: new \DateTimeImmutable('2027-06-01T00:00:00+00:00'));
        self::assertContains('IDE_DTEMI_ANTIGO', array_map(static fn ($i): string => $i->code, $em2027->warnings()));
    }
}
