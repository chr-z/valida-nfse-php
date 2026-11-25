#!/usr/bin/env python3
"""Harness de conformidade: compara o validador PHP com o de referencia (Python).

O pacote PHP e um port. Port sem prova de equivalencia e fe. Este script:

1. gera um corpus de XMLs (fixtures do projeto de referencia + mutacoes
   sistematicas: remocao/troca de cada elemento, valores de borda, trocas de
   ordem, truncamentos);
2. roda o validador Python em cada arquivo e grava o relatorio esperado;
3. roda o validador PHP (tools/dump.php) no mesmo corpus;
4. compara severidade, codigo, caminho, linha e coluna de cada issue, na ordem
   do relatorio, e tambem a mensagem.

Uso:
    python3 tools/conformance.py [--ref ../nfse-validator] [--corpus /tmp/nfse-corpus] [--show 20]

Saida: 0 quando nao ha divergencia; 1 quando ha.
"""

from __future__ import annotations

import argparse
import json
import os
import random
import re
import shutil
import subprocess
import sys
import tempfile
import unicodedata
from decimal import Decimal

PROBLEMAS = [
    "ide_ausente",
    "cnpj_dv_invalido",
    "cpf_dv_invalido",
    "vservico_divergente",
    "viss_divergente",
    "lista_vazia_com_total",
    "infnsig_ausente",
    "chave_invalida",
    "dtemi_futuro",
    "cnpj_com_cpf",
    "ordem_incorreta",
    "xml_malformado",
    "assinatura_uri",
    "assinatura_incompleta",
    "cstat_invalido",
    "nnf_zero",
    "uf_invalida",
    "aliquota_divergente",
    "diferenca_um_centavo",
    "diferenca_dois_centavos",
    "raiz_duplicada",
]

TOKEN = re.compile(r"<(/?)([A-Za-z_][\w:.-]*)([^>]*?)(/?)>")


class Elem:
    __slots__ = ("tag", "start", "open_end", "end", "depth", "parent", "children")

    def __init__(self, tag: str, start: int, open_end: int, depth: int, parent: int | None):
        self.tag = tag
        self.start = start
        self.open_end = open_end
        self.end = -1
        self.depth = depth
        self.parent = parent
        self.children: list[int] = []


def indexar(xml: str) -> list[Elem]:
    """Mapeia cada elemento do XML com os offsets de abertura e fechamento."""
    pilha: list[int] = []
    elems: list[Elem] = []
    for m in TOKEN.finditer(xml):
        fechando, tag, _attrs, auto = m.group(1), m.group(2), m.group(3), m.group(4)
        if fechando:
            if pilha:
                idx = pilha.pop()
                elems[idx].end = m.end()
            continue
        pai = pilha[-1] if pilha else None
        e = Elem(tag, m.start(), m.end(), len(pilha), pai)
        elems.append(e)
        if pai is not None:
            elems[pai].children.append(len(elems) - 1)
        if auto:
            e.end = m.end()
        else:
            pilha.append(len(elems) - 1)
    return elems


def texto_interno(xml: str, e: Elem) -> str:
    return xml[e.open_end : e.end - len(e.tag) - 3] if e.end > 0 else ""


def remover_elemento(xml: str, e: Elem) -> str:
    return xml[: e.start] + xml[e.end :]


def trocar_texto(xml: str, e: Elem, novo: str) -> str:
    if e.end < 0:
        return xml
    fecho = f"</{e.tag}>"
    fim_interno = xml.rindex(fecho, e.open_end, e.end)
    return xml[: e.open_end] + novo + xml[fim_interno:]


def trocar_atributo(xml: str, e: Elem, attr: str, novo: str) -> str:
    abertura = xml[e.start : e.open_end]
    padrao = re.compile(rf'({attr}=")([^"]*)(")')
    if padrao.search(abertura):
        novo_tag = padrao.sub(lambda m: m.group(1) + novo + m.group(3), abertura, count=1)
    else:
        novo_tag = abertura.replace("/>", f' {attr}="{novo}"/>')
    return xml[: e.start] + novo_tag + xml[e.open_end :]


def gerar_corpus(ref_dir: str, fuzz: int = 220, seed: int = 42) -> dict[str, str]:
    sys.path.insert(0, ref_dir)
    from nfse_validator import fixtures as fx  # noqa: PLC0415

    docs: dict[str, str] = {}
    valido = fx.documento_valido()
    docs["000_valido.xml"] = valido
    docs["001_lista_vazia.xml"] = fx.documento_com_lista_vazia()

    for i, problema in enumerate(PROBLEMAS, start=10):
        docs[f"{i:03d}_quebrado_{problema}.xml"] = fx.documento_quebrado(problema)

    # --- bordas de envelope -------------------------------------------------
    bordas = {
        "vazio": "",
        "so_espaco": "   \n  ",
        "so_pi": "<?xml version='1.0'?>",
        "so_comentario": "<?xml version='1.0'?><!-- nada -->",
        "encoding_invalido": '<?xml version="1.0" encoding="XINVALID-9"?><NFSe></NFSe>',
        "raiz_outro_nome": valido.replace("<NFSe ", "<Outro ").replace("</NFSe>", "</Outro>"),
        "ns_desconhecido": valido.replace("http://www.abrasf.org.br/nfse.xsd", "http://exemplo.invalido/ns"),
        "ns_nacional": valido.replace("http://www.abrasf.org.br/nfse.xsd", "http://www.portalfiscal.inf.br/nfe"),
        "sem_ns": valido.replace(' xmlns="http://www.abrasf.org.br/nfse.xsd"', ""),
        "nfse_vazia": '<?xml version="1.0"?><NFSe xmlns="http://www.abrasf.org.br/nfse.xsd"></NFSe>',
        "infnfe_sig_vazio": '<?xml version="1.0"?><NFSe xmlns="http://www.abrasf.org.br/nfse.xsd"><InfNFeSig></InfNFeSig></NFSe>',
        "raiz_envelope": '<?xml version="1.0"?><Raiz><NFSe xmlns="http://www.abrasf.org.br/nfse.xsd">'
        + valido[valido.index("<InfNFeSig>") : valido.index("</NFSe>")]
        + "</NFSe></Raiz>",
        "infnfe_vazio": valido.replace("</InfNFeSig>", "<InfNFe></InfNFe></InfNFeSig>"),
        "xml_com_bom": "\ufeff" + valido,
        "xml_cortado_metade": valido[: len(valido) // 2],
        "xml_sem_fechamento_total": valido.replace("</Total>", ""),
        "xml_tag_nao_fechada": valido.replace("</Ide>", ""),
        "xml_ampersand_solto": valido.replace("Servicos de teste sintetico", "Servicos & teste"),
        "xml_dois_infnfe": valido.replace(
            "</InfNFeSig>",
            "</InfNFeSig>"
            + valido[valido.index("<InfNFe>") : valido.index("</InfNFe>") + len("</InfNFe>")],
        ),
    }
    for nome, xml in bordas.items():
        docs[f"100_{nome}.xml"] = xml

    # --- mutacoes estruturais sistematicas ---------------------------------
    elems = indexar(valido)
    por_tag: dict[str, list[int]] = {}
    for i, e in enumerate(elems):
        por_tag.setdefault(e.tag, []).append(i)

    n = 0
    for i, e in enumerate(elems):
        if e.tag in {"NFSe", "InfNFeSig", "InfNFe"}:
            continue
        n += 1
        docs[f"200_rem_{n:03d}_{e.tag}.xml"] = remover_elemento(valido, e)
        if e.children == [] and texto_interno(valido, e).strip():
            docs[f"201_vazio_{n:03d}_{e.tag}.xml"] = trocar_texto(valido, e, "")
            docs[f"202_lixo_{n:03d}_{e.tag}.xml"] = trocar_texto(valido, e, "ABC!@#")

    # --- valores de borda ---------------------------------------------------
    valores_dinheiro = ["0", "0.00", "-10.00", "10,50", "abc", "", "1000.001", "1e3", " 20.00 ", "--"]
    for tag in ["vServ", "vServico", "vProd", "vBC", "vISS", "vTotTrib", "vLiq", "vDescIncond", "vDeducao",
                "vISSRetido", "vServicos", "aliq", "pISS", "retISS"]:
        for j, valor in enumerate(valores_dinheiro):
            if tag not in por_tag:
                continue
            for k in por_tag[tag][:3]:
                docs[f"300_{tag}_{j}_{k}.xml"] = trocar_texto(valido, elems[k], valor)

    valores_data = [
        "2026-01-15",
        "2026-01-15T10:30:00",
        "2099-01-15T10:30:00-03:00",
        "1999-01-15T10:30:00-03:00",
        "2026-13-15T10:30:00-03:00",
        "2026-02-30T10:30:00-03:00",
        "2026-01-15T25:30:00-03:00",
        "2026-01-15T10:30:00Z",
        "2026-01-15 10:30:00+03:00",
        "lixo",
        "",
    ]
    for j, valor in enumerate(valores_data):
        for k in por_tag.get("dhEmi", [])[:1]:
            docs[f"400_data_{j}_{k}.xml"] = trocar_texto(valido, elems[k], valor)

    valores_chave = ["1" * 44, "0" * 44, "", "123", "1" * 43, "1" * 45, "  "]
    for j, valor in enumerate(valores_chave):
        for k in por_tag.get("chNFe", [])[:1]:
            docs[f"401_chave_{j}_{k}.xml"] = trocar_texto(valido, elems[k], valor)

    valores_doc = ["11222333000181", "11222333000180", "12345678909", "11111111111111", "", "abc",
                   "1122233300018", "123.456.789-09"]
    for tag in ["CNPJ", "CPF"]:
        for j, valor in enumerate(valores_doc):
            for k in por_tag.get(tag, [])[:2]:
                docs[f"402_{tag}_{j}_{k}.xml"] = trocar_texto(valido, elems[k], valor)

    for tag, vals in {
        "cStat": ["1", "2", "9", ""],
        "nNF": ["0", "-1", "1", "abc", "1a", "007", ""],
        "cMunFG": ["3550308", "355030", "9990308", "", "abc"],
        "cMun": ["3550308", "5300108", "9990308", ""],
        "tpAmb": ["1", "2", "3", ""],
        "UF": ["SP", "XX", "sp", ""],
        "CEP": ["01001000", "0100100", "01001 000", ""],
        "fone": ["1133334444", "11987654321", "11887654321", "123", ""],
        "codTributacao": ["01.07", "xx", "", "1401"],
        "xNome": ["EMPRESA X", ""],
        "xServ": ["servico", ""],
    }.items():
        for j, valor in enumerate(vals):
            for k in por_tag.get(tag, [])[:2]:
                docs[f"403_{tag}_{j}_{k}.xml"] = trocar_texto(valido, elems[k], valor)

    # --- trocas de ordem (pares adjacentes) --------------------------------
    pais_de_interesse = {"Ide", "Emitente", "EndeEmit", "Destinatario", "EndeDest", "Servico", "Total", "ISS",
                          "ISSRetido", "Signature", "SignedInfo"}
    contador = 0
    for i, e in enumerate(elems):
        if e.tag not in pais_de_interesse or len(e.children) < 2:
            continue
        for a, b in zip(e.children, e.children[1:]):
            ea, eb = elems[a], elems[b]
            contador += 1
            if ea.end < 0 or eb.end < 0:
                continue
            trecho_a = valido[ea.start : ea.end]
            trecho_b = valido[eb.start : eb.end]
            novo = valido[: ea.start] + trecho_b + trecho_a + valido[eb.end :]
            docs[f"500_ordem_{contador:03d}_{ea.tag}_{eb.tag}.xml"] = novo

    # --- truncamentos deterministas ---------------------------------------
    random.seed(7)
    for j in range(25):
        corte = random.randint(10, max(11, len(valido) - 10))
        docs[f"600_trunc_{j:02d}.xml"] = valido[:corte]

    # --- formatacao e variacoes de serializacao ----------------------------
    multilinha = re.sub(r"><", ">\n  <", valido)
    docs["800_multilinha.xml"] = multilinha
    docs["801_crlf.xml"] = valido.replace("><", ">\r\n<")
    docs["802_prefixado.xml"] = (
        valido.replace("<NFSe ", '<abrasf:NFSe ')
        .replace("</NFSe>", "</abrasf:NFSe>")
        .replace("<InfNFeSig>", "<abrasf:InfNFeSig>")
        .replace("</InfNFeSig>", "</abrasf:InfNFeSig>")
        .replace("<InfNFe>", "<abrasf:InfNFe>")
        .replace("</InfNFe>", "</abrasf:InfNFe>")
        .replace("<Ide>", "<abrasf:Ide>")
        .replace("</Ide>", "</abrasf:Ide>")
    )
    docs["803_indentado_quebrado.xml"] = re.sub(r"><", ">\n  <", fx.documento_quebrado("ide_ausente"))
    docs["804_comentarios.xml"] = valido.replace("<Ide>", "<!-- ide --><Ide>")
    docs["805_cdata_texto.xml"] = valido.replace("<xServ>Servicos de teste sintetico</xServ>", "<xServ><![CDATA[Servicos & teste]]></xServ>")

    # --- fuzz deterministico ----------------------------------------------
    # Mutacoes aleatorias de um caractere (troca, remocao, insercao) e trocas
    # de texto: o objetivo e achar divergencia de classificacao, nao de estilo.
    rnd = random.Random(seed)
    alfabeto = "<>/&\"'= abcdefghijklmnopqrstuvwxyz0123456789\n"
    for j in range(fuzz):
        xml = valido
        for _ in range(rnd.randint(1, 4)):
            if not xml:
                break
            pos = rnd.randrange(len(xml))
            operacao = rnd.choice(["troca", "remove", "insere"])
            if operacao == "troca":
                xml = xml[:pos] + rnd.choice(alfabeto) + xml[pos + 1 :]
            elif operacao == "remove":
                xml = xml[:pos] + xml[pos + 1 :]
            else:
                xml = xml[:pos] + rnd.choice(alfabeto) + xml[pos:]
        docs[f"900_fuzz_{j:03d}.xml"] = xml

    # --- atributos ---------------------------------------------------------
    for i, e in enumerate(elems):
        if e.tag == "Reference":
            docs["700_reference_uri_vazia.xml"] = trocar_atributo(valido, e, "URI", "")
            docs["701_reference_uri_relativa.xml"] = trocar_atributo(valido, e, "URI", "NFe123")
            docs["702_reference_uri_absoluta.xml"] = trocar_atributo(valido, e, "URI", "http://x/y")
            break

    return docs


def main() -> int:
    aqui = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ref", default=os.environ.get("NFSE_REF_DIR", os.path.join(aqui, "..", "nfse-validator")))
    parser.add_argument("--corpus", default=None, help="diretorio do corpus (padrao: temporario)")
    parser.add_argument("--show", type=int, default=15, help="quantas divergencias detalhar")
    parser.add_argument("--fuzz", type=int, default=220, help="quantos documentos aleatorios gerar")
    parser.add_argument("--seed", type=int, default=42, help="semente do fuzz")
    args = parser.parse_args()

    ref = os.path.abspath(args.ref)
    if not os.path.isdir(ref):
        print(f"erro: projeto de referencia nao encontrado em {ref}", file=sys.stderr)
        return 2

    corpus = args.corpus or tempfile.mkdtemp(prefix="nfse-corpus-")
    if args.corpus and os.path.isdir(corpus):
        shutil.rmtree(corpus)
    os.makedirs(corpus, exist_ok=True)

    docs = gerar_corpus(ref, args.fuzz, args.seed)

    sys.path.insert(0, ref)
    from nfse_validator import validate  # noqa: PLC0415

    esperado: dict[str, dict] = {}
    for nome, xml in docs.items():
        with open(os.path.join(corpus, nome), "w", encoding="utf-8", newline="") as fh:
            fh.write(xml)
        rel = validate(xml)
        esperado[nome] = rel.to_dict()

    saida = subprocess.run(
        ["php", os.path.join(aqui, "tools", "dump.php"), corpus],
        capture_output=True,
        text=True,
        check=False,
    )
    if saida.returncode != 0:
        print("erro ao rodar o validador PHP:", file=sys.stderr)
        print(saida.stderr[:4000], file=sys.stderr)
        return 2

    obtido = json.loads(saida.stdout)

    divergencias = []
    for nome in sorted(docs):
        e, o = esperado[nome], obtido.get(nome)
        if o is None:
            divergencias.append((nome, "faltou relatorio no PHP", None))
            continue

        if e["ok"] != o["ok"] or e["counts"] != o["counts"]:
            divergencias.append((nome, f"ok/counts: py={e['ok']}/{e['counts']} php={o['ok']}/{o['counts']}", None))
            continue

        ei, oi = e["issues"], o["issues"]
        if len(ei) != len(oi):
            divergencias.append((nome, f"n issues: py={len(ei)} php={len(oi)}", (ei, oi)))
            continue

        for a, b in zip(ei, oi):
            chave_py = (a["severity"], a["code"], a["path"], a["line"], a["column"])
            chave_php = (b["severity"], b["code"], b["path"], b["line"], b["column"])

            # Erro sintatico vem do expat: codigo, mensagem e posicao do ponto
            # de deteccao mudam entre versoes da lib (PHP usa a libexpat do
            # sistema, Python usa a expat embutida). O contrato aqui e o codigo
            # do issue; posicao de erro sintatico e melhor esforco. Em XML
            # estruturalmente valido a comparacao e completa (path/linha/coluna).
            sintatico = a["code"] in {"XML_MALFORMADO", "XML_ENCODING_INVALIDO"}
            if sintatico:
                chave_py = (a["severity"], a["code"], a["path"])
                chave_php = (b["severity"], b["code"], b["path"])

            if chave_py != chave_php:
                divergencias.append((nome, f"issue: py={chave_py} php={chave_php}", (ei, oi)))
                break

            if sintatico:
                continue

            if normalizar(a["message"]) != normalizar(b["message"]):
                divergencias.append((nome, f"mensagem: py={a['message']!r} php={b['message']!r}", (ei, oi)))
                break

    total = len(docs)
    print(f"corpus: {total} arquivos em {corpus}")
    print(f"iguais: {total - len(divergencias)}")
    print(f"divergencias: {len(divergencias)}")

    for nome, motivo, detalhe in divergencias[: args.show]:
        print(f"\n--- {nome}\n    {motivo}")
        if detalhe:
            for lado, issues in (("py", detalhe[0]), ("php", detalhe[1])):
                for i in issues[:6]:
                    print(f"    {lado}: {i['severity']:<7} {i['code']:<45} {i['path']}")

    if divergencias:
        print("\nFALHOU")
        return 1

    print("\nOK - PHP e Python concordam em todos os arquivos")
    return 0


def normalizar(texto: str) -> str:
    """Normaliza o que nao e contrato: relogio e notacao cientifica.

    - O relogio aparece em IDE_DTEMI_FUTURO ("agora: ...") e obviamente difere
      entre duas execucoes; so o instante e comparado, nao a string.
    - Valor em notacao cientifica (`1e3`) e impresso como `1E+3` pelo Decimal
      do Python e como `1000` pelo PHP. O valor e o mesmo; normalizamos a
      representacao para comparar o numero.
    """
    texto = re.sub(r"\(agora: [^)]+\)", "(agora: <relogio>)", texto)

    def _cientfico(m: re.Match[str]) -> str:
        return format(Decimal(m.group(1) + "E" + m.group(2)), "f")

    texto = re.sub(r"\b(\d+(?:\.\d+)?)E([+-]\d+)\b", _cientfico, texto)

    return unicodedata.normalize("NFC", " ".join(texto.split()))


if __name__ == "__main__":
    raise SystemExit(main())
