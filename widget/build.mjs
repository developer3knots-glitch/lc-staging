/**
 * Concatenate widget/src/*.js into widget/embed-app.js (single file for sites).
 *
 * Usage: node widget/build.mjs
 *
 * Customer sites still load embed.js → embed-app.js only.
 * Edit files under widget/src/, then run this build before deploy.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const srcRoot = path.join(__dirname, 'src');
const outFile = path.join(__dirname, 'embed-app.js');
const manifestPath = path.join(srcRoot, 'modules.json');

if (!fs.existsSync(manifestPath)) {
  console.error('Missing widget/src/modules.json — run split-once.mjs first or restore modules.');
  process.exit(1);
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const parts = [];
for (const row of manifest) {
  const file = path.join(srcRoot, row.file);
  if (!fs.existsSync(file)) {
    console.error('Missing module', row.file);
    process.exit(1);
  }
  parts.push(fs.readFileSync(file, 'utf8').replace(/\s+$/, '') + '\n');
}

const banner = `/**
 * LiveChat Widget - embed on any website
 * <script src="…/widget/embed.js" data-site="…"></script>
 *
 * GENERATED FILE — edit widget/src/*.js then run: node widget/build.mjs
 */
(function () {
    'use strict';
    if (window.__LC_WIDGET_APP) return;
    window.__LC_WIDGET_APP = true;

`;

const footer = `
})();
`;

const out = banner + parts.join('\n') + footer;
fs.writeFileSync(outFile, out);
const lineCount = out.split(/\n/).length;
console.log('Built', outFile, `(${lineCount} lines, ${manifest.length} modules)`);
