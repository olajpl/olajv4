<?php
// engine/Parser/ParserEngine.php — Olaj.pl V4 (router z wynikiem + twarde logi)
declare(strict_types=1);

namespace Engine\Parser;

use PDO;
use Throwable;

require_once __DIR__ . '/Handlers/DajHandler.php';

use Engine\Parser\Handlers\DajHandler;

final class ParserEngine
{
    /** @var array<int, array{regex:string, handler:callable, cmd:string}> */
    private static array $routes = [
        [
            'regex'   => '/^\s*daj\b[\s\:\-\—\–\|]*.+/iu',
            'handler' => [DajHandler::class, 'handle'],
            'cmd'     => 'daj',
        ],
    ];

    /**
     * Główne wejście z Messengera (lub innego kanału).
     * Zwraca wynik handlera: ['status'=>'ok', ...] albo ['status'=>'error', 'reason'=>...]
     */
    public static function handleMessengerText(PDO $pdo, int $ownerId, int $clientId, string $text): array
    {
        $ownerId  = (int)$ownerId;
        $clientId = (int)$clientId;
        $raw      = (string)$text;

        $reqId = self::getRequestId();
        static $selfTestDone = false;
        if (!$selfTestDone) {
            $selfTestDone = true;
            self::safelog('info', 'parser', 'router:selftest', [
                'file' => __FILE__,
                'req_id' => $reqId,
            ]);

            self::safelog('info', 'parser', 'router:start', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'raw_len'   => mb_strlen($raw),
                'has_text'  => $raw !== '',
                'req_id'    => $reqId,
            ]);

            // 1) Normalizacja
            $normalized = self::normalizeText($raw);
            if ($normalized === '') {
                self::safelog('debug', 'parser', 'router:empty_after_normalize', [
                    'was_empty' => $raw === '',
                    'req_id'    => $reqId,
                ]);
                return ['status' => 'error', 'reason' => 'empty'];
            }

            self::safelog('debug', 'parser', 'router:normalized', [
                'preview' => mb_substr($normalized, 0, 120),
                'req_id'  => $reqId,
            ]);

            // 2) Routing
            foreach (self::$routes as $route) {
                if (preg_match($route['regex'], $normalized) === 1) {
                    $cmd = $route['cmd'] ?? 'unknown';

                    self::safelog('info', 'parser', 'route.match', [
                        'cmd'     => $cmd,
                        'regex'   => $route['regex'],
                        'preview' => mb_substr($normalized, 0, 80),
                        'req_id'  => $reqId,
                    ]);

                    try {
                        /** @var callable $handler */
                        $handler = $route['handler'];
                        $res = \call_user_func($handler, $pdo, $ownerId, $clientId, $normalized);

                        // Normalizacja wyniku
                        $status = (string)($res['status'] ?? 'ok');
                        self::safelog(
                            $status === 'ok' ? 'info' : 'warning',
                            'parser',
                            'route.handled',
                            ['cmd' => $cmd, 'status' => $status, 'req_id' => $reqId]
                        );

                        return is_array($res) ? $res : ['status' => 'ok'];
                    } catch (Throwable $e) {
                        self::safelog('error', 'parser', 'handler.exception', [
                            'cmd'    => $cmd,
                            'err'    => $e->getMessage(),
                            'file'   => $e->getFile(),
                            'line'   => $e->getLine(),
                            'req_id' => $reqId,
                        ]);
                        return ['status' => 'error', 'reason' => 'exception', 'message' => $e->getMessage()];
                    }
                }
            }
        }

        // 3) Fallback — brak dopasowanej komendy.
        self::safelog('info', 'parser', 'router:no_route', [
            'reason'  => 'no_command_matched',
            'preview' => mb_substr($normalized, 0, 200),
            'req_id'  => $reqId,
        ]);
        return ['status' => 'error', 'reason' => 'unrecognized'];
    }

    private static function normalizeText(string $s): string
    {
        $s = preg_replace('/\x{FEFF}|\x{200B}|\x{200C}|\x{200D}|\p{Cf}/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Miękkie logowanie — brak twardej zależności od includes/log.php. */
    private static function safelog(string $level, string $channel, string $msg, array $ctx = []): void
    {
        try {
            if (\function_exists('logg')) {
                \logg($level, $channel, $msg, $ctx); // wymuszamy global
                return;
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PARSER][logger-throw] %s %s %s %s :: %s',
                $level,
                $channel,
                $msg,
                json_encode($ctx, JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            ));
            return;
        }

        // ostatnia deska ratunku, gdy includes/log.php nie jest wczytany
        error_log(sprintf(
            '[PARSER][fallback] %s %s %s %s',
            $level,
            $channel,
            $msg,
            json_encode($ctx, JSON_UNESCAPED_UNICODE)
        ));
    }


    /** Korelacyjny request_id (jeśli jest) */
    private static function getRequestId(): ?string
    {
        $rid = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($rid) && $rid !== '') return $rid;
        if (isset($GLOBALS['__olaj_request_id']) && is_string($GLOBALS['__olaj_request_id']) && $GLOBALS['__olaj_request_id'] !== '') {
            return $GLOBALS['__olaj_request_id'];
        }
        return null;
    }
}
