<?php
// engine/CentralMessaging/CwBridge.php — Olaj V4
// Kompatybilna nakładka na różne implementacje Cw:
// - Preferuje Cw::enqueue() (statyczne), potem inne metody enqueue-like
// - Obsługuje też metody instancyjne (różne konstruktory: (), ($pdo), ($pdo,$ownerId))
// - Nie dubluje odpowiedzi: jeśli ret ma klucz 'ok', zwracamy go bez owijania
// - Zwracany kształt: ['ok'=>bool, ...] lub ['ok'=>bool, 'raw'=>mixed] gdy metoda nie zwraca struktury

declare(strict_types=1);

namespace Engine\CentralMessaging;

use PDO;
use Throwable;

final class CwBridge
{
    /** Kandydaci, gdy brak Cw::enqueue(). */
    private const CANDIDATE_METHODS = [
        'enqueue',
        'queue',
        'dispatch',
        'send',
        'publish',
        'enqueueMessage',
        'push',
    ];

    /**
     * Główne wejście: spróbuj dotrzeć do metody enqueue-like na Cw i zwróć spójny wynik.
     * @param array|string $msg
     * @return array{ok:bool}|array{ok:bool,raw:mixed}
     */
    public static function enqueue(PDO $pdo, int $ownerId, array|string $msg): array
    {
        $class = '\\Engine\\CentralMessaging\\Cw';
        if (!class_exists($class)) {
            throw new \RuntimeException('CwBridge: class \\Engine\\CentralMessaging\\Cw not found');
        }

        // 1) Twardo preferuj statyczne Cw::enqueue (jeśli istnieje)
        if (method_exists($class, 'enqueue')) {
            $ret = self::callWithVariants([$class, 'enqueue'], $pdo, $ownerId, $msg);
            return self::normalizeResult($ret);
        }

        // 2) Pozostałe metody statyczne w kolejności kandydatów (pomijamy już 'enqueue', bo sprawdzono wyżej)
        foreach (self::CANDIDATE_METHODS as $m) {
            if ($m === 'enqueue') continue;
            if (method_exists($class, $m)) {
                $ret = self::callWithVariants([$class, $m], $pdo, $ownerId, $msg);
                return self::normalizeResult($ret);
            }
        }

        // 3) Metody instancyjne (różne konstruktory)
        $instance = self::makeInstance($class, $pdo, $ownerId);
        if ($instance) {
            // najpierw enqueue, potem reszta
            if (method_exists($instance, 'enqueue')) {
                $ret = self::callWithVariants([$instance, 'enqueue'], $pdo, $ownerId, $msg);
                return self::normalizeResult($ret);
            }
            foreach (self::CANDIDATE_METHODS as $m) {
                if ($m === 'enqueue') continue;
                if (method_exists($instance, $m)) {
                    $ret = self::callWithVariants([$instance, $m], $pdo, $ownerId, $msg);
                    return self::normalizeResult($ret);
                }
            }
        }

        throw new \RuntimeException('CwBridge: no compatible enqueue-like method found on Cw');
    }

    // ───────────────────── helpers ─────────────────────

    /**
     * Próbuje wywołać funkcję/metodę z różnymi zestawami argumentów.
     * Zwraca bez modyfikacji „surowy” wynik celu (może być bool/int/array/string/null).
     * Rzuca dopiero gdy żadna kombinacja nie pasuje.
     */
    private static function callWithVariants(callable $fn, PDO $pdo, int $ownerId, array|string $msg)
    {
        $variants = [
            [$pdo, $ownerId, $msg],
            [$pdo, $msg],
            [$ownerId, $msg],
            [$msg],
        ];
        foreach ($variants as $args) {
            try {
                return $fn(...$args);
            } catch (Throwable $__) {
                // próbujemy kolejną sygnaturę
            }
        }
        // ostatecznie spróbuj bez argumentów (gdy Cw trzyma stan wewnętrzny)
        try {
            return $fn();
        } catch (Throwable $__) {
        }
        throw new \RuntimeException('CwBridge: no matching signature for enqueue-like method');
    }

    /** Podejmuje próby utworzenia instancji Cw różnymi konstruktorami. */
    private static function makeInstance(string $class, PDO $pdo, int $ownerId)
    {
        $ctors = [
            static fn() => new $class(),
            static fn() => new $class($pdo),
            static fn() => new $class($pdo, $ownerId),
        ];
        foreach ($ctors as $ctor) {
            try {
                return $ctor();
            } catch (Throwable $__) {
                // próbujemy następny konstruktor
            }
        }
        return null;
    }

    /** Spłaszcza wynik do konwencji bridge’a. Nie owija ponownie struktury z kluczem 'ok'. */
    private static function normalizeResult($ret): array
    {
        if (is_array($ret) && array_key_exists('ok', $ret)) {
            // już w formacie ['ok'=>...], zwróć jak jest
            return $ret;
        }
        return ['ok' => self::truthy($ret), 'raw' => $ret];
    }

    /** Heurystyka „truthy” do prostych zwrotów typu bool/int/scalar. */
    private static function truthy($ret): bool
    {
        if (is_array($ret) && array_key_exists('ok', $ret)) return (bool)$ret['ok'];
        if (is_bool($ret))  return $ret;
        if (is_int($ret))   return $ret > 0;
        if (is_string($ret)) return $ret !== '' && $ret !== '0';
        return $ret !== null;
    }
}
