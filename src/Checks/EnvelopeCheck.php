<?php

declare(strict_types=1);

namespace ChrZ\ValidaNfse\Checks;

use ChrZ\ValidaNfse\Codes;
use ChrZ\ValidaNfse\Xml\Node;
use ChrZ\ValidaNfse\Xml\Parser;

/**
 * Confere a raiz, o namespace e a presenca de `<InfNFe>` / `<InfNFeSig>`.
 */
final class EnvelopeCheck
{
    public static function validarEnvelope(Ctx $ctx): Node
    {
        $root = $ctx->root;
        $caminhoRoot = $root->path();

        if ($root->tag !== 'NFSe') {
            $ctx->erro(
                Codes::ENVELOPE_RAIZ_INVALIDA,
                \sprintf('Elemento raiz invalido: esperado <NFSe>, encontrado <%s>.', $root->tag),
                $caminhoRoot,
                'A raiz do documento deve ser <NFSe xmlns="http://www.abrasf.org.br/nfse.xsd">.',
                $root,
            );
        }

        $ns = $root->namespaceUri();
        if ($ns !== '' && !\in_array($ns, Parser::NAMESPACES_ACEITOS, true)) {
            $ctx->aviso(
                Codes::RAIZ_NAMESPACE_DESCONHECIDO,
                \sprintf('Namespace da raiz nao e um perfil ABRASF conhecido: %s', $ns),
                $caminhoRoot,
                'Este validador conhece os perfis ABRASF e nacional. Se o seu municipio usa um schema proprio, os nomes de tag podem diferir.',
                $root,
            );
        }

        // A raiz pode ser <NFSe> diretamente, ou um envelope externo que contem
        // <NFSe>. Nos dois casos o envelope que importa e o proprio <NFSe>.
        $nfse = $root->tag === 'NFSe' ? $root : $root->find('NFSe');
        if (!$nfse instanceof Node) {
            $ctx->erro(
                Codes::ENV_NFSE_AUSENTE,
                'Elemento <NFSe> (filho da raiz) ausente.',
                $caminhoRoot,
                'O documento precisa de <NFSe> envolvendo <InfNFeSig> e <InfNFe>.',
                $root,
            );

            return $root;
        }

        if ($nfse->isBlank()) {
            $ctx->erro(
                Codes::ENV_NFSE_VAZIA,
                'Elemento <NFSe> presente mas vazio.',
                $nfse->path(),
                'Preencha <NFSe> com os dados da nota.',
                $nfse,
            );

            return $root;
        }

        // <InfNFe> vive dentro de <InfNFeSig> no layout assinado, ou direto em
        // <NFSe> no perfil sem assinatura. Procuramos nos dois lugares.
        $sig = $nfse->find('InfNFeSig');
        $infnfe = ($sig?->find('InfNFe')) ?? $nfse->find('InfNFe');

        if (!$infnfe instanceof Node) {
            $ctx->erro(
                Codes::ENV_INFNFE_AUSENTE,
                'Elemento <InfNFe> ausente dentro de <NFSe>.',
                $nfse->childPath('InfNFe'),
                'Inclua <InfNFe> com os dados fiscais da nota.',
                $nfse,
            );
        } elseif ($infnfe->isBlank()) {
            $ctx->erro(
                Codes::ENV_INFNFE_VAZIA,
                'Elemento <InfNFe> presente mas vazio.',
                $infnfe->path(),
                'Preencha <InfNFe> com os dados fiscais da nota.',
                $infnfe,
            );
        }

        return $nfse;
    }

    /**
     * InfNFe dentro de InfNFeSig, ou InfNFe direto (perfil sem signature).
     */
    public static function acharInfNfe(Ctx $ctx): ?Node
    {
        $root = $ctx->root;
        $nfse = $root->tag === 'NFSe' ? $root : ($root->find('NFSe') ?? $root);

        $sig = $nfse->find('InfNFeSig');
        if ($sig instanceof Node) {
            $alvo = $sig->find('InfNFe');
            if ($alvo instanceof Node) {
                // Um documento com <InfNFe> dentro E fora de <InfNFeSig> tem
                // duas copias da nota. Usamos a assinada para nao duplicar
                // erros, mas sinalizamos a ambiguidade.
                if ($nfse->find('InfNFe') instanceof Node) {
                    $ctx->erro(
                        Codes::ENV_INFNFE_DUPLICADO,
                        '<InfNFe> aparece dentro e fora de <InfNFeSig>: o documento tem duas copias da nota.',
                        $nfse->childPath('InfNFe'),
                        'No layout ABRASF, <InfNFe> fica dentro de <InfNFeSig>. Remova a copia solta.',
                        $nfse,
                    );
                }

                return $alvo;
            }
        }

        return $nfse->find('InfNFe');
    }
}
