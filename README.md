# MD2PDF

> Markdown → profesionálně vysázené PDF. Vlastní řádkový Markdown parser + [mPDF](https://mpdf.github.io/), titulní strana, obsah, hlavička/patička, callouty, tabulky, auto-fit ASCII diagramů a **render `mermaid` diagramů**. Vše projektově specifické (cesty, výběr souborů, identita, překlad, logo) žije v jednom `md2pdf.config.php` — engine se nemění.

🇨🇿 **Čeština** (níže) · 🇬🇧 [English](#-md2pdf-english)

## Ukázka generovaného PDF

![](pdf/PDFSample.webp)

*Ukázka generovaného PDF s Mermaid diagramem*

---

## Co to je

`md2pdf.php` je samostatný PHP nástroj, který převede jeden nebo více Markdown souborů na samostatná, profesionálně vysázená PDF (jeden PDF na dokument). Je navržený jako **sdílený engine**: nainstaluješ ho jednou (např. do `c:\work\MD2PDF`) a používáš napříč projekty — každý projekt má jen svůj `md2pdf.config.php`.

## Vlastnosti

- **Vlastní Markdown parser** (nadpisy, seznamy vč. vnořených a checkboxů, tabulky GFM, blockquote/callouty, kód, obrázky, odkazy, HR, inline `**bold**`/`*italic*`/`code`).
- **Titulní strana** s brandingem — titul z `# H1`, podtitul/účel a metadata (verze, datum, autor) z úvodního blockquote, logo dole.
- **Obsah (TOC)** automaticky z `##` nadpisů (když jsou aspoň 4).
- **Hlavička a patička** na každé stránce; texty plně z configu (lokalizace).
- **Callout boxy** z blockquote; „varovné" (oranžové) podle klíčových slov.
- **Mermaid diagramy** — `mermaid` bloky se vyrenderují do PNG přes [mermaid-cli](https://github.com/mermaid-js/mermaid-cli) a vloží jako obrázek.
- **Auto-fit** širokých code-bloků (ASCII diagramy) a tabulek, ať se vejdou na šířku.
- **Embedované volné fonty** (Source Sans 3 + Cascadia Mono + DejaVu záloha) → PDF vypadá všude stejně a je legálně přenositelné.
- **Stránkové zlomy** — každá `# H1` i `## H2` kapitola začíná na nové straně.

## Požadavky

| Nástroj | Verze | K čemu |
|--------|-------|--------|
| PHP | 8.0+ (CLI, s `mbstring`) | běh enginu |
| Composer | — | instalace mPDF |
| Node.js + npm | 18+ | mermaid-cli (jen pro mermaid) |
| Chrome / Edge | jakýkoli Chromium | render mermaidu (jen pro mermaid) |
| GhostScript | — | volitelně PNG náhledy |

Mermaid je **volitelný**: bez Node/prohlížeče se `mermaid` bloky vysází jako kód a vše ostatní funguje.

## Instalace

```bash
git clone <repo> md2pdf
cd md2pdf
composer install          # mPDF do vendor/
npm install               # mermaid-cli (volitelné, jen pro mermaid)
```

Pro mermaid je potřeba Chromium. Engine **automaticky najde** systémový Chrome/Edge. Pokud žádný nemáš, stáhni puppeteerem:

```bash
npx puppeteer browsers install chrome
```

## Rychlý start

1. Zkopíruj vzor configu do svého projektu a uprav ho:

   ```bash
   cp md2pdf.config.sample.php /cesta/k/projektu/md2pdf.config.php
   ```

   Minimálně nastav `source_dir`, `glob` a identitu (`author`/`company`/`brand`).

2. Spusť převod:

   ```bash
   php /cesta/k/md2pdf/md2pdf.php --config=/cesta/k/projektu/md2pdf.config.php
   ```

   Nebo na Windows přes runner (najde PHP, doinstaluje vendor, umí náhledy):

   ```powershell
   pwsh -File c:\work\MD2PDF\export-pdf.ps1 -Config c:\projekt\md2pdf.config.php -Preview
   ```

   Na Linux/macOS ekvivalentně přes `export-pdf.sh`:

   ```bash
   ./export-pdf.sh --config /cesta/k/projektu/md2pdf.config.php --preview
   ```

PDF vzniknou v `output_dir` (defaultně `{source_dir}/pdf`).

### CLI

```
php md2pdf.php                       # všechny soubory dle 'glob' z configu
php md2pdf.php NazevDokumentu        # jen jeden (basename, .md volitelné)
php md2pdf.php --config=jiny.php     # jiná konfigurace
php md2pdf.php --print-config        # vypíše JSON {source_dir,output_dir,glob}
```

Pořadí hledání configu: `--config=` → env `MD2PDF_CONFIG` → `md2pdf.config.php` vedle skriptu.

## Konfigurace

Config je PHP soubor vracející pole. Plně okomentovaný vzor je [`md2pdf.config.sample.php`](md2pdf.config.sample.php). Nejdůležitější klíče:

| Klíč | Význam |
|------|--------|
| `source_dir` | adresář se zdrojovými `.md` (READ-ONLY) |
| `output_dir` | kam ukládat PDF (`null` = `{source_dir}/pdf`) |
| `glob` | výběr souborů, např. `*.md` nebo `Projekt_*.md` |
| `author` / `company` / `brand` | identita na titulce/v hlavičce/patičce |
| `doc_kind` | typ dokumentu (eyebrow na titulce + patička) |
| `date_format` | formát data (PHP `date()`) |
| `logo` | `['svg'=>…, 'png'=>…]`; `null` = výchozí logo enginu |
| `strings` | všechny zobrazované texty (lokalizace) |
| `source_meta_labels` | labely metabloku v `.md` (`Verze`/`Datum`/`Autor`/`Účel`) |
| `lead_blockquote` | `'meta'` (úvodní blockquote = metadata) / `'keep'` (= obsah) |
| `warn_keywords` | klíčová slova pro „varovný" callout |
| `mermaid` | render mermaidu (viz níže) |

## Konvence Markdownu

- **Titul:** první `# H1` v dokumentu jde na titulní stranu (z těla se vyřízne).
- **Metablok:** úvodní blockquote hned za H1 může nést metadata:

  ```markdown
  # Název dokumentu

  > **Verze:** 1.0 · **Datum:** 1. 1. 2026 · **Autor:** Jan Novák
  > **Účel:** Krátký popis, co dokument řeší.
  ```

  Hodnoty se vytáhnou na titulku. Pokud tvůj úvodní blockquote **není** metadata, ale obsah, nastav v configu `'lead_blockquote' => 'keep'` — zůstane v těle jako úvodní callout.
- **Callouty:** každý blockquote se vykreslí jako box; obsahuje-li `⚠`/`POZOR`/… (dle `warn_keywords`), je oranžový.
- **Obrázky:** `![popis](cesta.png)` — relativní cesty se berou vůči `source_dir`.
- **Mermaid:** blok s jazykem `mermaid` → vyrenderovaný diagram (viz níže).

## Mermaid

Bloky `mermaid` se před sazbou vyrenderují do PNG přes `mmdc` a vloží jako obrázek. PNG se cachují podle hashe obsahu (`.mermaid-cache/`), takže nezměněné diagramy se nerenderují znovu.

Engine hledá prohlížeč v pořadí: `mermaid.chrome` v configu → env `MD2PDF_CHROME` → stažený v `.puppeteer/` → systémový Chrome/Edge. Když mmdc nebo prohlížeč chybí, blok zůstane jako kód (graceful fallback).

Konfigurace (sekce `mermaid` v configu): `enabled`, `mmdc`, `chrome`, `theme`, `background`, `scale`.

## Fonty a licence

Všechny embedované fonty jsou **volně licencované** (lze legálně šířit i embedovat do PDF):

- **Source Sans 3** (text) — SIL OFL, [Adobe](https://github.com/adobe-fonts/source-sans)
- **Cascadia Mono** (kód/diagramy) — SIL OFL, [Microsoft](https://github.com/microsoft/cascadia-code)
- **DejaVu Sans/Mono** (záloha pro symboly ✓✗◆★⚠) — bundlováno v mPDF

Kód je pod licencí **MIT** (viz [`LICENSE`](LICENSE)).

## Více projektů

Engine je sdílený; každý projekt má jen `md2pdf.config.php` (a volitelně tenký `export-pdf.ps1` wrapper). Umístění enginu lze přepsat env `MD2PDF_HOME`. Tenký wrapper v projektu:

```powershell
# tools\export-pdf.ps1 v projektu
$Engine = Join-Path ($env:MD2PDF_HOME ?? 'C:\work\MD2PDF') 'export-pdf.ps1'
& $Engine -Config (Join-Path $PSScriptRoot 'md2pdf.config.php') @args
```

## Autor

Radek Hulán — [https://mywebdesign.cz/](https://mywebdesign.cz/)

---

# 🇬🇧 MD2PDF (English)

> Markdown → professionally typeset PDF. Custom line-based Markdown parser + [mPDF](https://mpdf.github.io/), cover page, table of contents, header/footer, callouts, tables, ASCII-diagram auto-fit and **`mermaid` diagram rendering**. Everything project-specific (paths, file selection, identity, translation, logo) lives in a single `md2pdf.config.php` — the engine never changes.

## What it is

`md2pdf.php` is a standalone PHP tool that converts one or more Markdown files into separate, professionally typeset PDFs (one PDF per document). It is designed as a **shared engine**: install it once (e.g. in `c:\work\MD2PDF`) and reuse it across projects — each project only carries its own `md2pdf.config.php`.

## Features

- **Custom Markdown parser** (headings, lists incl. nested & checkboxes, GFM tables, blockquotes/callouts, code, images, links, HR, inline `**bold**`/`*italic*`/`code`).
- **Cover page** with branding — title from `# H1`, subtitle/purpose and metadata (version, date, author) from the leading blockquote, logo at the bottom.
- **Table of contents** auto-generated from `##` headings (when there are at least 4).
- **Header & footer** on every page; all text comes from the config (localization).
- **Callout boxes** from blockquotes; "warning" (orange) by keyword match.
- **Mermaid diagrams** — `mermaid` blocks are rendered to PNG via [mermaid-cli](https://github.com/mermaid-js/mermaid-cli) and embedded as images.
- **Auto-fit** of wide code blocks (ASCII diagrams) and tables to page width.
- **Embedded free fonts** (Source Sans 3 + Cascadia Mono + DejaVu fallback) → PDF looks the same everywhere and is legally redistributable.
- **Page breaks** — every `# H1` and `## H2` chapter starts on a new page.

## Requirements

| Tool | Version | For |
|------|---------|-----|
| PHP | 8.0+ (CLI, with `mbstring`) | running the engine |
| Composer | — | installing mPDF |
| Node.js + npm | 18+ | mermaid-cli (mermaid only) |
| Chrome / Edge | any Chromium | mermaid rendering (mermaid only) |
| GhostScript | — | optional PNG previews |

Mermaid is **optional**: without Node/a browser, `mermaid` blocks are typeset as code and everything else still works.

## Install

```bash
git clone <repo> md2pdf
cd md2pdf
composer install          # mPDF into vendor/
npm install               # mermaid-cli (optional, mermaid only)
```

Mermaid needs a Chromium browser. The engine **auto-detects** a system Chrome/Edge. If you have none, download one via puppeteer:

```bash
npx puppeteer browsers install chrome
```

## Quick start

1. Copy the sample config into your project and edit it:

   ```bash
   cp md2pdf.config.sample.php /path/to/project/md2pdf.config.php
   ```

   At minimum set `source_dir`, `glob` and identity (`author`/`company`/`brand`).

2. Run the conversion:

   ```bash
   php /path/to/md2pdf/md2pdf.php --config=/path/to/project/md2pdf.config.php
   ```

   Or on Windows via the runner (finds PHP, installs vendor, can make previews):

   ```powershell
   pwsh -File c:\work\MD2PDF\export-pdf.ps1 -Config c:\project\md2pdf.config.php -Preview
   ```

   On Linux/macOS, equivalently via `export-pdf.sh`:

   ```bash
   ./export-pdf.sh --config /path/to/project/md2pdf.config.php --preview
   ```

PDFs are written to `output_dir` (defaults to `{source_dir}/pdf`).

### CLI

```
php md2pdf.php                       # all files matching 'glob' from the config
php md2pdf.php DocumentName          # only one (basename, .md optional)
php md2pdf.php --config=other.php    # different configuration
php md2pdf.php --print-config        # prints JSON {source_dir,output_dir,glob}
```

Config lookup order: `--config=` → env `MD2PDF_CONFIG` → `md2pdf.config.php` next to the script.

## Configuration

The config is a PHP file returning an array. A fully commented template is [`md2pdf.config.sample.php`](md2pdf.config.sample.php). Key options:

| Key | Meaning |
|-----|---------|
| `source_dir` | directory with source `.md` files (READ-ONLY) |
| `output_dir` | where to write PDFs (`null` = `{source_dir}/pdf`) |
| `glob` | file selection, e.g. `*.md` or `Project_*.md` |
| `author` / `company` / `brand` | identity on cover/header/footer |
| `doc_kind` | document kind (cover eyebrow + footer) |
| `date_format` | date format (PHP `date()`) |
| `logo` | `['svg'=>…, 'png'=>…]`; `null` = engine's default logo |
| `strings` | all displayed text (localization) |
| `source_meta_labels` | meta-block labels in `.md` (`Verze`/`Datum`/`Autor`/`Účel`) |
| `lead_blockquote` | `'meta'` (leading blockquote = metadata) / `'keep'` (= content) |
| `warn_keywords` | keywords that mark a "warning" callout |
| `mermaid` | mermaid rendering (see below) |

## Markdown conventions

- **Title:** the first `# H1` goes onto the cover page (removed from the body).
- **Meta block:** the leading blockquote right after H1 may carry metadata:

  ```markdown
  # Document title

  > **Verze:** 1.0 · **Datum:** 1. 1. 2026 · **Autor:** John Doe
  > **Účel:** A short description of what the document is about.
  ```

  (Labels are configurable via `source_meta_labels`.) If your leading blockquote is **not** metadata but actual content, set `'lead_blockquote' => 'keep'` — it stays in the body as an intro callout.
- **Callouts:** every blockquote renders as a box; if it contains a `warn_keywords` term it turns orange.
- **Images:** `![alt](path.png)` — relative paths resolve against `source_dir`.
- **Mermaid:** a `mermaid` block → a rendered diagram (see below).

## Mermaid

Fenced `mermaid` blocks are rendered to PNG via `mmdc` before typesetting and embedded as images. PNGs are cached by content hash (`.mermaid-cache/`), so unchanged diagrams aren't re-rendered.

Browser lookup order: `mermaid.chrome` in the config → env `MD2PDF_CHROME` → one downloaded into `.puppeteer/` → system Chrome/Edge. If `mmdc` or a browser is missing, the block stays as code (graceful fallback).

Config (the `mermaid` section): `enabled`, `mmdc`, `chrome`, `theme`, `background`, `scale`.

## Fonts & licensing

All embedded fonts are **freely licensed** (legal to redistribute and embed in PDFs):

- **Source Sans 3** (text) — SIL OFL, [Adobe](https://github.com/adobe-fonts/source-sans)
- **Cascadia Mono** (code/diagrams) — SIL OFL, [Microsoft](https://github.com/microsoft/cascadia-code)
- **DejaVu Sans/Mono** (fallback for symbols ✓✗◆★⚠) — bundled with mPDF

The code is licensed under **MIT** (see [`LICENSE`](LICENSE)).

## Multiple projects

The engine is shared; each project only has a `md2pdf.config.php` (and optionally a thin `export-pdf.ps1` wrapper). The engine location can be overridden via the `MD2PDF_HOME` env var. A thin per-project wrapper:

```powershell
# tools\export-pdf.ps1 in the project
$Engine = Join-Path ($env:MD2PDF_HOME ?? 'C:\work\MD2PDF') 'export-pdf.ps1'
& $Engine -Config (Join-Path $PSScriptRoot 'md2pdf.config.php') @args
```

## Author

Radek Hulán — [https://mywebdesign.cz/](https://mywebdesign.cz/)
