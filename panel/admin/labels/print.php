<?php
// admin/labels/print.php
// Etykieta 32x25 mm (203 dpi). Tekst = kod produktu, barcode = z tego kodu, + cena.
// Tryb 1: SOCKET — wysyłka RAW na IP:9100.
// Tryb 2: DOWNLOAD — zwraca plik .zpl do pobrania.

declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

// 1) KONFIG
const LABEL_WIDTH_MM  = 32;   // szerokość etykiety
const LABEL_HEIGHT_MM = 25;   // wysokość etykiety
const DPI             = 203;  // ZD420 w wersji 203dpi (pod 300dpi przelicz PW/LL)
const PRINTER_MODE    = 'SOCKET'; // 'SOCKET' albo 'DOWNLOAD'
const PRINTER_HOST    = '192.168.1.50'; // IP drukarki / print servera (port RAW)
const PRINTER_PORT    = 9100;

// 2) Pobierz dane (POST lub GET dla wygody testów)
$code  = trim($_POST['product_code'] ?? $_GET['product_code'] ?? '');
$price = trim($_POST['price'] ?? $_GET['price'] ?? '');

if ($code === '' || $price === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Brak product_code lub price']);
    exit;
}

// Walidacje „miękkie”
if (mb_strlen($code) > 40) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Kod produktu za długi (max 40 znaków)']);
    exit;
}
if (!preg_match('/^\d{1,5}([.,]\d{2})?$/', str_replace(' ', '', $price))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Cena w formacie np. 149.90']);
    exit;
}
$price = str_replace(',', '.', $price); // ujednolicenie

// 3) Przeliczenia mm→dots
$pw = (int)round(LABEL_WIDTH_MM  * DPI / 25.4);  // width in dots
$ll = (int)round(LABEL_HEIGHT_MM * DPI / 25.4);  // height in dots

// 4) ZPL dla 32x25 (kompakt — małe marginesy i niski barcode)
$zpl = "^XA\n";
$zpl .= "^CI28\n";                // UTF-8
$zpl .= "^PW{$pw}\n";             // szerokość
$zpl .= "^LL{$ll}\n";             // wysokość
$zpl .= "^LH0,0\n";               // origin

// Tekst kodu (mniejsza czcionka)
$zpl .= "^FO10,10^A0N,24,24^FD" . zplEscape($code) . "^FS\n";

// Barcode (Code128) niski, żeby zmieścił się na 25mm
$zpl .= "^FO10,40^BY2,2,40\n";
$zpl .= "^BCN,40,Y,N,N\n";
$zpl .= "^FD" . zplEscape($code) . "^FS\n";

// Cena
$zpl .= "^FO10,90^A0N,28,28^FD" . zplEscape($price . " PLN") . "^FS\n";

$zpl .= "^XZ\n";

// 5) Wyślij do drukarki albo zwróć plik
if (PRINTER_MODE === 'SOCKET') {
    $errno = 0; $errstr = '';
    $fp = @fsockopen(PRINTER_HOST, PRINTER_PORT, $errno, $errstr, 2.0);
    if (!$fp) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => "Nie mogę połączyć z drukarką ".PRINTER_HOST.":".PRINTER_PORT." ($errno $errstr)"
        ]);
        exit;
    }
    stream_set_timeout($fp, 2);
    $ok = fwrite($fp, $zpl);
    fclose($fp);

    if ($ok === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Wysyłka ZPL nie powiodła się']);
        exit;
    }
    echo json_encode(['success' => true, 'mode' => 'socket', 'sent_bytes' => $ok]);
    exit;
}

// Fallback: pobierz plik .zpl (np. gdy drukujesz z komputera operatora)
$fname = 'label_'.preg_replace('/[^A-Za-z0-9_-]+/','_',$code).'.zpl';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Content-Length: '.strlen($zpl));
echo $zpl;
exit;

// --- helper: bezpieczne wstawianie tekstu do ^FD (ZPL)
function zplEscape(string $s): string {
    // podwójne znaki ^ i \_ (opcjonalnie)
    $s = str_replace(['^','\\'], ['\^','\\\\'], $s);
    // Usuń kontrolne
    return preg_replace('/[\x00-\x1F]/', '', $s) ?? '';
}
