<?php
// includes/json_api_guard.php — wymusza czyste JSON, zbiera śmieci i loguje
declare(strict_types=1);

// wyłącz HTML-owe błędy; nic nie pisz na wyjście
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

// buforuj WSZYSTKO co by wyleciało
ob_start();

// nagłówek JSON (nie nadpisze, jeśli już wysłano)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// zamień warningi/notice na wyjątki → złapie je try/catch w skrypcie albo shutdown
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// fatal/shutdown: jeżeli cokolwiek zostało w buforze, zamień na JSON i zaloguj
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out !== '' && $out !== null) {
        if (function_exists('logg')) {
            logg('error', 'api.json', 'unexpected_output', [
                'snippet' => mb_substr(trim($out), 0, 1000, 'UTF-8')
            ]);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Unexpected server output'], JSON_UNESCAPED_UNICODE);
    }
});
