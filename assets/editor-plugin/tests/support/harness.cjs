/**
 * Test harness for the editor-plugin sources: loads .ts/.tsx files through
 * `typescript.transpileModule` into a fresh `node:vm` context (one per sandbox, so module-level
 * caches start empty), with a stubbed `wp` global and a tiny hook runtime (useState, useEffect,
 * useRef) that is enough to run one function component or hook without React.
 *
 * Element trees are plain objects: {type, props: {..., children: [...]}}. Nested function
 * components are NOT rendered; tests find them by `type` and call their props directly.
 */
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const ts = require('typescript');

const SRC = path.join(__dirname, '../../src');
const transpiled = new Map();

function transpile(file) {
    if (!transpiled.has(file)) {
        transpiled.set(file, ts.transpileModule(fs.readFileSync(file, 'utf8'), {
            fileName: file,
            compilerOptions: {
                module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020,
                jsx: ts.JsxEmit.React, jsxFactory: 'wp.element.createElement', jsxFragmentFactory: 'wp.element.Fragment',
            },
        }).outputText);
    }
    return transpiled.get(file);
}

function resolve(name) {
    if (name.endsWith('.css')) return null; // a stylesheet: nothing to run
    for (const ext of ['', '.ts', '.tsx']) {
        const file = path.join(SRC, name + ext);
        if (fs.existsSync(file) && fs.statSync(file).isFile()) return file;
    }
    throw new Error('Cannot resolve ' + name);
}

/** A hook runtime: mount(fn, props) runs fn as a component and re-runs it on state changes. */
function hookRuntime() {
    let current = null;
    function slot(init) {
        const inst = current;
        if (!inst) throw new Error('Hook called outside a component');
        const i = inst.index++;
        if (!(i in inst.hooks)) inst.hooks[i] = init();
        return inst.hooks[i];
    }
    const element = {
        Fragment: 'Fragment',
        RawHTML: 'RawHTML',
        createContext(value) { return {value, Provider: 'Provider'}; },
        useContext(context) { return context.value; },
        createElement(type, props, ...children) {
            return {type, props: {...(props || {}), children: children.flat(Infinity)}};
        },
        useState(initial) {
            const inst = current;
            const h = slot(() => ({value: typeof initial === 'function' ? initial() : initial}));
            h.set ||= (next) => {
                const value = typeof next === 'function' ? next(h.value) : next;
                if (Object.is(value, h.value)) return;
                h.value = value;
                inst.schedule();
            };
            return [h.value, h.set];
        },
        useEffect(fn, deps) {
            const h = slot(() => ({deps: undefined, cleanup: undefined, first: true}));
            const changed = h.first || !deps || deps.length !== h.deps.length || deps.some((d, i) => !Object.is(d, h.deps[i]));
            if (changed) current.effects.push(() => {
                if (typeof h.cleanup === 'function') h.cleanup();
                h.cleanup = fn();
                h.deps = deps; h.first = false;
            });
        },
        useLayoutEffect(fn, deps) { return element.useEffect(fn, deps); },
        useRef(initial) { return slot(() => ({current: initial})); },
        useMemo(fn) { return fn(); },
        useCallback(fn) { return fn; },
    };
    function mount(Component, props) {
        const inst = {hooks: [], index: 0, effects: [], props, output: undefined, renders: 0, busy: false, dirty: false, unmounted: false};
        inst.render = () => {
            if (inst.unmounted) return;
            let guard = 0;
            do {
                if (++guard > 50) throw new Error('Render loop');
                inst.dirty = false;
                inst.busy = true;
                current = inst; inst.index = 0; inst.effects = [];
                try { inst.output = Component(inst.props); } finally { current = null; }
                inst.renders++;
                for (const run of inst.effects) run();
                inst.busy = false;
            } while (inst.dirty);
        };
        inst.schedule = () => { if (inst.unmounted) return; if (inst.busy) inst.dirty = true; else inst.render(); };
        inst.update = (next) => { inst.props = next; inst.render(); return inst; };
        inst.unmount = () => {
            inst.unmounted = true;
            for (const h of inst.hooks) if (h && typeof h.cleanup === 'function') h.cleanup();
        };
        inst.render();
        return inst;
    }
    return {element, mount};
}

/**
 * A fresh sandbox. `apiFetch(options)` answers each request; every request is also recorded in
 * `requests`. Returns {load(name), mount, sandbox, requests, wp, window}.
 */
function createSandbox({apiFetch, editor, extraWp = {}, window: extraWindow = {}, stubs = {}} = {}) {
    const runtime = hookRuntime();
    const requests = [];
    const wp = {
        element: runtime.element,
        components: new Proxy({}, {get: (_, name) => typeof name === 'string' ? 'wp.components.' + name : undefined}),
        hooks: {addFilter() {}, removeFilter() {}},
        // sprintf: %s, %d and their numbered forms (%1$s), which is all the sources use.
        i18n: {__: (text) => text, _n: (single, plural, count) => (count === 1 ? single : plural), sprintf: (format, ...args) => {
            let next = 0;
            return String(format).replace(/%(?:(\d+)\$)?([sd%])/g, (_, at, kind) => kind === '%' ? '%' : String(args[at ? at - 1 : next++]));
        }},
        apiFetch: (options) => { requests.push(options); return apiFetch ? apiFetch(options) : Promise.reject(new Error('no apiFetch')); },
        ...extraWp,
    };
    const document = {
        getElementById: () => ({}), createElement: () => ({}), head: {appendChild() {}},
        querySelectorAll: () => [],
    };
    const window = {wp, fabrementEditor: editor, setTimeout, clearTimeout, confirm: () => true, ...extraWindow};
    const sandbox = vm.createContext({wp, window, document, setTimeout, clearTimeout, console, Promise,
        // The build writes these out as wp.i18n calls; here they are plain globals.
        __: (text) => text, _n: (single, plural, count) => (count === 1 ? single : plural)});
    const modules = new Map();
    function load(name) {
        if (Object.prototype.hasOwnProperty.call(stubs, name)) return stubs[name];
        const file = resolve(name.replace(/^\.\//, ''));
        if (file === null) return {};
        if (modules.has(file)) return modules.get(file).exports;
        const module = {exports: {}};
        modules.set(file, module);
        const fn = vm.runInContext('(function (exports, require, module) {' + transpile(file) + '\n})', sandbox, {filename: file});
        fn(module.exports, id => load(path.relative(SRC, path.resolve(path.dirname(file), id)).replaceAll('\\', '/')), module);
        return module.exports;
    }
    return {load, mount: runtime.mount, sandbox, requests, wp, window};
}

/** Every element in a tree that matches `test` (depth first, document order). */
function findAll(tree, test, out = []) {
    if (Array.isArray(tree)) { for (const t of tree) findAll(t, test, out); return out; }
    if (!tree || typeof tree !== 'object' || !tree.props) return out;
    if (test(tree)) out.push(tree);
    findAll(tree.props.children, test, out);
    return out;
}

const byClass = (name) => (el) => typeof el.props.className === 'string' && el.props.className.split(/\s+/).includes(name);

/** All text in a tree, concatenated. */
function text(tree) {
    if (tree === null || tree === undefined || tree === false || tree === true) return '';
    if (typeof tree === 'string' || typeof tree === 'number') return String(tree);
    if (Array.isArray(tree)) return tree.map(text).join('');
    return tree.props ? text(tree.props.children) : '';
}

/** Let queued timers (setTimeout 0) and promise chains settle. */
const settle = async (rounds = 3) => { for (let i = 0; i < rounds; i++) await new Promise((r) => setTimeout(r, 0)); };

/** Plain copy of a value from another VM context (so deepStrictEqual sees the same prototypes). */
const plain = (value) => value === undefined ? undefined : JSON.parse(JSON.stringify(value));

module.exports = {createSandbox, findAll, byClass, text, settle, plain, transpile, resolve, SRC};
