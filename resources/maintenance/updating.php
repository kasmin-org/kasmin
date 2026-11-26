<?php

// Shown instead of Kasmin while the files of a new version are being put in place (by the update in the browser or
// by a deployment that uploads the files). Laravel's public/index.php loads storage/framework/maintenance.php before
// anything else, so this file must not need the framework: half-replaced files would otherwise answer with errors.
// The addresses that finish an update and the hooks of deployment pipelines go through.
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/(system/hooks/|system/update/finish|system/cron)#', $path) === 1) {
    return;
}
// A leftover after a failed upload must not lock everybody out for good.
if (@filemtime(__FILE__) < time() - 7200) {
    return;
}
$german = str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'de');
http_response_code(503);
header('Retry-After: 120');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (str_starts_with($path, '/api/') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    header('Content-Type: application/json');
    echo '{"message":"Kasmin is being updated. Please try again in a few minutes."}';
    exit;
}
$title = $german ? 'Kasmin wird gerade aktualisiert' : 'Kasmin is being updated';
$text = $german
    ? 'Eine neue Version wird eingespielt. Das dauert meist nur wenige Minuten – bitte laden Sie die Seite dann neu. Ihre Daten bleiben erhalten.'
    : 'A new version is being installed. This usually takes only a few minutes – please reload the page then. Your data stays as it is.';
echo '<!DOCTYPE html><html lang="'.($german ? 'de' : 'en').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="60"><title>'.$title.'</title>'
    .'<style>body{font-family:system-ui,sans-serif;margin:0;padding:2rem 1rem;color:#14501C;background:#f6faf6}main{max-width:32rem;margin:10vh auto 0;background:#fff;border-radius:1rem;padding:1.5rem;box-shadow:0 1px 4px rgba(0,0,0,.08)}h1{font-size:1.4rem;margin:0 0 .5rem}</style></head>'
    .'<body><main><h1>'.$title.'</h1><p>'.$text.'</p></main></body></html>';
exit;
