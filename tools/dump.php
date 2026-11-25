<?php

declare(strict_types=1);

/**
 * Dump de conformidade: valida todos os XMLs de um diretorio e imprime um JSON
 * `{nome_do_arquivo: relatorio}`.
 *
 * Existe para o harness tools/conformance.py nao pagar o custo de subir um
 * processo PHP por arquivo. Nao faz parte da API publica do pacote.
 *
 * Uso: php tools/dump.php <diretorio>
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "erro: rode `composer install` antes.\n");
    exit(2);
}
require $autoload;

$dir = $argv[1] ?? null;
if ($dir === null || !is_dir($dir)) {
    fwrite(STDERR, "uso: php tools/dump.php <diretorio>\n");
    exit(2);
}

$arquivos = glob(rtrim($dir, '/') . '/*.xml') ?: [];
sort($arquivos);

$saida = [];
foreach ($arquivos as $arquivo) {
    $xml = file_get_contents($arquivo);
    if ($xml === false) {
        $xml = '';
    }
    $relatorio = ChrZ\ValidaNfse\Validator::validate($xml, false, basename($arquivo));
    $saida[basename($arquivo)] = $relatorio->toArray();
}

$json = json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "erro ao serializar: " . json_last_error_msg() . "\n");
    exit(2);
}

echo $json, PHP_EOL;
