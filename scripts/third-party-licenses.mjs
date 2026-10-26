#!/usr/bin/env node
// Writes THIRD-PARTY-LICENSES.txt: the licence and copyright notice of every third-party library
// that ships inside the plugin — the npm packages bundled into each assets/*/build file and the
// Composer packages in vendor/. MIT, BSD and the like require that notice to travel with every
// copy, and a minifier drops the comments that carried it, so build.sh generates this file.
//
//   node scripts/third-party-licenses.mjs <source root with node_modules> <stage with vendor/> > THIRD-PARTY-LICENSES.txt
//
// Only production dependencies count (npm ls --omit=dev): the build tools never reach a site.
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const [root, stage] = process.argv.slice(2);
if (!root || !stage) {
  console.error('usage: third-party-licenses.mjs <source root> <stage>');
  process.exit(2);
}

const BUNDLES = [
  ['assets/editor-plugin', 'assets/editor-plugin/build/'],
  ['assets/templates-app', 'assets/templates-app/build/'],
];

const EMBEDDED = { 'assets/templates-app': ['tailwindcss'] };

const MIT = (holder) => `MIT License

Copyright (c) ${holder}

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.`;

const licenceFile = (dir) =>
  readdirSync(dir).find((name) => /^(licen[cs]e|copying)(\.(md|txt))?$/i.test(name));

const author = (pkg) => {
  const a = pkg.author;
  const name = typeof a === 'string' ? a.replace(/\s*[<(].*$/, '') : a?.name;
  return name || `the ${pkg.name} authors`;
};

// The licence text of one package: its own file, or the standard MIT text with its author.
const licenceText = (dir, licence, pkgJson) => {
  const file = licenceFile(dir);
  if (file) return readFileSync(join(dir, file), 'utf8').trim();
  if (licence === 'MIT' && pkgJson) return MIT(author(pkgJson));
  return `(No licence file in the package. Licence: ${licence} — https://spdx.org/licenses/${licence}.html)`;
};

const sections = [];
const seen = new Set();

for (const [dir, target] of BUNDLES) {
  const cwd = join(root, dir);
  if (!existsSync(join(cwd, 'node_modules'))) {
    console.error(`third-party-licenses: ${dir}/node_modules is missing — run npm ci there first.`);
    process.exit(1);
  }
  const out = execFileSync(process.platform === 'win32' ? 'npm.cmd' : 'npm',
    ['ls', '--omit=dev', '--all', '--parseable'], { cwd, encoding: 'utf8', shell: process.platform === 'win32' });
  const entries = [];
  for (const path of out.split(/\r?\n/).slice(1)) {
    if (!path.trim() || !existsSync(join(path, 'package.json'))) continue;
    const pkg = JSON.parse(readFileSync(join(path, 'package.json'), 'utf8'));
    const id = `${pkg.name}@${pkg.version}`;
    if (seen.has(id)) continue;
    seen.add(id);
    let licence = pkg.license ?? (pkg.licenses || []).map((l) => l.type ?? l).join(' OR ');
    if (typeof licence === 'object') licence = licence.type;
    entries.push({ id, licence: licence || 'UNKNOWN', text: licenceText(path, licence, pkg) });
  }
  // A build tool whose OUTPUT ships (Tailwind's generated CSS keeps its MIT notice in the bundle).
  for (const name of EMBEDDED[dir] ?? []) {
    const path = join(cwd, 'node_modules', name);
    const pkg = JSON.parse(readFileSync(join(path, 'package.json'), 'utf8'));
    entries.push({ id: `${pkg.name}@${pkg.version}`, licence: pkg.license, text: licenceText(path, pkg.license, pkg) });
  }
  sections.push({ title: `JavaScript bundled into ${target}`, entries });
}

const ofl = join(stage, 'assets/fonts/OFL.txt');
if (existsSync(ofl)) {
  sections.push({ title: 'Fonts in assets/fonts/', entries: [ { id: 'Manrope', licence: 'OFL-1.1', text: readFileSync(ofl, 'utf8').trim() } ] });
}

const installed = JSON.parse(readFileSync(join(stage, 'vendor/composer/installed.json'), 'utf8'));
const php = [];
for (const pkg of installed.packages ?? installed) {
  const dir = join(stage, 'vendor', pkg.name);
  const licence = (pkg.license || []).join(' OR ') || 'UNKNOWN';
  php.push({ id: `${pkg.name} ${pkg.version}`, licence, text: licenceText(dir, licence, null) });
}
sections.push({ title: 'PHP libraries in vendor/ (Composer)', entries: php });

const rule = '='.repeat(78);
let text = `Third-party software shipped with Fabrement Lite

Fabrement Lite itself is licensed under the GNU General Public License v2 or later (see LICENSE).
It includes the third-party libraries below, each under its own licence, which is reproduced
in full with its copyright notice.
`;
for (const { title, entries } of sections) {
  text += `\n${rule}\n${title}\n${rule}\n`;
  for (const { id, licence, text: body } of entries.sort((a, b) => a.id.localeCompare(b.id))) {
    text += `\n----- ${id} (${licence}) -----\n\n${body}\n`;
  }
}
process.stdout.write(text);
