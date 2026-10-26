/**
 * Ambient declarations for WP editor globals consumed by the editor-plugin
 * bundle at runtime. We intentionally type everything as `any` — pulling in
 * @types/wordpress__* is heavyweight and the surface we touch is small.
 *
 * The bundle's JSX is transformed by esbuild to `wp.element.createElement(...)`,
 * so `wp` must exist on the global scope at execution time. WP guarantees that
 * via the `wp-element` script dependency we declare in PHP.
 */
type AnyFn = (...args: any[]) => any;

interface WpElement {
    createElement: AnyFn;
    Fragment: any;
    RawHTML: any;
    useState: <T>(initial: T | (() => T)) => [T, (next: T | ((prev: T) => T)) => void];
    useEffect: (fn: () => void | (() => void), deps?: ReadonlyArray<unknown>) => void;
    useLayoutEffect: (fn: () => void | (() => void), deps?: ReadonlyArray<unknown>) => void;
    useRef: <T>(initial: T) => { current: T };
    useCallback: <T extends Function>(fn: T, deps: ReadonlyArray<unknown>) => T;
    useMemo: <T>(fn: () => T, deps: ReadonlyArray<unknown>) => T;
}

interface WpGlobal {
    data: { useSelect: AnyFn; dispatch: AnyFn };
    element: WpElement;
    hooks: {
        addFilter: (hook: string, namespace: string, callback: AnyFn, priority?: number) => void;
        removeFilter: (hook: string, namespace: string) => void;
    };
    blocks?: { registerBlockType: AnyFn; [key: string]: any };
    components: Record<string, any>;
    blockEditor?: Record<string, any>;
    apiFetch?: (opts: { path: string; method?: string; data?: any }) => Promise<any>;
    i18n: {
        __: (text: string, domain: string) => string;
        _n: (single: string, plural: string, count: number, domain: string) => string;
        sprintf: (format: string, ...args: any[]) => string;
    };
}

declare global {
    // WP exposes a global `wp` object; esbuild emits unqualified `wp.element.*`
    // calls from JSX, so `wp` must be a global binding, not a module-scoped one.
    const wp: WpGlobal;

    // Written out as wp.i18n.__ / wp.i18n._n by the build (vite.config.ts `define`).
    function __(text: string, domain: string): string;
    function _n(single: string, plural: string, count: number, domain: string): string;

    interface Window {
        wp: WpGlobal;
        fabrementEditor?: {
            themeTemplates?: Array<{path: string; label: string; kind?: string | null}>;
            /**
             * The site's languages, or an EMPTY list when there is no translation plugin (and
             * also when it reports only one). Fields read the emptiness as "there is nothing to
             * choose" and render no language control at all.
             */
            languages?: Array<{ code: string; label: string; isCurrent: boolean }>;
            currentLanguage?: string;
        };
    }

    // Classic JSX runtime needs IntrinsicElements; we intentionally type every
    // tag as `any` so we don't have to maintain a full HTML element table.
    namespace JSX {
        interface IntrinsicElements {
            [tagName: string]: any;
        }
        interface IntrinsicAttributes {
            key?: string | number;
        }
        type Element = any;
    }
}

export {};

// Stylesheets are collected into build/editor-plugin.css by the build.
declare module '*.css';
