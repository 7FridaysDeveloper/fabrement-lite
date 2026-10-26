import './image-field.css';



const { useEffect } = wp.element;
const MediaUpload      = wp.blockEditor?.MediaUpload;
const MediaUploadCheck = wp.blockEditor?.MediaUploadCheck;

export type ImageFieldValue = { id?: number; url?: string; alt?: string };

export type ImageFieldProps = {
    label?:    string;
    value?:    ImageFieldValue;
    onChange?: (value: ImageFieldValue) => void;
};

const UploadIcon = (
    <svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="currentColor"
        strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <rect x={3} y={3} width={18} height={18} rx={2} />
        <circle cx={8.5} cy={8.5} r={1.5} />
        <polyline points="21 15 16 10 5 21" />
    </svg>
);

/** WP Media library picker styled as a square upload tile / preview-with-actions. */
export function ImageField(props: ImageFieldProps) {
    const { label, onChange } = props;
    const value = props.value || {};
    const imageId  = value.id  || 0;
    const imageUrl = value.url || '';


    if (!MediaUpload || !MediaUploadCheck) {
        return (
            <div className="fabrement-image-field">
                {label && <span className="fabrement-image-field__label">{label}</span>}
                <div style={{ fontSize: 12, color: '#aaa' }}>{__('The media library is not available here.', 'fabrement-lite')}</div>
            </div>
        );
    }

    return (
        <div className="fabrement-image-field">
            {label && <span className="fabrement-image-field__label">{label}</span>}
            <MediaUploadCheck>
                <MediaUpload
                    onSelect={(media: any) => onChange?.({ id: media.id, url: media.url, alt: media.alt || '' })}
                    allowedTypes={['image']}
                    value={imageId}
                    render={({ open }: { open: () => void }) => {
                        if (imageUrl) {
                            return (
                                <div className="fabrement-image-field__preview">
                                    <img src={imageUrl} alt="" className="fabrement-image-field__img" />
                                    <div className="fabrement-image-field__actions">
                                        <button
                                            type="button"
                                            className="fabrement-image-field__action"
                                            onClick={open}
                                        >{__('Change', 'fabrement-lite')}</button>
                                        <button
                                            type="button"
                                            className="fabrement-image-field__action fabrement-image-field__action--remove"
                                            onClick={() => onChange?.({ id: 0, url: '', alt: '' })}
                                        >{__('Remove', 'fabrement-lite')}</button>
                                    </div>
                                </div>
                            );
                        }
                        return (
                            <button
                                type="button"
                                className="fabrement-image-field__upload"
                                onClick={open}
                            >
                                {UploadIcon}
                                {__('Upload image', 'fabrement-lite')}
                            </button>
                        );
                    }}
                />
            </MediaUploadCheck>
        </div>
    );
}
