import './select-field.css';



const { useEffect } = wp.element;

export type SelectFieldOption = { value: string; label: string };

export type SelectFieldProps = {
    label?:    string;
    value?:    string;
    onChange?: (value: string) => void;
    options?:  SelectFieldOption[];
};

/** Native <select> styled to match the rest of the fabrement-* fields. */
export function SelectField(props: SelectFieldProps) {
    const {
        label,
        value = '',
        onChange,
        options = [],
    } = props;


    return (
        <div className="fabrement-select-field">
            {label && <span className="fabrement-select-field__label">{label}</span>}
            <div className="fabrement-select-field__wrapper">
                <select
                    className="fabrement-select-field__select"
                    value={value}
                    onChange={(e: any) => onChange?.(e.target.value)}
                >
                    {options.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))}
                </select>
                <span className="fabrement-select-field__arrow">▼</span>
            </div>
        </div>
    );
}
