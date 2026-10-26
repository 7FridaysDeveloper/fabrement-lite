/** Template width in the editor's document sidebar; the canvas follows the choice at once. */
const w = wp as any;

const WIDTHS = [
    {value: 'full', label: __('Full width', 'fabrement-lite')},
    {value: 'constrained', label: __('Content width', 'fabrement-lite')},
];

const HINTS: Record<string, string> = {
    full: __('For section blocks that hold their own inner column: they reach the edges and stack with no gap.', 'fabrement-lite'),
    constrained: __('For text and simple blocks: the theme\'s content column and spacing.', 'fabrement-lite'),
};

type Canvas = {theme?: {contentSize: string; wideSize: string; paddingLeft: string; paddingRight: string}; css?: string};

/** Redraw the canvas for a width: the same layout sizes, padding and rules the server sets on load. */
function applyWidth(width: string): void {
    // The editor's own settings: the canvas takes its styles from them, and the block editor's follow.
    const settings = w.data.select('core/editor').getEditorSettings() || {};
    const canvas: Canvas = settings.fabrementTemplateWidth || {};
    const theme = canvas.theme;
    const fullCss = canvas.css || '';
    if (!theme) return;
    const full = width === 'full';
    // Drop what a previous choice (or the server, for a template opened as full width) added.
    const styles = (settings.styles || []).filter((style: any) => !style.fabrementWidth && style.css !== fullCss);
    const vars = (content: string, wide: string, left: string, right: string) =>
        '.editor-styles-wrapper{'
        + (content ? '--wp--style--global--content-size:' + content + ';' : '')
        + (wide ? '--wp--style--global--wide-size:' + wide + ';' : '')
        + (left ? '--wp--style--root--padding-left:' + left + ';' : '')
        + (right ? '--wp--style--root--padding-right:' + right + ';' : '')
        + '}';
    const css = full
        ? vars('100%', '100%', '0px', '0px') + fullCss
        : vars(theme.contentSize, theme.wideSize, theme.paddingLeft, theme.paddingRight);
    const features = settings.__experimentalFeatures || {};
    w.data.dispatch('core/editor').updateEditorSettings({
        styles: [...styles, {css, fabrementWidth: true}],
        __experimentalFeatures: {
            ...features,
            layout: {
                ...(features.layout || {}),
                contentSize: full ? '100%' : theme.contentSize,
                wideSize: full ? '100%' : theme.wideSize,
            },
        },
    });
}

function TemplateWidthPanel() {
    const postType = w.data.useSelect((select: any) => select('core/editor').getCurrentPostType(), []);
    const saved = w.data.useSelect((select: any) => select('core/editor').getCurrentPostAttribute('contentWidth'), []);
    const width = w.data.useSelect((select: any) => select('core/editor').getEditedPostAttribute('contentWidth'), []) || 'full';
    const drawn = w.element.useRef(null as string | null);
    w.element.useEffect(() => {
        if (postType !== 'fabrement_template') return;
        // The server drew the saved width; only a change needs redrawing.
        if (drawn.current === null) {
            drawn.current = saved || width;
        }
        if (drawn.current !== width) {
            applyWidth(width);
            drawn.current = width;
        }
    }, [postType, saved, width]);

    const Panel = w.editor?.PluginDocumentSettingPanel;
    if (postType !== 'fabrement_template' || !Panel) return null;
    const el = w.element.createElement;
    return el(Panel, {name: 'fabrement-template-width', title: __('Width', 'fabrement-lite')},
        el(w.components.RadioControl, {
            selected: width,
            options: WIDTHS,
            help: HINTS[width],
            onChange: (contentWidth: string) => w.data.dispatch('core/editor').editPost({contentWidth}),
        }),
        saved && saved !== width
            ? el('p', null, __('Save the template to keep this width.', 'fabrement-lite'))
            : null,
    );
}

if (w.plugins?.registerPlugin && !w.plugins.getPlugin?.('fabrement-template-width')) {
    w.plugins.registerPlugin('fabrement-template-width', {render: TemplateWidthPanel});
}

export {};
