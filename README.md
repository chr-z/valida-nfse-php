# valida-nfse (PHP)

Validador **offline** de XML de NFS-e — DPS/NFSe do padrão nacional e ABRASF — para PHP.
Zero dependências de runtime, nenhuma chamada de rede, códigos de erro estáveis para CI.

Este pacote é o port do [nfse-validator](https://github.com/chr-z/nfse-validator) (Python) para PHP,
feito para ser usado como dependência por bibliotecas de emissão, ERPs e pipelines que já são PHP —
inclusive pelo [`nfse-nacional/nfse-php`](https://github.com/nfse-nacional/nfse-php).

## O problema

O erro de NFS-e quase nunca é "o provedor está fora". É o XML:

- identificador da DPS com zero-padding errado → `E0004`;
- chave de acesso com DV mod-11 inválido;
- `vServicos` que não fecha com a soma dos itens (e o provedor rejeita depois do certificado);
- `Signature` fora de `InfNFeSig`, `Reference` sem `#`, `cStat` de NF-e em documento de NFS-e.

Cada tentativa rejeitada custa tempo de certificado e hora de homologação. Este validador responde,
antes de transmitir: **este XML está correto?**

## Instalação

```bash
composer require chr-z/valida-nfse
```

Enquanto o pacote não estiver indexado no Packagist, use o repositório direto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/chr-z/valida-nfse-php" }
    ],
    "require": {
        "chr-z/valida-nfse": "^1.0"
    }
}
```

Requisitos: PHP 8.1+ com `ext-xml` (a extensão expat, habilitada por padrão na maioria das
distribuições) e `ext-json`. Nenhuma outra dependência.

## Uso

### CLI

```bash
# um arquivo
vendor/bin/valida-nfse nota.xml

# saída para CI
vendor/bin/valida-nfse nota.xml --json

# diretório inteiro (ou glob)
vendor/bin/valida-nfse --batch ./notas/

# pipe
cat nota.xml | vendor/bin/valida-nfse -

# só o código de saída
vendor/bin/valida-nfse -q nota.xml
```

```text
  ERRO SOMA_VSERVICO_DIVERGENTE: <vServicos> declarado (1500.00) difere da soma (1000.00). Diferenca de 500.00.
    em NFSe/InfNFeSig/InfNFe/Total/ISS/vServicos
    -> Soma dos valores dos itens de servico. A diferenca esta acima da tolerancia de 0.01 (centavo).

nota.xml: 1 erro encontrado.
```

| Opção | Efeito |
|---|---|
| `<caminho>` | Arquivo XML a validar. `-` lê da entrada padrão. |
| `--json` | Saída em JSON, pronta para CI. |
| `--batch <alvo>` | Valida um diretório, um arquivo ou um glob. |
| `--strict` | Promove **todo** aviso a erro (muda o código de saída). |
| `-q`, `--quiet` | Não imprime nada; só o código de saída. |
| `--fail-on <error\|warning\|none>` | Quando falhar. Padrão: `error`. |
| `--no-color` | Desliga as cores (ligadas só em terminal). |

**Códigos de saída:** `0` limpo · `1` erro de validação · `2` erro de uso/IO.

### API

```php
use ChrZ\ValidaNfse\Validator;

$relatorio = Validator::validate($xml);   // string com o XML

if (!$relatorio->ok()) {
    foreach ($relatorio->errors() as $erro) {
        printf("%s em %s:%s — %s\n", $erro->code, $erro->path, $erro->line, $erro->message);
    }
}
```

`Report` expõe `ok()`, `errors()`, `warnings()`, `issues()`, `codes()`, `hasCode()`,
`summary()` e `toArray()` (o mesmo JSON da CLI).

`Issue` é imutável: `code`, `severity` (`error`/`warning`), `message`, `path`
(caminho XPath-ish, ex. `NFSe/InfNFeSig/InfNFe/Ide`), `line`, `column` e `hint` — a dica de
como corrigir.

Ajustes finos:

```php
use ChrZ\ValidaNfse\Brazil\Decimal;
use ChrZ\ValidaNfse\Validator;

$relatorio = Validator::validate(
    xml: $xml,
    strict: false,
    tolerancia: Decimal::fromString('0.05'),      // tolerância da reconciliação, em reais
    toleranciaAliquota: Decimal::fromString('0.01'), // em pontos percentuais
);
```

### Integração no `nfse-php` (v2)

O caso de uso pretendido é validar a DPS **antes** de assinar e transmitir:

```php
// dentro do serviço que monta a DPS
$relatorio = \ChrZ\ValidaNfse\Validator::validate($xmlDps, source: 'dps');

if (!$relatorio->ok()) {
    throw new \RuntimeException(
        'DPS inválida: ' . implode('; ', array_map(
            static fn ($e) => "{$e->code} ({$e->path})",
            $relatorio->errors(),
        )),
    );
}
```

Como não há dependência de runtime, o pacote pode entrar no `composer.json` da lib sem arrastar
nada junto; e como a validação é offline, ela roda em teste unitário.

## Regras aritméticas

Todas as comparações usam decimal exato (inteiro escalado em 10⁻⁶), **nunca `float`**.
A tolerância padrão é **0,01** (um centavo): exatamente 0,01 passa; 0,02 falha.

| Regra | Código quando não confere |
|---|---|
| `vServicos` = soma de `vServ`/`vServico`/`vProd` dos itens | `SOMA_VSERVICO_DIVERGENTE` |
| `vBC` = soma de `vBC` dos itens | `SOMA_VBC_DIVERGENTE` |
| `vISS` = soma de `vISS` dos itens | `SOMA_VISS_DIVERGENTE` |
| `vTotTrib` = soma de `vTotTrib` dos itens | `SOMA_VTOTTRIB_DIVERGENTE` |
| `vLiq` = base − `vDescIncond` − `vDeducao` | `LIQUIDO_NAO_CONFERE` |
| `vISSRetido` = soma de `vISS` (quando ≠ 0) | `TOTAL_ISS_RECOLHIDO_DIVERGENTE` |
| `ListaServico` vazia ⇒ totais zerados | `TOTAL_SEM_ITENS`, `TOTAL_SEM_ITENS_NAO_ZERO` |
| Alíquota implícita (`vISS/vBC`) vs `aliq` declarada | `ITEM_SERVICO_ALIQUOTA_DIVERGENTE` (aviso) |

## Códigos de erro

O catálogo é o mesmo do validador de referência: **91 códigos declarados**, cobrindo:

- **XML e envelope:** `XML_VAZIO`, `XML_SEM_RAIZ`, `XML_MALFORMADO`, `XML_ENCODING_INVALIDO`,
  `XML_RAIZES_MULTIPLAS`, `ENVELOPE_RAIZ_INVALIDA`, `ENV_INFNFE_*`;
- **`<Ide>`:** `cStat`, `nNF`, `dhEmi` (formato, fuso, futuro/antigo), chave de acesso (44 dígitos,
  DV mod-11, UF, mês, ano), `cMunFG`/`cMun`, `tpAmb`, ordem dos elementos;
- **Emitente/destinatário:** CNPJ/CPF (dígitos verificadores), UF, CEP, telefone, ordem;
- **Serviço:** código LC 116, valores, base, alíquota, `retISS`, município do item;
- **Totais:** presença de `ISS`/`ISSRetido`, decimal inválido, reconciliação;
- **Assinatura:** presença e estrutura de `InfNFeSig`/`Signature`/`Reference`/`SignatureValue`
  (estrutura, **não** criptografia).

A lista completa está em `src/Codes.php` e é contrato público: código publicado não é renomeado.

## Limitações — leia antes de confiar

- **Não verifica criptografia da assinatura.** Checa presença e estrutura do XMLDSig.
  Validação criptográfica (RSA + cadeia de certificado) exige biblioteca de assinatura.
- **Não valida o XSD do seu provedor municipal.** Checa o layout ABRASF/nacional comum; a ordem
  dos elementos é heurística desse layout.
- **Não emite NFS-e** e não substitui homologação.
- **Não é aconselhamento fiscal ou jurídico.**
- Documento declarando codificação que o expat não conhece é convertido para UTF-8 quando o
  `mbstring` está disponível; sem `mbstring`, é rejeitado com `XML_ENCODING_INVALIDO`.
- Em documento malformado, a linha/coluna do erro vem do parser (expat) e pode variar entre
  versões da biblioteca. O **código** do issue é estável.

## Como isso foi verificado

Port sem prova de equivalência é fé. Este pacote foi validado em três frentes:

1. **Suíte própria** — 156 testes (PHPUnit) cobrindo documentos válidos, cada regra quebrada,
   dígitos verificadores, decimal, relatório e CLI.
2. **Análise estática** — PHPStan nível 8, sem baseline nem supressão.
3. **Conformidade diferencial** — `tools/conformance.py` gera um corpus de XMLs (fixtures do
   projeto de referência, remoção/troca de cada elemento do documento válido, valores de borda,
   trocas de ordem, truncamentos e fuzz determinístico) e roda **o validador Python e o PHP no
   mesmo arquivo**, comparando severidade, código, caminho, linha, coluna e mensagem.

Resultado com o corpus padrão: **774 arquivos, 0 divergências**. Com fuzz estendido
(1500 documentos aleatórios): **2053 de 2054** iguais — a única diferença é um documento com
`encoding="UTF-"` (nome de charset inexistente), em que o PHP diz `XML_ENCODING_INVALIDO` e o
Python diz `XML_MALFORMADO`; ambos fatais. Outras três sementes de fuzz (954 arquivos cada)
fecharam com **zero** divergências.

Reproduza:

```bash
composer install
composer check                     # phpstan + phpunit
python3 tools/conformance.py --ref ../nfse-validator
```

## Desenvolvimento

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan nível 8
composer check       # os dois
```

## Licença

MIT. Ver [LICENSE](LICENSE).
