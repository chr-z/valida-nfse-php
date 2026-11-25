<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Tests;

use ChrZ\ValidaNfse\Brazil\ChaveAcesso;
use ChrZ\ValidaNfse\Brazil\Documentos;

/**
 * Fabrica de XML sintetico de NFS-e para testes e demonstracoes.
 *
 * Todos os dados sao SINTETICOS: CNPJ, CPF, chave de acesso, enderecos e nomes
 * sao gerados algoritmicamente. Os digitos verificadores sao calculados de
 * verdade, para que a validacao exercite o caminho feliz.
 */
final class Fixtures
{
    public static function cnpjSintetico(): string
    {
        return Documentos::gerarCnpj('112223330001');
    }

    public static function cpfSintetico(): string
    {
        return Documentos::gerarCpf('987654321');
    }

    public static function itemServico(
        string $codigo = '01.07',
        string $descricao = 'Servico de teste sintetico',
        string $vServ = '1000.00',
        string $vBc = '1000.00',
        string $vIss = '50.00',
        string $aliq = '5.00',
        string $vTotTrib = '120.00',
        ?array $extra = null,
    ): string {
        $partes = [
            "<codTributacao>{$codigo}</codTributacao>",
            "<xServ>{$descricao}</xServ>",
            "<vServ>{$vServ}</vServ>",
            "<vBC>{$vBc}</vBC>",
            "<aliq>{$aliq}</aliq>",
            "<vISS>{$vIss}</vISS>",
        ];

        if ($vTotTrib !== '') {
            $partes[] = "<vTotTrib>{$vTotTrib}</vTotTrib>";
        }

        $partes[] = '<indISS>1</indISS>';

        foreach ($extra ?? [] as $chave => $valor) {
            $partes[] = "<{$chave}>{$valor}</{$chave}>";
        }

        return '<Servico>' . implode('', $partes) . '</Servico>';
    }

    /**
     * Data de emissao recente, com fuso de Sao Paulo, calculada de hoje.
     */
    public static function dataReferencia(): string
    {
        $agora = new \DateTimeImmutable('now', new \DateTimeZone('-03:00'));
        $alvo = $agora->modify('-2 days');

        return $alvo->setTime((int) $alvo->format('H'), (int) $alvo->format('i'), 0)->format('Y-m-d\TH:i:sP');
    }

    public static function chaveDeReferencia(string $cnpj, string $uf, int $numero): string
    {
        $agora = new \DateTimeImmutable('now', new \DateTimeZone('-03:00'));

        return ChaveAcesso::gerar(
            uf: $uf,
            cnpj: $cnpj,
            numero: $numero,
            ano: (int) $agora->format('Y'),
            mes: (int) $agora->format('n'),
        );
    }

    /**
     * Monta um XML de NFS-e sintetico. Os parametros existem para que cada
     * teste quebre exatamente uma coisa.
     *
     * @param list<string>|null $itens
     */
    public static function documentoBase(
        ?string $dtEmi = null,
        ?string $chave = null,
        ?string $cnpj = null,
        ?string $cpfDest = null,
        ?array $itens = null,
        string $vServicos = '1000.00',
        string $vBcTotal = '1000.00',
        string $vIssTotal = '50.00',
        string $vTotTribTotal = '120.00',
        string $vLiq = '950.00',
        string $vDescIncond = '50.00',
        string $vIssRetido = '0.00',
        string $cStat = '1',
        string $nNf = '1',
        string $cMunFg = '3550308',
        string $uf = 'SP',
        bool $semDestinatario = false,
        bool $semIde = false,
        bool $semInfnsig = false,
        bool $assinaturaIncompleta = false,
        bool $assinaturaVazia = false,
        bool $assinaturaSemReference = false,
        bool $assinaturaSemValor = false,
        bool $assinaturaValorVazio = false,
        bool $assinaturaFora = false,
        bool $uriInvalida = false,
    ): string {
        $cnpj ??= self::cnpjSintetico();
        $cpfDest ??= self::cpfSintetico();
        $chave ??= self::chaveDeReferencia($cnpj, $uf, (int) ($nNf !== '' ? $nNf : '1'));
        $dtEmi ??= self::dataReferencia();

        if ($itens === null) {
            $itens = [self::itemServico()];
        }

        $idePartes = [];
        if (!$semIde) {
            $idePartes = [
                "<cStat>{$cStat}</cStat>",
                '<cServ>1</cServ>',
                "<cMunFG>{$cMunFg}</cMunFG>",
                "<nNF>{$nNf}</nNF>",
                "<dhEmi>{$dtEmi}</dhEmi>",
                '<tpNF>1</tpNF>',
                "<cMun>{$cMunFg}</cMun>",
                '<tpImp>1</tpImp>',
                '<tpEmis>1</tpEmis>',
                '<cDV>1</cDV>',
                '<tpAmb>2</tpAmb>',
                '<indFinal>0</indFinal>',
                '<indPres>1</indPres>',
                "<chNFe>{$chave}</chNFe>",
            ];
        }
        $ide = $semIde ? '' : '<Ide>' . implode('', $idePartes) . '</Ide>';

        $emitente = '<Emitente>'
            . "<CNPJ>{$cnpj}</CNPJ>"
            . '<xNome>EMPRESA SINTETICA DE TESTE LTDA</xNome>'
            . '<xFant>EMPRESA SINTETICA</xFant>'
            . '<EndeEmit>'
            . '<xLgr>Rua Sintetica</xLgr><nro>100</nro><xBairro>Centro</xBairro>'
            . "<cMun>{$cMunFg}</cMun><xMun>Cidade Sintetica</xMun><UF>{$uf}</UF>"
            . '<CEP>01001000</CEP><cPais>1058</cPais><xPais>BRASIL</xPais>'
            . '<fone>1133334444</fone>'
            . '</EndeEmit>'
            . "<xMun>Cidade Sintetica</xMun><UF>{$uf}</UF><IE>ISENTO</IE><CRT>3</CRT>"
            . '</Emitente>';

        if ($semDestinatario) {
            $destinatario = '';
        } else {
            $destinatario = '<Destinatario>'
                . "<CPF>{$cpfDest}</CPF>"
                . '<xNome>CLIENTE SINTETICO</xNome>'
                . '<EndeDest>'
                . '<xLgr>Avenida Sintetica</xLgr><nro>200</nro><xBairro>Centro</xBairro>'
                . "<cMun>{$cMunFg}</cMun><xMun>Cidade Sintetica</xMun><UF>{$uf}</UF>"
                . '<CEP>01002000</CEP>'
                . '</EndeDest>'
                . "<xMun>Cidade Sintetica</xMun><UF>{$uf}</UF>"
                . '</Destinatario>';
        }

        $servico = '<Servico>'
            . "<cMun>{$cMunFg}</cMun>"
            . '<xServ>Servicos de teste sintetico</xServ>'
            . '<ListaServico>' . implode('', $itens) . '</ListaServico>'
            . '</Servico>';

        $total = '<Total>'
            . '<ISS>'
            . "<vBC>{$vBcTotal}</vBC>"
            . "<vServicos>{$vServicos}</vServicos>"
            . "<vISS>{$vIssTotal}</vISS>"
            . '</ISS>'
            . '<ISSRetido>'
            . "<vServico>{$vServicos}</vServico>"
            . "<vDescIncond>{$vDescIncond}</vDescIncond>"
            . "<vISSRetido>{$vIssRetido}</vISSRetido>"
            . "<vTotTrib>{$vTotTribTotal}</vTotTrib>"
            . "<vLiq>{$vLiq}</vLiq>"
            . '</ISSRetido>'
            . '</Total>';

        $infnfe = '<InfNFe>' . $ide . $emitente . $destinatario . $servico . $total . '</InfNFe>';

        if ($semInfnsig) {
            $corpo = $infnfe;
        } else {
            $uri = $uriInvalida ? 'http://exemplo.invalido' : "#{$chave}";

            if ($assinaturaVazia) {
                $assinatura = '<Signature></Signature>';
            } elseif ($assinaturaSemReference) {
                $assinatura = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
                    . '<SignedInfo></SignedInfo>'
                    . '<SignatureValue>U0lOVEVUSUNBLVlNIRVM=</SignatureValue>'
                    . '</Signature>';
            } elseif ($assinaturaSemValor) {
                $assinatura = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
                    . "<SignedInfo><Reference URI=\"{$uri}\"></Reference></SignedInfo>"
                    . '</Signature>';
            } elseif ($assinaturaValorVazio) {
                $assinatura = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
                    . "<SignedInfo><Reference URI=\"{$uri}\"></Reference></SignedInfo>"
                    . '<SignatureValue></SignatureValue>'
                    . '</Signature>';
            } elseif ($assinaturaIncompleta) {
                $assinatura = "<Signature><Reference URI=\"{$uri}\"></Reference></Signature>";
            } else {
                $assinatura = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
                    . "<SignedInfo><Reference URI=\"{$uri}\"></Reference></SignedInfo>"
                    . '<SignatureValue>U0lOVEVUSUNBLVlNIRVM=</SignatureValue>'
                    . '</Signature>';
            }

            $corpo = "<InfNFeSig>{$assinatura}{$infnfe}</InfNFeSig>";
        }

        $assinaturaExtra = $assinaturaFora ? '<Signature></Signature>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<NFSe xmlns="http://www.abrasf.org.br/nfse.xsd">'
            . $corpo . $assinaturaExtra
            . '</NFSe>';
    }

    public static function documentoValido(): string
    {
        return self::documentoBase();
    }

    public static function documentoComListaVazia(): string
    {
        return self::documentoBase(itens: [], vServicos: '1000.00', vIssTotal: '50.00');
    }

    /**
     * Cada problema quebra exatamente uma regra.
     */
    public static function documentoQuebrado(string $problema): string
    {
        switch ($problema) {
            case 'ide_ausente':
                return self::documentoBase(semIde: true);
            case 'cnpj_dv_invalido':
                $cnpj = self::cnpjSintetico();

                return self::documentoBase(cnpj: substr($cnpj, 0, -1) . '9');
            case 'cpf_dv_invalido':
                $cpf = self::cpfSintetico();

                return self::documentoBase(cpfDest: substr($cpf, 0, -1) . '8');
            case 'vservico_divergente':
                return self::documentoBase(vServicos: '1500.00', vLiq: '1450.00');
            case 'viss_divergente':
                return self::documentoBase(vIssTotal: '99.00');
            case 'lista_vazia_com_total':
                return self::documentoComListaVazia();
            case 'infnsig_ausente':
                return self::documentoBase(semInfnsig: true);
            case 'chave_invalida':
                return self::documentoBase(chave: str_repeat('1', 44));
            case 'dtemi_futuro':
                return self::documentoBase(dtEmi: '2099-01-15T10:30:00-03:00');
            case 'cnpj_com_cpf':
                return self::documentoBase(cnpj: self::cpfSintetico());
            case 'ordem_incorreta':
                return str_replace(
                    '<cMunFG>3550308</cMunFG><nNF>1</nNF>',
                    '<nNF>1</nNF><cMunFG>3550308</cMunFG>',
                    self::documentoValido(),
                );
            case 'xml_malformado':
                return str_replace('</Total>', '', self::documentoValido());
            case 'assinatura_uri':
                return self::documentoBase(uriInvalida: true);
            case 'assinatura_incompleta':
                return self::documentoBase(assinaturaIncompleta: true);
            case 'cstat_invalido':
                return self::documentoBase(cStat: '9');
            case 'nnf_zero':
                return self::documentoBase(nNf: '0');
            case 'uf_invalida':
                return str_replace('<UF>SP</UF>', '<UF>XX</UF>', self::documentoValido());
            case 'aliquota_divergente':
                return self::documentoBase(
                    itens: [self::itemServico(vServ: '1000.00', vBc: '1000.00', vIss: '50.00', aliq: '10.00')],
                    vIssTotal: '50.00',
                );
            case 'diferenca_um_centavo':
                return self::documentoBase(
                    itens: [self::itemServico(vServ: '1000.01')],
                    vServicos: '1000.00',
                    vLiq: '950.00',
                );
            case 'diferenca_dois_centavos':
                return self::documentoBase(
                    itens: [self::itemServico(vServ: '1000.02')],
                    vServicos: '1000.00',
                    vLiq: '950.00',
                );
            case 'raiz_duplicada':
                return self::documentoValido() . self::documentoValido();
            default:
                throw new \InvalidArgumentException("problema desconhecido: {$problema}");
        }
    }
}
