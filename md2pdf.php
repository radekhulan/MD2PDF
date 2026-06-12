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
    global $FN_ORDER;
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 0) backslash-escapy (\* \_ \` \~ \# \[ …) → placeholder; obnoví se na konci
    //    DOSLOVNĚ, takže neaktivují žádné formátování.
    $escStore = [];
    $s = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!~|])/', function ($m) use (&$escStore) {
        $i = count($escStore);
        $escStore[] = $m[1];
        return "\x01E{$i}\x02";
    }, $s);

    // 1) `code` spany do placeholderů (aby další regex nesahaly dovnitř)
    $codeStore = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeStore) {
        $i = count($codeStore);
        $codeStore[] = $m[1];
        return "\x01C{$i}\x02";
    }, $s);

    // 2) **bold** , ~~strike~~ , *italic* , _italic_
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s);
    $s = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\w)/', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/', '<em>$1</em>', $s);

    // 3) reference poznámek pod čarou [^id] → horní index (jen když má definici)
    if (!empty($FN_ORDER)) {
        $s = preg_replace_callback('/\[\^([^\]]+)\]/', function ($m) use ($FN_ORDER) {
            $id = $m[1];
            if (!isset($FN_ORDER[$id])) { return $m[0]; }
            $n = $FN_ORDER[$id];
            return '<sup class="fnref" id="fnref-' . $n . '"><a href="#fn-' . $n . '">' . $n . '</a></sup>';
        }, $s);
    }

    // 4) autolink <https://…> (po htmlspecialchars jsou závorky &lt; &gt;)
    $s = preg_replace_callback('/&lt;(https?:\/\/[^\s]+?)&gt;/', function ($m) {
        return '<a href="' . $m[1] . '">' . $m[1] . '</a>';
    }, $s);

    // 5) [text](url) — externí odkaz jako stylovaný text (bez rozbitých URL)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $text = $m[1];
        $href = trim($m[2]);
        // .md interní odkazy → jen text (žádné rozbité odkazy v PDF).
        // COMBINE režim (více .md → jeden PDF): cross-chapter .md odkaz se přepíše
        // na KLIKACÍ interní kotvu (#slug prvního H1 cílové kapitoly, případně
        // #slug uvedené sekce). Mimo combine (globální mapa není) zůstává původní
        // chování beze změny → 100% zpětná kompatibilita.
        if (preg_match('~\.md(#.*)?$~i', $href)) {
            $cmb = $GLOBALS['MD2PDF_COMBINE'] ?? null;
            if ($cmb && preg_match('~^([^#]+)\.md(?:#(.+))?$~i', $href, $hm)) {
                if (isset($hm[2]) && $hm[2] !== '') {
                    return '<a href="#' . mdSlug($hm[2]) . '">' . $text . '</a>';
                }
                $b = strtolower(pathinfo($hm[1], PATHINFO_FILENAME));
                if (!empty($cmb['bases'][$b])) {
                    return '<a href="#' . $cmb['bases'][$b] . '">' . $text . '</a>';
                }
            }
            return '<span class="xref">' . $text . '</span>';
        }
        if (preg_match('~^https?://~i', $href)) {
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
        }
        // interní kotva (#sekce) → KLIKACÍ odkaz; slug normalizuj přes mdSlug,
        // ať sedí na id nadpisu (GitHub-style kotvy s emoji / vedoucí pomlčkou).
        if ($href !== '' && $href[0] === '#') {
            return '<a href="#' . mdSlug(substr($href, 1)) . '">' . $text . '</a>';
        }
        return '<span class="xref">' . $text . '</span>';
    }, $s);

    // 6) vrať code spany
    $s = preg_replace_callback('/\x01C(\d+)\x02/', function ($m) use ($codeStore) {
        return '<code>' . $codeStore[(int) $m[1]] . '</code>';
    }, $s);

    // 7) vrať escapy DOSLOVNĚ (escapovaný znak už neformátuje)
    $s = preg_replace_callback('/\x01E(\d+)\x02/', function ($m) use ($escStore) {
        return htmlspecialchars($escStore[(int) $m[1]], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $s);

    return $s;
}

// =====================================================================
//  Poznámky pod čarou: vyřízni definice '[^id]: text', očísluj reference dle
//  pořadí výskytu. Vrací ['body'=>bez-definic, 'defs'=>[id=>text], 'order'=>[id=>n]].
// =====================================================================
function preprocessFootnotes(string $body): array
{
    $defs  = [];
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $out   = [];
    foreach ($lines as $l) {
        if (preg_match('/^\[\^([^\]]+)\]:\s?(.*)$/', $l, $m)) {
            $defs[$m[1]] = $m[2];
        } else {
            $out[] = $l;
        }
    }
    $bodyNoDefs = implode("\n", $out);

    $order = [];
    if ($defs && preg_match_all('/\[\^([^\]]+)\]/', $bodyNoDefs, $mm)) {
        $n = 0;
        foreach ($mm[1] as $id) {
            if (isset($defs[$id]) && !isset($order[$id])) { $order[$id] = ++$n; }
        }
    }
    return ['body' => $bodyNoDefs, 'defs' => $defs, 'order' => $order];
}

// Vyrenderuj sekci poznámek pod čarou (na konec dokumentu).
function renderFootnotesHtml(array $order, array $defs): string
{
    if (!$order) { return ''; }
    asort($order);
    $h = '<hr class="fn-sep" />' . "\n" . '<div class="footnotes"><ol>' . "\n";
    foreach ($order as $id => $n) {
        $h .= '<li id="fn-' . $n . '">' . mdInline($defs[$id])
            . ' <a class="fn-back" href="#fnref-' . $n . '">&#8617;</a></li>' . "\n";
    }
    $h .= '</ol></div>' . "\n";
    return $h;
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
    // H2 zlom lze řídit odděleně (combine režim: H1 = kapitola se zlomem, H2 =
    // sekce uvnitř BEZ zlomu). Když není zadán, dědí z chapter_page_break →
    // beze změny pro stávající jednodokumentové configy.
    $h2Break        = $CFG['h2_page_break'] ?? $chapterBreak;
    // Které úrovně nadpisů jdou do TOC (default H2+H3 = stávající chování).
    $tocLevels      = $CFG['toc_levels'] ?? [2, 3];

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
                    // Řádek širší než ~120 znaků se na stránku nevejde ani při
                    // minimálním písmu → zalomí se (pre-wrap), takže NEŘÍDÍ auto-fit
                    // (jinak by jediný dlouhý DNS/base64 token srazil písmo kódu
                    // v celém dokumentu a přetečením zmenšil celou stránku).
                    if ($w > 120) { continue; }
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
                if ($firstH2Done && $h2Break) { $cls = ' class="pb"'; }
                $firstH2Done = true;
            }
            $headingCounter++;
            $idAttr = $id !== '' ? ' id="' . $id . '"' : '';
            // POZN: kromě id přidej i <a name> — mPDF interní odkazy (#kotva)
            // resolvuje přes name, ne přes id (Chrome bere obojí).
            $anchor = $id !== '' ? '<a name="' . $id . '"></a>' : '';
            $html  .= "<h{$level}{$idAttr}{$cls}>{$anchor}{$text}</h{$level}>\n";
            if (in_array($level, $tocLevels, true)) {
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
        // ⚠ vysázej explicitně ze záložního fontu — mPDF ho jinak renderuje
        // DVOJITĚ (prázdný box z primárního fontu + triangl z DejaVu).
        "\u{26A0}" => '<span class="g-warn">&#9888;</span>',
        // varovné/info štítky (kdyby se vyskytly mimo callout)
        "\u{1F4A1}" => '<strong class="lbl">' . $tip . '</strong>',   // 💡
        "\u{2139}"  => '<strong class="lbl">' . $note . '</strong>',  // ℹ
        // barevné stavové puntíky (legendy stavů) — emoji plane, font je nemá;
        // přepiš na barevné ● / ○ (ty font má a jsou v keep-listu níže)
        "\u{1F7E2}" => '<span class="g-dot g-dot-green">&#9679;</span>',  // 🟢
        "\u{1F7E1}" => '<span class="g-dot g-dot-amber">&#9679;</span>',  // 🟡
        "\u{1F7E0}" => '<span class="g-dot g-dot-amber">&#9679;</span>',  // 🟠
        "\u{1F534}" => '<span class="g-dot g-dot-red">&#9679;</span>',    // 🔴
        "\u{1F535}" => '<span class="g-dot g-dot-blue">&#9679;</span>',   // 🔵
        "\u{26AB}"  => '<span class="g-dot">&#9679;</span>',              // ⚫
        "\u{26AA}"  => '<span class="g-dot g-dot-gray">&#9675;</span>',   // ⚪
        "\u{1F4E5}" => '&#8595;',                                          // 📥 → ↓
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
del { text-decoration: line-through; color: #6b7280; }
sup.fnref { font-size: 7pt; vertical-align: super; line-height: 0; }
sup.fnref a { color: #6c5ce7; text-decoration: none; font-weight: 700; }

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
  table-layout: fixed;                 /* nedovol buňce roztáhnout tabulku přes stránku */
  font-size: {$tf}pt; line-height: 1.35;
  overflow-wrap: break-word; word-wrap: break-word; word-break: break-word;
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
  line-height: 1.55; page-break-inside: avoid;
  white-space: pre-wrap; overflow-wrap: break-word;
}
pre.code-block code {
  background: transparent; border: none; padding: 0; color: inherit;
  font-family: inherit; font-size: inherit;
}

/* ---------- Glyph náhrady ---------- */
.g-warn { font-family: "dejavusans", sans-serif; }
.g-ok  { color: #16a34a; font-weight: 700; }
.g-no  { color: #dc2626; font-weight: 700; }
.g-ph2 { color: #e08e0b; }
.g-ph3 { color: #2f7fc9; }
.lbl   { color: #5b21b6; }

/* Barevné stavové puntíky (🟢🟡🔴⚪…) */
.g-dot { font-size: 0.9em; line-height: 1; }
.g-dot-green { color: #16a34a; }
.g-dot-amber { color: #d97706; }
.g-dot-red   { color: #dc2626; }
.g-dot-blue  { color: #2563eb; }
.g-dot-gray  { color: #9ca3af; }

/* ---------- Poznámky pod čarou ---------- */
hr.fn-sep { border: none; border-top: 0.6pt solid #d1d5db; margin: 7mm 0 3mm 0; }
.footnotes { font-size: 9pt; color: #4b5563; line-height: 1.45; }
.footnotes ol { padding-left: 6mm; margin: 0; }
.footnotes li { margin-bottom: 1.2mm; }
.fn-back { text-decoration: none; color: #6c5ce7; }
CSS;
}

// =====================================================================
//  Mermaid → PNG přes mermaid-cli (mmdc)
//  ```mermaid bloky se vyrenderují do PNG a v markdownu se nahradí obrázkem
//  ![](png). Když mmdc chybí nebo render selže, blok se PONECHÁ (spadne do
//  code-boxu) — graceful degradation. PNG se cachují dle hashe obsahu.
// =====================================================================
// ---------------------------------------------------------------------
//  Velikost písma v code blocích.
//  DEFAULT: NEzmenšovat — vrať 9pt a dlouhé řádky nech zalomit
//  (pre.code-block má white-space: pre-wrap). Platí pro oba renderery.
//  Volitelně přes config:
//    'code_font_pt' => 9.0    // pevná velikost (přebije vše)
//    'code_autofit' => true   // staré chování: zmenšit, aby se řádek vešel
// ---------------------------------------------------------------------
function computeCodeFontPt(int $maxCodeWidth): float
{
    global $CFG;
    if (isset($CFG['code_font_pt']) && $CFG['code_font_pt'] !== null) {
        return (float) $CFG['code_font_pt'];
    }
    if (empty($CFG['code_autofit'])) {
        return 9.0;                       // default: bez zdrobňování → zalomení
    }
    if ($maxCodeWidth <= 0) {
        return 9.0;
    }
    // Auto-fit JEN na vyžádání: zmenši písmo, aby se nejširší řádek vešel.
    // 0.62 = kalibrovaný advance Cascadia Mono vč. paddingu (0.59 podhodnocoval
    // a řádek se i po zmenšení o chlup zalamoval).
    $pt = (168.0 * 72.0) / (25.4 * 0.62 * $maxCodeWidth);
    return max(6.8, min(9.0, $pt));
}

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
//  CHROME RENDERER (default) — HTML → PDF přes headless Chrome (puppeteer)
//  + GhostScript (spojení titulky+těla a optimalizace). Vektorový mermaid
//  (SVG, ne PNG), full-bleed titulka bez furniture, běžící hlavička/patička
//  + čísla stran. Mezivýstupy jdou do temp; do output_dir se zapíše jen
//  finální {base}.pdf. Vyžaduje Node (render-pdf.mjs + puppeteer) a GhostScript.
// =====================================================================
function chromeExe(): ?string
{
    global $CFG;
    $cfg = $CFG['chrome']['exe'] ?? (getenv('MD2PDF_CHROME') ?: null);
    if ($cfg && is_file($cfg)) { return str_replace('\\', '/', $cfg); }
    foreach ([
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ] as $p) {
        if (is_file($p)) { return str_replace('\\', '/', $p); }
    }
    return null;
}

function ghostscriptExe(): ?string
{
    global $CFG;
    $cfg = $CFG['chrome']['gs'] ?? null;
    if ($cfg && is_file($cfg)) { return $cfg; }
    $cands = (DIRECTORY_SEPARATOR === '\\')
        ? ['c:/inetpub/GhostScript/bin/gswin64c.exe']
        : ['/usr/bin/gs', '/usr/local/bin/gs'];
    foreach ($cands as $c) { if (is_file($c)) { return $c; } }
    $finder = (DIRECTORY_SEPARATOR === '\\') ? 'where gswin64c 2>NUL' : 'command -v gs 2>/dev/null';
    $out = @shell_exec($finder);
    if ($out) { $l = trim(strtok($out, "\n")); if ($l !== '' && is_file($l)) { return $l; } }
    return null;
}

// mermaid → vektorové SVG (obdoba renderMermaidToPng, ale -o .svg; cache dle hashe)
function renderMermaidToSvg(string $src, string $mmdc): ?string
{
    global $CFG, $TOOLS_DIR;
    $theme = (string) ($CFG['mermaid']['theme'] ?? 'default');
    $bg    = (string) ($CFG['mermaid']['background'] ?? 'white');
    $cacheDir = $TOOLS_DIR . DIRECTORY_SEPARATOR . '.mermaid-cache';
    @mkdir($cacheDir, 0775, true);
    $key = sha1('svg|' . $src . "|{$theme}|{$bg}");
    $svgPath = $cacheDir . DIRECTORY_SEPARATOR . $key . '.svg';
    if (is_file($svgPath) && filesize($svgPath) > 0) { return $svgPath; }
    $mmd = $cacheDir . DIRECTORY_SEPARATOR . $key . '.mmd';
    file_put_contents($mmd, $src);
    $pCache = $TOOLS_DIR . DIRECTORY_SEPARATOR . '.puppeteer';
    if (is_dir($pCache)) { putenv('PUPPETEER_CACHE_DIR=' . $pCache); }
    $args = [$mmdc, '-i', $mmd, '-o', $svgPath, '-t', $theme, '-b', $bg];
    $pptr = mermaidPuppeteerConfig();
    if ($pptr !== null) { $args[] = '-p'; $args[] = $pptr; }
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    @exec($cmd, $o, $rc);
    return ($rc === 0 && is_file($svgPath) && filesize($svgPath) > 0) ? $svgPath : null;
}

// neutralizuj fixní rozměry SVG → škáluje se na šířku sloupce
function responsiveSvg(string $svg): string
{
    $svg = preg_replace('/^\xEF\xBB\xBF/', '', $svg);
    $svg = preg_replace('/<\?xml.*?\?>/s', '', $svg);
    $svg = preg_replace('/<!DOCTYPE.*?>/s', '', $svg);
    if (preg_match('/<svg\b[^>]*\bviewBox=/i', $svg)) {
        $svg = preg_replace('/(<svg\b[^>]*?)\s+width="[^"]*"/i',  '$1', $svg, 1);
        $svg = preg_replace('/(<svg\b[^>]*?)\s+height="[^"]*"/i', '$1', $svg, 1);
        $svg = preg_replace('/(<svg\b[^>]*?)\s+style="[^"]*"/i',  '$1', $svg, 1);
        $svg = preg_replace('/<svg\b/i', '<svg style="width:100%;height:auto;display:block;margin:0 auto"', $svg, 1);
    }
    return $svg;
}

// počet stran PDF přes GhostScript (starý PDF interpret kvůli runpdfbegin)
function gsPageCount(string $gs, string $pdf): int
{
    $ps  = '(' . str_replace('\\', '/', $pdf) . ') (r) file runpdfbegin pdfpagecount = quit';
    $cmd = implode(' ', array_map('escapeshellarg', [$gs, '-q', '-dNODISPLAY', '-dNEWPDF=false', '-dNOSAFER', '-c', $ps])) . ' 2>&1';
    @exec($cmd, $o, $rc);
    foreach ($o as $l) { if (preg_match('/^\d+$/', trim($l))) { return (int) trim($l); } }
    return 0;
}

function renderDocumentChrome(string $mdPath): array
{
    global $OUT_DIR, $SRC_DIR, $AUTHOR, $COMPANY, $BRAND, $CFG, $TOOLS_DIR;

    $chrome = chromeExe();
    $gs     = ghostscriptExe();
    if ($chrome === null) { throw new RuntimeException("Chrome/Edge nenalezen (nastav 'chrome'=>['exe'=>…] nebo env MD2PDF_CHROME; případně 'renderer'=>'mpdf')"); }
    if ($gs === null)     { throw new RuntimeException("GhostScript nenalezen (nastav 'chrome'=>['gs'=>…]; případně 'renderer'=>'mpdf')"); }

    $base = pathinfo($mdPath, PATHINFO_FILENAME);
    $md   = file_get_contents($mdPath);
    if ($md === false) { throw new RuntimeException("Nelze číst {$mdPath}"); }
    $md = str_replace("\xEF\xBB\xBF", '', $md);

    $meta  = parseMeta($md);
    $split = splitTitle($md);
    $title = $split['title'];

    // poznámky pod čarou: vyřízni definice, očísluj reference (před parserem)
    $fn = preprocessFootnotes($split['body']);
    $GLOBALS['FN_ORDER'] = $fn['order'];

    // mermaid → vektorové SVG (placeholder ![](mermaidsvg:N), pak inline)
    $svgStore = [];
    $body = $fn['body'];
    if (($CFG['mermaid']['enabled'] ?? true) !== false && strpos($body, 'mermaid') !== false) {
        $mmdc = locateMmdc();
        if ($mmdc) {
            $body = preg_replace_callback(
                '/^[ \t]*`{3,}[ \t]*mermaid[ \t]*\r?\n(.*?)\r?\n[ \t]*`{3,}[ \t]*$/ms',
                function ($m) use (&$svgStore, $mmdc) {
                    $svg = renderMermaidToSvg($m[1], $mmdc);
                    if ($svg === null) { return $m[0]; }
                    $i = count($svgStore); $svgStore[$i] = $svg;
                    return '![](mermaidsvg:' . $i . ')';
                },
                $body
            );
        }
    }

    $parsed       = mdToHtml($body);
    $bodyHtml     = applyGlyphSubstitutions($parsed['html']);
    $toc          = $parsed['toc'];
    $maxCodeWidth = $parsed['maxCodeWidth'];

    $bodyHtml = preg_replace_callback('/<img\s+src="mermaidsvg:(\d+)"[^>]*>/i',
        function ($m) use ($svgStore) {
            $i = (int) $m[1];
            return isset($svgStore[$i]) ? responsiveSvg(file_get_contents($svgStore[$i])) : '';
        }, $bodyHtml);
    $bodyHtml = str_replace('<!--/fig-->', '', $bodyHtml);
    $bodyHtml = preg_replace('~<hr\s*/?>\s*(?=<h[12]\b)~', '', $bodyHtml);
    $bodyHtml = preg_replace('~<hr\s*/?>\s*$~', '', $bodyHtml);
    $bodyHtml .= renderFootnotesHtml($fn['order'], $fn['defs']);

    $codeFontPt = computeCodeFontPt($maxCodeWidth);
    $css = buildCss(8.9, $codeFontPt);

    // ---- titulní strana + TOC (HTML fragment) ----
    $genDate = date($CFG['date_format'] ?? 'j. n. Y');
    $purpose = $meta['purpose'] ? '<div class="cover-purpose">' . mdInline($meta['purpose']) . '</div>' : '';
    $purpose = applyGlyphSubstitutions($purpose);
    $MS   = $CFG['strings']['meta'] ?? [];
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
    $dkRaw   = (string) ($CFG['doc_kind'] ?? '');
    $dk      = htmlspecialchars($dkRaw, ENT_QUOTES, 'UTF-8');
    $eyebrow = $BRAND !== ''
        ? ($dk !== '' ? $dk . ' &#183; ' : '') . htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8')
        : $dk;
    $logoSvg = $CFG['logo']['svg'] ?? null;
    $logoPng = $CFG['logo']['png'] ?? null;
    if ($logoSvg === null && $logoPng === null) {
        $logoSvg = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-clean.svg';
        $logoPng = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-white.png';
    }
    $logoFile = ($logoSvg && is_file($logoSvg)) ? $logoSvg : (($logoPng && is_file($logoPng)) ? $logoPng : null);
    $logoHtml = '';
    if ($logoFile !== null) {
        $logoUrl  = 'file:///' . str_replace('\\', '/', $logoFile);
        $logoHtml = '<div class="cover-logo-bottom"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" /></div>';
    }
    $cover = <<<HTML
<div class="cover">
  <div class="cover-band">
    <div class="cover-rule"></div>
    <div class="cover-eyebrow">{$eyebrow}</div>
    <h1 class="cover-title">{$title}</h1>
    {$purpose}
    <table class="cover-meta" cellspacing="0" cellpadding="0">
      {$metaRows}
    </table>
    {$logoHtml}
  </div>
</div>
HTML;

    $tocH2   = array_values(array_filter($toc, fn ($t) => $t['level'] === 2));
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

    // ---- CSS pro Chrome: full-bleed titulka, okraje těla řídí puppeteer ----
    $fontsDir = str_replace('\\', '/', $TOOLS_DIR) . '/fonts';
    $fontFace = '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-Regular.ttf");font-weight:normal;font-style:normal;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-Bold.ttf");font-weight:bold;font-style:normal;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-It.ttf");font-weight:normal;font-style:italic;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-BoldIt.ttf");font-weight:bold;font-style:italic;font-display:block;}'
        . '@font-face{font-family:"cascadiamono";src:url("file:///' . $fontsDir . '/CascadiaMono.ttf");font-display:block;}'
        . '*{ -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }';
    $coverCss = '@page{ size:A4; margin:0; }'
        // Bleed: band i body mírně přes okraj (overflow:hidden ořízne) — Chrome
        // při margin:0 občas nechá sub-pixel proužek vpravo; přesah ho přebije.
        . 'html,body{ margin:0; padding:0; width:101%; height:297mm; overflow:hidden; background:#4c1d95; }'
        . '.cover{ page-break-after:auto; }'
        . '.cover-band{ box-sizing:border-box; width:101%; height:297mm; display:flex; flex-direction:column; }'
        . '.cover-logo-bottom{ margin-top:auto; text-align:center; padding-top:8mm; }'
        . '.cover-logo-bottom img{ width:42mm; }';
    $mainCss = '@page{ size:A4; }'
        . 'html,body{ margin:0; padding:0; }'
        . '.toc{ page-break-after:always; }'
        . '.toc-title{ margin-top:0; }'
        . '.fig svg{ max-width:100%; height:auto; }'
        . '.fig img{ box-sizing:border-box; max-width:100%; }'
        // nadpisy začínající čerstvou stranu (zlom .pb, první za obsahem,
        // nebo úplně první v těle) → bez horního marginu, ať sedí konzistentně
        . 'h1.pb, h2.pb,'
        . '.toc + h1, .toc + h2,'
        . 'body > h1:first-child, body > h2:first-child{ margin-top:0; padding-top:0; }';
    $mkDoc = function (string $extraCss, string $inner) use ($css, $fontFace): string {
        return "<!DOCTYPE html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\">\n<style>\n"
            . $css . "\n" . $fontFace . "\n" . $extraCss . "\n</style>\n</head>\n<body>\n"
            . $inner . "\n</body></html>\n";
    };

    // ---- temp pracovní adresář (mezivýstupy se po sobě uklidí) ----
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'md2pdf-chrome-' . getmypid() . '-' . substr(sha1($mdPath), 0, 8);
    @mkdir($work, 0775, true);
    $coverHtml = $work . DIRECTORY_SEPARATOR . 'cover.html';
    $mainHtml  = $work . DIRECTORY_SEPARATOR . 'main.html';
    $coverPdf  = $work . DIRECTORY_SEPARATOR . 'cover.pdf';
    $mainPdf   = $work . DIRECTORY_SEPARATOR . 'main.pdf';
    $coverJob  = $work . DIRECTORY_SEPARATOR . 'cover-job.json';
    $mainJob   = $work . DIRECTORY_SEPARATOR . 'main-job.json';
    $tmpFinal  = $work . DIRECTORY_SEPARATOR . 'final.pdf';
    file_put_contents($coverHtml, $mkDoc($coverCss, $cover));
    file_put_contents($mainHtml,  $mkDoc($mainCss, $tocHtml . "\n" . $bodyHtml));

    // ---- header/footer šablony pro tělo (systémový font — izolovaný kontext) ----
    $pageLabel = htmlspecialchars($CFG['strings']['page_label'] ?? 'Strana', ENT_QUOTES, 'UTF-8');
    $hBrand = htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8');
    $hTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $hComp  = htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8');
    $footKind  = $dkRaw !== '' ? mb_strtolower($dkRaw, 'UTF-8') : '';
    $footRight = ($footKind !== '' ? htmlspecialchars($footKind, ENT_QUOTES, 'UTF-8') . ' &#183; ' : '') . $genDate;
    $headerTpl = '<div style="font-family:Arial,sans-serif;font-size:8pt;color:#6b7280;width:100%;padding:0 18mm;box-sizing:border-box;">'
        . '<table style="width:100%;border-collapse:collapse;border-bottom:0.5pt solid #d1d5db;"><tr>'
        . '<td style="text-align:left;padding-bottom:1mm;"><span style="color:#4c1d95;font-weight:bold;">' . $hBrand . '</span>'
        . ($hBrand !== '' ? ' &#183; ' : '') . $hTitle . '</td>'
        . '<td style="text-align:right;color:#4c1d95;font-weight:bold;padding-bottom:1mm;width:40mm;">' . $hComp . '</td>'
        . '</tr></table></div>';
    $footerTpl = '<div style="font-family:Arial,sans-serif;font-size:8pt;color:#6b7280;width:100%;padding:0 18mm;box-sizing:border-box;">'
        . '<table style="width:100%;border-collapse:collapse;border-top:0.5pt solid #d1d5db;"><tr>'
        . '<td style="text-align:left;color:#4c1d95;font-weight:bold;padding-top:1mm;">' . $hComp . '</td>'
        . '<td style="text-align:center;padding-top:1mm;">' . $pageLabel . ' <span class="pageNumber"></span> / <span class="totalPages"></span></td>'
        . '<td style="text-align:right;padding-top:1mm;">' . $footRight . '</td>'
        . '</tr></table></div>';

    $margins = $CFG['chrome']['margins'] ?? ['top' => '22mm', 'bottom' => '16mm', 'left' => '18mm', 'right' => '18mm'];
    file_put_contents($coverJob, json_encode([
        'html' => str_replace('\\', '/', $coverHtml), 'out' => str_replace('\\', '/', $coverPdf),
        'chrome' => $chrome, 'margin' => ['top' => '0', 'bottom' => '0', 'left' => '0', 'right' => '0'],
        'displayHeaderFooter' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents($mainJob, json_encode([
        'html' => str_replace('\\', '/', $mainHtml), 'out' => str_replace('\\', '/', $mainPdf),
        'chrome' => $chrome, 'margin' => $margins,
        'displayHeaderFooter' => true, 'headerTemplate' => $headerTpl, 'footerTemplate' => $footerTpl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // ---- render obou částí přes Node/puppeteer ----
    $nodeRun = function (string $jobPath) use ($TOOLS_DIR) {
        $cmd = implode(' ', array_map('escapeshellarg', ['node', $TOOLS_DIR . '/render-pdf.mjs', $jobPath])) . ' 2>&1';
        @exec($cmd, $o, $rc);
        return [$rc, $o];
    };
    [$rc1, $o1] = $nodeRun($coverJob);
    [$rc2, $o2] = $nodeRun($mainJob);
    if (!is_file($coverPdf) || !is_file($mainPdf)) {
        throw new RuntimeException("Chrome render selhal (cover rc={$rc1}, main rc={$rc2}): " . implode(' | ', array_merge($o1, $o2)));
    }

    // ---- GhostScript: spoj titulku+tělo + optimalizace (subset fontů, komprese,
    //      volitelný downsample obrázků na image_dpi — kvalita vs. velikost) ----
    $imgDpi = (int) ($CFG['chrome']['image_dpi'] ?? 200);
    $gsArgs = [$gs, '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/printer',
        '-dSubsetFonts=true', '-dCompressFonts=true', '-dDetectDuplicateImages=true'];
    if ($imgDpi > 0 && $imgDpi < 300) {
        $gsArgs = array_merge($gsArgs, [
            '-dDownsampleColorImages=true', '-dColorImageResolution=' . $imgDpi, '-dColorImageDownsampleThreshold=1.0',
            '-dDownsampleGrayImages=true', '-dGrayImageResolution=' . $imgDpi, '-dGrayImageDownsampleThreshold=1.0',
        ]);
    }
    $gsArgs = array_merge($gsArgs, ['-dNOPAUSE', '-dBATCH', '-dQUIET', '-sOutputFile=' . $tmpFinal, $coverPdf, $mainPdf]);
    $gcmd = implode(' ', array_map('escapeshellarg', $gsArgs)) . ' 2>&1';
    @exec($gcmd, $go, $grc);
    if (!is_file($tmpFinal) || filesize($tmpFinal) === 0) {
        throw new RuntimeException("GhostScript merge selhal (rc={$grc}): " . implode(' | ', $go));
    }

    $pages = gsPageCount($gs, $tmpFinal);

    // ---- atomický zápis do output_dir (retry kvůli Windows zámkům) ----
    $outPath = $OUT_DIR . DIRECTORY_SEPARATOR . $base . '.pdf';
    $pdfData = file_get_contents($tmpFinal);
    $written = false; $lastErr = '';
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $fh = @fopen($outPath, 'wb');
        if ($fh !== false) { fwrite($fh, $pdfData); fclose($fh); $written = true; break; }
        $e = error_get_last(); $lastErr = $e['message'] ?? 'unknown';
        usleep(250000);
    }
    if (!$written) { throw new RuntimeException("Nelze zapsat {$outPath} ({$lastErr})"); }

    // ---- úklid temp ----
    foreach ([$coverHtml, $mainHtml, $coverPdf, $mainPdf, $coverJob, $mainJob, $tmpFinal] as $f) { @unlink($f); }
    @rmdir($work);

    return [
        'base' => $base, 'title' => $title, 'out' => $outPath,
        'pages' => $pages, 'size_kb' => round(filesize($outPath) / 1024, 1),
        'codeFontPt' => round($codeFontPt, 2), 'maxCodeW' => $maxCodeWidth,
        'version' => $meta['version'], 'date' => $meta['date'], 'tocItems' => count($toc),
    ];
}

// =====================================================================
//  Vyrenderuj jeden dokument (mPDF — alternativní renderer 'mpdf')
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

    // poznámky pod čarou: vyřízni definice, očísluj reference (před parserem)
    $fn = preprocessFootnotes($split['body']);
    $GLOBALS['FN_ORDER'] = $fn['order'];

    // mermaid bloky → PNG (před parserem; nahradí se za ![](png))
    $body = preprocessMermaid($fn['body']);

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
    $bodyHtml .= renderFootnotesHtml($fn['order'], $fn['defs']);

    // ---- Velikost písma kódu (default: NEzmenšovat → dlouhé řádky se zalomí) ----
    $codeFontPt = computeCodeFontPt($maxCodeWidth);

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
        // POZN: mPDF respektuje position:absolute jen u PŘÍMÝCH potomků body.
        // Titulka je full-bleed (margin 0 → strana 210×297 mm), logo dole
        // uprostřed ~22 mm nad spodním krajem: top = 297 − 22 − ~19 ≈ 262 mm.
        $logoHtml = '<div style="position:absolute;left:0;top:262mm;width:210mm;text-align:center;">'
                  . '<img src="' . $logoSrc . '" style="width:42mm;" alt="' . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '" />'
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

    // Kerning ZAP — mPDF ho má defaultně vypnutý; Chrome kerní vždy. Bez něj
    // působí text „jinak" (volnější mezery u párů Te/Va/Wa…). Sjednotí to vzhled.
    $mpdf->useKerning = true;

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
    $headerHtml =
        '<table style="width:100%;border-bottom:0.5pt solid #d1d5db;'
        . 'font-size:8pt;color:#6b7280;"><tr>'
        . '<td style="text-align:left;">' . $hdrBrand . $hdrTitle . '</td>'
        . '<td style="text-align:right;width:34mm;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr></table>';

    // Patička: společnost · strana · typ dokumentu + datum (texty z configu)
    $pageLabel  = htmlspecialchars($CFG['strings']['page_label'] ?? 'Strana', ENT_QUOTES, 'UTF-8');
    $footKind   = $dkRaw !== '' ? mb_strtolower($dkRaw, 'UTF-8') : '';
    $footRight  = ($footKind !== '' ? htmlspecialchars($footKind, ENT_QUOTES, 'UTF-8') . ' &#183; ' : '')
                . $genDate;
    $footerHtml =
        '<table style="width:100%;border-top:0.5pt solid #d1d5db;'
        . 'font-size:8pt;color:#6b7280;padding-top:1mm;"><tr>'
        . '<td style="text-align:left;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="text-align:center;">' . $pageLabel . ' {PAGENO} / {nbpg}</td>'
        . '<td style="text-align:right;">' . $footRight . '</td>'
        . '</tr></table>';

    // Pojmenovaná hlavička/patička — přiřadí se až tělu přes AddPageByArray
    // (přiřazení při otevření strany → hlavička se vykreslí; SetHTMLHeader volaný
    // uprostřed strany by se chytl až od další strany).
    $mpdf->DefHTMLHeaderByName('mainhdr', $headerHtml);
    $mpdf->DefHTMLFooterByName('mainftr', $footerHtml);

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    // ---- Titulní strana: FULL-BLEED (margin 0, purpurová přes celou A4) BEZ
    // hlavičky/patičky/čísla stránky — stejně jako chrome renderer. Titulce
    // nepřiřazujeme žádnou hlavičku/patičku → zůstane čistá.
    // Band na výšku celé strany; .cover bez page-breaku (stranu 2 zakládáme ručně).
    $mpdf->WriteHTML('.cover-band{height:297mm;} .cover{page-break-after:auto;}', \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->AddPageByArray([
        'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0,
    ]);
    $mpdf->WriteHTML($cover, \Mpdf\HTMLParserMode::HTML_BODY);

    // ---- Obsah + tělo: zpět normální okraje + běžící hlavička/patička;
    // čísla stran začínají od 1 (resetpagenum), {nbpg} = počet stran v této
    // skupině (bez titulky) → patička jako u chrome („Strana 1 / N").
    $mpdf->AddPageByArray([
        'mgl' => 18, 'mgr' => 18, 'mgt' => 20, 'mgb' => 18, 'mgh' => 8, 'mgf' => 9,
        'ohname' => 'mainhdr', 'ehname' => 'mainhdr',
        'ofname' => 'mainftr', 'efname' => 'mainftr',
        'ohvalue' => 1, 'ehvalue' => 1, 'ofvalue' => 1, 'efvalue' => 1,
        'resetpagenum' => 1,
    ]);
    $mpdf->WriteHTML($tocHtml . $bodyHtml, \Mpdf\HTMLParserMode::HTML_BODY);

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
//  COMBINE režim — VÍCE .md → JEDEN PDF
//  ---------------------------------------------------------------------
//  Aktivuje se klíčem `combine.enabled => true` v configu. Sloučí všechny
//  zdrojové .md (v pořadí dle volitelného `combine.index` souboru, jinak dle
//  glob/abecedy) do JEDNOHO dokumentu: jedna titulní strana (z configu),
//  jeden průběžný obsah (H1 kapitoly + H2 sekce), stránkový zlom před každou
//  kapitolou (H1), cross-chapter `.md` odkazy jako klikací interní kotvy.
//
//  Mimo combine (klíč nezadán) se NIC z tohoto nevolá → engine se chová přesně
//  jako dřív (jeden PDF na soubor).
// =====================================================================

// Pořadí kapitol z index souboru (### skupiny + číslované [název](NN_Name.md)).
// Vrací seznam basenames v požadovaném pořadí; soubory mimo index se doplní
// volajícím na konec.
function combineParseIndexOrder(string $indexPath): array
{
    $order = [];
    if (!is_file($indexPath)) { return $order; }
    foreach (file($indexPath) as $line) {
        $t = trim($line);
        if (preg_match('/^\d+[a-z]?[.)]\s+\[[^\]]+\]\(([^)]+\.md)\)/u', $t, $m)
            || preg_match('/^[-*]\s+\[[^\]]+\]\(([^)]+\.md)\)/u', $t, $m)) {
            $order[] = pathinfo($m[1], PATHINFO_FILENAME);
        }
    }
    return $order;
}

// Seřaď soubory dle indexu; soubory mimo index připoj na konec (abecedně).
function combineOrderFiles(array $files): array
{
    global $CFG, $SRC_DIR;
    $indexName = $CFG['combine']['index'] ?? null;
    $byBase = [];
    foreach ($files as $f) { $byBase[pathinfo($f, PATHINFO_FILENAME)] = $f; }

    if (!$indexName) {
        ksort($byBase, SORT_STRING);
        return array_values($byBase);
    }

    $indexPath = $SRC_DIR . DIRECTORY_SEPARATOR . $indexName;
    $ordered = [];
    foreach (combineParseIndexOrder($indexPath) as $base) {
        if (isset($byBase[$base])) { $ordered[] = $byBase[$base]; unset($byBase[$base]); }
    }
    // zbytek (mimo index) na konec, abecedně
    ksort($byBase, SORT_STRING);
    foreach ($byBase as $f) { $ordered[] = $f; }
    return $ordered;
}

// Sestav tělo sloučeného dokumentu (mapa cross-chapter kotev, spojení, footnotes,
// mermaid dle rendereru). Vrací ['html','toc','maxCodeWidth'].
function buildCombinedBody(array $files, string $renderer): array
{
    global $CFG;

    // 1) mapa base → slug prvního H1 (cíl cross-chapter odkazů)
    $bases = [];
    foreach ($files as $f) {
        $md = str_replace("\xEF\xBB\xBF", '', (string) file_get_contents($f));
        $h1 = null;
        foreach (preg_split('/\r\n|\r|\n/', $md) as $ln) {
            if (preg_match('/^#\s+(.*)$/', trim($ln), $mm)) { $h1 = trim($mm[1]); break; }
        }
        $bases[strtolower(pathinfo($f, PATHINFO_FILENAME))] = $h1 !== null ? mdSlug($h1) : '';
    }
    $GLOBALS['MD2PDF_COMBINE'] = ['bases' => $bases];

    // 2) spoj surová těla (H1 zůstává → kapitolový nadpis + zlom)
    $combined = '';
    foreach ($files as $f) {
        $md = str_replace("\xEF\xBB\xBF", '', (string) file_get_contents($f));
        $combined .= rtrim($md) . "\n\n";
    }

    // 3) poznámky pod čarou přes CELÝ dokument (globální číslování)
    $fn = preprocessFootnotes($combined);
    $GLOBALS['FN_ORDER'] = $fn['order'];

    if ($renderer === 'chrome') {
        // mermaid → vektorové SVG (inline)
        $svgStore = [];
        $body = $fn['body'];
        if (($CFG['mermaid']['enabled'] ?? true) !== false && strpos($body, 'mermaid') !== false) {
            $mmdc = locateMmdc();
            if ($mmdc) {
                $body = preg_replace_callback(
                    '/^[ \t]*`{3,}[ \t]*mermaid[ \t]*\r?\n(.*?)\r?\n[ \t]*`{3,}[ \t]*$/ms',
                    function ($m) use (&$svgStore, $mmdc) {
                        $svg = renderMermaidToSvg($m[1], $mmdc);
                        if ($svg === null) { return $m[0]; }
                        $i = count($svgStore); $svgStore[$i] = $svg;
                        return '![](mermaidsvg:' . $i . ')';
                    },
                    $body
                );
            }
        }
        $parsed   = mdToHtml($body);
        $bodyHtml = applyGlyphSubstitutions($parsed['html']);
        $bodyHtml = preg_replace_callback('/<img\s+src="mermaidsvg:(\d+)"[^>]*>/i',
            function ($m) use ($svgStore) {
                $i = (int) $m[1];
                return isset($svgStore[$i]) ? responsiveSvg(file_get_contents($svgStore[$i])) : '';
            }, $bodyHtml);
    } else {
        $body     = preprocessMermaid($fn['body']);  // mermaid → PNG
        $parsed   = mdToHtml($body);
        $bodyHtml = applyGlyphSubstitutions($parsed['html']);
    }

    $bodyHtml = str_replace('<!--/fig-->', '', $bodyHtml);
    $bodyHtml = preg_replace('~<hr\s*/?>\s*(?=<h[12]\b)~', '', $bodyHtml);
    $bodyHtml = preg_replace('~<hr\s*/?>\s*$~', '', $bodyHtml);
    $bodyHtml .= renderFootnotesHtml($fn['order'], $fn['defs']);

    return ['html' => $bodyHtml, 'toc' => $parsed['toc'], 'maxCodeWidth' => $parsed['maxCodeWidth']];
}

// Titulní blok pro combine (eyebrow/titul/podtitul/meta řádky z configu).
function combineCoverParts(): array
{
    global $CFG, $AUTHOR, $COMPANY, $BRAND;
    $C        = $CFG['combine'] ?? [];
    $title    = htmlspecialchars((string) ($C['title'] ?? ($CFG['strings']['default_title'] ?? 'Dokument')), ENT_QUOTES, 'UTF-8');
    $subtitle = (string) ($C['subtitle'] ?? '');
    $purpose  = $subtitle !== ''
        ? '<div class="cover-purpose">' . applyGlyphSubstitutions(mdInline($subtitle)) . '</div>'
        : '';

    $dkRaw   = (string) ($CFG['doc_kind'] ?? '');
    $dk      = htmlspecialchars($dkRaw, ENT_QUOTES, 'UTF-8');
    $eyebrow = $BRAND !== ''
        ? ($dk !== '' ? $dk . ' &#183; ' : '') . htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8')
        : $dk;

    $today = date($CFG['date_format'] ?? 'j. n. Y');
    // Vlastní řádky: combine.meta_rows = [[label, valueHtml], …] (value smí
    // obsahovat HTML/odkazy; token {date} se nahradí datem). Jinak default.
    $rows = $C['meta_rows'] ?? null;
    if ($rows === null) {
        $MS   = $CFG['strings']['meta'] ?? [];
        $rows = [];
        if ($AUTHOR !== '')  { $rows[] = [$MS['author'] ?? 'Autor', htmlspecialchars($AUTHOR, ENT_QUOTES, 'UTF-8')]; }
        if ($COMPANY !== '') { $rows[] = [$MS['company'] ?? 'Společnost', htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8')]; }
        $rows[] = [$MS['generated'] ?? 'Vygenerováno', $today];
    } else {
        foreach ($rows as &$r) { $r[1] = str_replace('{date}', $today, (string) $r[1]); }
        unset($r);
    }
    $metaRows = '';
    $last     = count($rows) - 1;
    foreach ($rows as $i => $r) {
        $cls = $i === $last ? ' class="last"' : '';
        $metaRows .= '<tr' . $cls . '><td class="cover-meta-label">'
                  . htmlspecialchars((string) $r[0], ENT_QUOTES, 'UTF-8') . '</td>'
                  . '<td class="cover-meta-value">' . $r[1] . '</td></tr>';
    }
    return ['eyebrow' => $eyebrow, 'title' => $title, 'purpose' => $purpose, 'metaRows' => $metaRows];
}

// Průběžný obsah pro combine (H1 kapitoly tučně, H2 sekce odsazené).
function combineTocHtml(array $toc): string
{
    global $CFG;
    if (!$toc) { return ''; }
    $tocTitle = htmlspecialchars($CFG['strings']['toc_title'] ?? 'Obsah', ENT_QUOTES, 'UTF-8');
    $h = '<div class="toc"><div class="toc-title">' . $tocTitle . '</div>' . "\n";
    foreach ($toc as $t) {
        $txt = applyGlyphSubstitutions(mdInline($t['text']));
        $cls = ((int) $t['level'] === 1) ? 'toc-h1' : 'toc-h2 toc-h2-sub';
        $h  .= '<div class="' . $cls . '"><a href="#' . $t['slug'] . '">' . $txt . "</a></div>\n";
    }
    $h .= "</div>\n";
    return $h;
}

// Doplňkové CSS pro combine TOC (dvě úrovně) — připojí se k hlavnímu CSS.
function combineTocCss(): string
{
    return '.toc-h1 { margin: 4mm 0 1mm 0; font-size: 12pt; font-weight: 700; }'
        . '.toc-h1 a { color: #4c1d95; text-decoration: none; }'
        . '.toc-h2-sub { margin: 1mm 0 1mm 6mm; font-size: 10.2pt; }'
        . '.toc-h2-sub a { color: #4b5563; text-decoration: none; }';
}

// ---- COMBINE: mPDF renderer -------------------------------------------
function renderCombined(array $files): array
{
    global $OUT_DIR, $AUTHOR, $COMPANY, $BRAND, $CFG, $TOOLS_DIR;

    $built        = buildCombinedBody($files, 'mpdf');
    $bodyHtml     = $built['html'];
    $toc          = $built['toc'];
    $maxCodeWidth = $built['maxCodeWidth'];

    $codeFontPt = computeCodeFontPt($maxCodeWidth);
    $css = buildCss(8.9, $codeFontPt);

    $cov     = combineCoverParts();
    $genDate = date($CFG['date_format'] ?? 'j. n. Y');

    // logo (stejně jako jednodokumentový mPDF renderer)
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
        $logoHtml = '<div style="position:absolute;left:0;top:262mm;width:210mm;text-align:center;">'
                  . '<img src="' . $logoSrc . '" style="width:42mm;" alt="' . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '" />'
                  . '</div>';
    }

    $cover = <<<HTML
{$logoHtml}
<div class="cover">
  <div class="cover-band">
    <div class="cover-rule"></div>
    <div class="cover-eyebrow">{$cov['eyebrow']}</div>
    <h1 class="cover-title">{$cov['title']}</h1>
    {$cov['purpose']}
    <table class="cover-meta" cellspacing="0" cellpadding="0">
      {$cov['metaRows']}
    </table>
  </div>
</div>
HTML;

    $tocHtml = combineTocHtml($toc);

    $tmpDir = sys_get_temp_dir() . '/md2pdf-mpdf';
    @mkdir($tmpDir, 0775, true);

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
        'useSubstitutions'  => true,
        'backupSubsFont'    => ['dejavusans', 'dejavusansmono'],
    ]);

    $title = html_entity_decode($cov['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $mpdf->SetTitle($title);
    if ($AUTHOR !== '') { $mpdf->SetAuthor($AUTHOR); }
    $mpdf->SetCreator(($BRAND !== '' ? $BRAND . ' ' : '') . 'md2pdf.php (mPDF)');
    $mpdf->SetSubject($BRAND !== '' ? $BRAND . ' — ' . $title : $title);

    $mpdf->useKerning = true;
    $mpdf->shrink_tables_to_fit = 1;
    $mpdf->use_kwt = true;
    $mpdf->defaultheaderline = 0;
    $mpdf->defaultfooterline = 0;

    $dkRaw    = (string) ($CFG['doc_kind'] ?? '');
    $hdrTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $hdrBrand = $BRAND !== ''
        ? '<span style="color:#4c1d95;font-weight:700;">' . htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8') . '</span> &nbsp;&#183;&nbsp; '
        : '';
    $headerHtml =
        '<table style="width:100%;border-bottom:0.5pt solid #d1d5db;font-size:8pt;color:#6b7280;"><tr>'
        . '<td style="text-align:left;">' . $hdrBrand . $hdrTitle . '</td>'
        . '<td style="text-align:right;width:34mm;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr></table>';

    $pageLabel = htmlspecialchars($CFG['strings']['page_label'] ?? 'Strana', ENT_QUOTES, 'UTF-8');
    $footKind  = $dkRaw !== '' ? mb_strtolower($dkRaw, 'UTF-8') : '';
    $footRight = ($footKind !== '' ? htmlspecialchars($footKind, ENT_QUOTES, 'UTF-8') . ' &#183; ' : '') . $genDate;
    $footerHtml =
        '<table style="width:100%;border-top:0.5pt solid #d1d5db;font-size:8pt;color:#6b7280;padding-top:1mm;"><tr>'
        . '<td style="text-align:left;color:#4c1d95;font-weight:700;">'
        . htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="text-align:center;">' . $pageLabel . ' {PAGENO} / {nbpg}</td>'
        . '<td style="text-align:right;">' . $footRight . '</td>'
        . '</tr></table>';

    $mpdf->DefHTMLHeaderByName('mainhdr', $headerHtml);
    $mpdf->DefHTMLFooterByName('mainftr', $footerHtml);

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(combineTocCss(), \Mpdf\HTMLParserMode::HEADER_CSS);

    // Titulka full-bleed bez furniture
    $mpdf->WriteHTML('.cover-band{height:297mm;} .cover{page-break-after:auto;}', \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->AddPageByArray(['mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0]);
    $mpdf->WriteHTML($cover, \Mpdf\HTMLParserMode::HTML_BODY);

    // Obsah + tělo s běžícími okraji + hlavičkou/patičkou
    $mpdf->AddPageByArray([
        'mgl' => 18, 'mgr' => 18, 'mgt' => 20, 'mgb' => 18, 'mgh' => 8, 'mgf' => 9,
        'ohname' => 'mainhdr', 'ehname' => 'mainhdr',
        'ofname' => 'mainftr', 'efname' => 'mainftr',
        'ohvalue' => 1, 'ehvalue' => 1, 'ofvalue' => 1, 'efvalue' => 1,
        'resetpagenum' => 1,
    ]);
    $mpdf->WriteHTML($tocHtml . $bodyHtml, \Mpdf\HTMLParserMode::HTML_BODY);

    $outName = (string) ($CFG['combine']['output'] ?? 'combined.pdf');
    $outPath = $OUT_DIR . DIRECTORY_SEPARATOR . $outName;
    $pdfData = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    $written = false; $lastErr = '';
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $fh = @fopen($outPath, 'wb');
        if ($fh !== false) { fwrite($fh, $pdfData); fclose($fh); $written = true; break; }
        $e = error_get_last(); $lastErr = $e['message'] ?? 'unknown';
        usleep(250000);
    }
    if (!$written) { throw new RuntimeException("Nelze zapsat {$outPath} ({$lastErr})"); }

    return [
        'base' => pathinfo($outName, PATHINFO_FILENAME), 'title' => $title, 'out' => $outPath,
        'pages' => $mpdf->page, 'size_kb' => round(filesize($outPath) / 1024, 1),
        'codeFontPt' => round($codeFontPt, 2), 'maxCodeW' => $maxCodeWidth,
        'chapters' => count($files), 'tocItems' => count($toc),
    ];
}

// ---- COMBINE: Chrome renderer -----------------------------------------
function renderCombinedChrome(array $files): array
{
    global $OUT_DIR, $AUTHOR, $COMPANY, $BRAND, $CFG, $TOOLS_DIR;

    $chrome = chromeExe();
    $gs     = ghostscriptExe();
    if ($chrome === null) { throw new RuntimeException("Chrome/Edge nenalezen ('renderer'=>'mpdf' nebo nastav 'chrome'=>['exe'=>…])"); }
    if ($gs === null)     { throw new RuntimeException("GhostScript nenalezen ('renderer'=>'mpdf' nebo nastav 'chrome'=>['gs'=>…])"); }

    $built        = buildCombinedBody($files, 'chrome');
    $bodyHtml     = $built['html'];
    $toc          = $built['toc'];
    $maxCodeWidth = $built['maxCodeWidth'];

    $codeFontPt = computeCodeFontPt($maxCodeWidth);
    $css = buildCss(8.9, $codeFontPt);

    $cov     = combineCoverParts();
    $title   = html_entity_decode($cov['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $genDate = date($CFG['date_format'] ?? 'j. n. Y');

    $logoSvg = $CFG['logo']['svg'] ?? null;
    $logoPng = $CFG['logo']['png'] ?? null;
    if ($logoSvg === null && $logoPng === null) {
        $logoSvg = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-clean.svg';
        $logoPng = $TOOLS_DIR . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo-white.png';
    }
    $logoFile = ($logoSvg && is_file($logoSvg)) ? $logoSvg : (($logoPng && is_file($logoPng)) ? $logoPng : null);
    $logoHtml = '';
    if ($logoFile !== null) {
        $logoUrl  = 'file:///' . str_replace('\\', '/', $logoFile);
        $logoHtml = '<div class="cover-logo-bottom"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" /></div>';
    }
    $cover = <<<HTML
<div class="cover">
  <div class="cover-band">
    <div class="cover-rule"></div>
    <div class="cover-eyebrow">{$cov['eyebrow']}</div>
    <h1 class="cover-title">{$cov['title']}</h1>
    {$cov['purpose']}
    <table class="cover-meta" cellspacing="0" cellpadding="0">
      {$cov['metaRows']}
    </table>
    {$logoHtml}
  </div>
</div>
HTML;

    $tocHtml = combineTocHtml($toc);

    $fontsDir = str_replace('\\', '/', $TOOLS_DIR) . '/fonts';
    $fontFace = '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-Regular.ttf");font-weight:normal;font-style:normal;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-Bold.ttf");font-weight:bold;font-style:normal;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-It.ttf");font-weight:normal;font-style:italic;font-display:block;}'
        . '@font-face{font-family:"sourcesans";src:url("file:///' . $fontsDir . '/SourceSans3-BoldIt.ttf");font-weight:bold;font-style:italic;font-display:block;}'
        . '@font-face{font-family:"cascadiamono";src:url("file:///' . $fontsDir . '/CascadiaMono.ttf");font-display:block;}'
        . '*{ -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }';
    $coverCss = '@page{ size:A4; margin:0; }'
        . 'html,body{ margin:0; padding:0; width:101%; height:297mm; overflow:hidden; background:#4c1d95; }'
        . '.cover{ page-break-after:auto; }'
        . '.cover-band{ box-sizing:border-box; width:101%; height:297mm; display:flex; flex-direction:column; }'
        . '.cover-logo-bottom{ margin-top:auto; text-align:center; padding-top:8mm; }'
        . '.cover-logo-bottom img{ width:42mm; }';
    $mainCss = '@page{ size:A4; }'
        . 'html,body{ margin:0; padding:0; }'
        . '.toc{ page-break-after:always; }'
        . '.toc-title{ margin-top:0; }'
        . combineTocCss()
        . '.fig svg{ max-width:100%; height:auto; }'
        . '.fig img{ box-sizing:border-box; max-width:100%; }'
        . 'h1.pb, h2.pb,'
        . '.toc + h1, .toc + h2,'
        . 'body > h1:first-child, body > h2:first-child{ margin-top:0; padding-top:0; }';
    $mkDoc = function (string $extraCss, string $inner) use ($css, $fontFace): string {
        return "<!DOCTYPE html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\">\n<style>\n"
            . $css . "\n" . $fontFace . "\n" . $extraCss . "\n</style>\n</head>\n<body>\n"
            . $inner . "\n</body></html>\n";
    };

    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'md2pdf-chrome-combine-' . getmypid();
    @mkdir($work, 0775, true);
    $coverHtml = $work . DIRECTORY_SEPARATOR . 'cover.html';
    $mainHtml  = $work . DIRECTORY_SEPARATOR . 'main.html';
    $coverPdf  = $work . DIRECTORY_SEPARATOR . 'cover.pdf';
    $mainPdf   = $work . DIRECTORY_SEPARATOR . 'main.pdf';
    $coverJob  = $work . DIRECTORY_SEPARATOR . 'cover-job.json';
    $mainJob   = $work . DIRECTORY_SEPARATOR . 'main-job.json';
    $tmpFinal  = $work . DIRECTORY_SEPARATOR . 'final.pdf';
    file_put_contents($coverHtml, $mkDoc($coverCss, $cover));
    file_put_contents($mainHtml,  $mkDoc($mainCss, $tocHtml . "\n" . $bodyHtml));

    $pageLabel = htmlspecialchars($CFG['strings']['page_label'] ?? 'Strana', ENT_QUOTES, 'UTF-8');
    $dkRaw  = (string) ($CFG['doc_kind'] ?? '');
    $hBrand = htmlspecialchars($BRAND, ENT_QUOTES, 'UTF-8');
    $hTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $hComp  = htmlspecialchars($COMPANY, ENT_QUOTES, 'UTF-8');
    $footKind  = $dkRaw !== '' ? mb_strtolower($dkRaw, 'UTF-8') : '';
    $footRight = ($footKind !== '' ? htmlspecialchars($footKind, ENT_QUOTES, 'UTF-8') . ' &#183; ' : '') . $genDate;
    $headerTpl = '<div style="font-family:Arial,sans-serif;font-size:8pt;color:#6b7280;width:100%;padding:0 18mm;box-sizing:border-box;">'
        . '<table style="width:100%;border-collapse:collapse;border-bottom:0.5pt solid #d1d5db;"><tr>'
        . '<td style="text-align:left;padding-bottom:1mm;"><span style="color:#4c1d95;font-weight:bold;">' . $hBrand . '</span>'
        . ($hBrand !== '' ? ' &#183; ' : '') . $hTitle . '</td>'
        . '<td style="text-align:right;color:#4c1d95;font-weight:bold;padding-bottom:1mm;width:40mm;">' . $hComp . '</td>'
        . '</tr></table></div>';
    $footerTpl = '<div style="font-family:Arial,sans-serif;font-size:8pt;color:#6b7280;width:100%;padding:0 18mm;box-sizing:border-box;">'
        . '<table style="width:100%;border-collapse:collapse;border-top:0.5pt solid #d1d5db;"><tr>'
        . '<td style="text-align:left;color:#4c1d95;font-weight:bold;padding-top:1mm;">' . $hComp . '</td>'
        . '<td style="text-align:center;padding-top:1mm;">' . $pageLabel . ' <span class="pageNumber"></span> / <span class="totalPages"></span></td>'
        . '<td style="text-align:right;padding-top:1mm;">' . $footRight . '</td>'
        . '</tr></table></div>';

    $margins = $CFG['chrome']['margins'] ?? ['top' => '22mm', 'bottom' => '16mm', 'left' => '18mm', 'right' => '18mm'];
    file_put_contents($coverJob, json_encode([
        'html' => str_replace('\\', '/', $coverHtml), 'out' => str_replace('\\', '/', $coverPdf),
        'chrome' => $chrome, 'margin' => ['top' => '0', 'bottom' => '0', 'left' => '0', 'right' => '0'],
        'displayHeaderFooter' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents($mainJob, json_encode([
        'html' => str_replace('\\', '/', $mainHtml), 'out' => str_replace('\\', '/', $mainPdf),
        'chrome' => $chrome, 'margin' => $margins,
        'displayHeaderFooter' => true, 'headerTemplate' => $headerTpl, 'footerTemplate' => $footerTpl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $nodeRun = function (string $jobPath) use ($TOOLS_DIR) {
        $cmd = implode(' ', array_map('escapeshellarg', ['node', $TOOLS_DIR . '/render-pdf.mjs', $jobPath])) . ' 2>&1';
        @exec($cmd, $o, $rc);
        return [$rc, $o];
    };
    [$rc1, $o1] = $nodeRun($coverJob);
    [$rc2, $o2] = $nodeRun($mainJob);
    if (!is_file($coverPdf) || !is_file($mainPdf)) {
        throw new RuntimeException("Chrome render selhal (cover rc={$rc1}, main rc={$rc2}): " . implode(' | ', array_merge($o1, $o2)));
    }

    $imgDpi = (int) ($CFG['chrome']['image_dpi'] ?? 200);
    $gsArgs = [$gs, '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/printer',
        '-dSubsetFonts=true', '-dCompressFonts=true', '-dDetectDuplicateImages=true'];
    if ($imgDpi > 0 && $imgDpi < 300) {
        $gsArgs = array_merge($gsArgs, [
            '-dDownsampleColorImages=true', '-dColorImageResolution=' . $imgDpi, '-dColorImageDownsampleThreshold=1.0',
            '-dDownsampleGrayImages=true', '-dGrayImageResolution=' . $imgDpi, '-dGrayImageDownsampleThreshold=1.0',
        ]);
    }
    $gsArgs = array_merge($gsArgs, ['-dNOPAUSE', '-dBATCH', '-dQUIET', '-sOutputFile=' . $tmpFinal, $coverPdf, $mainPdf]);
    $gcmd = implode(' ', array_map('escapeshellarg', $gsArgs)) . ' 2>&1';
    @exec($gcmd, $go, $grc);
    if (!is_file($tmpFinal) || filesize($tmpFinal) === 0) {
        throw new RuntimeException("GhostScript merge selhal (rc={$grc}): " . implode(' | ', $go));
    }
    $pages = gsPageCount($gs, $tmpFinal);

    $outName = (string) ($CFG['combine']['output'] ?? 'combined.pdf');
    $outPath = $OUT_DIR . DIRECTORY_SEPARATOR . $outName;
    $pdfData = file_get_contents($tmpFinal);
    $written = false; $lastErr = '';
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $fh = @fopen($outPath, 'wb');
        if ($fh !== false) { fwrite($fh, $pdfData); fclose($fh); $written = true; break; }
        $e = error_get_last(); $lastErr = $e['message'] ?? 'unknown';
        usleep(250000);
    }
    if (!$written) { throw new RuntimeException("Nelze zapsat {$outPath} ({$lastErr})"); }

    foreach ([$coverHtml, $mainHtml, $coverPdf, $mainPdf, $coverJob, $mainJob, $tmpFinal] as $f) { @unlink($f); }
    @rmdir($work);

    return [
        'base' => pathinfo($outName, PATHINFO_FILENAME), 'title' => $title, 'out' => $outPath,
        'pages' => $pages, 'size_kb' => round(filesize($outPath) / 1024, 1),
        'codeFontPt' => round($codeFontPt, 2), 'maxCodeW' => $maxCodeWidth,
        'chapters' => count($files), 'tocItems' => count($toc),
    ];
}

// Knihovní režim: při includu s definovanou konstantou MD2PDF_LIB_ONLY vystav
// jen funkce/proměnné a NEspouštěj CLI main. Pro běžné `php md2pdf.php` je to
// no-op (konstanta není definovaná), takže žádná změna chování.
if (defined('MD2PDF_LIB_ONLY') && MD2PDF_LIB_ONLY) { return; }

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

// Renderer: 'chrome' (default — vektorový mermaid, full-bleed cover) nebo 'mpdf'.
// Když chrome chybí (Chrome/Edge nenalezen), spadne zpět na mPDF.
$renderer = strtolower((string) ($CFG['renderer'] ?? 'mpdf'));
if ($renderer === 'chrome' && chromeExe() === null) {
    fwrite(STDERR, "  (renderer=chrome: Chrome/Edge nenalezen — fallback na mPDF)\n");
    $renderer = 'mpdf';
}

echo ($BRAND !== '' ? $BRAND . ' ' : '') . "md2pdf — zdroj: {$SRC_DIR}\n";
echo "Výstup: {$OUT_DIR}  (renderer: {$renderer})\n";
echo str_repeat('-', 64) . "\n";

// ---- COMBINE režim: VÍCE .md → JEDEN PDF -----------------------------------
// Aktivní jen když config má `combine.enabled => true`. Filtr na jeden basename
// (poziční argument) v combine režimu nedává smysl → ignoruje se. Jinak (klíč
// nezadán) běží níže standardní smyčka jeden-PDF-na-soubor, beze změny.
if (($CFG['combine']['enabled'] ?? false) === true) {
    $ordered = combineOrderFiles($files);
    $outName = (string) ($CFG['combine']['output'] ?? 'combined.pdf');
    echo "Combine: " . count($ordered) . " souborů → {$outName}\n";
    try {
        $r = ($renderer === 'mpdf') ? renderCombined($ordered) : renderCombinedChrome($ordered);
        printf("Hotovo: %s  (%d str, %s kB, %d kapitol, %d TOC, kód %spt/maxW %d)\n",
            $r['out'], $r['pages'], $r['size_kb'], $r['chapters'], $r['tocItems'], $r['codeFontPt'], $r['maxCodeW']);
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "CHYBA: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}

$results = [];
$hadError = false;
foreach ($files as $f) {
    $name = basename($f);
    echo "» {$name} ... ";
    try {
        $r = ($renderer === 'mpdf') ? renderDocument($f) : renderDocumentChrome($f);
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
