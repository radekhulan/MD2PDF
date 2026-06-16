<?php

declare(strict_types=1);

/**
 * Po composer install/update odstraní z mpdf/mpdf/ttfonts/ všechny TTF soubory,
 * které MD2PDF nepotřebuje. Text jede přes Montserrat, kód přes JetBrains Mono
 * (oboje v ./fonts); z bundlovaných mPDF fontů zůstává jen DejaVu Sans jako
 * fallback na symboly (✓ ✗ ◆ ⚠ …), které Montserrat nemá.
 *
 * Šetří ~85 MB ve vendoru.
 *
 * ⚠️ Whitelist MUSÍ sedět s fontdata / backupSubsFont v md2pdf.php (= jen
 * 'dejavusans'). Kdyby tam přibyl mono fallback 'dejavusansmono', vrať sem i
 * DejaVuSansMono*.
 */

$ttfontsDir = __DIR__ . '/vendor/mpdf/mpdf/ttfonts';
if (!is_dir($ttfontsDir)) {
    fwrite(STDERR, "ttfonts adresář nenalezen: {$ttfontsDir}\n");
    exit(0); // no-op (mpdf nenainstalován)
}

$keep = [
    'DejaVuSans.ttf',
    'DejaVuSans-Bold.ttf',
    'DejaVuSans-Oblique.ttf',
    'DejaVuSans-BoldOblique.ttf',
];

$deleted = 0;
$keptCount = 0;
$bytesFreed = 0;

foreach (glob($ttfontsDir . '/*.ttf') ?: [] as $file) {
    if (in_array(basename($file), $keep, true)) {
        $keptCount++;
        continue;
    }
    $size = filesize($file) ?: 0;
    if (@unlink($file)) {
        $deleted++;
        $bytesFreed += $size;
    }
}

// Smaž i ne-TTF font formáty (mpdf je pro DejaVu nepoužívá).
foreach (['*.otf', '*.pfb', '*.pfm', '*.afm'] as $pattern) {
    foreach (glob($ttfontsDir . '/' . $pattern) ?: [] as $file) {
        $size = filesize($file) ?: 0;
        if (@unlink($file)) {
            $deleted++;
            $bytesFreed += $size;
        }
    }
}

printf(
    "[mpdf-fonts-cleanup] Smazáno %d souborů (%.1f MB), ponecháno %d.\n",
    $deleted,
    $bytesFreed / 1048576,
    $keptCount,
);
