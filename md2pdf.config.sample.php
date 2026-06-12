<?php
/**
 * md2pdf.config.sample.php — VZOR konfigurace pro nový projekt
 * ============================================================
 *
 * Tohle je sdílený engine (adresář s MD2PDF). Pro nasazení na projekt:
 *   1) zkopíruj tento soubor do projektu jako `md2pdf.config.php`
 *      (typicky do `<projekt>\tools\`),
 *   2) uprav 'source_dir', 'glob' a identitu/texty,
 *   3) spusť přes tenký spouštěč v projektu, nebo přímo:
 *        pwsh -File <MD2PDF>\export-pdf.ps1 -Config <projekt>\tools\md2pdf.config.php
 *
 * Soubor MUSÍ vracet asociativní pole (`return [ ... ];`).
 * Cesty přes `__DIR__` jsou relativní k UMÍSTĚNÍ tohoto configu.
 */

return [

    // --- Vstup / výstup ---------------------------------------------------
    'source_dir' => __DIR__ . '/../.source', // adresář se zdrojovými .md (READ-ONLY)
    'output_dir' => null,                      // null = {source_dir}/pdf
    'glob'       => '*.md',                     // výběr souborů, např. 'Projekt_*.md'

    // Jak naložit s úvodním blockquote hned po H1:
    //   'meta' (default) = je to metablok (Verze/Datum/Autor/Účel) → vyřízne se
    //                      a hodnoty jdou na titulku
    //   'keep'           = je to obsah → nechá se v těle jako úvodní callout
    'lead_blockquote' => 'meta',

    // Zalamovat po nové kapitole na novou stránku (každá H1/H2 začne na nové
    // straně). true = Ano (default), false = Ne (kapitoly plynou za sebou).
    'chapter_page_break' => true,

    // Stránkový zlom před H2 lze řídit ODDĚLENĚ od H1. Když není zadán, dědí
    // z 'chapter_page_break'. V jednodokumentovém režimu je H1 titul (vyříznut)
    // a kapitoly jsou H2 → ponech default. V COMBINE režimu (níže) jsou kapitoly
    // H1 a H2 jsou sekce uvnitř → nastav 'h2_page_break' => false.
    // 'h2_page_break' => false,

    // Které úrovně nadpisů jdou do obsahu (TOC). Default [2, 3] (sekce + podsekce
    // jednoho dokumentu). V COMBINE režimu typicky [1, 2] (kapitoly + sekce).
    // 'toc_levels' => [2, 3],

    // --- COMBINE: VÍCE .md → JEDEN PDF -----------------------------------
    // Volitelné. Když 'enabled' => true, engine NEgeneruje jeden PDF na soubor,
    // ale sloučí všechny soubory dle 'glob' do JEDNOHO PDF: jedna titulní strana
    // (z klíčů níže, NE z H1 souboru), jeden průběžný obsah, stránkový zlom před
    // každou kapitolou (H1), a cross-chapter `.md` odkazy přepsané na klikací
    // interní kotvy (#slug prvního H1 cílové kapitoly). Když klíč chybí / je
    // false, chování je nezměněné (jeden PDF na soubor).
    //
    // Doporučená souhra kláves pro combine:
    //   'glob'               => '[0-9][0-9]*_*.md'   (jen kapitoly; index řídí pořadí)
    //   'chapter_page_break' => true
    //   'h2_page_break'      => false
    //   'toc_levels'         => [1, 2]
    //
    // 'combine' => [
    //     'enabled'  => true,
    //     'output'   => 'manual.pdf',          // název výsledného PDF v output_dir
    //     'index'    => 'INDEX.md',            // pořadí kapitol z indexu (### skupiny +
    //                                          // číslované [název](NN_Name.md)); soubory
    //                                          // mimo index se připojí na konec abecedně.
    //                                          // null = pořadí dle glob/abecedy.
    //     'title'    => 'Název dokumentu',     // titul na titulce
    //     'subtitle' => 'Krátký podtitul.',    // podtitul (inline markdown povolen)
    //     // Vlastní řádky metadat titulky. value smí obsahovat HTML/odkazy;
    //     // token {date} se nahradí datem generování. Když klíč chybí, použijí
    //     // se default řádky (Autor/Společnost/Vygenerováno).
    //     'meta_rows' => [
    //         ['Dokument', 'Název dokumentu'],
    //         ['Datum',    '{date}'],
    //         ['Web',      '<a href="https://example.com/" style="color:#fff;">example.com</a>'],
    //     ],
    // ],

    // --- Renderer ---------------------------------------------------------
    // 'mpdf' (DEFAULT) = čistě PHP (mPDF), bez externích závislostí; mermaid
    //     jako PNG (mermaid-cli). Menší soubory, žádný Node/Chrome.
    // 'chrome' = sazba přes headless Chrome + GhostScript: vektorový mermaid
    //     (SVG, ostré). Vyžaduje Node (puppeteer — `npm install` v enginu),
    //     Chrome/Edge a GhostScript; když Chrome chybí, spadne zpět na 'mpdf'.
    // Titulní strana je u OBOU stejná: full-bleed, bez hlavičky/patičky/čísla;
    // header/footer + čísla stran běží jen v těle.
    'renderer' => 'mpdf',

    // Nastavení chrome rendereru (uplatní se jen pro 'renderer' => 'chrome').
    'chrome' => [
        'exe'       => null,   // cesta k Chrome/Edge; null = autodetekce / env MD2PDF_CHROME
        'gs'        => null,   // cesta ke GhostScriptu (gswin64c.exe); null = autodetekce
        'image_dpi' => 200,    // downsample RASTROVÝCH obrázků na N dpi. 300 = bez downsamplu
                                // (max kvalita, větší soubor), 150 = nejmenší. Vektor (mermaid)
                                // ani text se NEdownsamplují — ovlivní jen bitmapy.
        'margins'   => ['top' => '22mm', 'bottom' => '16mm', 'left' => '18mm', 'right' => '18mm'],
    ],

    // --- Identita / branding ---------------------------------------------
    'author'      => 'Jméno Příjmení',
    'company'     => 'Firma s.r.o.',
    'brand'       => 'Projekt',
    'doc_kind'    => 'Interní dokument',       // eyebrow na titulce + patička
    'date_format' => 'j. n. Y',                // PHP date()

    // --- Logo (titulka, dole uprostřed) ----------------------------------
    // null = výchozí logo enginu (<MD2PDF>\assets\). Vlastní:
    //   'svg' => __DIR__ . '/logo.svg',
    'logo' => [
        'svg' => null,
        'png' => null,
    ],

    // --- Texty UI (překlad) ----------------------------------------------
    'strings' => [
        'default_title' => 'Dokument',
        'toc_title'     => 'Obsah',
        'page_label'    => 'Strana',
        'meta' => [
            'document'  => 'Dokument',
            'version'   => 'Verze',
            'date'      => 'Datum',
            'author'    => 'Autor',
            'company'   => 'Společnost',
            'generated' => 'Vygenerováno',
        ],
        'label_tip'  => 'TIP:',
        'label_note' => 'POZN:',
    ],

    // --- Parsování metadat ze zdrojového .md (labely v úvodním blockquote) -
    'source_meta_labels' => [
        'version' => 'Verze',
        'date'    => 'Datum',
        'author'  => 'Autor',
        'purpose' => 'Účel',
    ],

    // --- Klíčová slova varovného calloutu (oranžový styl) -----------------
    'warn_keywords' => ['⚠', 'POZOR', 'Upozorn', 'Pozor', 'Varov'],

    // --- Mermaid diagramy (```mermaid bloky → diagram) --------------------
    // Renderer 'chrome' = vektorové SVG (ostré, 'scale' se ignoruje).
    // Renderer 'mpdf'   = PNG přes mermaid-cli (mmdc); 'scale' řídí ostrost.
    // Vyžaduje `npm install` v adresáři enginu (mermaid-cli + Chromium).
    // Když mmdc chybí, bloky se ponechají jako kód. Celá sekce je volitelná.
    'mermaid' => [
        'enabled'    => true,       // false = ```mermaid sázet jako kód
        'mmdc'       => null,        // cesta k mmdc; null = autodetekce (engine/PATH)
        'chrome'     => null,        // cesta k prohlížeči pro mmdc; null = autodetekce
                                      // (engine .puppeteer → systémový Chrome/Edge).
                                      // Lze přepsat i env MD2PDF_CHROME.
        'theme'      => 'default',   // default | neutral | dark | forest | base
        'background' => 'white',     // pozadí (white | transparent | #hex)
        'scale'      => 2.5,         // měřítko PNG renderu (jen renderer 'mpdf')
    ],
];
