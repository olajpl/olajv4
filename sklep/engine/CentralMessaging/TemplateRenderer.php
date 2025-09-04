<?php

declare(strict_types=1);

namespace Engine\CentralMessaging;

final class TemplateRenderer
{
    /**
     * Renderuje zwykły tekst na bazie szablonu i payloadu.
     *
     * @param string $template
     * @param array  $payload
     * @param array  $opts     ['preserve_unknown'=>false]
     */
    public static function renderText(string $template, array $payload, array $opts = []): string
    {
        return self::replaceTags($template ?? '', $payload, $opts);
    }

    /**
     * Renderuje HTML + (opcjonalnie) tekst. Jeśli HTMLa brak, a mamy tekst,
     * tworzy prosty fallback <p>…</p>.
     *
     * @param ?string $templateHtml
     * @param array   $payload
     * @param ?string $fallbackText
     * @param array   $opts         ['preserve_unknown'=>false]
     * @return array{text:?string, html:?string}
     */
    public static function renderHtml(?string $templateHtml, array $payload, ?string $fallbackText = null, array $opts = []): array
    {
        $text = $fallbackText !== null ? self::replaceTags($fallbackText, $payload, $opts) : null;
        $html = $templateHtml !== null ? self::replaceTags($templateHtml, $payload, $opts) : null;

        if (!$html && $text) {
            $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }
        return ['text' => $text, 'html' => $html];
    }

    /**
     * Structured output (np. Messenger).
     * Zwraca:
     *  [
     *    'text'    => '…',
     *    'buttons' => [
     *       ['type'=>'web_url',  'title'=>'…', 'url'=>'…'],
     *       ['type'=>'postback', 'title'=>'…', 'payload'=>'…'],
     *       …
     *    ]
     *  ]
     *
     * @param array $tpl    Oczekuje pól: body_text|template_text, buttons (tablica definicji)
     * @param array $payload
     * @param array $opts   [
     *    'max_buttons'       => 3,              // twardy limit
     *    'preserve_unknown'  => false,
     * ]
     */
    public static function renderStructured(array $tpl, array $payload, array $opts = []): array
    {
        $maxButtons = (int)($opts['max_buttons'] ?? 3);
        if ($maxButtons < 0) $maxButtons = 0;

        $textTpl = (string)($tpl['body_text'] ?? ($tpl['template_text'] ?? ''));
        $text    = self::replaceTags($textTpl, $payload, $opts);

        $buttonsOut = [];
        $buttonsIn  = (array)($tpl['buttons'] ?? []);

        foreach ($buttonsIn as $btn) {
            if (count($buttonsOut) >= $maxButtons) break;

            $btnType    = strtolower((string)($btn['type'] ?? 'web_url'));
            $titleRaw   = (string)($btn['title'] ?? '');
            $title      = self::replaceTags($titleRaw, $payload, $opts);

            // Puste tytuły są bez sensu → pomijamy
            if (trim($title) === '') {
                continue;
            }

            if ($btnType === 'web_url') {
                $urlRaw = isset($btn['url']) ? (string)$btn['url'] : '';
                $url    = self::replaceTags($urlRaw, $payload, $opts);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $buttonsOut[] = ['type' => 'web_url', 'title' => $title, 'url' => $url];
                }
            } elseif ($btnType === 'postback') {
                $plRaw   = isset($btn['payload']) ? (string)$btn['payload'] : '';
                $pl     = self::replaceTags($plRaw, $payload, $opts);
                if ($pl !== '') {
                    $buttonsOut[] = ['type' => 'postback', 'title' => $title, 'payload' => $pl];
                }
            }
            // Nieznane typy – pomijamy cicho
        }

        return ['text' => $text, 'buttons' => $buttonsOut];
    }

    /**
     * Renderuje tablicę przycisków (Messenger buttons) z placeholderami.
     *
     * @param array $buttonsRaw — tablica z szablonu `buttons_json`
     * @param array $payload    — dane do podstawienia (np. checkout_url)
     * @param array $opts       — ['max_buttons'=>3, 'preserve_unknown'=>false]
     * @return array — tablica przycisków gotowa do wysyłki
     */
    public static function renderButtons(array $buttonsRaw, array $payload, array $opts = []): array
    {
        $maxButtons = (int)($opts['max_buttons'] ?? 3);
        if ($maxButtons < 0) $maxButtons = 0;

        $buttonsOut = [];

        foreach ($buttonsRaw as $btn) {
            if (count($buttonsOut) >= $maxButtons) break;

            $btnType  = strtolower((string)($btn['type'] ?? 'web_url'));
            $titleRaw = (string)($btn['title'] ?? '');
            $title    = self::replaceTags($titleRaw, $payload, $opts);

            if (trim($title) === '') continue;

            if ($btnType === 'web_url') {
                $urlRaw = (string)($btn['url'] ?? '');
                $url    = self::replaceTags($urlRaw, $payload, $opts);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $buttonsOut[] = ['type' => 'web_url', 'title' => $title, 'url' => $url];
                }
            } elseif ($btnType === 'postback') {
                $plRaw = (string)($btn['payload'] ?? '');
                $pl    = self::replaceTags($plRaw, $payload, $opts);
                if ($pl !== '') {
                    $buttonsOut[] = ['type' => 'postback', 'title' => $title, 'payload' => $pl];
                }
            }
        }

        return $buttonsOut;
    }



    /**
     * Podmienia {{ key }} oraz {{ key|FILTER(...) }}.
     * Obsługiwane filtry (proste i szybkie):
     *  - upper / lower
     *  - escape      (HTML-escape)
     *  - urlencode
     *  - json        (JSON-encode value)
     *  - number(2)   (format liczby z podaną liczbą miejsc po przecinku)
     *
     * Opcja 'preserve_unknown' (bool): true → zostawia {{ … }} gdy brak wartości;
     * domyślnie false → zamienia na pusty string.
     */
    private static function replaceTags(string $template, array $payload, array $opts = []): string
    {
        $preserveUnknown = (bool)($opts['preserve_unknown'] ?? false);

        // {{ path.to.key | filter(arg) }}
        $re = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|\s*([a-zA-Z0-9_]+)(?:\((.*?)\))?)?\s*\}\}/u';

        $cb = function (array $m) use ($payload, $preserveUnknown): string {
            $path   = isset($m[1]) ? $m[1] : '';
            $filter = isset($m[2]) ? strtolower($m[2]) : null;
            $argStr = isset($m[3]) ? (string)$m[3] : null;

            $val = self::dig($payload, explode('.', $path));

            // Brak wartości
            if ($val === null || $val === '') {
                return $preserveUnknown ? $m[0] : '';
            }

            // Skalary OK, resztę zserializuj
            if (!is_scalar($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $val = (string)$val;
            }

            if (!$filter) {
                return $val;
            }

            // prościutki parser argumentu (np. number(2) → "2")
            $arg = null;
            if ($argStr !== null) {
                $argStr = trim($argStr);
                // zdejmij cudzysłowy jeśli są
                if ((str_starts_with($argStr, '"') && str_ends_with($argStr, '"'))
                    || (str_starts_with($argStr, "'") && str_ends_with($argStr, "'"))
                ) {
                    $arg = substr($argStr, 1, -1);
                } else {
                    $arg = $argStr;
                }
            }

            switch ($filter) {
                case 'upper':
                    return mb_strtoupper($val, 'UTF-8');
                case 'lower':
                    return mb_strtolower($val, 'UTF-8');
                case 'escape':
                    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                case 'urlencode':
                    return urlencode($val);
                case 'json':
                    return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'number':
                    $dec = is_numeric($arg) ? (int)$arg : 0;
                    $n   = (float)str_replace(',', '.', $val);
                    return number_format($n, max(0, $dec), '.', '');
                default:
                    // Nieznany filtr – zachowaj oryginał tagu
                    return $val;
            }
        };

        return preg_replace_callback($re, $cb, $template) ?? $template;
    }

    /** Proste zejście po kluczach a.b.c → wartość lub null */
    private static function dig(array $arr, array $path)
    {
        $x = $arr;
        foreach ($path as $k) {
            if (is_array($x) && array_key_exists($k, $x)) {
                $x = $x[$k];
            } else {
                return null;
            }
        }
        return $x;
    }
}
