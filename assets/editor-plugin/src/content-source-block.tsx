/** The built-in template slot: where a template shows the page content or a theme PHP file. */
const blockName = 'fabrement/content-source';
if (wp.blocks && !wp.blocks.getBlockType(blockName)) {
    wp.blocks.registerBlockType(blockName, {
        edit: () => {
            const source = wp.data.useSelect((select: any) => select('core/editor').getEditedPostAttribute('contentSource'), []);
            const postType = wp.data.useSelect((select: any) => select('core/editor').getCurrentPostType(), []);
            const rules: Array<{context: string; file: string}> = source?.rules || [];
            const setRules = (next: Array<{context: string; file: string}>) =>
                wp.data.dispatch('core/editor').editPost({contentSource: {rules: next}});
            const files = window.fabrementEditor?.themeTemplates || [];
            const {InspectorControls} = wp.blockEditor!;
            const {PanelBody, SelectControl, TextControl, Button} = wp.components;
            const el = wp.element.createElement;
            const setRule = (index: number, value: Partial<{context: string; file: string}>) =>
                setRules(rules.map((rule, i) => i === index ? {...rule, ...value} : rule));
            // The full edition's placeholder, as inline styles: the canvas is an iframe of its own.
            const props = wp.blockEditor!.useBlockProps({
                className: 'fabrement-content-source',
                style: {
                    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
                    minHeight: '260px', gap: '6px', padding: '40px 24px', textAlign: 'center', color: '#5a5a68',
                    border: '1px dashed #b8b8c0', borderRadius: '10px',
                    background: 'repeating-linear-gradient(45deg,#fafafc,#fafafc 10px,#f3f3f7 10px,#f3f3f7 20px)',
                },
            });
            const line = { fontSize: '12px', color: '#7a7a88', margin: 0 };
            return el(wp.element.Fragment, null,
                postType === 'fabrement_template' ? el(InspectorControls, null, el(PanelBody, {title: __('Content Source', 'fabrement-lite')},
                    el('p', null, __('Without a rule, this slot shows the page content. PHP files are supplied by your theme.', 'fabrement-lite')),
                    el('p', null, __('Source rules are shared with every translation of this template. Save the template to apply changes.', 'fabrement-lite')),
                    ...rules.map((rule, index) => el('div', {key: index},
                        el(TextControl, {label: __('View context', 'fabrement-lite'), value: rule.context, onChange: (context: string) => setRule(index, {context})}),
                        el(SelectControl, {label: __('Theme PHP file', 'fabrement-lite'), value: rule.file,
                            options: [{label: __('Choose a theme file', 'fabrement-lite'), value: ''},
                                ...(!files.some(file => file.path === rule.file) && rule.file ? [{label: /* translators: %s: theme file path */ wp.i18n.sprintf(__('%s (unavailable)', 'fabrement-lite'), rule.file), value: rule.file}] : []),
                                ...files.map(file => ({label: file.label, value: file.path}))],
                            onChange: (file: string) => setRule(index, {file})}),
                        el(Button, {variant: 'tertiary', isDestructive: true, onClick: () => setRules(rules.filter((_, i) => i !== index))}, __('Remove rule', 'fabrement-lite')),
                    )),
                    el(Button, {variant: 'secondary', disabled: files.length === 0, onClick: () => setRules([...rules, {context: 'singular:page', file: files[0].path}])}, __('Add theme source', 'fabrement-lite')),
                    files.length === 0 ? el('p', null, __('No PHP files were found in your theme’s templates directory.', 'fabrement-lite')) : null,
                )) : null,
                el('div', props,
                    el('p', {style: {fontSize: '15px', fontWeight: 600, margin: 0}}, '📄 ' + __('Content Source', 'fabrement-lite')),
                    el('p', {style: line}, rules.length ? __('Theme PHP sources are configured for this template.', 'fabrement-lite') : __('The content of the page using this template appears here.', 'fabrement-lite')),
                ),
            );
        },
        save: () => null,
    });
}
