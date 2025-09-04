<?php

/**
 * includes/pdf_invoice_parser.php — Olaj.pl V4
 * 1) Ekstrakcja tekstu z PDF:
 *    - pdf_read_text_via_library() (jeśli dostępne w pdf_text_reader.php)
 *    - pdftotext (Poppler)
 *    - Smalot\PdfParser (composer)
 *    - tesseract (OCR)
 * 2) Parsowanie pozycji:
 *    - Profil "KRZYS" (dwulinijkowe nazwy, linia z "X szt.", EAN doklejony do ceny)
 *    - Fallback heurystyczny
 * Logowanie: logg() (jeśli dostępne).
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor_bootstrap.php';
require_once __DIR__ . '/pdf_text_reader.php';
require_once __DIR__ . '/log.php';

/* =========================
 *  Ekstrakcja tekstu z PDF (pojedyncza definicja)
 * ========================= */
function olaj_pdf_extract_text(string $pdf_path): string
{
    $text = '';

    // A) biblioteka (pdf_text_reader.php) - jeśli jest dostępna
    if (function_exists('pdf_read_text_via_library')) {
        try {
            $t0 = pdf_read_text_via_library($pdf_path);
            if (is_string($t0) && mb_strlen(trim($t0)) > 80) {
                $text = $t0;
                if (function_exists('logg')) logg('debug', 'pdf.extract', 'library OK', ['len' => strlen($t0)]);
            }
        } catch (\Throwable $e) {
            if (function_exists('logg')) logg('warning', 'pdf.extract', 'library exception', ['error' => $e->getMessage()]);
        }
    }

    // B) pdftotext (Poppler)
    if ($text === '' && function_exists('shell_exec')) {
        $cmd = 'pdftotext -layout -nopgbrk -q ' . escapeshellarg($pdf_path) . ' -';
        $out = @shell_exec($cmd);
        if (is_string($out) && mb_strlen(trim($out)) > 80) {
            $text = $out;
            if (function_exists('logg')) logg('debug', 'pdf.extract', 'pdftotext OK', ['len' => strlen($out)]);
        }
    }

    // C) Smalot\PdfParser (composer)
    if ($text === '' && class_exists('\\Smalot\\PdfParser\\Parser')) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($pdf_path);
            $t2     = $pdf->getText();
            if (is_string($t2) && mb_strlen(trim($t2)) > 80) {
                $text = $t2;
                if (function_exists('logg')) logg('debug', 'pdf.extract', 'Smalot OK', ['len' => strlen($t2)]);
            }
        } catch (\Throwable $e) {
            if (function_exists('logg')) logg('warning', 'pdf.extract', 'Smalot exception', ['error' => $e->getMessage()]);
        }
    }

    // D) OCR: tesseract
    if ($text === '' && function_exists('shell_exec')) {
        $cmd = 'tesseract ' . escapeshellarg($pdf_path) . ' stdout -l pol+eng --psm 6 2>/dev/null';
        $out = @shell_exec($cmd);
        if (is_string($out) && mb_strlen(trim($out)) > 80) {
            $text = $out;
            if (function_exists('logg')) logg('debug', 'pdf.extract', 'tesseract OK', ['len' => strlen($out)]);
        }
    }

    $text = (string)$text;
    $text = str_replace("\x00", '', $text);

    if ($text === '' && function_exists('logg')) {
        logg('error', 'pdf.extract', 'Brak tekstu z PDF', []);
    } elseif ($text === '' && function_exists('logg')) {
        logg('warning', 'pdf.extract', 'Brak tekstu (PDF skan lub nietypowy encoding)', []);
    }

    return $text;
}

/* =========================
 *  Helpery / wzorce
 * ========================= */
function olaj_pdf_normalize_dec(?string $v): ?float
{
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return null;
    return (float)$v;
}
function olaj_pdf_normalize_int(?string $v): int
{
    if ($v === null) return 0;
    $v = trim($v);
    if ($v === '') return 0;
    $v = str_replace(["\xC2\xA0", ' '], '', $v);
    $v = str_replace(',', '.', $v);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return 0;
    return (int)round((float)$v);
}
function olaj_like_ean(string $t): bool
{
    return (bool)preg_match('/^\d{8,14}$/', $t);
}
function olaj_like_12nc(string $t): bool
{
    return (bool)preg_match('/^[A-Z0-9]{6,}$/i', $t);
}
function olaj_like_sku(string $t): bool
{
    return (bool)preg_match('/^[A-Z0-9][A-Z0-9\-\._]{2,}$/i', $t);
}

/* =========================
 *  Profil: „KRZYS”
 * ========================= */
function olaj_pdf_parse_supplier_krzys(string $text): array
{
    $rows = [];
    if ($text === '') return $rows;

    $lines = preg_split("/\r\n|\n|\r/", $text);
    if (!$lines) return $rows;

    $T = mb_strtolower($text);
    $looks_like = (mb_strpos($T, 'lp nazwa') !== false && mb_strpos($T, 'kod') !== false && mb_strpos($T, 'kreskowy') !== false)
        || (mb_strpos($T, 'podstawowy podatek vat 23%') !== false);
    if (!$looks_like) return [];

    $clean = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '') continue;
        if (preg_match('/(Ciąg dalszy|Wykaz należności|Razem do zapłaty)/ui', $t)) continue;
        $clean[] = preg_replace('/\s{2,}/', '  ', $t);
    }

    $defaultVat = 23.0;
    if (preg_match('/vat\s*23\%/i', $text)) $defaultVat = 23.0;

    $current = null;
    $seenTable = false;

    foreach ($clean as $ln) {
        if (preg_match('/^lp\b/i', $ln)) {
            $seenTable = true;
            continue;
        }
        if (!$seenTable) continue;

        // nazwa (może być wielolinijkowa)
        if (preg_match('/^\s*\d+\s+(.+)$/u', $ln, $m)) {
            $current = ['name' => trim($m[1])];
            continue;
        }
        // kontynuacja nazwy
        if ($current && !preg_match('/\bszt\.\b/u', $ln)) {
            $current['name'] .= ' ' . trim($ln);
            $current['name'] = trim(preg_replace('/\s+/', ' ', $current['name']));
            continue;
        }
        // linia detali z "X szt."
        if ($current && preg_match('/\b(\d{1,5})\s*szt\.\b/u', $ln, $mQty)) {
            $qty = olaj_pdf_normalize_int($mQty[1]);
            $lineForPrices = $ln;

            // EAN doklejony na końcu do ceny
            $barcode = null;
            if (preg_match('/(\d+,\d{2})(\d{8,14})\s*$/', $lineForPrices, $mTail)) {
                $maybeEAN = $mTail[2];
                if (olaj_like_ean($maybeEAN)) {
                    $barcode = $maybeEAN;
                    $lineForPrices = preg_replace('/(\d+,\d{2})(\d{8,14})\s*$/', '$1', $lineForPrices);
                }
            }

            // wszystkie kwoty z przecinkiem — zwykle 2. od końca to cena netto szt.
            $unit_net = null;
            preg_match_all('/\d+,\d{2}/', $lineForPrices, $mAll);
            $dec = $mAll[0] ?? [];
            if (count($dec) >= 2) $unit_net = olaj_pdf_normalize_dec($dec[count($dec) - 2]);
            if ($unit_net === null && count($dec) >= 1) $unit_net = olaj_pdf_normalize_dec($dec[count($dec) - 1]);
            if ($unit_net === null) $unit_net = 0.0;

            $rows[] = [
                'name'          => $current['name'],
                'qty'           => $qty,
                'unit_net'      => $unit_net,
                'vat_rate'      => $defaultVat,
                'barcode'       => $barcode,
                'external_12nc' => null,
                'supplier_sku'  => null,
            ];
            $current = null;
            continue;
        }
    }

    if (function_exists('logg')) logg('info', 'pdf.parse', 'Profil KRZYS dopasowany', ['rows' => count($rows)]);
    return $rows;
}

/* =========================
 *  Fallback heurystyczny
 * ========================= */
function olaj_pdf_parse_rows_generic(string $text): array
{
    $rows = [];
    if ($text === '') return $rows;

    $lines = preg_split("/\r\n|\n|\r/", $text);
    if (!$lines) return $rows;

    $clean = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '') continue;
        $clean[] = preg_replace('/\s{2,}/', '  ', $t);
    }

    foreach ($clean as $ln) {
        $t = trim($ln);
        if (preg_match('/^(?P<name>.+?)\s{2,}(?:(?P<code1>[A-Z0-9\.\-]{6,})\s{2,})?(?:(?P<code2>\d{8,14})\s{2,})?(?P<qty>\d{1,4})\s{2,}(?P<net>\d+[.,]\d{2})\s{2,}(?P<vat>\d{1,2})(?:%|)\b/i', $t, $m)) {
            $name = trim($m['name']);
            $qty  = olaj_pdf_normalize_int($m['qty']);
            $net  = olaj_pdf_normalize_dec($m['net']);
            $vat  = olaj_pdf_normalize_dec($m['vat']);
            if ($vat === null) $vat = 23.0;

            $barcode = null;
            $ext12 = null;
            $sku = null;
            $cands = [];
            if (!empty($m['code1'])) $cands[] = $m['code1'];
            if (!empty($m['code2'])) $cands[] = $m['code2'];
            foreach ($cands as $c) {
                $C = strtoupper($c);
                if (olaj_like_ean($C)) $barcode = $C;
                elseif (olaj_like_12nc($C)) $ext12 = $C;
                elseif (olaj_like_sku($C)) $sku = $C;
            }

            if ($name !== '' && $qty > 0 && $net !== null) {
                $rows[] = [
                    'name'          => $name,
                    'qty'           => $qty,
                    'unit_net'      => $net,
                    'vat_rate'      => $vat,
                    'barcode'       => $barcode,
                    'external_12nc' => $ext12,
                    'supplier_sku'  => $sku,
                ];
            }
        }
    }

    if (empty($rows) && function_exists('logg')) logg('error', 'pdf.parse', 'Fallback nie znalazł pozycji', []);
    return $rows;
}

/* =========================
 *  Parser z PLIKU PDF
 * ========================= */
function olaj_parse_pdf_invoice_to_rows(string $pdf_path): array
{
    $text = olaj_pdf_extract_text($pdf_path);
    if ($text === '') {
        if (function_exists('logg')) logg('error', 'pdf.parse', 'Brak tekstu po ekstrakcji', []);
        return [];
    }

    $rows = olaj_pdf_parse_supplier_krzys($text);
    if (!empty($rows)) return $rows;

    if (function_exists('logg')) logg('warning', 'pdf.parse', 'Brak profilu — fallback', ['peek' => trim(mb_substr($text, 0, 200))]);
    return olaj_pdf_parse_rows_generic($text);
}

/* =========================
 *  Parser z GOTOWEGO TEKSTU (np. PDF.js)
 * ========================= */
function olaj_parse_pdf_text_to_rows(string $text): array
{
    $text = str_replace("\x00", '', (string)$text);
    if ($text === '') return [];
    $rows = olaj_pdf_parse_supplier_krzys($text);
    if (!empty($rows)) return $rows;
    if (function_exists('logg')) logg('warning', 'pdf.parse', 'Brak profilu w parse_text — fallback', []);
    return olaj_pdf_parse_rows_generic($text);
}
/**
 * RELAXED: super-fallback — łapie najczęstsze układy:
 *  - "...  3 szt.  12,34"
 *  - "...  3 x 12,34"
 *  - "...  qty: 3   12.34"
 *  - dowolny EAN (8–14 cyfr) w linii
 */
function olaj_pdf_parse_rows_relaxed(string $text): array
{
    $rows = [];
    if ($text === '') return $rows;

    $lines = preg_split("/\r\n|\n|\r/u", $text);
    if (!$lines) return $rows;

    foreach ($lines as $ln) {
        $t = trim(preg_replace('/\s{2,}/u', '  ', (string)$ln));
        if ($t === '' || mb_strlen($t) < 5) continue;

        // Szukaj EAN (gdziekolwiek)
        $barcode = null;
        if (preg_match('/\b(\d{8,14})\b/u', $t, $mEAN)) {
            $barcode = $mEAN[1];
        }

        // Ilość: "3 szt", "3szt", "3 x", "qty 3", "ilość 3"
        $qty = null;
        if (preg_match('/\b(\d{1,5})\s*(szt\.?|pcs|op\.?|x)\b/ui', $t, $mQ)) {
            $qty = (int)$mQ[1];
        } elseif (preg_match('/\b(?:qty|ilosc|ilość)\s*[:\-]?\s*(\d{1,5})\b/ui', $t, $mQ2)) {
            $qty = (int)$mQ2[1];
        } elseif (preg_match('/\b(\d{1,5})\s*[x×]\s*(\d+[.,]\d{2})\b/u', $t, $mQ3)) {
            $qty = (int)$mQ3[1];
        }

        // Cena jednostkowa: weź OSTATNIą liczbę z groszami w linii (często to unit/net)
        $unit = null;
        if (preg_match_all('/\b(\d{1,3}(?:[ .]\d{3})*|\d+)[,\.]\d{2}\b/u', $t, $mP) && !empty($mP[0])) {
            $last = end($mP[0]);
            $unit = (float)str_replace([' ', '.', ','], ['', '', '.'], preg_replace('/,(\d{2})$/', '.$1', $last));
        }

        // VAT: "23%" lub "23"
        $vat = null;
        if (preg_match('/\b(\d{1,2})\s*%/u', $t, $mV)) {
            $vat = (float)$mV[1];
        }

        // Nazwa: do pierwszego wzmiankowanego tokenu (ilość/cena), inaczej cała linia
        $name = $t;
        // Przytnij końcówki cen/ilości, żeby nazwa nie była „za długa”
        if ($qty !== null || $unit !== null) {
            // spróbuj odciąć od ostatniej ceny
            if ($unit !== null && preg_match('/^(.*?)(?:\s+\d+[.,]\d{2}[^\d]*)$/u', $t, $mName)) {
                $name = trim($mName[1]);
            } elseif ($qty !== null && preg_match('/^(.*?)(?:\s+\d{1,5}\s*(?:szt\.?|pcs|op\.?|x)\b.*)$/ui', $t, $mName2)) {
                $name = trim($mName2[1]);
            }
        }

        // Minimalne warunki: nazwa + ilość + cena
        if ($name !== '' && $qty !== null && $qty > 0 && $unit !== null) {
            $rows[] = [
                'name'          => $name,
                'qty'           => $qty,
                'unit_net'      => $unit,
                'vat_rate'      => $vat ?? 23.0,
                'barcode'       => $barcode,
                'external_12nc' => null,
                'supplier_sku'  => null,
            ];
        }
    }

    if (function_exists('logg')) {
        logg(empty($rows) ? 'warning' : 'info', 'pdf.parse', 'RELAXED parser', ['rows' => count($rows)]);
    }
    return $rows;
}
