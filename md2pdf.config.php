<?php
/**
 * md2pdf.config.php — export vlastního README.md enginu MD2PDF do PDF
 * ===================================================================
 *
 * Slouží zároveň jako „dogfooding" ukázka: engine vysází vlastní dokumentaci.
 * Protože se jmenuje md2pdf.config.php a leží vedle skriptu, je to VÝCHOZÍ
 * config — stačí tedy:
 *   php md2pdf.php
 *   pwsh -File export-pdf.ps1 -Preview
 */

return [

    // --- Vstup / výstup ---------------------------------------------------
    'source_dir' => __DIR__,        // README.md leží v rootu enginu
    'output_dir' => null,             // null = {source_dir}/pdf
    'glob'       => 'README.md',     // jen README

    // Úvodní blockquote v README je obsahový (tagline), ne metablok → nech ho v těle
    'lead_blockquote' => 'keep',

    // README plyne souvisle, nezalamovat každou kapitolu na novou stránku
    'chapter_page_break' => false,

    // --- Renderer ---------------------------------------------------------
    // 'mpdf' (DEFAULT) = čistě PHP (mPDF), bez externích závislostí; mermaid
    //     jako PNG. Menší soubory, žádný Node/Chrome.
    // 'chrome' = sazba přes headless Chrome + GhostScript: vektorový mermaid
    //     (SVG, ostré), header/footer + čísla v těle. Vyžaduje Node (puppeteer
    //     — `npm install`), Chrome/Edge a GhostScript; když Chrome chybí, spadne
    //     zpět na 'mpdf'. Titulní strana je u OBOU stejná (full-bleed, bez furniture).
    'renderer' => 'mpdf',

    // Nastavení chrome rendereru (uplatní se jen pro 'renderer' => 'chrome').
    'chrome' => [
        'exe'       => null,   // cesta k Chrome/Edge; null = autodetekce / env MD2PDF_CHROME
        'gs'        => null,   // cesta ke GhostScriptu (gswin64c.exe); null = autodetekce
        'image_dpi' => 200,    // downsample rastrových obrázků na N dpi (300 = bez downsamplu)
        'margins'   => ['top' => '22mm', 'bottom' => '16mm', 'left' => '18mm', 'right' => '18mm'],
    ],

    // --- Identita / branding ---------------------------------------------
    'author'      => 'Radek Hulán',
    'company'     => 'MyWebdesign.cz s.r.o.',
    'brand'       => 'MD2PDF',
    'doc_kind'    => 'Dokumentace',
    'date_format' => 'j. n. Y',

    // --- Logo: null = výchozí logo enginu --------------------------------
    'logo' => ['svg' => null, 'png' => null],

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

    // --- Parsování metadat ze zdrojového .md -----------------------------
    'source_meta_labels' => [
        'version' => 'Verze',
        'date'    => 'Datum',
        'author'  => 'Autor',
        'purpose' => 'Účel',
    ],

    // --- Klíčová slova varovného calloutu --------------------------------
    'warn_keywords' => ['⚠', 'POZOR', 'Upozorn', 'Pozor', 'Varov'],

    // --- Mermaid: README žádný diagram nemá → vypnuto (rychlejší, bez Chrome)
    'mermaid' => ['enabled' => false],
];
