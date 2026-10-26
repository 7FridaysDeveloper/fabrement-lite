import { TextField } from '../text-field';
import { TextareaField } from '../textarea-field';
import { WysiwygField } from '../wysiwyg-field';
import { NumberField } from '../number-field';
import { DatePickerField } from '../date-picker-field';
import { SelectField } from '../select-field';
import { ImageField } from '../image-field';
import { DownloadField } from '../download-field';
import { LinkField } from '../link-field';
import { PostObjectField } from '../post-object-field';
import { PostObjectsField } from '../post-objects-field';
import { RepeaterItem, AddButton, reorderItems } from '../repeater';
import { GalleryField } from '../gallery-field';
import { cachedMedia, rememberMedia, toMediaRef, useMediaRefs } from '../media-refs';
import { decodeEntities } from '../decode-entities';

import type { FieldIntent } from '../post-fields-save';
import type { Field, LayoutItem, Values } from './types';
import { Locks, Drafts } from './context';

const { useState, useEffect, useRef } = wp.element;

const join = (path: string, key: string) => (path ? path + '/' + key : key);

const WIDE = ['textarea', 'wysiwyg', 'relationship', 'gallery', 'group', 'repeater'];
const SPAN: Record<string, number> = {'1/4': 3, '1/3': 4, '1/2': 6, '2/3': 8, '3/4': 9, '1': 12};

function empty(field: Field): any {
    if (field.default !== undefined) return field.default;
    if (field.type === 'true_false') return null;
    if (field.type === 'number') return null;
    if (['image', 'file', 'post_object'].includes(field.type)) return 0;
    if (['relationship', 'gallery', 'repeater'].includes(field.type)) return [];
    if (field.type === 'group') return Object.fromEntries((field.sub_fields || []).map(child => [child.key, empty(child)]));
    if (field.type === 'link') return {url: '', label: '', postId: 0, lang: '', newTab: false, useRecordTitle: false};
    return '';
}

/** A new row's identity is made here and kept by the server, so it stays stable across saves. */
function rowId(): string {
    const bytes = new Uint8Array(6);
    crypto.getRandomValues(bytes);
    return 'row_' + Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('');
}

function MediaField({field, value, onChange}: {field: Field; value: number; onChange: (value: number) => void}) {
    useMediaRefs(value ? [value] : []);
    const ref = value ? cachedMedia(value) : undefined;
    // The control keeps the ID and can still clear or replace it while a preview is missing.
    const media = !value ? {} : ref && !ref.missing
        ? {id: value, url: ref.url, alt: ref.alt, filename: ref.filename, mimeType: ref.mimeType, fileSize: ref.fileSize}
        : {id: value};
    const Component = field.type === 'image' ? ImageField : DownloadField;
    return <Component label="" value={media} onChange={(item: any) => {rememberMedia(toMediaRef('control', item)); onChange(item.id || 0);}} />;
}

export function FieldControl({field, value, onChange, path}:{field: Field; value: any; onChange: (value: any, intent?: FieldIntent) => void; path: string}) {
    const common = {label: '', value, onChange};
    const locks = (wp.element as any).useContext(Locks);
    switch (field.type) {
        // Words someone saved through kses hold "&lt;" / "&amp;": shown as "<" / "&", as ACF's esc_attr() shows them.
        case 'text': return <TextField {...common} value={typeof value === 'string' ? decodeEntities(value) : ''} />;
        case 'textarea': return <TextareaField {...common} value={typeof value === 'string' ? decodeEntities(value) : ''} />;
        case 'wysiwyg': return <WysiwygField {...common} value={value ?? ''} />;
        // A switch has three states: not set, explicitly false, explicitly true.
        // Coercing null to false here would lose that distinction when another field is saved.
        case 'true_false': return <SelectField label="" value={value === null ? '' : value ? 'true' : 'false'}
            options={[{value: '', label: __('No value', 'fabrement-lite')}, {value: 'true', label: __('Yes', 'fabrement-lite')}, {value: 'false', label: __('No', 'fabrement-lite')}]}
            onChange={next => onChange(next === '' ? null : next === 'true')} />;
        // Bounded by the field's own min/max, else by what a number holds exactly (the server's
        // ValueCodec::NUMBER_LIMIT): a longer one would be refused on save, or rounded here first.
        case 'number': {
            const low = field.min ?? -Number.MAX_SAFE_INTEGER, high = field.max ?? Number.MAX_SAFE_INTEGER;
            return <div>
            {value === null ? <EmptyNumber label={field.label} low={low} high={high} whole={field.format === 'integer'} onChange={onChange} />
                : <NumberField {...common} min={low} max={high} format={field.format} />}
            {value !== null && <button className="button-link" type="button" onClick={() => onChange(null)}>{__('Clear number', 'fabrement-lite')}</button>}
        </div>;
        }
        case 'select': return <SelectField {...common} value={value ?? ''} options={[{value: '', label: __('Select…', 'fabrement-lite')}, ...(field.choices || [])]} />;
        case 'date_picker': case 'date_time_picker': return <DatePickerField {...common} value={value ?? ''} withTime={field.type === 'date_time_picker'} />;
        case 'image': case 'file': return <MediaField field={field} value={value ?? 0} onChange={onChange} />;
        case 'gallery': return <GalleryField value={value ?? []} onChange={onChange} max={field.max} min={field.min} />;
        case 'link': return <LinkField {...common} value={value ?? {url: '', label: '', postId: 0, lang: '', newTab: false, useRecordTitle: false}} />;
        case 'post_object': return <PostObjectField {...common} value={value ?? 0} postTypes={field.postTypes} />;
        case 'relationship': return <PostObjectsField {...common} value={value ?? []} postTypes={field.postTypes} max={field.max} />;
        case 'group': return <div className="fabrement-post-fields__children">
            <FieldsGrid auto fields={field.sub_fields || []} values={value || {}} onChange={onChange} path={path} />
        </div>;
        case 'repeater': {
            const rows = Array.isArray(value) ? value : [];
            const rowsShared = locks.rows.includes(path);
            return <div>
                {rowsShared && <p className="fabrement-post-fields__description">{__('Rows are shared across languages. Changes are saved to the original.', 'fabrement-lite')}</p>}
                {rows.map((row: any, index: number) => <RepeaterItem key={row.id}
                    /* translators: %d: row number */
                    label={wp.i18n.sprintf(__('Row %d', 'fabrement-lite'), index + 1)}
                    index={index} count={rows.length} onMove={reorderItems(rows, next => onChange(next, {}))}
                    onRemove={() => onChange(rows.filter((_: any, i: number) => i !== index), {})}>
                    <FieldsGrid auto fields={field.sub_fields || []} values={row.values} path={path}
                        onChange={(values: Values, intent = true) => onChange(rows.map((r: any, i: number) => i === index ? {...r, values} : r), {[row.id]: intent})} />
                </RepeaterItem>)}
                <AddButton count={rows.length} maxItems={Math.min(field.max ?? 500, 500)}
                    onClick={() => {const id = rowId(); onChange([...rows, {id, values: Object.fromEntries((field.sub_fields || []).map(child => [child.key, empty(child)]))}], {[id]: true});}}>
                    {__('Add row', 'fabrement-lite')}
                </AddButton>
            </div>;
        }
        default: return <p role="alert">{__('This field type is not available in this editor.', 'fabrement-lite')}</p>;
    }
}

/**
 * An empty number box. What is typed stays a draft until the box is left (or Enter): handing the
 * first digit up at once swapped this box for the stepper and dropped the rest of the typing —
 * "5000" came out as 5. Bounded on commit like the stepper: max, min, and whole for an integer.
 */
function EmptyNumber({label, low, high, whole, onChange}: {label: string; low: number; high: number; whole: boolean; onChange: (value: number) => void}) {
    const [draft, setDraft] = useState('');
    const drafts = (wp.element as any).useContext(Drafts);
    // Committed on blur or Enter, not per key (the box would turn into the number control mid-typing);
    // a save with the caret still here (Ctrl+S) takes the draft first through `hold`.
    const typed = useRef('');
    const commit = () => {
        const n = Number(typed.current);
        if (typed.current.trim() === '' || !Number.isFinite(n)) return;
        onChange(Math.max(low, Math.min(high, whole ? Math.trunc(n) : n)));
    };
    const commitRef = useRef(commit);
    commitRef.current = commit;
    useEffect(() => drafts.hold(() => commitRef.current()), []);
    return <input className="fabrement-post-fields__number" type="number" aria-label={label}
        placeholder={__('No value', 'fabrement-lite')} min={low} max={high} step={whole ? 1 : 'any'} value={draft}
        onChange={(event: any) => {
            typed.current = String(event.target.value);
            setDraft(typed.current);
            if (typed.current !== '') drafts.touch();
        }} onBlur={commit}
        onKeyDown={(event: any) => {if (event.key === 'Enter') {event.preventDefault(); commit();}}} />;
}

/**
 * `min` of a list is a wish, not a rule: the save goes through, and the form says what is short.
 * An empty list says nothing (it is optional); a gallery shows its own count.
 */
function MinNote({field, value}: {field: Field; value: any}) {
    if (!field.min || !['repeater', 'relationship'].includes(field.type)) return null;
    const count = Array.isArray(value) ? value.length : 0;
    if (count === 0 || count >= field.min) return null;
    return <p className="fabrement-post-fields__min" role="status">
        {/* translators: 1: number of items the field asks for, 2: number of items it has now */
            wp.i18n.sprintf(__('Add at least %1$d (now %2$d).', 'fabrement-lite'), field.min, count)}
    </p>;
}

/**
 * Fields in a grid. `auto`: three/two/one columns by container width, long fields full width.
 * With `items` (a layout tab), each field takes its declared width on a 12-column grid.
 */
export function FieldsGrid({fields, values, onChange, items, auto, path = ''}: {fields: Field[]; values: Values; onChange: (values: Values, intent?: FieldIntent) => void; items?: LayoutItem[]; auto?: boolean; path?: string}) {
    const locks = (wp.element as any).useContext(Locks);
    const byKey = Object.fromEntries(fields.map(field => [field.key, field]));
    const entries: Array<{field: Field; item?: LayoutItem}> = items
        ? items.filter(item => byKey[item.field]).map(item => ({field: byKey[item.field], item}))
        : fields.map(field => ({field}));
    return <div className="fabrement-post-fields__grid-shell"><div className={'fabrement-post-fields__grid' + (auto ? ' fabrement-post-fields__grid--auto' : '')}>
        {entries.map(({field, item}) => {
            const wide = WIDE.includes(field.type);
            const span = item?.width ? SPAN[item.width] : (wide ? 12 : (auto ? undefined : 6));
            const fieldPath = join(path, field.key);
            const copied = locks.fields.includes(fieldPath);
            const words = locks.words.includes(fieldPath);
            const locked = words;
            return <fieldset key={field.key} className="fabrement-post-fields__field" disabled={locked} style={span ? {'--span': span} as any : undefined}>
                <legend>{field.label}{field.required ? ' *' : ''}</legend>
                <p className="fabrement-post-fields__description">{field.description}</p>
                {copied && <p className="fabrement-post-fields__description">{__('Shared across languages. Changes are saved to the original.', 'fabrement-lite')}</p>}
                {words && !copied && <p className="fabrement-post-fields__description">{__('Translated in the WPML translation editor.', 'fabrement-lite')}</p>}
                <FieldControl field={field} path={fieldPath}
                    value={Object.prototype.hasOwnProperty.call(values, field.key) ? values[field.key] : empty(field)}
                    onChange={(value, intent = true) => onChange({...values, [field.key]: value}, {[field.key]: intent})} />
                <MinNote field={field} value={values[field.key]} />
            </fieldset>;
        })}
    </div></div>;
}
