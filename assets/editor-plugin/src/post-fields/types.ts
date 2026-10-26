import type { RefsPayload } from '../refs-repository';

export type Field = {
    key: string; name: string; label: string; description: string; type: string; required: boolean;
    format?: 'integer' | 'decimal'; min?: number; max?: number; default?: any;
    choices?: Array<{value: string; label: string}>; sub_fields?: Field[]; postTypes?: string[];
};
export type LayoutItem = {field: string; width?: string; display?: string};
export type Layout = {tabs: Array<{label: string; items: LayoutItem[]}>};
export type Values = Record<string, any>;
export type Translation = {isTranslation: boolean; managedByWpml?: boolean; lockedFields?: string[]; lockedRows?: string[]; wordFields?: string[]; error?: string};
export type State = {owner: string; fields: Field[]; layout: Layout; values: Values; exists: Record<string, boolean>; translation?: Translation; refs?: RefsPayload};

/** One options page and the language currently being edited. */
export type OptionsTarget = {slug: string; lang: string};
