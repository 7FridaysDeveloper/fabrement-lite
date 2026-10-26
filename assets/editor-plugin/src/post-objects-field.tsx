/**
 * PostObjectsField — the MULTI-record picker of a `relationship` field (ACF's "Relationship":
 * several records, in a chosen order). The field's `postTypes` decide which post types may be
 * chosen; `max` caps how many.
 *
 * The value is an ARRAY OF IDS ([] when empty), in the chosen order. Titles are looked up
 * live from those ids.
 */
import { PostPickerModal, PostRef, usePostRefs, LangChips, OnPageNote } from './post-picker-shared';
import './post-objects-field.css';



const { useState, useEffect, Fragment } = wp.element;
const { Button } = wp.components;

export type PostObjectsFieldProps = {
    label?:     string;
    /** Post type slugs the picker is restricted to; omitted/empty = every public type. */
    postTypes?: string[];
    /** Hard cap on how many records may be picked; omitted = unlimited. */
    max?:       number;
    /** The picked records' ids, in the admin's order. */
    value?:     number[];
    onChange?:  (value: number[]) => void;
};

export function PostObjectsField(props: PostObjectsFieldProps) {
    const { postTypes, max, onChange } = props;
    const label = props.label === undefined ? __('Posts', 'fabrement-lite') : props.label;
    const ids: number[] = Array.isArray(props.value)
        ? props.value.filter((id) => typeof id === 'number' && id > 0)
        : [];
    const cap  = typeof max === 'number' && max > 0 ? max : 0;
    const full = cap > 0 && ids.length >= cap;

    const [isOpen, setIsOpen] = useState(false);
    const { refs, loading } = usePostRefs(ids);


    const commit = (next: number[]) => onChange?.(next);

    const removeAt = (i: number) => commit(ids.filter((_, idx) => idx !== i));

    const move = (i: number, delta: number) => {
        const j = i + delta;
        if (j < 0 || j >= ids.length) return;
        const next = ids.slice();
        const tmp = next[i];
        next[i] = next[j];
        next[j] = tmp;
        commit(next);
    };

    // Picking an already-picked record REMOVES it — the ✓ row in the modal is a
    // toggle, so the editor never has to close the modal to undo a mis-click.
    const toggle = (ref: PostRef) => {
        const at = ids.indexOf(ref.id);
        if (at !== -1) { removeAt(at); return; }
        if (full) return;
        commit(ids.concat([ref.id]));
    };

    const list = ids.length ? (
        <div className="fabrement-post-objects-field__list">
            {ids.map((id, i) => {
                const ref = refs[id];
                // Same three states as PostObjectField: resolved, still loading, gone for good.
                /* translators: %d: record id */
                const gone = wp.i18n.sprintf(__('Record #%d is unavailable', 'fabrement-lite'), id);
                const title = ref ? (ref.title || __('(no title)', 'fabrement-lite')) : loading ? '#' + id + '…' : gone;
                return (
                    <div className="fabrement-post-objects-field__item" key={id}>
                        <span className="fabrement-post-objects-field__item-index">{i + 1}</span>
                        <span className="fabrement-post-objects-field__item-body">
                            <span
                                className={'fabrement-post-objects-field__item-title' + (!ref && !loading ? ' fabrement-post-objects-field__item-title--missing' : '')}
                                title={title}
                            >{title}{ref && <LangChips languages={ref.languages} />}</span>
                            {ref && (
                                <span className="fabrement-post-objects-field__item-meta">
                                    <span className="fabrement-post-objects-field__item-type">{ref.postType || 'post'}</span>
                                    {ref.url}
                                </span>
                            )}
                            <OnPageNote record={ref} />
                        </span>
                        <button
                            type="button" className="fabrement-post-objects-field__btn" title={__('Move up', 'fabrement-lite')}
                            disabled={i === 0} onClick={() => move(i, -1)}
                        >↑</button>
                        <button
                            type="button" className="fabrement-post-objects-field__btn" title={__('Move down', 'fabrement-lite')}
                            disabled={i === ids.length - 1} onClick={() => move(i, 1)}
                        >↓</button>
                        <button
                            type="button" className="fabrement-post-objects-field__btn fabrement-post-objects-field__btn--remove"
                            title={__('Remove', 'fabrement-lite')} onClick={() => removeAt(i)}
                        >×</button>
                    </div>
                );
            })}
        </div>
    ) : (
        <div className="fabrement-post-objects-field__empty">{__('No records picked yet', 'fabrement-lite')}</div>
    );

    const field = (
        <div className="fabrement-post-objects-field">
            {label && <span className="fabrement-post-picker__label">{label}</span>}
            {list}
            <button
                type="button"
                className="fabrement-post-objects-field__add"
                onClick={() => setIsOpen(true)}
                disabled={full}
            >
                {full
                    /* translators: %d: the most records the field takes */
                    ? wp.i18n.sprintf(__('Maximum %d reached', 'fabrement-lite'), cap)
                    : __('+ Add record', 'fabrement-lite')}
            </button>
        </div>
    );

    if (!isOpen) return field;

    const hint = cap
        /* translators: 1: number of records picked, 2: the most records the field takes */
        ? wp.i18n.sprintf(__('%1$d of %2$d picked — click a row to add or remove it.', 'fabrement-lite'), ids.length, cap)
        : __('Click a row to add or remove it.', 'fabrement-lite');

    return (
        <Fragment>
            {field}
            <PostPickerModal
                title={label || __('Posts', 'fabrement-lite')}
                postTypes={postTypes}
                selectedIds={ids}
                full={full}
                hint={hint}
                onPick={toggle}
                onClose={() => setIsOpen(false)}
                footer={(
                    <div className="fabrement-post-picker__footer">
                        <span className="fabrement-post-picker__hint">
                            {ids.length
                                /* translators: %d: number of records picked */
                                ? wp.i18n.sprintf(__('%d picked', 'fabrement-lite'), ids.length)
                                : __('Nothing picked', 'fabrement-lite')}
                        </span>
                        <div className="fabrement-post-picker__footer-actions">
                            <Button variant="primary" onClick={() => setIsOpen(false)}>{__('Done', 'fabrement-lite')}</Button>
                        </div>
                    </div>
                )}
            />
        </Fragment>
    );
}
