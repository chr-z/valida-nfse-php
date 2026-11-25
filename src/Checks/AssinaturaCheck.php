<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Repr;
use ChrZ\ValidaNfse\Xml\Node;

/**
 * Presenca e estrutura da assinatura. **Nao** faz verificacao criptografica.
 *
 * Validacao criptografica (XMLDSig/RSA) exige biblioteca de assinatura e o
 * certificado do emitente — use a do seu SDK/provedor. Aqui checamos apenas
 * se a assinatura existe, esta no lugar certo e tem os campos minimos.
 */
final class AssinaturaCheck
{
    public static function validar(Ctx $ctx, ?Node $nfse): void
    {
        if (!$nfse instanceof Node) {
            return;
        }

        $sig = $nfse->find('InfNFeSig');
        if (!$sig instanceof Node) {
            $ctx->erro(
                Codes::INFNFESIG_AUSENTE,
                'Elemento <InfNFeSig> ausente: a NFS-e nao tem bloco de assinatura.',
                $nfse->childPath('InfNFeSig'),
                'Todo XML de NFS-e assinado precisa de <InfNFeSig> contendo <Signature>.',
                $nfse,
            );

            return;
        }

        if ($sig->isBlank()) {
            $ctx->erro(
                Codes::INFNFESIG_VAZIA,
                'Elemento <InfNFeSig> presente mas vazio.',
                $sig->path(),
                'Preencha <InfNFeSig> com <Signature>.',
                $sig,
            );

            return;
        }

        $assinatura = $sig->find('Signature');
        if (!$assinatura instanceof Node) {
            $ctx->erro(
                Codes::ASSINATURA_AUSENTE,
                'Elemento <Signature> ausente dentro de <InfNFeSig>.',
                $sig->childPath('Signature'),
                'Inclua <Signature> com <SignedInfo> e <SignatureValue>.',
                $sig,
            );

            return;
        }

        if ($assinatura->isBlank()) {
            $ctx->erro(
                Codes::ASSINATURA_VAZIA,
                'Elemento <Signature> presente mas vazio.',
                $assinatura->path(),
                'Preencha <Signature>.',
                $assinatura,
            );

            return;
        }

        // <Reference> normalmente vive dentro de <SignedInfo>; procuramos nos
        // dois lugares para aceitar o layout completo e o simplificado.
        $signedInfo = $assinatura->find('SignedInfo');
        $reference = $assinatura->find('Reference') ?? $signedInfo?->find('Reference');

        if (!$reference instanceof Node) {
            $ctx->erro(
                Codes::ASSINATURA_REFERENCE_AUSENTE,
                'Elemento <Reference> ausente dentro de <Signature>.',
                $assinatura->childPath('Reference'),
                'Inclua <Reference URI="#NFe...">.',
                $assinatura,
            );
        } else {
            // <Reference> e um elemento com atributo URI: pode legitimamente
            // ter texto vazio, entao so tratamos como vazio quando nao tem
            // texto, nem filhos, nem atributos.
            if ($reference->isBlank() && $reference->attributes === []) {
                $ctx->erro(
                    Codes::ASSINATURA_REFERENCE_VAZIA,
                    'Elemento <Reference> presente mas totalmente vazio.',
                    $reference->path(),
                    'Preencha <Reference URI="#NFe<chave de acesso>">.',
                    $reference,
                );
            }

            $uri = $reference->attribute('URI');
            if ($uri !== '' && !str_starts_with($uri, '#')) {
                $ctx->erro(
                    Codes::ASSINATURA_URI_INVALIDA,
                    \sprintf('Atributo URI invalido em <Reference>: %s.', Repr::de($uri)),
                    $reference->path(),
                    "Em NFS-e o URI e uma referencia interna, no formato #NFe<chave de acesso>. Deve comecar com '#'.",
                    $reference,
                );
            }
        }

        $valor = $assinatura->find('SignatureValue');
        if (!$valor instanceof Node) {
            $ctx->erro(
                Codes::ASSINATURA_SIGNATUREVALUE_AUSENTE,
                'Elemento <SignatureValue> ausente dentro de <Signature>.',
                $assinatura->childPath('SignatureValue'),
                'Inclua <SignatureValue> com a assinatura em Base64.',
                $assinatura,
            );
        } elseif (trim($valor->text) === '') {
            $ctx->erro(
                Codes::ASSINATURA_SIGNATUREVALUE_VAZIA,
                'Elemento <SignatureValue> presente mas vazio.',
                $valor->path(),
                'Preencha <SignatureValue> com a assinatura em Base64.',
                $valor,
            );
        }

        // Assinatura fora do InfNFeSig sugere documento montado errado.
        if ($nfse->find('Signature') instanceof Node) {
            $ctx->erro(
                Codes::ASSINATURA_FORA_DE_INFNFE,
                '<Signature> aparece fora de <InfNFeSig>.',
                $nfse->childPath('Signature'),
                'A assinatura deve ficar dentro de <InfNFeSig>.',
                $nfse,
            );
        }
    }
}
