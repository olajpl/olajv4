<?php

declare(strict_types=1);

/**
 * includes/vendor_bootstrap.php — soft, open_basedir-safe
 * - Najpierw próbujemy local Composer: ../vendor/autoload.php
 * - Potem opcjonalnie: third_party/{fpdf,fpdi,smalot_pdfparser}
 * - ZERO fatal errors: brak vendorów ≠ crash (import PDF dalej użyje pdftotext/tesseract).
 */

if (defined('VENDOR_BOOTSTRAP_OK')) return;

$log = function (string $level, string $msg, array $ctx = []) {
  if (function_exists('logg')) {
    logg($level, 'vendor.bootstrap', $msg, $ctx);
  }
};

// 1) Composer (jeśli kiedyś dorzucisz vendor/)
$composerCandidates = [
  __DIR__ . '/../vendor/autoload.php',     // public_html/vendor
  dirname(__DIR__) . '/vendor/autoload.php' // panel.olaj.pl/vendor
];
foreach ($composerCandidates as $autoload) {
  if (is_file($autoload)) {
    require_once $autoload;
    $log('info', 'composer autoload loaded', ['path' => $autoload]);
    define('VENDOR_BOOTSTRAP_OK', true);
    return;
  }
}

// 2) Opcjonalne „vendorless” – tylko jeśli katalog istnieje
$base = realpath(__DIR__ . '/../third_party');
if ($base === false) {
  // brak third_party — to OK, kontynuujemy bez zewnętrznych klas
  $log('warning', 'third_party missing, skipping vendorless libs');
  define('VENDOR_BOOTSTRAP_OK', true);
  return;
}
$path = static fn(string $rel) => $base . '/' . ltrim($rel, '/');

// 2a) FPDF
try {
  if (!class_exists('FPDF', false)) {
    $fpdf = $path('fpdf/fpdf.php');
    if (is_file($fpdf)) {
      require_once $fpdf;
      $log('info', 'FPDF loaded', ['file' => $fpdf]);
    }
  }
} catch (Throwable $e) {
  $log('warning', 'FPDF load error', ['error' => $e->getMessage()]);
}

// 2b) FPDI (ma swój autoloader)
try {
  if (!class_exists(\setasign\Fpdi\Fpdi::class, false)) {
    $fpdiAuto = $path('fpdi/src/autoload.php');
    if (is_file($fpdiAuto)) {
      require_once $fpdiAuto;
      $log('info', 'FPDI autoload loaded', ['file' => $fpdiAuto]);
    }
  }
} catch (Throwable $e) {
  $log('warning', 'FPDI load error', ['error' => $e->getMessage()]);
}

// 2c) Smalot PDF Parser
try {
  if (!class_exists(\Smalot\PdfParser\Parser::class, false)) {
    $smalotAuto = $path('smalot_pdfparser/autoload.php');
    if (is_file($smalotAuto)) {
      require_once $smalotAuto;
      $log('info', 'Smalot autoload loaded', ['file' => $smalotAuto]);
    } else {
      // Minimalny PSR-4 autoloader jeśli brak autoload.php
      spl_autoload_register(static function (string $class) use ($base) {
        $prefix = 'Smalot\\PdfParser\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $rel = substr($class, strlen($prefix));
        $file = $base . '/smalot_pdfparser/src/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) require $file;
      }, true, true);
      // Nie wymuszamy istnienia klasy – parser PDF i tak ma fallbacki.
    }
  }
} catch (Throwable $e) {
  $log('warning', 'Smalot load error', ['error' => $e->getMessage()]);
}

define('VENDOR_BOOTSTRAP_OK', true);
