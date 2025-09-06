<?php
// tools/scan_includes.php — Olaj V4
// Skanuje repo w poszukiwaniu require/include do includes/* i raportuje/wg opcji poprawia ścieżki.
// Użycie:
//   php tools/scan_includes.php
//   php tools/scan_includes.php --fix=abs
//   php tools/scan_includes.php --root=C:\xampp\htdocs
//
// Ignoruje: .git, vendor, node_modules, uploads, storage, cache, temp, tmp

declare(strict_types=1);

$argvMap = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $argvMap[$m[1]] = $m[2];
}
$ROOT = rtrim($argvMap['root'] ?? getcwd(), DIRECTORY_SEPARATOR);
$FIX  = $argvMap['fix']  ?? null; // 'abs' lub null

$ignoreDirs = [
    '.git','vendor','node_modules','uploads','storage','cache','temp','tmp','.idea','.vscode','public/build'
];
$exts = ['php','phtml','inc'];

$patterns = [
    // require/include... '.../includes/xxx.php' z dowolną głębokością ../
    '~\b(require|require_once|include|include_once)\s*\(\s*[\'"](?P<rel>(?:\.\./)+includes/(?P<file>[^\'"]+))[\'"]\s*\)\s*;~i',
    // oraz wariant bez ../ na początku (np. 'includes/db.php' w plikach z root)
    '~\b(require|require_once|include|include_once)\s*\(\s*[\'"](?P<rel>includes/(?P<file>[^\'"]+))[\'"]\s*\)\s*;~i',
];

$found = [];
$totalFiles = 0;

$iter = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
        function ($file, $key, $iter) use ($ignoreDirs) {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $ignoreDirs, true);
            }
            return true;
        }
    )
);

foreach ($iter as $fileInfo) {
    $path = $fileInfo->getPathname();
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) continue;
    $totalFiles++;

    $code = @file_get_contents($path);
    if ($code === false) continue;

    $lines = preg_split("/\r\n|\n|\r/", $code);
    $modified = false;

    foreach ($patterns as $rx) {
        if (!preg_match_all($rx, $code, $m, PREG_OFFSET_CAPTURE)) continue;

        foreach ($m[0] as $idx => $fullMatch) {
            // Wyciągamy względną ścieżkę 'rel', np. ../../includes/db.php
            $rel = $m['rel'][$idx][0];
            $incFile = $m['file'][$idx][0];

            // Numer linii:
            $pos = $fullMatch[1];
            $pre = substr($code, 0, $pos);
            $lineNo = substr_count($pre, "\n") + 1;

            // Wylicz ile trzeba cofnąć katalogów, by dojść do $ROOT
            $fileDir = dirname($path);
            $absTarget = $ROOT . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $incFile);

            // Policz wspólny prefix, aby policzyć dirname(__DIR__, N)
            $relDepth = getDirDepth($fileDir, $ROOT);

            // Zbuduj replacement (absolutny względem root):
            $replacement = "require_once " . buildDirnameExpr($relDepth) . " . '/includes/" . $incFile . "';";
            // Rekonstruuj dokładny replacement tak, żeby zachować oryginalny typ (require vs include + _once)
            $kind = detectKindFromMatch($fullMatch[0]);

            $replacement = $kind . ' ' . buildDirnameExpr($relDepth) . " . '/includes/" . $incFile . "';";

            $found[] = [
                'file' => $path,
                'line' => $lineNo,
                'rel'  => $rel,
                'inc'  => $incFile,
                'depth'=> $relDepth,
                'suggest' => $replacement,
            ];

            if ($FIX === 'abs') {
                // Zamieniamy pojedyncze wystąpienie w danej linii – bez ryzyka psucia innych
                $lines[$lineNo - 1] = preg_replace(
                    $rx,
                    $kind . ' ' . buildDirnameExpr($relDepth) . " . '/includes/" . $incFile . "';",
                    $lines[$lineNo - 1],
                    1
                );
                $modified = true;
            }
        }
    }

    if ($modified && $FIX === 'abs') {
        $newCode = implode(PHP_EOL, $lines);
        if ($newCode !== $code) {
            file_put_contents($path, $newCode);
        }
    }
}

// Raport
echo "== Olaj V4 include-scanner ==\n";
echo "Root: $ROOT\n";
echo "Plików przejrzanych: $totalFiles\n";
echo "Znalezionych wpisów: " . count($found) . "\n\n";

foreach ($found as $hit) {
    echo "- {$hit['file']}:{$hit['line']}\n";
    echo "    rel: {$hit['rel']}\n";
    echo "    ->  {$hit['suggest']}\n";
}

if ($FIX === 'abs') {
    echo "\n[OK] Zmiany zapisane. Zrób commit:\n";
    echo "    git add -A && git commit -m \"refactor: absolutne require do /includes\"\n";
}

function getDirDepth(string $from, string $root): int {
    $from = realpath($from) ?: $from;
    $root = realpath($root) ?: $root;

    $from = str_replace('\\','/',$from);
    $root = str_replace('\\','/',$root);

    // Ile poziomów w górę trzeba iść z $from do $root
    if (strpos($from, $root) === 0) {
        $rest = trim(substr($from, strlen($root)), '/');
        if ($rest === '') return 1; // z pliku w root → dirname(__DIR__, 1)
        return substr_count($rest, '/') + 2; // +1 za wejście w katalog, +1 żeby dojść powyżej aktualnego pliku
    }
    // Jeśli nie ma wspólnego — konserwatywnie 3
    return 3;
}

function buildDirnameExpr(int $depth): string {
    if ($depth <= 1) return "__DIR__";
    return "\\dirname(__DIR__, $depth)";
}

function detectKindFromMatch(string $match): string {
    if (stripos($match, 'require_once') !== false) return 'require_once';
    if (stripos($match, 'include_once') !== false) return 'include_once';
    if (stripos($match, 'require') !== false) return 'require';
    return 'include';
}
php tools/scan_includes.php