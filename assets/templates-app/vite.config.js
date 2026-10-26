import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const src = (segment) => resolve(__dirname, 'src', segment);

// React comes from WordPress (the `react` / `react-dom` script handles), not from the bundle.
const REACT_GLOBALS = { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' };

// Translation tools read the BUILT file, and minifying drops comments. So the build does two things
// to the bundle: writes `(0,wp.i18n.__)(…)` back as the plain call, and puts each string's
// `/* translators: … */` note from the sources back in front of that call.
const SRC_DIR = fileURLToPath(new URL('./src', import.meta.url));
const NOTE = /\/\*\s*(translators:[^*]*?)\s*\*\/[\s\S]{0,400}?\b(?:__|_n)\(\s*('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")/g;
const CALL = /wp\.i18n\.(?:__|_n)\(("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\$])*`),/g;
const literal = (text) => new Function('return ' + text)();

function translatorNotes(dir = SRC_DIR, notes = new Map()) {
    for (const entry of readdirSync(dir, {withFileTypes: true})) {
        const file = join(dir, entry.name);
        if (entry.isDirectory()) translatorNotes(file, notes);
        else if (/\.tsx?$/.test(entry.name)) {
            for (const match of readFileSync(file, 'utf8').matchAll(NOTE)) notes.set(literal(match[2]), match[1]);
        }
    }
    return notes;
}

const i18nForTools = {
    name: 'fabrement-i18n-for-tools',
    generateBundle(_options, bundle) {
        const notes = translatorNotes();
        for (const chunk of Object.values(bundle)) {
            if (chunk.type !== 'chunk') continue;
            chunk.code = chunk.code
                // `return(0,wp.i18n.__)(…)` has no space to lose: keep one after a keyword.
                .replace(/([\w$]?)\(0,\s*wp\.i18n\.(__|_n)\)\(/g,
                    (_call, before, fn) => before + (before ? ' ' : '') + 'wp.i18n.' + fn + '(')
                .replace(CALL, (call, text, at, code) => {
                    const note = notes.get(literal(text));
                    if (!note) return call;
                    // A line break is only safe inside an argument list: never after `return`.
                    const lead = '(,'.includes(code[at - 1]) ? '\n' : '';
                    // The blank line after the text keeps the note from reaching the calls that follow.
                    return lead + '/* ' + note + ' */' + call + '\n\n';
                });
        }
    },
};

export default defineConfig({
  // `__` / `_n` are written out as wp.i18n calls, so the built file keeps the literal strings.
  define: { __: 'wp.i18n.__', _n: 'wp.i18n._n' },
  plugins: [tailwindcss(), react({ jsxRuntime: 'classic' }), i18nForTools],
  resolve: {
    alias: [
      { find: /^@shared\/(.+)$/, replacement: src('shared/$1') },
      { find: /^@repository\/(.+)$/, replacement: src('repository/$1') },
    ],
  },
  build: {
    outDir: 'build',
    // es2022: native class fields, so no helper leaks a global `_` over WordPress's underscore.
    target: 'es2022',
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/main.tsx'),
      external: Object.keys(REACT_GLOBALS),
      output: {
        entryFileNames: 'templates-app.js',
        assetFileNames: 'templates-app[extname]',
        format: 'iife',
        name: 'FabrementTemplatesApp',
        globals: REACT_GLOBALS,
        inlineDynamicImports: true,
      },
    },
  },
});
