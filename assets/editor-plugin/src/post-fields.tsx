import { Locks, Drafts } from './post-fields/context';
import { FieldsLayout } from './post-fields/layout';
import './post-fields/styles.css';
import { useFieldsForm } from './post-fields/use-fields-form';
import type { OptionsTarget } from './post-fields/types';

const { useEffect } = wp.element;

export function PostFieldsEditor({postId, options}: {postId: number; options?: OptionsTarget}) {
    const {key, state, values, dirty, busy, error, saved, draftApi, change, save, reload} = useFieldsForm(postId, options);

    const translation = state?.translation;
    const managed = !!translation?.isTranslation && !!translation?.managedByWpml;
    const locks = {fields: translation?.lockedFields || [], rows: translation?.lockedRows || [], words: translation?.wordFields || []};

    return <Locks.Provider value={locks}><Drafts.Provider value={draftApi}><div className="fabrement-post-fields" aria-busy={busy}>
        <p className="fabrement-post-fields__intro">{options
            ? __('These settings are shared by the whole site. Save them with Save fields; they are not autosaved.', 'fabrement-lite')
            : __('Fields are saved with Save / Update / Publish. They are not autosaved.', 'fabrement-lite')}</p>
        {translation?.isTranslation && !managed && <p className="fabrement-post-fields__intro">{options
            ? __('These are this language\'s saved settings. Shared fields are saved to the original language.', 'fabrement-lite')
            : __('This is a translation. Shared fields are saved to the original language.', 'fabrement-lite')}</p>}
        {managed && <p className="fabrement-post-fields__intro">{__('This translation is managed in the WPML translation editor: its words are translated there. Values that belong to this language (a price, a date) are set here.', 'fabrement-lite')}</p>}
        {translation?.error && <div className="fabrement-post-fields__error" role="alert">{__('Last sync from the original failed:', 'fabrement-lite')} {translation.error}</div>}
        {error && <div className="fabrement-post-fields__error" role="alert">{error}</div>}
        {!state && busy && <p>{__('Loading fields…', 'fabrement-lite')}</p>}
        <fieldset disabled={busy} style={{border: 0, padding: 0, margin: 0, minWidth: 0}}>
            {state && <FieldsLayout id={key} state={state} values={values} onChange={change} />}
        </fieldset>
        <div className="fabrement-post-fields__actions">
            {/* A record's fields are saved by the post's own Update, as in ACF; an options page has no post to save. */}
            {options && <button className="button button-primary" type="button" disabled={!state || !dirty || busy}
                onClick={() => {void save().catch(() => { /* Shown in the box. */ });}}>{busy ? __('Saving…', 'fabrement-lite') : __('Save fields', 'fabrement-lite')}</button>}
            <button className="button" type="button" disabled={busy} onClick={reload}>{__('Reload fields', 'fabrement-lite')}</button>
            {saved && <span role="status" className="fabrement-post-fields__success">{__('Fields saved.', 'fabrement-lite')}</span>}
            {dirty && <span role="status">{__('Unsaved field changes', 'fabrement-lite')}</span>}
        </div>
    </div></Drafts.Provider></Locks.Provider>;
}

function mountPostFields() {
    document.querySelectorAll<HTMLElement>('[data-fabrement-post-fields], [data-fabrement-options-fields]').forEach(element => {
        if (element.dataset.mounted) return;
        element.dataset.mounted = 'true';
        const slug = element.dataset.fabrementOptionsFields;
        (wp.element as any).createRoot(element).render(slug
            ? <PostFieldsEditor postId={0} options={{slug, lang: element.dataset.fabrementOptionsLang || ''}} />
            : <PostFieldsEditor postId={Number(element.dataset.fabrementPostFields)} />);
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountPostFields);
else mountPostFields();
