<?php
// Trzymaj to poza webrootem, repo lub zaszyfruj w managerze haseł.
// Jeśli plik już istnieje – zostaw jak jest, nie duplikuj definicji.
if (!defined('LIVE_INGEST_HMAC_SECRET')) {
  define('LIVE_INGEST_HMAC_SECRET', 'TwojMegaSekretnyKlucz123'); // ← PODMIEŃ
}
