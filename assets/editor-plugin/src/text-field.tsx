import './text-field.css';



const { useEffect } = wp.element;

export type TextFieldProps = {
    label?:       string;
    value?:       string;
    onChange?:    (value: string) => void;
};

/** Single-line text input with a small uppercase label. */
export function TextField(props: TextFieldProps) {
    const {
        label,
        value = '',
        onChange,
    } = props;


    return (
        <div className="fabrement-text-field">
            {label && <span className="fabrement-text-field__label">{label}</span>}
            <input
                type="text"
                className="fabrement-text-field__input"
                value={value}
                onChange={(e: any) => onChange?.(e.target.value)}
            />
        </div>
    );
}
