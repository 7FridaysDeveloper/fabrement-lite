import './number-field.css';



const { useEffect, useState } = wp.element;

export type NumberFieldProps = {
    label?:    string;
    value?:    number;
    onChange?: (value: number) => void;
    min?:      number;
    max?:      number;
    /**
     * What kind of number this field is for.
     *
     *   omitted     whatever the editor types — 5 and 1.25 are both kept; the arrows step by 1.
     *   'integer'   whole numbers only: a typed 1.25 becomes 1, and the arrows step by 1.
     *   'decimal'   fractions are the point, so the arrows step by 0.1.
     *
     * `min`/`max` bound the value in every format.
     */
    format?:   'integer' | 'decimal';
};

/** Stepper input (− value +) with hard min/max clamping. */
export function NumberField(props: NumberFieldProps) {
    const { label, onChange, format } = props;

    // parseFloat, not parseInt — which is what this read before, and it silently truncated every
    // fraction the field was ever given: a stored 1.25 arrived as 1 and the next save wrote that
    // 1 back, so the value was lost rather than merely displayed wrong.
    const read  = (raw: unknown) => (typeof raw === 'number' ? raw : parseFloat(String(raw)));
    const whole = format === 'integer';
    const value = whole ? Math.trunc(read(props.value) || 0) : (read(props.value) || 0);
    const min   = typeof props.min === 'number' ? props.min : -Infinity;
    const max   = typeof props.max === 'number' ? props.max :  Infinity;

    // One step size, used by the arrows and declared to the browser, so the two cannot disagree.
    const step = format === 'decimal' ? 0.1 : 1;

    /**
     * What the box shows WHILE it is being typed in.
     *
     * An empty box is a legitimate thing to pass through on the way from 5 to 7, and treating it
     * as "keep what you had" — which is what this did — hands the old value straight back and
     * repaints it, so the field fights the keyboard and the only way to change it is to select
     * all and overtype. The draft holds what the box says; onChange only ever receives
     * numbers.
     *
     * A `type="number"` box reports "" for a half-written "1." or "-" rather than the characters
     * themselves, so those arrive here as the empty case too — which is exactly why it has to be
     * a passable state rather than an error.
     */
    const [draft, setDraft] = useState<string | null>(null);


    const clamp = (v: number) => Math.max(min === -Infinity ? v : min, Math.min(max === Infinity ? v : max, v));

    /**
     * The binary noise off a stepped value — 0.2 + 0.1 is 0.30000000000000004 in any language
     * that stores numbers this way, and that is what would be saved and shown.
     *
     * ONLY for stepping. A typed number is left exactly as typed: rounding it here cost six
     * decimals of anything longer, and quietly turned 0.0000001 into 0.
     */
    const stepped = (v: number) => (whole ? Math.trunc(v) : Math.round(v * 1e6) / 1e6);

    const commit = (v: number) => { setDraft(null); onChange?.(clamp(stepped(v))); };

    return (
        <div className="fabrement-number-field">
            {label && <span className="fabrement-number-field__label">{label}</span>}
            <div className="fabrement-number-field__row">
                <button
                    type="button"
                    className="fabrement-number-field__btn"
                    onClick={() => commit(value - step)}
                    disabled={value <= min}
                >−</button>
                <div className="fabrement-number-field__divider" />
                <input
                    type="number"
                    className="fabrement-number-field__input"
                    value={draft !== null ? draft : String(value)}
                    min={min === -Infinity ? undefined : min}
                    max={max ===  Infinity ? undefined : max}
                    step={step}
                    onChange={(e: any) => {
                        const typed = String(e.target.value);
                        setDraft(typed);

                        const parsed = read(typed);
                        if (typed.trim() === '' || isNaN(parsed)) {
                            // Mid-edit. The value stays what it was until there is a number
                            // to give it — an empty box must not be stored as 0.
                            return;
                        }
                        // The CEILING applies at once, the floor does not. Typing towards 15
                        // with min 10 passes through 1, so clamping up would rewrite the box
                        // under the cursor — the floor therefore waits for blur. Nothing above
                        // the ceiling is ever on the way to a valid number, though, and a value
                        // left out of range by a save before blur is a value that gets saved
                        // out of range.
                        const typedValue = whole ? Math.trunc(parsed) : parsed;
                        onChange?.(max === Infinity ? typedValue : Math.min(typedValue, max));
                    }}
                    onBlur={() => {
                        // Nothing was typed, so there is nothing to settle. Committing anyway
                        // rewrote the stored value on a bare focus-and-tab: an integer field
                        // holding 1.25 read it back as 1 and saved that, truncating a fraction
                        // nobody had touched.
                        if (draft === null) {
                            return;
                        }

                        const parsed = read(draft);
                        setDraft(null);
                        // An abandoned edit — the box left empty or unreadable — falls back to
                        // the value that was there, which is what is still stored.
                        onChange?.(clamp(isNaN(parsed) ? value : (whole ? Math.trunc(parsed) : parsed)));
                    }}
                />
                <div className="fabrement-number-field__divider" />
                <button
                    type="button"
                    className="fabrement-number-field__btn"
                    onClick={() => commit(value + step)}
                    disabled={value >= max}
                >+</button>
            </div>
        </div>
    );
}
