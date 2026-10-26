import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// Translation tools read the BUILT file, and minifying drops comments. So the build does two things
// to the bundle: writes `(0,wp.i18n.__)(…)` back as the plain call, and puts each string's
// `/* translators: … */` note from the sources back in front of that call.
const SRC_DIR = fileURLToPath(new URL('./src', import.meta.url));
const NOTE = /\/\*\s*(translators:[^*]*?)\s*\*\/[\s\S]{0,400}?\b(?:__|_n)\(\s*('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")/g;
const CALL = /wp\.i18n\.(?:__|_n)\(("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\$])*`),/g;
const literal = (text: any): any => new Function('return ' + text)();

function translatorNotes(dir: any = SRC_DIR, notes = new Map()): any {
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
    generateBundle(_options: any, bundle: any) {
        const notes = translatorNotes();
        for (const chunk of Object.values(bundle) as any[]) {
            if (chunk.type !== 'chunk') continue;
            chunk.code = chunk.code
                // `return(0,wp.i18n.__)(…)` has no space to lose: keep one after a keyword.
                .replace(/([\w$]?)\(0,\s*wp\.i18n\.(__|_n)\)\(/g,
                    (_call: any, before: any, fn: any) => before + (before ? ' ' : '') + 'wp.i18n.' + fn + '(')
                .replace(CALL, (call: any, text: any, at: any, code: any) => {
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

/**
 * Single-bundle build that compiles every TSX component + hook in src/ into
 * one IIFE at build/editor-plugin.js, and the .css files they import into
 * build/editor-plugin.css. The bundle reads wp.element (and other
 * WP globals) at runtime — no React is bundled, no externals to declare.
 *
 * JSX is transformed by esbuild to `wp.element.createElement(...)` calls via
 * the jsxFactory / jsxFragment options below — must match tsconfig.json.
 *
 * Output is registered by EditorAssets as the `fabrement-editor-plugin` handle.
 *
 * `__` and `_n` in the sources are written out as `wp.i18n.__` / `wp.i18n._n`, so the calls keep
 * their literal strings in the built file and translation tools find them there.
 */
export default defineConfig({
    define: { __: 'wp.i18n.__', _n: 'wp.i18n._n' },
    plugins: [i18nForTools],
    build: {
        outDir: 'build',
        // false: deterministic filename, and emptying during `--watch` leaves a
        // window where editor-plugin.js is absent (WP then can't register the
        // 'fabrement-editor-plugin' handle).
        emptyOutDir: false,
        minify: 'esbuild',
        sourcemap: false,
        lib: {
            entry: 'src/index.tsx',
            formats: ['iife'],
            name:    'FabrementEditorPlugin',   // required by Rollup IIFE; not actually consumed
            fileName: () => 'editor-plugin.js',
        },
        rollupOptions: { output: { assetFileNames: 'editor-plugin[extname]' } },
    },
    esbuild: {
        jsx:         'transform',
        jsxFactory:  'wp.element.createElement',
        jsxFragment: 'wp.element.Fragment',
    },
});
