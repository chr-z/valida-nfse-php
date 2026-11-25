# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).
Versionamento: [SemVer](https://semver.org/lang/pt-BR/).

## [1.0.0] — 2026-11-26

Primeira versão pública. Port do `nfse-validator` (Python) para PHP, com o mesmo
catálogo de códigos e as mesmas regras.

### Adicionado

- `Validator::validate()` e `Validator::validateFile()` com `Report`/`Issue` imutáveis.
- 91 códigos de issue, idênticos aos do validador de referência (`src/Codes.php`).
- Validação de envelope, `<Ide>`, emitente, destinatário, endereço, `<Servico>`,
  `<Total>` e estrutura de assinatura (XMLDSig, sem criptografia).
- Reconciliação aritmética com decimal exato (inteiro escalado em 10⁻⁶, sem `float`)
  e tolerância configurável de 0,01.
- CPF, CNPJ, CEP, telefone, UF, código IBGE e chave de acesso (DV mod-11).
- CLI `valida-nfse` com `--json`, `--batch`, `--strict`, `--fail-on`, `--quiet`
  e códigos de saída 0/1/2.
- Posição (linha/coluna) de cada elemento, apontando o início da tag mesmo em
  documento de uma linha só.
- Conversão automática de documento em ISO-8859-15/windows-1252 para UTF-8 quando
  o `mbstring` está disponível.
- `tools/conformance.py`: harness que compara o resultado do PHP com o do Python
  no mesmo corpus (fixtures, mutações estruturais, valores de borda, ordem,
  truncamentos e fuzz determinístico).

### Verificação

- 156 testes PHPUnit; PHPStan nível 8 sem baseline.
- Corpus padrão: 774 arquivos, 0 divergências contra o validador de referência.
- Fuzz estendido: 2053 de 2054 arquivos iguais; a única diferença é o código de
  erro para declaração de encoding inexistente (`UTF-`), fatal nos dois lados.
