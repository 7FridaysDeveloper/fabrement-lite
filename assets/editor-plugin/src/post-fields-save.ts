/**
 * Tie the fields box to Gutenberg's explicit saves. Fields are saved first only when they have
 * unsaved changes; otherwise the post saves exactly as it would without them, so a fields
 * problem can never block publishing an unrelated edit. Autosave and preview never save fields.
 */
export function registerPostFieldsSave(
    hooks: Pick<typeof wp.hooks, 'addFilter' | 'removeFilter'>,
    postId: number,
    isDirty: () => boolean,
    save: () => Promise<void>,
): () => void {
    const namespace = 'fabrement/post-fields-' + postId;
    hooks.addFilter('editor.preSavePost', namespace, async (edits: {id?: number | string}, options: {isAutosave?: boolean; isPreview?: boolean} = {}) => {
        if (options.isAutosave || options.isPreview || Number(edits.id) !== postId || !isDirty()) return edits;
        await save();
        return edits;
    }, 100);
    return () => hooks.removeFilter('editor.preSavePost', namespace);
}

export type FieldIntent = true | {[key: string]: FieldIntent};

/** Compose actual control edits. Equality with a displayed default does not cancel an edit. */
export function mergeFieldIntent(before: FieldIntent, next: FieldIntent): FieldIntent {
    if (before === true || next === true) return true;
    const result = {...before};
    for (const key of Object.keys(next)) result[key] = key in result ? mergeFieldIntent(result[key], next[key]) : next[key];
    return result;
}

/** Removed rows drop pending child edits. Atomic link values never have child intent. */
export function fieldWritePayload(fields: Array<{key: string; type: string; sub_fields?: any[]}>, values: Record<string, any>, intent: Record<string, FieldIntent>) {
    const prune = (field: {type: string; sub_fields?: any[]}, value: any, selected: FieldIntent): FieldIntent => {
        if (selected === true || !value) return selected;
        if (field.type === 'repeater') {
            return Object.fromEntries(value.filter((row: any) => row.id in selected)
                .map((row: any) => [row.id, prune({...field, type: 'group'}, row.values, selected[row.id])]));
        }
        return Object.fromEntries((field.sub_fields || []).filter(child => child.key in selected)
            .map(child => [child.key, prune(child, value[child.key], selected[child.key])]));
    };
    const submitted = fields.filter(field => field.key in intent);
    return {values: Object.fromEntries(submitted.map(field => [field.key, values[field.key]])), intentVersion: 1,
        intent: Object.fromEntries(submitted.map(field => [field.key, prune(field, values[field.key], intent[field.key])]))};
}

/**
 * The REST route of the form: a record's fields, or one language of an options page. The
 * language rides along so the titles the server sends for pickers match what they look up; on an
 * options page it is also the language whose values are edited.
 */
export function fieldsRoute(postId: number, options: {slug: string; lang: string} | undefined, pageLanguage: string): string {
    if (options) {
        return '/fabrement/v1/fields/options/' + options.slug + (options.lang ? '?lang=' + encodeURIComponent(options.lang) : '');
    }
    return '/fabrement/v1/fields/post/' + postId + (pageLanguage ? '?lang=' + encodeURIComponent(pageLanguage) : '');
}
