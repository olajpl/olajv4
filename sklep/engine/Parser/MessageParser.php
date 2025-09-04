<?php

declare(strict_types=1);

namespace Engine\Parser;

use PDO;

// brak autoloadera? doładuj ręcznie handlery
require_once __DIR__ . '/handlers/DajHandler.php';
require_once __DIR__ . '/handlers/PomocHandler.php';
require_once __DIR__ . '/handlers/ZamknijHandler.php';
require_once __DIR__ . '/handlers/WaitlistHandler.php';

use Engine\Parser\Handlers\DajHandler;
use Engine\Parser\Handlers\PomocHandler;
use Engine\Parser\Handlers\ZamknijHandler;
use Engine\Parser\Handlers\WaitlistHandler;

/**
 * MessageParser — centralny router parsera wiadomości (V4).
 *
 * Zasady:
 * - Panel/Sklep/LIVE → zawsze przez engine; logi centralnie (olaj_v4_logger: logg()).

 * - Nie dotykamy DB bezpośrednio — robią to handlery/enginy.
 * - Wszędzie przekazujemy owner/platform/platformId w kontekście.
 */
final class MessageParser
{
    /** Max długość logowanego tekstu (żeby nie zalewać logów). */
    private const LOG_TEXT_LIMIT = 1024;

    /** Wzorce pomocnicze */
    private const RE_DAJ = '/^(?:\s*#?\s*)?(?:daj|dajcie)\s*([A-Za-z0-9\-\_]{3,})\s*(?:[+x×*]\s*(\d{1,4}))?\s*$/iu';

    /**
     * @param PDO|null $pdo        (wymagane dla komend ingerujących w DB, np. DAJ)
     * @param int      $ownerId
     * @param string   $platform   Np. 'facebook' | 'messenger' | 'instagram'
     * @param string   $platformId PSID/ThreadID/itp. (źródłowy identyfikator)
     * @param string   $text       Surowy tekst od użytkownika
     * @return array               Wynik handlera lub ['error' => '...']
     */
    public static function dispatch(
        ?PDO $pdo,
        int $ownerId,
        string $platform,
        string $platformId,
        string $text
    ): array {
        // --- sanity & normalizacja ---
        $text = (string)$text;
        if ($text === '' || trim($text) === '') {
            self::log('info', 'parser', 'cmd_pomoc', [
                'owner_id'    => $ownerId,
                'platform'    => $platform,
                'platform_id' => $platformId,
                'channel'     => $channel
            ]);
            return ['error' => 'Pusta wiadomość'];
        }

        // odszumianie (nietypowe whitespace, podwójne spacje, miękkie enterki)
        $original = self::trimToLength(self::normalizeSpaces($text), 4000);
        $norm     = mb_strtolower($original, 'UTF-8');

        // „messenger” jako kanał logiczny; platformę zostawiamy jak przyszła (facebook/messenger)
        $channel  = self::mapPlatformToChannel($platform);

        self::log('info', 'parser', 'parse_start', [
            'owner_id'    => $ownerId,
            'platform'    => $platform,
            'channel'     => $channel,
            'platform_id' => self::maskId($platformId),
            'text'        => self::trimToLength($original, self::LOG_TEXT_LIMIT),
        ]);

        try {
            // 1) POMOC (hashtag + naturalny język)
            if (
                str_contains($norm, '#pomoc') ||
                preg_match('/\b(pomoc|jak|zamówić|co mogę|gdzie.*paczka|jak kupić|kontakt|help|support)\b/u', $norm)
            ) {
                self::log('info', 'parser', 'cmd_pomoc', compact('ownerId', 'platform', 'platformId', 'channel'));
                $res = PomocHandler::handle($ownerId, $platformId);
                self::log('info', 'parser', 'cmd_pomoc_result', ['result' => self::safeJson($res)]);
                return $res;
            }

            // 2) DAJ — „daj CODE x QTY” (x/×/*/+), kod ≥3 znaki (litery/cyfry/_/-)
            if (preg_match(self::RE_DAJ, $original, $m)) {
                if (!$pdo) {
                    self::log('warning', 'parser', 'cmd_daj_no_pdo', compact('ownerId', 'platform', 'platformId', 'channel'));
                    return ['error' => 'Brak PDO w kontekście'];
                }
                // Parsujemy od razu, ale DajHandler i tak ma swoje walidacje — przekazujemy raw + parsed
                $code = (string)$m[1];
                $qty  = isset($m[2]) ? (int)$m[2] : 1;

                self::log('info', 'parser', 'cmd_daj', [
                    'owner_id'    => $ownerId,
                    'platform'    => $platform,
                    'platform_id' => self::maskId($platformId),
                    'code'        => $code,
                    'qty'         => $qty
                ]);
                $res = DajHandler::handle($pdo, $ownerId, $platform, $platformId, $original);
                self::log('info', 'parser', 'cmd_daj_result', ['result' => self::safeJson($res)]);
                return $res;
            }

            // 3) ZAMKNIJ (domknięcie rozmowy/procesu)
            if (
                str_contains($norm, '#zamknij') ||
                preg_match('/\b(zamknij|koniec|skończone|to wszystko|zamyka|finish|done)\b/u', $norm)
            ) {
                self::log('info', 'parser', 'cmd_zamknij', compact('ownerId', 'platform', 'platformId', 'channel'));
                $res = ZamknijHandler::handle($ownerId, $platformId);
                self::log('info', 'parser', 'cmd_zamknij_result', ['result' => self::safeJson($res)]);
                return $res;
            }

            // 4) WAITLIST (opcjonalna komenda)
            if (str_contains($norm, '#wait') || preg_match('/\b(lista\s*oczekujących|waitlist|rezerwuj|zapisz)\b/u', $norm)) {
                self::log('info', 'parser', 'cmd_waitlist', compact('ownerId', 'platform', 'platformId', 'channel'));
                $res = WaitlistHandler::handle($ownerId, $platformId, $original, $platform);
                self::log('info', 'parser', 'cmd_waitlist_result', ['result' => self::safeJson($res)]);
                return $res;
            }

            // Nic nie pasuje → sygnalizujemy no‑match
            self::log('notice', 'parser', 'unrecognized', [
                'owner_id'    => $ownerId,
                'platform'    => $platform,
                'channel'     => $channel,
                'platform_id' => self::maskId($platformId),
                'text'        => self::trimToLength($original, self::LOG_TEXT_LIMIT),
            ]);
            return ['error' => 'Nie rozpoznano wiadomości.'];
        } catch (\Throwable $e) {
            self::log('error', 'parser', 'exception', [
                'owner_id'    => $ownerId,
                'platform'    => $platform,
                'channel'     => $channel,
                'platform_id' => self::maskId($platformId),
                'text'        => self::trimToLength($original, self::LOG_TEXT_LIMIT),
                'exc'         => $e->getMessage(),
                'trace'       => substr($e->getTraceAsString(), 0, 2000),
            ]);
            return ['error' => 'Parser error: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** Mapuje platformę źródłową do logicznego kanału CW. */
    private static function mapPlatformToChannel(string $platform): string
    {
        $p = mb_strtolower(trim($platform));
        return match ($p) {
            'facebook', 'messenger', 'messenger_api' => 'messenger',
            'ig', 'instagram'                         => 'messenger', // jeśli IG DM jedzie tym samym kanałem
            'sms'                                     => 'sms',
            'email', 'mail'                           => 'email',
            default                                   => 'manual',
        };
    }

    /** Prosta anonimizacja ID do logów. */
    private static function maskId(string $id): string
    {
        $id = (string)$id;
        if ($id === '') return '';
        if (mb_strlen($id) <= 6) return str_repeat('*', max(0, mb_strlen($id) - 2)) . mb_substr($id, -2);
        return mb_substr($id, 0, 2) . '***' . mb_substr($id, -2);
        // czytelne: ab***yz
    }

    /** Usuwa dziwne whitespace’y i normalizuje spacje. */
    private static function normalizeSpaces(string $s): string
    {
        // zamiana niełamliwych spacji/ciężkich whitespace na zwykłe
        $s = preg_replace('/\h+/u', ' ', $s ?? '') ?? '';
        // utnij nadmiarowe whitespace na końcach i złóż wielokrotne spacje
        $s = trim(preg_replace('/\s{2,}/u', ' ', $s) ?? '');
        return $s;
    }

    /** Przycinanie do max długości. */
    private static function trimToLength(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }

    /** Proxy na centralny logger V4 */
    private static function log(string $level, string $channel, string $event, array $ctx = []): void
    {
        // Zawsze staramy się zachować owner/platform_id w kontekście
        if (\function_exists('logg')) {
            \logg($level, $channel, $event, $ctx);
        } else {
            // fallback do error_log – bezpieczny
            error_log(sprintf('[%s][%s] %s %s', $level, $channel, $event, json_encode($ctx, JSON_UNESCAPED_UNICODE)));
        }
    }

    private static function safeJson($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
