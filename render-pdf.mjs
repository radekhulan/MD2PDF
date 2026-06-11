// render-pdf.mjs — HTML → PDF přes headless Chrome (puppeteer)
// =============================================================
// Vstup: cesta k JSON job souboru:
//   {
//     "html": "C:/.../doc.html",
//     "out":  "C:/.../doc.pdf",
//     "chrome": "C:/Program Files/.../chrome.exe",   // executablePath (volitelné)
//     "margin": { "top":"15mm","bottom":"15mm","left":"18mm","right":"18mm" },
//     "displayHeaderFooter": true,
//     "headerTemplate": "<div…>…</div>",
//     "footerTemplate": "<div…>…</div>"
//   }
//
// Header/footer šablony se renderují v izolovaném kontextu BEZ @font-face —
// používají systémový font. Speciální třídy .pageNumber / .totalPages Chrome
// doplní. Tělo dokumentu používá embedované fonty z @font-face v HTML.

import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';

const jobPath = process.argv[2];
if (!jobPath) {
  console.error('Použití: node render-pdf.mjs <job.json>');
  process.exit(2);
}

const job = JSON.parse(readFileSync(jobPath, 'utf8'));
const fileUrl = 'file:///' + String(job.html).replace(/\\/g, '/');

const launchOpts = {
  headless: true,
  args: ['--no-sandbox', '--disable-gpu', '--font-render-hinting=none'],
};
if (job.chrome) {
  launchOpts.executablePath = job.chrome;
}

const browser = await puppeteer.launch(launchOpts);
try {
  const page = await browser.newPage();
  await page.goto(fileUrl, { waitUntil: 'networkidle0', timeout: 60000 });
  // počkej na fonty, ať se nesází systémovým náhradníkem
  await page.evaluate(async () => { await document.fonts.ready; });

  await page.pdf({
    path: job.out,
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: false,
    displayHeaderFooter: job.displayHeaderFooter ?? false,
    headerTemplate: job.headerTemplate ?? '<span></span>',
    footerTemplate: job.footerTemplate ?? '<span></span>',
    margin: job.margin ?? { top: '15mm', bottom: '15mm', left: '18mm', right: '18mm' },
  });
  console.log('OK ' + job.out);
} catch (e) {
  console.error('CHYBA renderu: ' + (e && e.message ? e.message : e));
  process.exitCode = 1;
} finally {
  await browser.close();
}
