/** In a translation: which fields (key paths, rows ignored) and repeaters' rows the original owns. */
export const Locks = (wp.element as any).createContext({fields: [] as string[], rows: [] as string[], words: [] as string[]});
/**
 * A control that holds typed text it has not handed on yet (an empty number box commits on blur):
 * `touch` marks the form changed at the first key, `hold` lets a save take the draft first.
 */
export const Drafts = (wp.element as any).createContext({touch: () => {}, hold: (_flush: () => void) => () => {}});
