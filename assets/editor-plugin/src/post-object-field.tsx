/**
 * PostObjectField — the SINGLE-record picker of a `post_object` field (ACF's "Post Object").
 * The field's `postTypes` decide which post types may be chosen.
 *
 * The value is a BARE ID (0 = nothing picked). The title on the trigger is looked up live
 * from the id, so a renamed record reads correctly.
 */
import { PostPickerModal, PostRef, usePostRefs, LangChips, OnPageNote } from './post-picker-shared';
import './post-object-field.css';



const { useState, useEffect, Fragment } = wp.element;

export type PostObjectFieldProps = {
    label?:     string;
    /** Post type slugs the picker is restricted to; omitted/empty = every public type. */
    postTypes?: string[];
    /** The picked record's id. 0 / undefined = nothing picked. */
    value?:     number;
    onChange?:  (value: number) => void;
};

const DocIcon = (
    <svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}
        strokeLinecap="round" strokeLinejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
    </svg>
);

export function PostObjectField(props: PostObjectFieldProps) {
    const { postTypes, onChange } = props;
    // Explicit '' hides the label (the fields form prints its own).
    const label = props.label === undefined ? __('Post', 'fabrement-lite') : props.label;
    const id = typeof props.value === 'number' && props.value > 0 ? props.value : 0;

    const [isOpen, setIsOpen] = useState(false);
    const { refs, loading } = usePostRefs(id ? [id] : []);
    const ref = refs[id];


    // Three states, and they must read differently: nothing picked, a record whose
    // label is still loading, and an id that no longer resolves (trashed/deleted) —
    // the last one is a real problem the admin has to see, not an empty-looking field.
    let titleText = __('Choose a record…', 'fabrement-lite');
    let titleMod  = ' fabrement-post-object-field__trigger-title--empty';
    if (id && ref) {
        titleText = ref.title || __('(no title)', 'fabrement-lite');
        titleMod  = '';
    } else if (id && loading) {
        titleText = '#' + id + '…';
        titleMod  = ' fabrement-post-object-field__trigger-title--empty';
    } else if (id) {
        /* translators: %d: record id */
        titleText = wp.i18n.sprintf(__('Record #%d is unavailable', 'fabrement-lite'), id);
        titleMod  = ' fabrement-post-object-field__trigger-title--missing';
    }

    const trigger = (
        <div className="fabrement-post-object-field">
            {label && <span className="fabrement-post-picker__label">{label}</span>}
            <button type="button" className="fabrement-post-object-field__trigger" onClick={() => setIsOpen(true)}>
                <span className="fabrement-post-object-field__trigger-icon">{DocIcon}</span>
                <span className="fabrement-post-object-field__trigger-body">
                    <span className={'fabrement-post-object-field__trigger-title' + titleMod}>
                        {titleText}
                        {ref && <LangChips languages={ref.languages} />}
                    </span>
                    {ref && ref.url && (
                        <span className="fabrement-post-object-field__trigger-meta">{ref.url}</span>
                    )}
                    <OnPageNote record={ref} />
                </span>
                {id > 0 && (
                    <span
                        className="fabrement-post-object-field__trigger-clear"
                        title={__('Remove', 'fabrement-lite')}
                        onClick={(e: any) => { e.stopPropagation(); onChange?.(0); }}
                    >×</span>
                )}
            </button>
        </div>
    );

    if (!isOpen) return trigger;

    // Single-value semantics: the first pick IS the answer, so it applies and
    // closes — no Apply button to forget to press.
    return (
        <Fragment>
            {trigger}
            <PostPickerModal
                title={label || __('Post', 'fabrement-lite')}
                postTypes={postTypes}
                selectedIds={id ? [id] : []}
                onPick={(picked: PostRef) => { onChange?.(picked.id); setIsOpen(false); }}
                onClose={() => setIsOpen(false)}
            />
        </Fragment>
    );
}
