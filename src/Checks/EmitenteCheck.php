<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Brazil\Documentos;
use ChrZ\ValidaNfse\Brazil\Formato;
use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Repr;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Verificacoes de `<Emitente>`, `<Destinatario>` e `<Endereco>`.
 *
 * O emitente e obrigatorio; o destinatario nao (NFS-e para pessoa fisica
 * costuma sair sem tomador), por isso os avisos.
 */
final class EmitenteCheck
{
    /** @var list<string> */
    public const ORDEM_EMITENTE = [
        'CNPJ', 'CPF', 'xNome', 'xFant', 'EndeEmit', 'xMun', 'UF', 'IE', 'IEST',
        'IM', 'CNAE', 'CRT',
    ];

    /** @var list<string> */
    public const ORDEM_DESTINATARIO = [
        'CPF', 'CNPJ', 'idEstrangeiro', 'xNome', 'EndeDest', 'xMun', 'UF', 'IE',
        'ISUF', 'IM', 'Email',
    ];

    /** @var list<string> */
    public const ORDEM_ENDERECO = [
        'xLgr', 'nro', 'xCpl', 'xBairro', 'cMun', 'xMun', 'UF', 'CEP', 'cPais', 'xPais', 'fone',
    ];

    public static function validarEmitente(Ctx $ctx, ?Node $infnfe): ?Node
    {
        if (!$infnfe instanceof Node) {
            return null;
        }

        $emit = $infnfe->find('Emitente');
        if (!$emit instanceof Node) {
            $ctx->erro(
                Codes::EMITENTE_AUSENTE,
                'Elemento <Emitente> ausente dentro de <InfNFe>.',
                $infnfe->childPath('Emitente'),
                'Todo InfNFe precisa de <Emitente> com os dados do prestador de servico.',
                $infnfe,
            );

            return null;
        }

        if ($emit->isBlank()) {
            $ctx->erro(
                Codes::EMITENTE_VAZIO,
                'Elemento <Emitente> presente mas vazio.',
                $emit->path(),
                'Preencha <Emitente>.',
                $emit,
            );

            return null;
        }

        Rules::conferirOrdem($ctx, $emit, self::ORDEM_EMITENTE, 'EMITENTE');

        self::validarDocumentoEmitente($ctx, $emit);
        self::validarNome($ctx, $emit);

        $uf = $emit->value('UF');
        if ($uf !== '' && !Formato::ufValida($uf)) {
            $ctx->erro(
                Codes::EMITENTE_UF_INVALIDA,
                \sprintf('UF invalida no emitente: %s.', Repr::de($uf)),
                $emit->childPath('UF'),
                'Use a sigla de uma UF brasileira (ex.: SP, RJ, MG).',
                $emit->find('UF'),
            );
        }

        self::validarEndereco($ctx, $emit->find('EndeEmit'), 'ENDERECO');

        return $emit;
    }

    private static function validarDocumentoEmitente(Ctx $ctx, Node $emit): void
    {
        $cnpjNode = $emit->find('CNPJ');
        $cpfNode = $emit->find('CPF');

        if ($cnpjNode instanceof Node && $cpfNode instanceof Node) {
            $ctx->erro(
                Codes::EMITENTE_CNPJ_E_CPF,
                '<Emitente> tem <CNPJ> e <CPF> ao mesmo tempo; escolha um.',
                $emit->path(),
                'Preencha apenas um: <CNPJ> para pessoa juridica, <CPF> para pessoa fisica.',
                $emit,
            );
        }

        if ($cnpjNode instanceof Node) {
            $bruto = trim($cnpjNode->text);
            $digitos = Documentos::somenteDigitos($bruto);

            if ($digitos === '') {
                $ctx->erro(
                    Codes::EMITENTE_CNPJ_OBRIGATORIO,
                    '<CNPJ> do emitente presente mas vazio.',
                    $cnpjNode->path(),
                    'Preencha o CNPJ com 14 digitos, apenas numeros.',
                    $cnpjNode,
                );
            } elseif (preg_match('/^\d{11}$/', $digitos) === 1) {
                $ctx->erro(
                    Codes::EMITENTE_CNPJ_E_CPF,
                    \sprintf('<CNPJ> do emitente contem %d digitos: parece um CPF, nao um CNPJ.', \strlen($digitos)),
                    $cnpjNode->path(),
                    'CPF tem 11 digitos e CNPJ tem 14. Confira se nao gravou o documento errado no campo.',
                    $cnpjNode,
                );
            } elseif (preg_match('/^\d{14}$/', $digitos) !== 1) {
                $ctx->erro(
                    Codes::EMITENTE_CNPJ_INVALIDO,
                    \sprintf('<CNPJ> do emitente invalido: %s (esperado 14 digitos).', Repr::de($bruto)),
                    $cnpjNode->path(),
                    'O CNPJ tem 14 digitos, apenas numeros, sem pontos, barras ou espacos.',
                    $cnpjNode,
                );
            } elseif (!Documentos::validarCnpj($digitos)) {
                $ctx->erro(
                    Codes::EMITENTE_CNPJ_INVALIDO,
                    \sprintf('CNPJ do emitente %s tem digito verificador incorreto.', $digitos),
                    $cnpjNode->path(),
                    'Recalcule os dois digitos verificadores do CNPJ. Em PHP, use a funcao de validacao do seu SDK ou faca o calculo mod-11.',
                    $cnpjNode,
                );
            }

            return;
        }

        if ($cpfNode instanceof Node) {
            $digitos = Documentos::somenteDigitos($cpfNode->text);

            if ($digitos === '') {
                $ctx->erro(
                    Codes::EMITENTE_CPF_OBRIGATORIO,
                    '<CPF> do emitente presente mas vazio.',
                    $cpfNode->path(),
                    'Preencha o CPF com 11 digitos.',
                    $cpfNode,
                );
            } elseif (preg_match('/^\d{11}$/', $digitos) !== 1) {
                $ctx->erro(
                    Codes::EMITENTE_CPF_INVALIDO,
                    \sprintf('<CPF> do emitente invalido: %s (esperado 11 digitos).', Repr::de(trim($cpfNode->text))),
                    $cpfNode->path(),
                    'O CPF tem 11 digitos, apenas numeros.',
                    $cpfNode,
                );
            } elseif (!Documentos::validarCpf($digitos)) {
                $ctx->erro(
                    Codes::EMITENTE_CPF_INVALIDO,
                    \sprintf('CPF do emitente %s tem digito verificador incorreto.', $digitos),
                    $cpfNode->path(),
                    'Recalcule os dois digitos verificadores do CPF (mod-11).',
                    $cpfNode,
                );
            }

            return;
        }

        $ctx->erro(
            Codes::EMITENTE_CNPJ_OBRIGATORIO,
            '<Emitente> sem <CNPJ> e sem <CPF>: nao ha como identificar o prestador.',
            $emit->path(),
            'Inclua <CNPJ> (pessoa juridica) ou <CPF> (pessoa fisica).',
            $emit,
        );
    }

    private static function validarNome(Ctx $ctx, Node $emit): void
    {
        $nome = $emit->value('xNome');
        if ($nome !== '') {
            return;
        }

        $fant = $emit->value('xFant');
        if ($fant === '') {
            $ctx->erro(
                Codes::EMITENTE_RAZAO_SOCIAL_OBRIGATORIA,
                '<Emitente> sem <xNome> (razao social) ou <xFant> (nome fantasia).',
                $emit->childPath('xNome'),
                'Inclua <xNome> com a razao social do prestador.',
                $emit,
            );

            return;
        }

        $ctx->erro(
            Codes::EMITENTE_NOME_OBRIGATORIO,
            '<Emitente> tem <xFant> mas nao tem <xNome> (razao social).',
            $emit->childPath('xNome'),
            'Nome fantasia sozinho nao substitui a razao social nesta posicao.',
            $emit,
        );
    }

    public static function validarEndereco(Ctx $ctx, ?Node $ender, string $prefixo): void
    {
        if (!$ender instanceof Node) {
            return;
        }

        Rules::conferirOrdem($ctx, $ender, self::ORDEM_ENDERECO, $prefixo);

        $cep = $ender->value('CEP');
        if ($cep !== '' && !Formato::cepValido($cep)) {
            $ctx->erro(
                $prefixo . '_CEP_INVALIDO',
                \sprintf('CEP invalido: %s (esperado 8 digitos).', Repr::de($cep)),
                $ender->childPath('CEP'),
                'CEP tem 8 digitos, apenas numeros.',
                $ender->find('CEP'),
            );
        }

        $fone = $ender->value('fone');
        if ($fone !== '' && !Formato::foneValido($fone)) {
            $ctx->erro(
                $prefixo . '_TEL_INVALIDO',
                \sprintf('Telefone invalido: %s (esperado 10 ou 11 digitos).', Repr::de($fone)),
                $ender->childPath('fone'),
                'DDD com 2 digitos + numero de 8 ou 9 digitos, apenas numeros.',
                $ender->find('fone'),
            );
        }
    }

    public static function validarDestinatario(Ctx $ctx, ?Node $infnfe): ?Node
    {
        if (!$infnfe instanceof Node) {
            return null;
        }

        $dest = $infnfe->find('Destinatario');
        if (!$dest instanceof Node) {
            $ctx->aviso(
                Codes::DESTINATARIO_AUSENTE,
                '<Destinatario> ausente: a NFS-e foi emitida sem tomador identificado.',
                $infnfe->childPath('Destinatario'),
                'Muitas NFS-e para pessoa fisica sao emitidas sem destinatario. Se o servico e B2B, o provedor pode exigir.',
                $infnfe,
            );

            return null;
        }

        if ($dest->isBlank()) {
            $ctx->aviso(
                Codes::DESTINATARIO_VAZIO,
                '<Destinatario> presente mas vazio.',
                $dest->path(),
                'Preencha <Destinatario> ou remova o elemento.',
                $dest,
            );

            return null;
        }

        Rules::conferirOrdem($ctx, $dest, self::ORDEM_DESTINATARIO, 'DESTINATARIO');

        $cpfNode = $dest->find('CPF');
        $cnpjNode = $dest->find('CNPJ');
        $estrangeiro = $dest->find('idEstrangeiro');

        if ($cpfNode instanceof Node && $cnpjNode instanceof Node) {
            $ctx->erro(
                Codes::DESTINATARIO_CPF_E_CNPJ,
                '<Destinatario> tem <CPF> e <CNPJ> ao mesmo tempo; escolha um.',
                $dest->path(),
                'Preencha apenas um dos dois.',
                $dest,
            );
        } elseif ($cpfNode instanceof Node) {
            $digitos = Documentos::somenteDigitos($cpfNode->text);
            if ($digitos !== '' && preg_match('/^\d{11}$/', $digitos) !== 1) {
                $ctx->erro(
                    Codes::DESTINATARIO_CPF_INVALIDO,
                    \sprintf('CPF do destinatario invalido: %s (esperado 11 digitos).', Repr::de(trim($cpfNode->text))),
                    $cpfNode->path(),
                    'O CPF tem 11 digitos.',
                    $cpfNode,
                );
            } elseif ($digitos !== '' && !Documentos::validarCpf($digitos)) {
                $ctx->erro(
                    Codes::DESTINATARIO_CPF_INVALIDO,
                    \sprintf('CPF do destinatario %s tem digito verificador incorreto.', $digitos),
                    $cpfNode->path(),
                    'Recalcule os digitos verificadores do CPF.',
                    $cpfNode,
                );
            }
        } elseif ($cnpjNode instanceof Node) {
            $digitos = Documentos::somenteDigitos($cnpjNode->text);
            if ($digitos !== '' && preg_match('/^\d{14}$/', $digitos) !== 1) {
                $ctx->erro(
                    Codes::DESTINATARIO_CNPJ_INVALIDO,
                    \sprintf('CNPJ do destinatario invalido: %s (esperado 14 digitos).', Repr::de(trim($cnpjNode->text))),
                    $cnpjNode->path(),
                    'O CNPJ tem 14 digitos.',
                    $cnpjNode,
                );
            } elseif ($digitos !== '' && !Documentos::validarCnpj($digitos)) {
                $ctx->erro(
                    Codes::DESTINATARIO_CNPJ_INVALIDO,
                    \sprintf('CNPJ do destinatario %s tem digito verificador incorreto.', $digitos),
                    $cnpjNode->path(),
                    'Recalcule os digitos verificadores do CNPJ.',
                    $cnpjNode,
                );
            }
        } elseif (!$estrangeiro instanceof Node) {
            $ctx->erro(
                Codes::DESTINATARIO_SEM_DOCUMENTO,
                '<Destinatario> sem <CPF>, <CNPJ> ou <idEstrangeiro>.',
                $dest->path(),
                'Identifique o tomador por um dos tres campos.',
                $dest,
            );
        }

        self::validarEndereco($ctx, $dest->find('EndeDest'), 'ENDERECO');

        return $dest;
    }
}
