/**
 * "Assign to" in the template editor's document sidebar: what renders with this template.
 * The same choices as the Templates screen, through the same routes; each applies at once,
 * without the editor's Save.
 */
const w = wp as any;

const NOT_FOUND = '404';
const PAGE_LIMIT = 30;

type Summary = {id: number; slug: string; defaultFor: string[]};
type Archive = {context: string; label: string; templateId: number};
type Page = {id: number; title: string; template: string; typeLabel: string};

const api = (path: string, data?: unknown): Promise<any> =>
    w.apiFetch(data === undefined ? {path: '/fabrement/v1/' + path} : {path: '/fabrement/v1/' + path, method: 'POST', data});

function TemplateAssignPanel() {
    const {useState, useEffect, useCallback, createElement: el} = w.element;
    const postType = w.data.useSelect((select: any) => select('core/editor').getCurrentPostType(), []);
    const id: number = w.data.useSelect((select: any) => select('core/editor').getCurrentPostId(), []);

    const [template, setTemplate] = useState(null as Summary | null);
    const [types, setTypes] = useState({} as Record<string, string>);
    const [archives, setArchives] = useState([] as Archive[]);
    const [pages, setPages] = useState([] as Page[]);
    const [picked, setPicked] = useState([] as number[]);
    const [dirty, setDirty] = useState(false);
    const [search, setSearch] = useState('');
    const [loaded, setLoaded] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null as {status: string; text: string} | null);

    const isTemplate = postType === 'fabrement_template';

    const loadPages = useCallback((slug: string, term: string, keepPicks: boolean) =>
        api('site-templates/pages?limit=' + PAGE_LIMIT + '&templateSlug=' + encodeURIComponent(slug) + (term ? '&search=' + encodeURIComponent(term) : ''))
            .then((res: {pages: Page[]}) => {
                setPages(res.pages);
                if (!keepPicks) {
                    setPicked(res.pages.filter((page) => page.template === slug).map((page) => page.id));
                    setDirty(false);
                }
            }), []);

    const load = useCallback(() =>
        Promise.all([api('site-templates'), api('site-templates/archives')]).then(([list, arch]: any[]) => {
            const own: Summary | null = (list.templates as Summary[]).find((t) => t.id === id) || null;
            setTemplate(own);
            setTypes(list.postTypes?.available || {});
            setArchives(arch.archives || []);
            return own ? loadPages(own.slug, '', false) : undefined;
        }), [id, loadPages]);

    useEffect(() => {
        if (!isTemplate) return;
        load()
            .catch((e: any) => setMessage({status: 'error', text: e?.message || __('The assignments could not be loaded.', 'fabrement-lite')}))
            .then(() => setLoaded(true));
    }, [isTemplate, load]);

    const Panel = w.editor?.PluginDocumentSettingPanel;
    if (!isTemplate || !Panel) return null;

    const act = (action: () => Promise<unknown>, done: string) => {
        setBusy(true);
        setMessage(null);
        action()
            .then(() => load())
            .then(() => setMessage({status: 'success', text: done}))
            .catch((e: any) => setMessage({status: 'error', text: e?.message || __('The request failed.', 'fabrement-lite')}))
            .then(() => setBusy(false));
    };

    const {CheckboxControl, TextControl, Button, Notice, Spinner} = w.components;
    const heading = (text: string) => el('p', {style: {margin: '16px 0 8px', fontWeight: 600}}, text);
    const rows = (children: unknown[]) => el('div', {style: {display: 'flex', flexDirection: 'column', gap: '8px'}}, ...children);

    let body;
    if (!loaded) {
        body = el(Spinner, null);
    } else if (!template) {
        body = el('p', null, __('This is a language version or an unsaved template. Assignments belong to the original template.', 'fabrement-lite'));
    } else {
        const typeRows = Object.keys(types).concat(NOT_FOUND).map((slug) => {
            const on = template.defaultFor.includes(slug);
            return el(CheckboxControl, {
                key: slug,
                __nextHasNoMarginBottom: true,
                label: slug === NOT_FOUND ? __('Not found (404)', 'fabrement-lite') : types[slug],
                checked: on,
                disabled: busy,
                onChange: () => act(() => api('site-templates/default', {postType: slug, templateId: on ? 0 : template.id}), __('Default updated.', 'fabrement-lite')),
            });
        });
        const archiveRows = (archives as Archive[]).map((archive) => {
            const on = archive.templateId === template.id;
            return el(CheckboxControl, {
                key: archive.context,
                __nextHasNoMarginBottom: true,
                label: archive.label,
                help: !on && archive.templateId > 0 ? __('Uses another template now.', 'fabrement-lite') : undefined,
                checked: on,
                disabled: busy,
                onChange: () => act(() => api('site-templates/archive', {context: archive.context, templateId: on ? 0 : template.id}), __('Archive updated.', 'fabrement-lite')),
            });
        });
        const pageRows = (pages as Page[]).map((page) => el(CheckboxControl, {
            key: page.id,
            __nextHasNoMarginBottom: true,
            label: page.title + ' · ' + page.typeLabel,
            checked: picked.includes(page.id),
            disabled: busy,
            onChange: (on: boolean) => {
                setPicked(on ? picked.concat(page.id) : (picked as number[]).filter((one) => one !== page.id));
                setDirty(true);
            },
        }));
        body = el(w.element.Fragment, null,
            el('p', null, __('Each choice applies at once, without saving the template.', 'fabrement-lite')),
            heading(__('Default for post types', 'fabrement-lite')),
            rows(typeRows),
            heading(__('Default for archives', 'fabrement-lite')),
            archiveRows.length ? rows(archiveRows) : el('p', null, __('This site has no archives.', 'fabrement-lite')),
            heading(__('Individual pages', 'fabrement-lite')),
            el(TextControl, {
                __nextHasNoMarginBottom: true,
                placeholder: __('Search pages and posts', 'fabrement-lite'),
                value: search,
                onChange: (term: string) => {
                    setSearch(term);
                    // The picks made so far stay; only the rows on screen change.
                    loadPages(template.slug, term.trim(), true).catch(() => undefined);
                },
            }),
            el('div', {style: {margin: '12px 0', maxHeight: '220px', overflowY: 'auto'}}, rows(pageRows)),
            el(Button, {
                variant: 'secondary',
                disabled: busy || !dirty,
                onClick: () => act(() => api('site-templates/' + template.id + '/pages', {pageIds: picked}), __('Pages updated.', 'fabrement-lite')),
            }, __('Apply pages', 'fabrement-lite')),
        );
    }

    return el(Panel, {name: 'fabrement-template-assign', title: __('Assign to', 'fabrement-lite')},
        message ? el(Notice, {status: message.status, isDismissible: false}, message.text) : null,
        body,
    );
}

if (w.plugins?.registerPlugin && !w.plugins.getPlugin?.('fabrement-template-assign')) {
    w.plugins.registerPlugin('fabrement-template-assign', {render: TemplateAssignPanel});
}

export {};
