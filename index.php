<?php
declare(strict_types=1);

$dataPath = __DIR__ . '/data.json';
$htmlPath = __DIR__ . '/index.html';

if (!is_file($dataPath) || !is_readable($dataPath) || !is_file($htmlPath) || !is_readable($htmlPath)) {
    http_response_code(500);
    exit('The site content files are unavailable.');
}

$data = json_decode((string) file_get_contents($dataPath), true);
$html = file_get_contents($htmlPath);

if (!is_array($data) || !is_string($html)) {
    http_response_code(500);
    exit('The site content is invalid.');
}

$payload = json_encode(
    $data,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

if (!is_string($payload)) {
    http_response_code(500);
    exit('The site content could not be encoded.');
}

$contentScript = '<script>window.SITE_CONTENT=' . $payload . ';</script>';
$bodyClose = strripos($html, '</body>');

if ($bodyClose === false) {
    http_response_code(500);
    exit('The homepage markup is invalid.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
echo substr($html, 0, $bodyClose) . $contentScript . substr($html, $bodyClose);
