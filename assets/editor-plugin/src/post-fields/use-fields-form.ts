import { registerPostFieldsSave, fieldsRoute, mergeFieldIntent, fieldWritePayload } from '../post-fields-save';
import type { FieldIntent } from '../post-fields-save';
import { refs } from '../refs-repository';
import { pageLanguage } from '../post-picker-shared';
import { decodeEntities } from '../decode-entities';
import type { State, Values, OptionsTarget } from './types';

const { useState, useEffect, useRef } = wp.element;

/** The state the page was printed with (FieldsAdmin::print_state), read once: the first render needs no request. */
function printedState(key: string): State | null {
    const all = (window as unknown as { fabrementFieldsState?: Record<string, State> }).fabrementFieldsState;
    if (!all || !(key in all)) return null;
    const state = all[key];
    delete all[key];
    return state;
}

/** Shared form state and save lifecycle for post fields and options pages. */
export function useFieldsForm(postId: number, options?: OptionsTarget) {
    const [state, setState] = useState<State | null>(null);
    const [values, setValues] = useState<Values>({});
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [generation, setGeneration] = useState(0);
    const currentState = useRef<State | null>(null);
    const currentValues = useRef<Values>({});
    const dirtyRef = useRef(false);
    const writeIntent = useRef<Record<string, FieldIntent>>({});
    const pendingSave = useRef<Promise<void> | null>(null);
    // The pickers' language rides along, so the titles the server sends match what they look up.
    // An options page is edited one language at a time: that language is the row and the pickers'.
    const key = options ? 'options-' + options.slug : String(postId);
    const route = fieldsRoute(postId, options, pageLanguage());

    const markDirty = (next: boolean) => {dirtyRef.current = next; setDirty(next);};
    // Controls holding typed text not handed on yet (EmptyNumber): a save takes them first.
    const drafts = useRef(new Set<() => void>());
    const draftApi = useRef({
        touch: () => markDirty(true),
        hold: (flush: () => void) => {drafts.current.add(flush); return () => {drafts.current.delete(flush);};},
    }).current;
    /** Each held draft committed, one render apart: every commit builds on the values the last one left. */
    const withDrafts = async (): Promise<void> => {
        for (const flush of Array.from(drafts.current)) {
            flush();
            await new Promise(resolve => setTimeout(resolve, 0));
        }
        return saveRef.current();
    };

    useEffect(() => {
        let cancelled = false;
        setBusy(true);
        const printed = generation === 0 ? printedState(key) : null;
        (printed ? Promise.resolve(printed) : wp.apiFetch!({path: route}) as Promise<State>).then((result: State) => {
            if (cancelled) return;
            refs.prime(result.refs);
            currentState.current = result; currentValues.current = result.values; writeIntent.current = {};
            setState(result); setValues(result.values); markDirty(false); setError('');
        }).catch((e: any) => {if (!cancelled) setError(decodeEntities(e.message || __('Could not load fields.', 'fabrement-lite')));})
            .finally(() => {if (!cancelled) setBusy(false);});
        return () => {cancelled = true;};
    }, [key, generation]);
    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => {event.preventDefault(); event.returnValue = '';};
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const save = (): Promise<void> => {
        // Both the button and the post save await the same request, failures included.
        if (pendingSave.current) return pendingSave.current;
        const snapshot = currentState.current;
        if (!snapshot) {
            const message = __('Fields are not loaded. Reload them before saving.', 'fabrement-lite');
            setError(message);
            return Promise.reject(new Error(message));
        }
        const submitted = currentValues.current;
        setBusy(true); setError(''); setSaved(false);
        const payload = fieldWritePayload(snapshot.fields, submitted, writeIntent.current);
        pendingSave.current = wp.apiFetch!({path: route, method: 'PUT', data: payload})
            .then((result: State) => {
                refs.prime(result.refs);
                currentState.current = result;
                setState(result);
                // A rich-text control can still emit an edit while the request is in flight.
                if (currentValues.current !== submitted) {
                    markDirty(true);
                    throw new Error(__('Fields changed while saving. Review them and save again.', 'fabrement-lite'));
                }
                currentValues.current = result.values; writeIntent.current = {};
                setValues(result.values); markDirty(false); setSaved(true);
            })
            .catch((e: any) => {
                const message = decodeEntities(e.message || __('Could not save fields.', 'fabrement-lite'));
                setError(message);
                throw new Error(message);
            })
            .finally(() => {pendingSave.current = null; setBusy(false);});
        return pendingSave.current;
    };
    const saveRef = useRef(save);
    saveRef.current = save;
    // A record's fields also save with the post; an options page has only its own button.
    useEffect(() => options ? undefined : registerPostFieldsSave(wp.hooks, postId, () => dirtyRef.current, () => withDrafts()), [key]);

    const change = (next: Values, intent: FieldIntent = {}) => {writeIntent.current = mergeFieldIntent(writeIntent.current, intent) as Record<string, FieldIntent>; currentValues.current = next; setValues(next); markDirty(true); setSaved(false);};

    const reload = () => {
        if (dirty && !window.confirm(__('Discard unsaved field changes and reload?', 'fabrement-lite'))) return;
        setGeneration(n => n + 1); setSaved(false);
    };
    return {key, state, values, dirty, busy, error, saved, draftApi, change, save: withDrafts, reload};
}
