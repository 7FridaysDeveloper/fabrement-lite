import './date-picker-field.css';


const { useEffect, useState } = wp.element;

export type DatePickerFieldProps = {
    label?: string;
    /** YYYY-MM-DD, or YYYY-MM-DDTHH:mm with withTime. Empty string clears it. */
    value?: string;
    onChange?: (value: string) => void;
    /** Local wall time in the site's timezone, never the browser's UTC offset. */
    withTime?: boolean;
};

/**
 * Native calendar (and optional clock) in the shared field style. The browser
 * localizes the input; onChange always receives the machine-readable string.
 */
export function DatePickerField({ label, value = '', onChange, withTime = false }: DatePickerFieldProps) {
    const [draft, setDraft] = useState<string | null>(null);

    useEffect(() => { setDraft(null); }, [value, withTime]);

    return (
        <div className="fabrement-date-picker-field">
            <label className="fabrement-date-picker-field__label">
                {label && <span className="fabrement-date-picker-field__caption">{label}</span>}
                <input
                    type={withTime ? 'datetime-local' : 'date'}
                    className="fabrement-date-picker-field__input"
                    aria-label={label || (withTime ? __('Date and time', 'fabrement-lite') : __('Date', 'fabrement-lite'))}
                    value={draft ?? value}
                    min={withTime ? '0001-01-01T00:00' : '0001-01-01'}
                    max={withTime ? '9999-12-31T23:59' : '9999-12-31'}
                    step={withTime ? 60 : 1}
                    onChange={(event: any) => {
                        const input = event.currentTarget as HTMLInputElement;
                        setDraft(input.value);
                        // No native form is submitted here: check validity, so a manually
                        // typed out-of-range date is never handed on.
                        if (input.validity.valid) onChange?.(input.value);
                    }}
                    onBlur={() => { setDraft(null); }}
                />
            </label>
            {(value || draft) && (
                <button type="button" className="fabrement-date-picker-field__clear"
                    onClick={() => { setDraft(null); onChange?.(''); }}>
                    {__('Clear date', 'fabrement-lite')}
                </button>
            )}
        </div>
    );
}
