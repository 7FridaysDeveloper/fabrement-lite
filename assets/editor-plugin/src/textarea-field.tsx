import './textarea-field.css';



const { useEffect } = wp.element;

export type TextareaFieldProps = {
    label?:       string;
    value?:       string;
    onChange?:    (value: string) => void;
};

/** Multi-line text input (vertical-resizable). */
export function TextareaField(props: TextareaFieldProps) {
    const {
        label,
        value = '',
        onChange,
    } = props;


    return (
        <div className="fabrement-textarea-field">
            {label && <span className="fabrement-textarea-field__label">{label}</span>}
            <textarea
                className="fabrement-textarea-field__input"
                value={value}
                rows={3}
                onChange={(e: any) => onChange?.(e.target.value)}
            />
        </div>
    );
}
