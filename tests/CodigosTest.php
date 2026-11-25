<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura dos codigos de issue alcancaveis pelo validador.
 *
 * Os casos sao feitos com remocoes/substituicoes sobre o XML sintetico, nao
 * com blocos escritos a mao: assim o documento quebrado difere do valido por
 * exatamente uma coisa.
 */
final class CodigosTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function codigos(string $xml): array
    {
        return Validator::validate($xml)->codes();
    }

    private static function valido(): string
    {
        return Fixtures::documentoValido();
    }

    public function testXmlVazio(): void
    {
        self::assertContains('XML_VAZIO', self::codigos(''));
        self::assertContains('XML_VAZIO', self::codigos("   \n  "));
    }

    public function testXmlSoComDeclaracao(): void
    {
        self::assertContains('XML_SEM_RAIZ', self::codigos("<?xml version='1.0'?>"));
    }

    public function testXmlSoComComentario(): void
    {
        self::assertContains('XML_SEM_RAIZ', self::codigos("<?xml version='1.0'?><!-- nada -->"));
    }

    public function testEncodingInvalido(): void
    {
        $xml = '<?xml version="1.0" encoding="XINVALID-9"?><NFSe></NFSe>';
        $relatorio = Validator::validate($xml);

        self::assertFalse($relatorio->ok());
        self::assertTrue(
            $relatorio->hasCode('XML_ENCODING_INVALIDO') || $relatorio->hasCode('XML_MALFORMADO'),
            'esperava erro de encoding, veio ' . implode(',', $relatorio->codes()),
        );
    }

    public function testRaizComOutroNome(): void
    {
        $xml = str_replace(['<NFSe ', '</NFSe>'], ['<Outro ', '</Outro>'], self::valido());

        self::assertContains('ENVELOPE_RAIZ_INVALIDA', self::codigos($xml));
    }

    public function testNamespaceDesconhecidoEAviso(): void
    {
        $xml = str_replace('http://www.abrasf.org.br/nfse.xsd', 'http://exemplo.invalido/ns', self::valido());
        $relatorio = Validator::validate($xml);

        self::assertContains('RAIZ_NAMESPACE_DESCONHECIDO', $relatorio->codes());
        self::assertSame('RAIZ_NAMESPACE_DESCONHECIDO', $relatorio->warnings()[0]->code);
    }

    public function testNamespaceNacionalEAceitoSemAviso(): void
    {
        $xml = str_replace('http://www.abrasf.org.br/nfse.xsd', 'http://www.portalfiscal.inf.br/nfe', self::valido());

        $relatorio = Validator::validate($xml);
        self::assertNotContains('RAIZ_NAMESPACE_DESCONHECIDO', $relatorio->codes());
        self::assertTrue($relatorio->ok(), implode(',', $relatorio->codes()));
    }

    public function testNfseAusente(): void
    {
        self::assertContains('ENV_NFSE_AUSENTE', self::codigos('<?xml version="1.0"?><Raiz></Raiz>'));
    }

    public function testNfseVazia(): void
    {
        self::assertContains(
            'ENV_NFSE_VAZIA',
            self::codigos('<?xml version="1.0"?><NFSe xmlns="http://www.abrasf.org.br/nfse.xsd"></NFSe>'),
        );
    }

    public function testInfNfeAusente(): void
    {
        self::assertContains(
            'ENV_INFNFE_AUSENTE',
            self::codigos('<?xml version="1.0"?><NFSe xmlns="http://www.abrasf.org.br/nfse.xsd"><InfNFeSig></InfNFeSig></NFSe>'),
        );
    }

    public function testInfNfeVazia(): void
    {
        $xml = preg_replace('/<InfNFe>.*<\/InfNFe>/s', '<InfNFe></InfNFe>', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('ENV_INFNFE_VAZIA', self::codigos($xml));
    }

    public function testIdeVazio(): void
    {
        $xml = preg_replace('/<Ide>.*?<\/Ide>/s', '<Ide></Ide>', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('IDE_VAZIO', self::codigos($xml));
    }

    public function testEmitenteAusente(): void
    {
        $xml = preg_replace('/<Emitente>.*?<\/Emitente>/s', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('EMITENTE_AUSENTE', self::codigos($xml));
    }

    public function testEmitenteSoComFantasia(): void
    {
        $xml = str_replace('<xNome>EMPRESA SINTETICA DE TESTE LTDA</xNome>', '', self::valido());

        self::assertContains('EMITENTE_NOME_OBRIGATORIO', self::codigos($xml));
    }

    public function testEmitenteSemNomeENemFantasia(): void
    {
        $xml = str_replace(
            ['<xNome>EMPRESA SINTETICA DE TESTE LTDA</xNome>', '<xFant>EMPRESA SINTETICA</xFant>'],
            ['', ''],
            self::valido(),
        );

        self::assertContains('EMITENTE_RAZAO_SOCIAL_OBRIGATORIA', self::codigos($xml));
    }

    public function testEmitenteSemDocumento(): void
    {
        $xml = preg_replace('/<CNPJ>[^<]*<\/CNPJ>/', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('EMITENTE_CNPJ_OBRIGATORIO', self::codigos($xml));
    }

    public function testCepInvalido(): void
    {
        $xml = str_replace('<CEP>01001000</CEP>', '<CEP>0100100</CEP>', self::valido());

        self::assertContains('ENDERECO_CEP_INVALIDO', self::codigos($xml));
    }

    public function testTelefoneInvalido(): void
    {
        $xml = str_replace('<fone>1133334444</fone>', '<fone>123</fone>', self::valido());

        self::assertContains('ENDERECO_TEL_INVALIDO', self::codigos($xml));
    }

    public function testDestinatarioAusenteEAviso(): void
    {
        $xml = Fixtures::documentoBase(semDestinatario: true);
        $relatorio = Validator::validate($xml);

        self::assertContains('DESTINATARIO_AUSENTE', $relatorio->codes());
        self::assertTrue($relatorio->ok(), 'sem tomador nao pode ser erro');
    }

    public function testServicoAusente(): void
    {
        $xml = preg_replace('/<Servico><cMun>.*?<\/ListaServico><\/Servico>/s', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('SERVICO_AUSENTE', self::codigos($xml));
    }

    public function testListaServicoVazia(): void
    {
        self::assertContains('LISTA_SERVICO_VAZIA', self::codigos(Fixtures::documentoComListaVazia()));
    }

    public function testItemSemCodigo(): void
    {
        $xml = str_replace('<codTributacao>01.07</codTributacao>', '', self::valido());

        self::assertContains('ITEM_SERVICO_SEM_CODIGO', self::codigos($xml));
    }

    public function testItemComCodigoSemDigitos(): void
    {
        $xml = str_replace('<codTributacao>01.07</codTributacao>', '<codTributacao>abc</codTributacao>', self::valido());

        self::assertContains('ITEM_SERVICO_CODIGO_INVALIDO', self::codigos($xml));
    }

    public function testItemSemValor(): void
    {
        $xml = str_replace('<vServ>1000.00</vServ>', '<vServ></vServ>', self::valido());

        self::assertContains('ITEM_SERVICO_SEM_VALOR', self::codigos($xml));
    }

    public function testValorNaoNumerico(): void
    {
        $xml = str_replace('<vServ>1000.00</vServ>', '<vServ>mil</vServ>', self::valido());

        self::assertContains('VALOR_NAO_NUMERICO', self::codigos($xml));
    }

    public function testValorNegativo(): void
    {
        $xml = str_replace('<vServ>1000.00</vServ>', '<vServ>-1000.00</vServ>', self::valido());

        self::assertContains('VALOR_NEGATIVO', self::codigos($xml));
    }

    public function testAliquotaForaDaFaixa(): void
    {
        $xml = str_replace('<aliq>5.00</aliq>', '<aliq>101.00</aliq>', self::valido());

        self::assertContains('ITEM_SERVICO_ALIQUOTA_INVALIDA', self::codigos($xml));
    }

    public function testRetIssInvalido(): void
    {
        $xml = str_replace('<indISS>1</indISS>', '<indISS>1</indISS><retISS>7</retISS>', self::valido());

        self::assertContains('ITEM_SERVICO_ISS_RETIDO_INVALIDO', self::codigos($xml));
    }

    public function testTotalAusente(): void
    {
        $xml = preg_replace('/<Total>.*?<\/Total>/s', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('TOTAL_AUSENTE', self::codigos($xml));
    }

    public function testIssTotalAusente(): void
    {
        $xml = preg_replace('/<ISS>.*?<\/ISS>/s', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('TOTAL_ISS_TRIBUTARIO_AUSENTE', self::codigos($xml));
    }

    public function testIssRetidoTotalAusente(): void
    {
        $xml = preg_replace('/<ISSRetido>.*?<\/ISSRetido>/s', '', self::valido(), 1);
        self::assertNotNull($xml);

        self::assertContains('TOTAL_ISS_RECOLHIDO_AUSENTE', self::codigos($xml));
    }

    public function testTotalNaoNumerico(): void
    {
        $xml = str_replace('<vServicos>1000.00</vServicos>', '<vServicos>mil</vServicos>', self::valido());

        self::assertContains('TOTAL_DECIMAL_INVALIDO', self::codigos($xml));
    }

    public function testLiquidoNaoConfere(): void
    {
        $xml = str_replace('<vLiq>950.00</vLiq>', '<vLiq>800.00</vLiq>', self::valido());

        self::assertContains('LIQUIDO_NAO_CONFERE', self::codigos($xml));
    }

    public function testIssRetidoDivergente(): void
    {
        $xml = str_replace('<vISSRetido>0.00</vISSRetido>', '<vISSRetido>99.00</vISSRetido>', self::valido());

        self::assertContains('TOTAL_ISS_RECOLHIDO_DIVERGENTE', self::codigos($xml));
    }

    public function testAssinaturaForaDoInfNfeSig(): void
    {
        $xml = Fixtures::documentoBase(assinaturaFora: true);

        self::assertContains('ASSINATURA_FORA_DE_INFNFE', self::codigos($xml));
    }

    public function testAssinaturaVazia(): void
    {
        $xml = Fixtures::documentoBase(assinaturaVazia: true);

        self::assertContains('ASSINATURA_VAZIA', self::codigos($xml));
    }

    public function testAssinaturaSemReference(): void
    {
        $xml = Fixtures::documentoBase(assinaturaSemReference: true);

        self::assertContains('ASSINATURA_REFERENCE_AUSENTE', self::codigos($xml));
    }

    public function testAssinaturaComValorVazio(): void
    {
        $xml = Fixtures::documentoBase(assinaturaValorVazio: true);

        self::assertContains('ASSINATURA_SIGNATUREVALUE_VAZIA', self::codigos($xml));
    }

    public function testCatalogoTemOsCodigosPublicados(): void
    {
        $codigos = \ChrZ\ValidaNfse\Codes::all();

        self::assertGreaterThanOrEqual(80, \count($codigos));
        self::assertContains('XML_MALFORMADO', $codigos);
        self::assertContains('SOMA_VSERVICO_DIVERGENTE', $codigos);
        self::assertContains('ASSINATURA_URI_INVALIDA', $codigos);
        self::assertSame($codigos, array_unique($codigos), 'codigo duplicado no catalogo');
    }
}
