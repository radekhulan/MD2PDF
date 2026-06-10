<?php
/**
 * md2pdf.php — Markdown → PDF export pipeline (MD2PDF)
 * ====================================================
 *
 * Převede VŠECHNY soubory odpovídající vzoru `glob` (z configu) na samostatná,
 * profesionálně vysázená PDF (jeden PDF na dokument, stejný basename).
 *
 * Vlastní řádkový markdown konvertor + mPDF; nabízí:
 *   - běh nad glob vzorem z configu (názvy se NEhardkódují),
 *   - titulní blok (titul z H1, autor, firma, verze + datum z úvodního blockquote),
 *   - hlavičku/patičku na každé stránce (texty z configu),
 *   - callout boxy (blockquote ⚠️) se zachováním vnitřních seznamů/nadpisů,
 *   - render mermaid diagramů přes mermaid-cli (mmdc) do PNG,
 *   - auto-zmenšení písma širokých code-bloků (ASCII diagramy) a tabulek,
 *   - kompletní náhradu emoji dle skutečného pokrytí záložního fontu DejaVu.
 *
 * Zdrojové .md jsou STRIKTNĚ READ-ONLY — nikdy se nemodifikují.
 *
 * Použití:
 *   php tools/md2pdf.php                  # převede všechny dle 'glob' z configu
 *   php tools/md2pdf.php Nazev            # převede jen jeden (basename, .md volitelné)
 *   php tools/md2pdf.php --config=jiny.php   # použij jinou konfiguraci
 *   php tools/md2pdf.php --print-config   # vypiš JSON {source_dir,output_dir,glob}
 *
 * Konstanty/texty specifické pro projekt (cesty, glob, identita, překlad,
 * logo) jsou v `md2pdf.config.php` vedle skriptu — pro jiný projekt se
 * upravuje JEN ten soubor.
 *
 * Exit kód: 0 = vše OK, 1 = chyba.
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');

require __DIR__ . '/vendor/autoload.php';

$TOOLS_DIR = __DIR__;

// ---- Argumenty: --config=PATH, --print-config, zbytek = filtr basename ----
$argsIn      = array_slice($argv, 1);
$configPath  = null;
$printConfig = false;
$positional  = [];
foreach ($argsIn as $a) {
    if (preg_match('/^--config=(.+)$/', $a, $m)) {
        $configPath = $m[1];
    } elseif ($a === '--print-config') {
        $printConfig = true;
    } else {
        $positional[] = $a;
    }
}

// ---- Načti konfiguraci: --config=  >  env MD2PDF_CONFIG  >  vedle skriptu ----
if ($configPath === null) {
    $configPath = getenv('MD2PDF_CONFIG') ?: (__DIR__ . DIRECTORY_SEPARATOR . 'md2pdf.config.php');
}
if (!is_file($configPath)) {
    fwrite(STDERR, "Konfigurační soubor nenalezen: {$configPath}\n");
    exit(1);
}
$CFG = require $configPath;
if (!is_array($CFG)) {
    fwrite(STDERR, "Konfigurace musí vracet pole (return [ ... ];): {$configPath}\n");
    exit(1);
}

// ---- Odvozené globální hodnoty -------------------------------------------
$SRC_DIR = realpath($CFG['source_dir'] ?? '');
if ($SRC_DIR === false) {
    fwrite(STDERR, "Zdrojový adresář neexistuje: " . ($CFG['source_dir'] ?? '(nezadán)') . "\n");
    exit(1);
}
$OUT_DIR = $CFG['output_dir'] ?: ($SRC_DIR . DIRECTORY_SEPARATOR . 'pdf');
$GLOB    = $CFG['glob']    ?? '*.md';
$AUTHOR  = $CFG['author']  ?? '';
$COMPANY = $CFG['company'] ?? '';
$BRAND   = $CFG['brand']   ?? '';

// --print-config: vypiš cesty pro externí runner (export-pdf.ps1) a skonči
if ($printConfig) {
    echo json_encode([
        'source_dir' => $SRC_DIR,
        'output_dir' => $OUT_DIR,
        'glob'       => $GLOB,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

@mkdir($OUT_DIR, 0775, true);

// =====================================================================
//  Inline markdown (bold, italic, code, links) → HTML
// =====================================================================
function mdInline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 1) `code` spany do placeholderů (aby další regex nesahaly dovnitř)
    $codeStore = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeStore) {
        $i = count($codeStore);
        $codeStore[] = $m[1];
        return "\x01C{$i}\x02";
    }, $s);

    // 2) **bold** , 3) *italic* , 4) _italic_
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\w)/', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/', '<em>$1</em>', $s);

    // 5) [text](url) — externí odkaz jako stylovaný text (bez rozbitých URL)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $text = $m[1];
        $href = trim($m[2]);
        // .md interní odkazy → jen text (žádné rozbité odkazy v PDF)
        if (preg_match('~\.md(#.*)?$~i', $href)) {
            return '<span class="xref">' . $text . '</span>';
        }
        if (preg_match('~^https?://~i', $href)) {
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
        }
        return '<span class="xref">' . $text . '</span>';
    }, $s);

    // 6) vrať code spany
    $s = preg_replace_callback('/\x01C(\d+)\x02/', function ($m) use ($codeStore) {
        return '<code>' . $codeStore[(int) $m[1]] . '</code>';
    }, $s);

    return $s;
}

// =====================================================================
//  Slug pro nadpisové anchory
// =====================================================================
function mdSlug(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) {
            $s = $tr;
        }
    }
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s_]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

// =====================================================================
//  Markdown → HTML (řádkový parser; spojuje hard-wrap řádky do odstavců)
//
//  Vrací: ['html' => string, 'toc' => array, 'maxCodeWidth' => int]
// =====================================================================
function mdToHtml(string $md): array
{
    global $CFG;
    $warnKw = $CFG['warn_keywords'] ?? ['⚠', 'POZOR', 'Upozorn', 'Pozor', 'Varov'];
    $warnRe = '/(' . implode('|', array_map(fn ($k) => preg_quote($k, '/'), $warnKw)) . ')/u';

    $lines = preg_split('/\r\n|\r|\n/', $md);

    $html         = '';
    $toc          = [];
    $maxCodeWidth = 0;

    $paragraph   = [];
    $listStack   = [];           // [['indent'=>int,'type'=>'ul'|'ol'], ...]
    $pendingLi   = false;
    $bqLines     = [];           // surové řádky uvnitř blockquote (callout)
    $inBq        = false;
    $tableRows   = [];
    $tableAligns = [];
    $inTable     = false;
    $inCode      = false;
    $codeFenceLen = 0;        // délka otevíracího plotu (``` vs ````) — CommonMark nesting
    $codeLines   = [];

    $headingCounter = 0;
    $firstH1Done    = false;
    $firstH2Done    = false;
    $chapterBreak   = $CFG['chapter_page_break'] ?? true;  // zlom před každou kapitolou (H1/H2)

    // ---- flush helpery -------------------------------------------------
    $flushPara = function () use (&$paragraph, &$html) {
        if (!$paragraph) { return; }
        $text = implode(' ', $paragraph);
        // Popisek obrázku: samostatný kurzívový řádek hned za <figure> → vycentrovat.
        $afterFig = (substr(rtrim($html), -11) === '<!--/fig-->');
        $t = trim($text);
        $isCaption = $afterFig && count($paragraph) === 1
            && (preg_match('/^\*[^*]+\*$/u', $t) || preg_match('/^_[^_]+_$/u', $t));
        $html .= ($isCaption ? '<p class="fig-cap">' : '<p>') . mdInline($text) . "</p>\n";
        $paragraph = [];
    };

    $emitLi = function (int $indent, string $type, string $text) use (&$listStack, &$html, &$pendingLi) {
        while ($listStack && end($listStack)['indent'] > $indent) {
            if ($pendingLi) { $html .= "</li>\n"; $pendingLi = false; }
            $top = array_pop($listStack);
            $html .= "</{$top['type']}>\n";
            if ($listStack) { $pendingLi = true; }
        }
        $top = $listStack ? end($listStack) : null;
        if ($top && $top['indent'] === $indent) {
            if ($top['type'] !== $type) {
                if ($pendingLi) { $html .= "</li>\n"; }
                array_pop($listStack);
                $html .= "</{$top['type']}>\n<{$type}>\n";
                $listStack[] = ['indent' => $indent, 'type' => $type];
            } else {
                if ($pendingLi) { $html .= "</li>\n"; }
            }
        } else {
            $html .= "<{$type}>\n";
            $listStack[] = ['indent' => $indent, 'type' => $type];
        }
        // checkbox list:  - [ ] / - [x]
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $cm)) {
            $box = (strtolower($cm[1]) === 'x') ? '&#9745;' : '&#9744;'; // ☑ / ☐
            $html .= '  <li class="task"><span class="cb">' . $box . '</span> ' . mdInline($cm[2]);
        } else {
            $html .= '  <li>' . mdInline($text);
        }
        $pendingLi = true;
    };

    $flushList = function () use (&$listStack, &$html, &$pendingLi) {
        while ($listStack) {
            if ($pendingLi) { $html .= "</li>\n"; $pendingLi = false; }
            $top = array_pop($listStack);
            $html .= "</{$top['type']}>\n";
            $pendingLi = $listStack ? true : false;
        }
        $pendingLi = false;
    };

    // Callout box: blockquote může obsahovat ### nadpis, seznam, odstavce.
    // Rekurzivně se zpracuje vnitřek (bez další úrovně blockquote).
    $flushBq = function () use (&$inBq, &$bqLines, &$html, &$toc, $warnRe) {
        if (!$inBq) { return; }
        $inner = implode("\n", $bqLines);
        // detekce „varovného" callout boxu (klíčová slova z configu)
        $isWarn = (bool) preg_match($warnRe, $inner);
        $cls = $isWarn ? 'callout callout-warn' : 'callout';
        // rekurzivní render vnitřku (nadpisy uvnitř callout NEjdou do TOC)
        $sub = mdToHtml($inner);
        $html .= '<div class="' . $cls . '">' . "\n" . $sub['html'] . "</div>\n";
        $inBq = false;
        $bqLines = [];
    };

    $flushTable = function () use (&$inTable, &$tableRows, &$tableAligns, &$html) {
        if (!$inTable || count($tableRows) < 1) {
            $inTable = false; $tableRows = []; $tableAligns = [];
            return;
        }
        $header = $tableRows[0];
        $body   = array_slice($tableRows, 1);
        $cols   = count($header);
        $html  .= "<table class=\"md-tab\">\n<thead><tr>";
        foreach ($header as $i => $cell) {
            $a = $tableAligns[$i] ?? 'left';
            $html .= '<th style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";
        foreach ($body as $row) {
            $html .= '<tr>';
            for ($i = 0; $i < $cols; $i++) {
                $cell = $row[$i] ?? '';
                $a = $tableAligns[$i] ?? 'left';
                $html .= '<td style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody></table>\n";
        $inTable = false; $tableRows = []; $tableAligns = [];
    };

    // ---- hlavní smyčka -------------------------------------------------
    foreach ($lines as $line) {
        // Fenced code block — s podporou VNOŘENÝCH plotů dle CommonMark:
        // ````vnejsi blok smí obsahovat ``` vnitřní; zavírá jen plot s >= délkou
        // otevíracího a bez dalšího obsahu na řádku.
        if (preg_match('/^\s*(`{3,})(.*)$/', $line, $fm)) {
            $fenceLen = strlen($fm[1]);
            if (!$inCode) {
                $flushPara(); $flushList(); $flushBq(); $flushTable();
                $inCode = true; $codeFenceLen = $fenceLen; $codeLines = [];
                continue;
            }
            if ($fenceLen >= $codeFenceLen && trim($fm[2]) === '') {
                $inCode = false;
                foreach ($codeLines as $cl) {
                    $w = mb_strlen($cl, 'UTF-8');
                    if ($w > $maxCodeWidth) { $maxCodeWidth = $w; }
                }
                // POZOR: žádný vnořený <code> — mPDF neumí font-family:inherit a obsah
                // <code> by spadl do výchozího proporcionálního fontu (rozbité diagramy).
                $code = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html .= "<pre class=\"code-block\">{$code}</pre>\n";
                $codeLines = [];
                continue;
            }
            // kratší/nečistý plot uvnitř bloku = obsah (vnořený markdown příklad)
            $codeLines[] = $line;
            continue;
        }
        if ($inCode) { $codeLines[] = $line; continue; }

        $trim = trim($line);

        // Prázdný řádek → flush odstavce/listu/tabulky (blockquote se sbírá dál
        // jen pokud následuje další '>' – jinak se uzavře)
        if ($trim === '') {
            $flushPara();
            $flushList();
            $flushTable();
            // blockquote NEflushovat na prázdném řádku UVNITŘ? V těchto docech
            // jsou callouty souvislé; prázdný řádek callout ukončí.
            $flushBq();
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', $trim) || preg_match('/^\*\*\*+$/', $trim)) {
            $flushPara(); $flushList(); $flushBq(); $flushTable();
            $html .= "<hr />\n";
            continue;
        }

        // Image ![alt](src)
        if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $trim, $m)) {
            $flushPara(); $flushList(); $flushBq(); $flushTable();
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $src = $m[2];
            global $SRC_DIR;
            if (!preg_match('~^([a-z]+:|/)~i', $src)) {
                $src = $SRC_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $src);
            }
            $srcH = htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= '<div class="fig"><img src="' . $srcH . '" alt="' . $alt . '" />';
            if ($alt !== '') { $html .= '<div class="fig-caption">' . $alt . '</div>'; }
            // marker pro flushPara: následující samostatný kurzívový řádek = popisek (vycentruje se)
            $html .= "</div><!--/fig-->\n";
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $flushPara(); $flushList(); $flushBq(); $flushTable();
            $level = strlen($m[1]);
            $raw   = rtrim($m[2]);
            $text  = mdInline($raw);
            $id    = mdSlug($raw);
            $cls   = '';
            // H1 začíná novou stranou — kromě prvního H1 (ten je hned za titulním blokem)
            if ($level === 1) {
                if ($firstH1Done && $chapterBreak) { $cls = ' class="pb"'; }
                $firstH1Done = true;
            }
            // Každá kapitola (H2) začíná na nové stránce — kromě první (tělo už začíná
            // na čerstvé stránce za obsahem; break by nechal skoro prázdnou stranu jen
            // s úvodním metablokem). mPDF break neaplikuje, je-li už na začátku stránky,
            // takže nevznikají prázdné stránky ani po H1 zlomu.
            if ($level === 2) {
                if ($firstH2Done && $chapterBreak) { $cls = ' class="pb"'; }
                $firstH2Done = true;
            }
            $headingCounter++;
            $idAttr = $id !== '' ? ' id="' . $id . '"' : '';
            $html  .= "<h{$level}{$idAttr}{$cls}>{$text}</h{$level}>\n";
            if ($level === 2 || $level === 3) {
                $toc[] = ['level' => $level, 'text' => $raw, 'slug' => $id];
            }
            continue;
        }

        // Blockquote (sbírá surové vnitřní řádky vč. '> ###', '> 1.', '> -')
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara(); $flushList(); $flushTable();
            $inBq = true;
            $bqLines[] = $m[1];
            continue;
        }

        // GFM tabulka
        if (strpos($trim, '|') !== false && substr_count($trim, '|') >= 1) {
            $stripped = preg_replace('/^\||\|$/', '', $trim);
            $cells    = array_map('trim', explode('|', $stripped));
            $isSep    = (count($cells) > 0) && !array_filter($cells, function ($c) {
                return !preg_match('/^:?-{2,}:?$/', $c);
            });
            if ($isSep && count($tableRows) >= 1) {
                $tableAligns = [];
                foreach ($cells as $c) {
                    if (preg_match('/^:.*:$/', $c))      { $tableAligns[] = 'center'; }
                    elseif (preg_match('/^.*:$/', $c))   { $tableAligns[] = 'right'; }
                    else                                 { $tableAligns[] = 'left'; }
                }
                $inTable = true;
                continue;
            }
            $flushPara(); $flushList();
            $tableRows[] = $cells;
            $inTable = true;
            continue;
        } else {
            if ($inTable) { $flushTable(); }
        }

        // Unordered / nested list
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m)) {
            $flushPara();
            $emitLi(strlen($m[1]), 'ul', $m[2]);
            continue;
        }

        // Ordered list (i „15a." styl) + nested
        if (preg_match('/^(\s*)\d+[a-z]?[.)]\s+(.*)$/', $line, $m)) {
            $flushPara();
            $emitLi(strlen($m[1]), 'ol', $m[2]);
            continue;
        }

        // Pokračování list-item (indentovaný text bez bulletu)
        if ($listStack && $pendingLi && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) {
            $html .= ' ' . mdInline(trim($m[1]));
            continue;
        }

        // Běžný odstavec
        $flushList();
        // Řádek začínající bold LABELEM s dvojtečkou (**Auth:** / **J1 — …:** apod.)
        // = nová logická řádka → samostatný odstavec (jinak by se skupiny slily).
        // POZOR: nesmí chytat běžné pokračování věty, které po hard-wrapu náhodou
        // začíná tučným textem (`**s odkazem na něco** …`) — proto se vyžaduje
        // dvojtečka těsně před uzavíracími hvězdičkami.
        // Druhý labelový vzor: `**E7 Chat** — popis…` (bold + pomlčka) — typicky
        // definiční seznamy (akceptační kritéria). Běžné věty takto nezačínají.
        if ($paragraph && preg_match('/^\*\*[^*]+(?::\*\*|\*\*\s+—)/u', $trim)) {
            $flushPara();
        }
        $paragraph[] = $trim;
    }

    $flushPara(); $flushList(); $flushBq(); $flushTable();

    return ['html' => $html, 'toc' => $toc, 'maxCodeWidth' => $maxCodeWidth];
}

// =====================================================================
//  Emoji / symboly → náhrady (dle skutečného pokrytí DejaVu)
//  Ponecháno (font je má): → ← ↔ ★ ✓ ✗ ✚ ⚠ ≈ − ≥ ≤ ∩ a box-drawing.
//  Nahrazeno (font NEmá – SMP/emoji plane): ✅ ❌ 🔶 🔷 ⭐ ️(VS16)
// =====================================================================
function applyGlyphSubstitutions(string $html): string
{
    global $CFG;
    $tip  = htmlspecialchars($CFG['strings']['label_tip']  ?? 'TIP:',  ENT_QUOTES, 'UTF-8');
    $note = htmlspecialchars($CFG['strings']['label_note'] ?? 'POZN:', ENT_QUOTES, 'UTF-8');
    $map = [
        "\u{2705}" => '<span class="g-ok">&#10003;</span>',   // ✅ → ✓ (zeleně)
        "\u{274C}" => '<span class="g-no">&#10007;</span>',   // ❌ → ✗ (červeně)
        "\u{2B50}" => '&#9733;',                              // ⭐ → ★
        "\u{1F536}" => '<span class="g-ph2">&#9670;</span>',  // 🔶 → ◆ (oranžově)
        "\u{1F537}" => '<span class="g-ph3">&#9670;</span>',  // 🔷 → ◆ (modře)
        "\u{FE0F}" => '',                                      // VS-16 — odstranit
        "\u{FE0E}" => '',                                      // VS-15 — odstranit
        // varovné/info štítky (kdyby se vyskytly mimo callout)
        "\u{1F4A1}" => '<strong class="lbl">' . $tip . '</strong>',   // 💡
        "\u{2139}"  => '<strong class="lbl">' . $note . '</strong>',  // ℹ
    ];
    $html = strtr($html, $map);

    // Pojistka: jakýkoli zbylý znak z emoji plane (1F000+) nebo dingbats,
    // který DejaVu nemá, odstraníme — kromě explicitně povolených.
    $keep = ['→','←','↔','↑','↓','★','☆','✓','✗','✚','⚠','≈','−','≥','≤','∩',
             '◆','◇','■','□','●','○','►','◄','▼','▲','▶','◀','·','–','—','…',
             '☑','☐'];
    $keepSet = array_flip($keep);
    $html = preg_replace_callback(
        '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}]/u',
        function ($m) use ($keepSet) {
            return isset($keepSet[$m[0]]) ? $m[0] : '';
        },
        $html
    );
    return $html;
}

// =====================================================================
//  Parse úvodního „> **Verze:** X · **Datum:** Y · **Autor:** Z" bloku
// =====================================================================
function parseMeta(string $md): array
{
    global $CFG;
    $L    = $CFG['source_meta_labels'] ?? [];
    $lVer = preg_quote($L['version'] ?? 'Verze', '/');
    $lDat = preg_quote($L['date']    ?? 'Datum', '/');
    $lAut = preg_quote($L['author']  ?? 'Autor', '/');
    $lUce = preg_quote($L['purpose'] ?? 'Účel',  '/');

    $meta = ['version' => null, 'date' => null, 'docAuthor' => null, 'purpose' => null];
    // sloučit prvních ~12 řádků blockquote
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $bq = [];
    foreach ($lines as $l) {
        if (preg_match('/^>\s?(.*)$/', $l, $m)) {
            $bq[] = $m[1];
        } elseif ($bq) {
            // první ne-blockquote řádek po startu metadat ukončí čtení záhlaví,
            // ale jen pokud už máme verzi
            if (isset($meta['_started'])) break;
        }
        if (count($bq) > 14) break;
    }
    $head = implode("\n", $bq);

    if (preg_match('/\*\*' . $lVer . ':\*\*\s*([^·\n]+?)(?:\s*·|\n|$)/u', $head, $m)) {
        $meta['version'] = trim($m[1]);
    }
    if (preg_match('/\*\*' . $lDat . ':\*\*\s*([^·\n]+?)(?:\s*·|\n|$)/u', $head, $m)) {
        $meta['date'] = trim($m[1]);
    }
    if (preg_match('/\*\*' . $lAut . ':\*\*\s*([^·\n]+?)(?:\s*·|\n|$)/u', $head, $m)) {
        $meta['docAuthor'] = trim($m[1]);
    }
    // Účel: vícerádkový — sbírej pokračovací řádky, dokud věta nekončí
    // tečkou/!/? nebo nenarazíme na další **Label:** řádek (max 4 řádky).
    $bqN = count($bq);
    for ($k = 0; $k < $bqN; $k++) {
        if (preg_match('/\*\*' . $lUce . ':\*\*\s*(.*)$/u', $bq[$k], $m)) {
            $parts = [trim($m[1])];
            for ($j = $k + 1; $j < $bqN && count($parts) < 4; $j++) {
                $nl = trim($bq[$j]);
                if ($nl === '' || preg_match('/^\*\*[^*]+:\*\*/u', $nl)) { break; }
                // už máme ukončenou větu? pak nepokračuj
                $acc = implode(' ', $parts);
                if (preg_match('/[.!?]\**\s*$/u', $acc)) { break; }
                $parts[] = $nl;
            }
            $meta['purpose'] = trim(implode(' ', $parts));
            break;
        }
    }
    return $meta;
}

// =====================================================================
//  Vyřízni úvodní H1 a meta-blockquote z těla (jdou do titulního bloku)
//  Vrací: ['title' => string, 'body' => string-bez-H1-a-meta]
// =====================================================================
function splitTitle(string $md): array
{
    global $CFG;
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $title = null;
    $out   = [];
    $i = 0;
    $n = count($lines);

    // 1) první neprázdný řádek = H1
    while ($i < $n && trim($lines[$i]) === '') { $i++; }
    if ($i < $n && preg_match('/^#\s+(.*)$/', trim($lines[$i]), $m)) {
        $title = trim($m[1]);
        $i++;
    }
    // 2) přeskoč prázdné
    while ($i < $n && trim($lines[$i]) === '') { $i++; }
    // 3) přeskoč úvodní meta-blockquote (souvislé '>' řádky)
    //    Režim 'keep' (config) blockquote NEodřízne — zůstane v těle jako callout
    //    (pro dokumenty, kde úvodní blockquote není metablok, ale obsah).
    if (($CFG['lead_blockquote'] ?? 'meta') !== 'keep'
        && $i < $n && preg_match('/^>\s?/', trim($lines[$i]))) {
        while ($i < $n && (preg_match('/^>\s?/', $lines[$i]) || trim($lines[$i]) === '')) {
            // ukonči, pokud po prázdném řádku NEnásleduje další '>'
            if (trim($lines[$i]) === '') {
                // koukni dopředu
                $j = $i + 1;
                while ($j < $n && trim($lines[$j]) === '') { $j++; }
                if ($j >= $n || !preg_match('/^>\s?/', $lines[$j])) { $i++; break; }
            }
            $i++;
        }
    }
    // zbytek = tělo
    for (; $i < $n; $i++) { $out[] = $lines[$i]; }

    $default = $CFG['strings']['default_title'] ?? 'Dokument';
    return ['title' => $title ?? $default, 'body' => implode("\n", $out)];
}

// =====================================================================
//  CSS — fialový (purple) branding
//  Paleta: #4c1d95 primární · #5b21b6/#6d28d9 odstíny · #6c5ce7 akcent ·
//          #c4b5fd/#e9d5ff světlé · #f3f0ff podklady
// =====================================================================
function buildCss(float $tableFontPt, float $codeFontPt): string
{
    $tf = number_format($tableFontPt, 1);
    $cf = number_format($codeFontPt, 1);
    return <<<CSS
body {
  font-family: "sourcesans", "dejavusans", sans-serif;
  font-size: 10.9pt;
  color: #1f2937;
  line-height: 1.55;
}

/* ---------- Titulní strana (fialový blok přes celou stranu) ---------- */
.cover { page-break-after: always; }
.cover-band {
  background: #4c1d95;
  color: #ffffff;
  padding: 18mm 16mm 14mm 16mm;
  height: 250mm;
}
.cover-rule { border-top: 4px solid #ede9fe; margin-bottom: 12mm; width: 100%; }
.cover-logo { margin-bottom: 12mm; }
.logo-mark {
  display: inline-block; background: #ffffff; color: #4c1d95;
  font-weight: 700; font-size: 22pt; line-height: 1;
  padding: 4mm 4.6mm; border-radius: 3mm;
}
.cover-eyebrow {
  font-size: 10pt; letter-spacing: 4px; text-transform: uppercase;
  color: #c4b5fd; margin-bottom: 7mm;
}
.cover-title {
  font-size: 29pt; font-weight: 700; line-height: 1.14;
  color: #ffffff; margin: 0 0 9mm 0; padding: 0; border: none;
}
.cover-purpose { font-size: 12pt; color: #e9d5ff; line-height: 1.5; margin-bottom: 16mm; }
/* bold/code na fialovém podkladu musí být bílé (globální strong je fialový) */
.cover-band strong { color: #ffffff; }
.cover-band code { background: transparent; border: none; color: #ffffff; padding: 0; }
.cover-meta { font-size: 9.5pt; color: #e9d5ff; width: 100%; border-collapse: collapse; }
.cover-meta td { padding: 6pt 0; border-top: 0.5pt solid #8b6fd0; vertical-align: middle; }
.cover-meta tr.last td { border-bottom: 0.5pt solid #8b6fd0; }
.cover-meta-label {
  width: 44mm; color: #c4b5fd; letter-spacing: 2px; text-transform: uppercase;
  font-size: 8.2pt; font-weight: 700;
}
.cover-meta-value { padding-left: 6mm; color: #ffffff; }

/* ---------- Obsah (TOC) ---------- */
.toc { page-break-after: always; }
.toc-title {
  color: #4c1d95; font-size: 21pt; font-weight: 700;
  margin: 6mm 0 7mm 0; padding-bottom: 3mm; border-bottom: 2px solid #6c5ce7;
}
.toc-h2 { margin: 2.6mm 0; font-size: 11pt; }
.toc-h2 a { color: #1f2937; text-decoration: none; }
.toc-num { color: #6c5ce7; font-weight: 700; }

/* ---------- Nadpisy ---------- */
h1, h2, h3, h4, h5, h6 {
  color: #4c1d95; font-weight: 700; line-height: 1.25; page-break-after: avoid;
}
h1 {
  font-size: 20pt; margin: 7mm 0 4.5mm 0; padding-bottom: 2.5mm;
  border-bottom: 2px solid #6c5ce7;
}
h1.pb, h2.pb { page-break-before: always; }
h2 {
  font-size: 15pt; margin: 8mm 0 3mm 0; padding-bottom: 1.5mm;
  border-bottom: 0.6pt solid #d1d5db;
}
h3 { font-size: 12.6pt; margin: 6mm 0 2.2mm 0; color: #5b21b6; }
h4 { font-size: 11.3pt; margin: 4.5mm 0 1.8mm 0; color: #6d28d9; }
h5, h6 { font-size: 10.9pt; margin: 4mm 0 1.5mm 0; color: #6d28d9; }

/* ---------- Text ---------- */
p { margin: 0 0 2.8mm 0; text-align: justify; }
ul, ol { margin: 1mm 0 3.2mm 0; padding-left: 6mm; }
li { margin-bottom: 1.4mm; line-height: 1.5; }
li.task { list-style: none; margin-left: -4mm; }
li.task .cb { color: #5b21b6; font-size: 11.5pt; }

strong { color: #4c1d95; }
em { font-style: italic; }

code {
  font-family: "cascadiamono", "dejavusansmono", monospace; font-size: 9.2pt;
  background: #f3f0ff; border: 0.4pt solid #e0d7fa; border-radius: 2pt;
  padding: 0 3pt; color: #5b21b6;
}

a { color: #6c5ce7; text-decoration: underline; }
.xref { color: #5b21b6; font-style: italic; }

/* ---------- Callout / blockquote ---------- */
.callout {
  margin: 3.5mm 0; padding: 3mm 5mm 1.5mm 5mm;
  background: #f3f0ff; border-left: 3pt solid #6c5ce7; border-radius: 2pt;
  page-break-inside: avoid;
}
.callout p { margin: 0 0 2mm 0; }
.callout ul, .callout ol { margin: 1mm 0 2mm 0; }
.callout h3, .callout h4 { margin: 0 0 2mm 0; color: #4c1d95; }
.callout-warn { background: #fdf3ec; border-left-color: #d97706; }
.callout-warn strong { color: #b45309; }

hr { border: none; border-top: 0.6pt solid #d1d5db; margin: 5mm 0; }

/* ---------- Tabulky ---------- */
table.md-tab {
  border-collapse: collapse; width: 100%; margin: 2.5mm 0 4.2mm 0;
  font-size: {$tf}pt; line-height: 1.35;
  overflow-wrap: break-word; word-wrap: break-word;
}
table.md-tab th {
  background: #4c1d95; color: #ffffff; font-weight: 700;
  padding: 1.8mm 2.4mm; border: 0.4pt solid #4c1d95; text-align: left;
}
table.md-tab td {
  padding: 1.6mm 2.4mm; border: 0.4pt solid #d1d5db; vertical-align: top;
  overflow-wrap: break-word; word-wrap: break-word;
}
table.md-tab tr:nth-child(even) td { background: #faf8ff; }

/* ---------- Obrázky ---------- */
.fig { margin: 3mm 0 4mm 0; page-break-inside: avoid; text-align: center; }
.fig img {
  max-width: 100%; border: 0.6pt solid #d1d5db; border-radius: 2pt;
  padding: 2mm; background: #fff;
}
.fig-caption { margin-top: 1.5mm; font-size: 8.8pt; color: #6b7280; font-style: italic; }
/* popisek pod obrázkem zapsaný jako samostatný *kurzívový* řádek za obrázkem */
.fig-cap { text-align: center; margin: -1mm 0 4.5mm 0; color: #6b7280; font-size: 9.2pt; }

/* ---------- Fenced code / ASCII diagramy (tmavé, vzdušné) ---------- */
pre.code-block {
  background: #1e1e2e; color: #cdd6f4;
  border-radius: 3pt;
  padding: 3.2mm 4mm; margin: 2.8mm 0 4mm 0;
  font-family: "cascadiamono", "dejavusansmono", monospace; font-size: {$cf}pt;
  line-height: 1.55; page-break-inside: avoid; white-space: pre;
}
pre.code-block code {
  background: transparent; border: none; padding: 0; color: inherit;
  font-family: inherit; font-size: inherit;
}

/* ---------- Glyph náhrady ---------- */
.g-ok  { color: #16a34a; font-weight: 700; }
.g-no  { color: #dc2626; font-weight: 700; }
.g-ph2 { color: #e08e0b; }
.g-ph3 { color: #2f7fc9; }
.lbl   { color: #5b21b6; }
CSS;
}

// =====================================================================
//  Mermaid → PNG přes mermaid-cli (mmdc)
//  ```mermaid bloky se vyrenderují do PNG a v markdownu se nahradí obrázkem
//  ![](png). Když mmdc chybí nebo render selže, blok se PONECHÁ (spadne do
//  code-boxu) — graceful degradation. PNG se cachují dle hashe obsahu.
// =====================================================================
function locateMmdc(): ?string
{
    global $CFG, $TOOLS_DIR;
    $cfgPath = $CFG['mermaid']['mmdc'] ?? null;
    if ($cfgPath && is_file($cfgPath)) { return $cfgPath; }

    $bin   = (DIRECTORY_SEPARATOR === '\\') ? 'mmdc.cmd' : 'mmdc';
    $local = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
           . '.bin' . DIRECTORY_SEPARATOR . $bin;
    if (is_file($local)) { return $local; }

    // na PATH
    $finder = (DIRECTORY_SEPARATOR === '\\') ? 'where mmdc 2>NUL' : 'command -v mmdc 2>/dev/null';
    $out = @shell_exec($finder);
    if ($out) {
        $line = trim(strtok($out, "\n"));
        if ($line !== '' && is_file($line)) { return $line; }
    }
    return null;
}

// Vytvoř runtime puppeteer config (args + executablePath). Prohlížeč hledá
// v engine-local `.puppeteer` (chrome-headless-shell / chrome). Předáním
// executablePath se obejde resolveExecutablePath („Could not find Chrome").
function mermaidPuppeteerConfig(): ?string
{
    global $CFG, $TOOLS_DIR;
    $exe = $CFG['mermaid']['chrome'] ?? (getenv('MD2PDF_CHROME') ?: null);
    // 1) prohlížeč stažený puppeteerem v engine-local .puppeteer
    if (!$exe) {
        $base = str_replace('\\', '/', $TOOLS_DIR) . '/.puppeteer';
        foreach (['chrome-headless-shell', 'chrome'] as $kind) {
            $leaf = ($kind === 'chrome') ? 'chrome.exe' : $kind . '.exe';
            // Windows: .puppeteer/<kind>/<platform-ver>/<kind>-<platform>/<leaf>
            $hits = glob("$base/$kind/*/$kind-*/$leaf");
            // unix: .../<kind>/<platform-ver>/<...>/<kind>
            if (!$hits) { $hits = glob("$base/$kind/*/*/$kind"); }
            if ($hits) { $exe = $hits[0]; break; }
        }
    }
    // 2) systémový Chrome / Edge (AV-friendly, bez stahování)
    if (!$exe) {
        $sys = [
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
            'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
            '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];
        foreach ($sys as $p) { if (is_file($p)) { $exe = $p; break; } }
    }
    $conf = ['args' => ['--no-sandbox', '--disable-gpu']];
    if ($exe && is_file($exe)) { $conf['executablePath'] = str_replace('\\', '/', $exe); }

    $cacheDir = $TOOLS_DIR . DIRECTORY_SEPARATOR . '.mermaid-cache';
    @mkdir($cacheDir, 0775, true);
    $path = $cacheDir . DIRECTORY_SEPARATOR . 'puppeteer-runtime.json';
    if (@file_put_contents($path, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        return null;
    }
    return $path;
}

function renderMermaidToPng(string $src, string $mmdc): ?string
{
    global $CFG, $TOOLS_DIR;
    $theme = (string) ($CFG['mermaid']['theme'] ?? 'default');
    $bg    = (string) ($CFG['mermaid']['background'] ?? 'white');
    $scale = (string) ($CFG['mermaid']['scale'] ?? 2.5);

    $cacheDir = $TOOLS_DIR . DIRECTORY_SEPARATOR . '.mermaid-cache';
    @mkdir($cacheDir, 0775, true);
    $key = sha1($src . "|{$theme}|{$bg}|{$scale}");
    $png = $cacheDir . DIRECTORY_SEPARATOR . $key . '.png';
    if (is_file($png) && filesize($png) > 0) {
        return str_replace('\\', '/', $png);  // cache hit
    }

    $mmd = $cacheDir . DIRECTORY_SEPARATOR . $key . '.mmd';
    file_put_contents($mmd, $src);

    // engine-local puppeteer cache (self-contained, gitignored) — ať mmdc najde prohlížeč
    $pCache = $TOOLS_DIR . DIRECTORY_SEPARATOR . '.puppeteer';
    if (is_dir($pCache)) { putenv('PUPPETEER_CACHE_DIR=' . $pCache); }

    $args = [$mmdc, '-i', $mmd, '-o', $png, '-t', $theme, '-b', $bg, '-s', $scale];
    $pptr = mermaidPuppeteerConfig();
    if ($pptr !== null) { $args[] = '-p'; $args[] = $pptr; }

    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    @exec($cmd, $o, $rc);

    if ($rc === 0 && is_file($png) && filesize($png) > 0) {
        return str_replace('\\', '/', $png);
    }
    return null;
}

function preprocessMermaid(string $md): string
{
    global $CFG;
    if (($CFG['mermaid']['enabled'] ?? true) === false) { return $md; }
    if (strpos($md, 'mermaid') === false) { return $md; }

    static $mmdc = null;
    static $warned = false;
    if ($mmdc === null) { $mmdc = locateMmdc() ?? ''; }
    if ($mmdc === '') {
        if (!$warned) {
            fwrite(STDERR, "  (mermaid: mmdc nenalezen — bloky ponechány jako kód; spusť `npm install` v enginu)\n");
            $warned = true;
        }
        return $md;
    }

    return preg_replace_callback(
        '/^[ \t]*`{3,}[ \t]*mermaid[ \t]*\r?\n(.*?)\r?\n[ \t]*`{3,}[ \t]*$/ms',
        function ($m) use ($mmdc) {
            $png = renderMermaidToPng($m[1], $mmdc);
            return $png === null ? $m[0] : '![](' . $png . ')';
        },
        $md
    );
}

// =====================================================================
//  Vyrenderuj jeden dokument
// =====================================================================
function renderDocument(string $mdPath): array
{
    global $OUT_DIR, $SRC_DIR, $AUTHOR, $COMPANY, $BRAND, $CFG, $TOOLS_DIR;

    $base = pathinfo($mdPath, PATHINFO_FILENAME);
    $md   = file_get_contents($mdPath);
    if ($md === false) { throw new RuntimeException("Nelze číst {$mdPath}"); }
    $md = str_replace("\xEF\xBB\xBF", '', $md); // strip BOM

    $meta  = parseMeta($md);
    $split = splitTitle($md);
    $title = $split['title'];

    // mermaid bloky → PNG (před parserem; nahradí se za ![](png))
    $body = preprocessMermaid($split['body']);

    $parsed       = mdToHtml($body);
    $bodyHtml     = $parsed['html'];
    $toc          = $parsed['toc'];
    $maxCodeWidth = $parsed['maxCodeWidth'];

    $bodyHtml = applyGlyphSubstitutions($bodyHtml);
    $bodyHtml = str_replace('<!--/fig-->', '', $bodyHtml);  // interní marker popisků pryč

    // Oddělovací čáry mezi kapitolami jsou po zavedení stránkových zlomů zbytečné:
    // zahodit <hr> stojící těsně před H1/H2 (zlom oddělí sám) i osamocený na konci.
    $bodyHtml = preg_replace('~<hr\s*/?>\s*(?=<h[12]\b)~', '', $bodyHtml);
    $bodyHtml = preg_replace('~<hr\s*/?>\s*$~', '', $bodyHtml);

    // ---- Auto-fit písma kódu dle nejširšího řádku diagramu --------------
    // Obsahová šířka strany ~ 174 mm (A4 210 - 2*18). Cascadia Mono: 1 znak ≈
    // 0.59 * fontSize(pt) (advance 1200/2048 em). Najdi největší pt tak, aby
    // maxWidth*0.59*pt/72*25.4 <= 168 (tmavý blok má větší vnitřní padding).
    $codeFontPt = 9.0;
    if ($maxCodeWidth > 0) {
        $avail_mm = 168.0;
        $pt = ($avail_mm * 72.0) / (25.4 * 0.59 * $maxCodeWidth);
        $codeFontPt = max(6.8, min(9.0, $pt));
    }

    // ---- Tabulkový font: docs jsou table-heavy a široké -------------------
    $tableFontPt = 8.9;

    $css = buildCss($tableFontPt, $codeFontPt);

    // ---- Titulní strana (fialová, logo dole uprostřed) ----
    $genDate = date($CFG['date_format'] ?? 'j. n. Y');
    $purpose = $meta['purpose'] ? '<div class="cover-purpose">' . mdInline($meta['purpose']) . '</div>' : '';
    $purpose = applyGlyphSubstitutions($purpose);

    // popisky řádků metadat z configu (překlad); prázdné hodnoty se vynechají
    $MS  = $CFG['strings']['meta'] ?? [];
    $rows = [];
    $rows[] = [$MS['document'] ?? 'Dokument', htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '.md'];
    if ($meta['version']) { $rows[] = [$MS['version'] ?? 'Verze', mdInline($meta['version'])]; }
    if ($meta['date'])    { $rows[] = [$MS['date'] ?? 'Datum', htmlspecialchars($meta['date'], ENT_QUOTES, 'UTF-8')]; }
    if ($AUTHOR !== '')   { $rows[] = [$MS['author'] ?? 'Autor', htmlspecialchars($AUTHOR, ENT_QUOTES, 'UTF-8')]; }
    if ($COMPANY !== '')  { $rows[] = [$MS['company'] ?? 'Společnost', htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8')]; }
    $rows[] = [$MS['generated'] ?? 'Vygenerováno', $genDate];

    $metaRows = '';
    $lastIdx  = count($rows) - 1;
    foreach ($rows as $i => $r) {
        $trCls = ($i === $lastIdx) ? ' class="last"' : '';
        $metaRows .= '<tr' . $trCls . '><td class="cover-meta-label">'
                  . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8') . '</td>'
                  . '<td class="cover-meta-value">' . $r[1] . '</td></tr>';
    }

    // eyebrow nad titulkem: „{doc_kind} · {brand}"
    $dkRaw   = (string) ($CFG['doc_kind'] ?? '');
    $dk      = htmlspecialchars($dkRaw, ENT_QUOTES, 'UTF-8');
    $eyebrow = $BRAND !== ''
        ? ($dk !== '' ? $dk . ' &#183; ' : '') . htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8')
        : $dk;

    // Logo (bílý wordmark, VEKTOROVÉ SVG) — dole uprostřed titulní strany.
    // mPDF SVG parser zvládá paths/rect; používáme kopii s číselnými rozměry
    // (logo-clean.svg). PNG fallback pro případ chybějícího SVG.
    // Cesty z configu; když nejsou, sáhne se po výchozím logu enginu (vedle skriptu).
    $logoSvg = $CFG['logo']['svg'] ?? null;
    $logoPng = $CFG['logo']['png'] ?? null;
    if ($logoSvg === null && $logoPng === null) {
        $logoSvg = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-clean.svg';
        $logoPng = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-white.png';
    }
    $logoFile = ($logoSvg && is_file($logoSvg)) ? $logoSvg
              : (($logoPng && is_file($logoPng)) ? $logoPng : null);
    $logoHtml = '';
    if ($logoFile !== null) {
        $logoSrc  = htmlspecialchars($logoFile, ENT_QUOTES, 'UTF-8');
        // POZN: mPDF respektuje position:absolute jen u PŘÍMÝCH potomků body —
        // proto se logo přidává ZA .cover div (viz níže), ne dovnitř bandu.
        // ~2 cm nad patičkou: obsah končí na 279 mm (297 − 18 spodní okraj),
        // logo (40 mm široké ⇒ ~19,3 mm vysoké) tedy top = 279 − 20 − 19,3 ≈ 240 mm.
        $logoHtml = '<div style="position:absolute;left:0;top:240mm;width:210mm;text-align:center;">'
                  . '<img src="' . $logoSrc . '" style="width:40mm;" alt="' . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '" />'
                  . '</div>';
    }

    $cover = <<<HTML
{$logoHtml}
<div class="cover">
  <div class="cover-band">
    <div class="cover-rule"></div>
    <div class="cover-eyebrow">{$eyebrow}</div>
    <h1 class="cover-title">{$title}</h1>
    {$purpose}
    <table class="cover-meta" cellspacing="0" cellpadding="0">
      {$metaRows}
    </table>
  </div>
</div>
HTML;

    // ---- Obsah (TOC) — jen úroveň H2, ať se vejde na stranu ----
    $tocH2 = array_values(array_filter($toc, fn ($t) => $t['level'] === 2));
    $tocHtml = '';
    if (count($tocH2) >= 4) {
        $tocTitle = htmlspecialchars($CFG['strings']['toc_title'] ?? 'Obsah', ENT_QUOTES, 'UTF-8');
        $tocHtml = '<div class="toc"><div class="toc-title">' . $tocTitle . '</div>' . "\n";
        foreach ($tocH2 as $t) {
            $txt = applyGlyphSubstitutions(mdInline($t['text']));
            $tocHtml .= '<div class="toc-h2"><a href="#' . $t['slug'] . '">' . $txt . "</a></div>\n";
        }
        $tocHtml .= "</div>\n";
    }

    $html = $cover . $tocHtml . $bodyHtml;

    // ---- mPDF ----
    $tmpDir = sys_get_temp_dir() . '/md2pdf-mpdf';
    @mkdir($tmpDir, 0775, true);

    // Vlastní VOLNĚ LICENCOVANÉ fonty z tools/fonts (mPDF je EMBEDUJE do PDF jako
    // subset → dokument vypadá stejně všude; lze legálně šířit i komerčně):
    //  - Source Sans 3 (text, SIL OFL): humanistický bezpatkový font blízký
    //    Segoe UI, plná čeština, pravé řezy R/B/I/BI.
    //  - Cascadia Mono (kód/diagramy, SIL OFL): mPDF bundluje OŘEZANÝ DejaVu Mono
    //    bez box-drawing znaků (─│┌▼►…) — bral je substitucí z proporcionálního
    //    fontu a ASCII diagramy se rozjížděly. Cascadia má kompletní sadu v mono šířce.
    //  - DejaVu (bundlováno v mPDF, volné): záloha pro glyfy mimo Source Sans
    //    (✓ ✗ ◆ ★ ⚠ ✚ ☑ ∩ …).
    $defCfg   = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $defFonts = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defFonts['fontdata'];
    $fontData['sourcesans'] = [
        'R' => 'SourceSans3-Regular.ttf', 'B' => 'SourceSans3-Bold.ttf',
        'I' => 'SourceSans3-It.ttf', 'BI' => 'SourceSans3-BoldIt.ttf',
    ];
    $fontData['cascadiamono'] = [
        'R' => 'CascadiaMono.ttf', 'B' => 'CascadiaMono.ttf',
        'I' => 'CascadiaMono.ttf', 'BI' => 'CascadiaMono.ttf',
    ];

    $mpdf = new \Mpdf\Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'margin_left'       => 18,
        'margin_right'      => 18,
        'margin_top'        => 20,
        'margin_bottom'     => 18,
        'margin_header'     => 8,
        'margin_footer'     => 9,
        'default_font_size' => 10.3,
        'default_font'      => 'sourcesans',
        'tempDir'           => $tmpDir,
        'autoLangToFont'    => false,
        'autoScriptToLang'  => false,
        'fontDir'           => array_merge($defCfg['fontDir'], [__DIR__ . '/fonts']),
        'fontdata'          => $fontData,
        // chybějící glyfy (✓ ✗ ◆ ⚠ …) dober ze záložních fontů — Source Sans je nemá;
        // v kódových blocích se neuplatní (Cascadia má box-drawing kompletní)
        'useSubstitutions'  => true,
        'backupSubsFont'    => ['dejavusans', 'dejavusansmono'],
    ]);

    $mpdf->SetTitle($title);
    if ($AUTHOR !== '') { $mpdf->SetAuthor($AUTHOR); }
    $mpdf->SetCreator(($BRAND !== '' ? $BRAND . ' ' : '') . 'md2pdf.php (mPDF)');
    $mpdf->SetSubject($BRAND !== '' ? $BRAND . ' — ' . $title : $title);

    // tabulky: zmenši, pokud přetékají; opakuj hlavičku přes stránky
    $mpdf->shrink_tables_to_fit = 1;
    $mpdf->use_kwt = true; // keep-with-table (repeat thead)
    $mpdf->defaultheaderline = 0;
    $mpdf->defaultfooterline = 0;

    // Hlavička: vlevo brand · titul, vpravo společnost (fialový branding)
    $hdrTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $hdrBrand = $BRAND !== ''
        ? '<span style="color:#4c1d95;font-weight:700;">' . htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8')
          . '</span> &nbsp;&#183;&nbsp; '
        : '';
    $mpdf->SetHTMLHeader(
        '<table style="width:100%;border-bottom:0.5pt solid #d1d5db;'
        . 'font-size:8pt;color:#6b7280;"><tr>'
        . '<td style="text-align:left;">' . $hdrBrand . $hdrTitle . '</td>'
        . '<td style="text-align:right;width:34mm;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr></table>'
    );

    // Patička: společnost · strana · typ dokumentu + datum (texty z configu)
    $pageLabel  = htmlspecialchars($CFG['strings']['page_label'] ?? 'Strana', ENT_QUOTES, 'UTF-8');
    $footKind   = $dkRaw !== '' ? mb_strtolower($dkRaw, 'UTF-8') : '';
    $footRight  = ($footKind !== '' ? htmlspecialchars($footKind, ENT_QUOTES, 'UTF-8') . ' &#183; ' : '')
                . $genDate;
    $mpdf->SetHTMLFooter(
        '<table style="width:100%;border-top:0.5pt solid #d1d5db;'
        . 'font-size:8pt;color:#6b7280;padding-top:1mm;"><tr>'
        . '<td style="text-align:left;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="text-align:center;">' . $pageLabel . ' {PAGENO} / {nbpg}</td>'
        . '<td style="text-align:right;">' . $footRight . '</td>'
        . '</tr></table>'
    );

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    $outPath = $OUT_DIR . DIRECTORY_SEPARATOR . $base . '.pdf';
    // Render do paměti a zapiš atomicky s retry — Windows občas drží zámek na
    // cílovém PDF (antivir / náhledový handler / GhostScript) → "Resource
    // temporarily unavailable". Retry to spolehlivě obejde.
    $pdfData = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    $written = false;
    $lastErr = '';
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $fh = @fopen($outPath, 'wb');
        if ($fh !== false) {
            fwrite($fh, $pdfData);
            fclose($fh);
            $written = true;
            break;
        }
        $e = error_get_last();
        $lastErr = $e['message'] ?? 'unknown';
        usleep(250000); // 0,25 s
    }
    if (!$written) {
        throw new RuntimeException("Nelze zapsat {$outPath} ({$lastErr})");
    }

    return [
        'base'        => $base,
        'title'       => $title,
        'out'         => $outPath,
        'pages'       => $mpdf->page,
        'size_kb'     => round(filesize($outPath) / 1024, 1),
        'codeFontPt'  => round($codeFontPt, 2),
        'maxCodeW'    => $maxCodeWidth,
        'version'     => $meta['version'],
        'date'        => $meta['date'],
        'tocItems'    => count($toc),
    ];
}

// =====================================================================
//  Main
// =====================================================================
$argFilter = $positional[0] ?? null;

$files = glob($SRC_DIR . DIRECTORY_SEPARATOR . $GLOB);
sort($files, SORT_STRING);

if ($argFilter) {
    $argBase = preg_replace('/\.md$/i', '', $argFilter);
    $files = array_values(array_filter($files, function ($f) use ($argBase) {
        return pathinfo($f, PATHINFO_FILENAME) === $argBase;
    }));
}

if (!$files) {
    fwrite(STDERR, "Žádné soubory odpovídající '{$GLOB}' nenalezeny v {$SRC_DIR}\n");
    exit(1);
}

echo ($BRAND !== '' ? $BRAND . ' ' : '') . "md2pdf — zdroj: {$SRC_DIR}\n";
echo "Výstup: {$OUT_DIR}\n";
echo str_repeat('-', 64) . "\n";

$results = [];
$hadError = false;
foreach ($files as $f) {
    $name = basename($f);
    echo "» {$name} ... ";
    try {
        $r = renderDocument($f);
        $results[] = $r;
        echo "OK  ({$r['pages']} str, {$r['size_kb']} kB, kód {$r['codeFontPt']}pt/maxW {$r['maxCodeW']})\n";
    } catch (\Throwable $e) {
        $hadError = true;
        echo "CHYBA: " . $e->getMessage() . "\n";
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
}

echo str_repeat('-', 64) . "\n";
echo "Hotovo: " . count($results) . " PDF.\n";
foreach ($results as $r) {
    printf("  %-28s %s  (v%s, %s)  -> %d str\n",
        $r['base'] . '.pdf',
        str_pad((string)$r['size_kb'] . ' kB', 10),
        $r['version'] ?? '?',
        $r['date'] ?? '?',
        $r['pages']);
}

exit($hadError ? 1 : 0);
