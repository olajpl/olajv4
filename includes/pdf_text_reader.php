<?php
// includes/pdf_text_reader.php
// 1. opis czynności lub funkcji
// Czytanie tekstu PDF czystym PHP (Smalot\PdfParser). Bez pdftotext/tesseract.

require_once __DIR__ . '/vendor_bootstrap.php';
require_once __DIR__ . '/log.php'; // jeśli masz logg()

function pdf_read_text_via_library(string $pdf_path): string {
  try {
    if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
      if (function_exists('logg')) logg('warning','pdf.read','Smalot\\PdfParser not loaded',[]);
      return '';
    }
    $parser = new \Smalot\PdfParser\Parser();
    $pdf    = $parser->parseFile($pdf_path);
    $text   = (string) $pdf->getText();
    $text   = str_replace("\x00", '', $text);
    if (function_exists('logg')) logg('info','pdf.read','Smalot OK',['len'=>strlen($text)]);
    return $text;
  } catch (Throwable $e) {
    if (function_exists('logg')) logg('error','pdf.read','Exception',['err'=>$e->getMessage()]);
    return '';
  }
}
