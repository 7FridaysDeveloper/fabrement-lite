import './download-field.css';



const { useEffect } = wp.element;
const MediaUpload      = wp.blockEditor?.MediaUpload;
const MediaUploadCheck = wp.blockEditor?.MediaUploadCheck;

export type DownloadFieldValue = {
    id?:       number;
    url?:      string;
    filename?: string;
    /** Bytes — for displaying "1.4 MB" beside filename. */
    fileSize?: number;
    /** e.g. "application/pdf". */
    mimeType?: string;
};

export type DownloadFieldProps = {
    label?:    string;
    value?:    DownloadFieldValue;
    onChange?: (value: DownloadFieldValue) => void;
};

const UploadIcon = (
    <svg width={16} height={16} viewBox="0 0 24 24" className="fabrement-download-field__upload-icon"
        fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <polyline points="17 8 12 3 7 8" />
        <line x1="12" y1="3" x2="12" y2="15" />
    </svg>
);

function extFromFilename(filename: string): string {
    const dot = filename.lastIndexOf('.');
    if (dot === -1 || dot === filename.length - 1) return __('FILE', 'fabrement-lite');
    return filename.slice(dot + 1).toUpperCase().slice(0, 4);
}

function formatBytes(bytes: number): string {
    if (!bytes || bytes <= 0) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let n = bytes;
    let u = 0;
    while (n >= 1024 && u < units.length - 1) { n /= 1024; u += 1; }
    return n.toFixed(n < 10 && u > 0 ? 1 : 0) + ' ' + units[u];
}

/**
 * WP Media library picker for any file type: a preview tile with the filename, an extension
 * badge, the size and Change/Remove actions.
 */
export function DownloadField(props: DownloadFieldProps) {
    const { label, onChange } = props;
    const value = props.value || {};
    const fileId   = value.id   || 0;
    const fileUrl  = value.url  || '';
    const filename = value.filename || (fileUrl ? fileUrl.split('/').pop() || '' : '');
    const fileSize = value.fileSize || 0;


    if (!MediaUpload || !MediaUploadCheck) {
        return (
            <div className="fabrement-download-field">
                {label && <span className="fabrement-download-field__label">{label}</span>}
                <div style={{ fontSize: 12, color: '#aaa' }}>{__('The media library is not available here.', 'fabrement-lite')}</div>
            </div>
        );
    }

    return (
        <div className="fabrement-download-field">
            {label && <span className="fabrement-download-field__label">{label}</span>}
            <MediaUploadCheck>
                <MediaUpload
                    onSelect={(media: any) => onChange?.({
                        id:       media.id,
                        url:      media.url,
                        filename: media.filename || media.name || '',
                        fileSize: media.filesizeInBytes || media.filesize || 0,
                        mimeType: media.mime || media.mime_type || '',
                    })}
                    value={fileId}
                    render={({ open }: { open: () => void }) => {
                        if (!fileUrl) {
                            return (
                                <button type="button" className="fabrement-download-field__upload" onClick={open}>
                                    {UploadIcon}
                                    <span className="fabrement-download-field__upload-text">{__('Upload file', 'fabrement-lite')}</span>
                                </button>
                            );
                        }
                        const ext  = extFromFilename(filename || fileUrl);
                        const size = formatBytes(fileSize);
                        return (
                            <div className="fabrement-download-field__preview">
                                <div className="fabrement-download-field__file-icon" title={ext}>{ext}</div>
                                <div className="fabrement-download-field__file-info">
                                    <span className="fabrement-download-field__filename" title={filename}>{filename || __('Untitled', 'fabrement-lite')}</span>
                                    {size && <span className="fabrement-download-field__meta">{size}</span>}
                                </div>
                                <div className="fabrement-download-field__actions">
                                    <button type="button" className="fabrement-download-field__action" onClick={open}>{__('Change', 'fabrement-lite')}</button>
                                    <button
                                        type="button"
                                        className="fabrement-download-field__action fabrement-download-field__action--remove"
                                        onClick={() => onChange?.({})}
                                    >{__('Remove', 'fabrement-lite')}</button>
                                </div>
                            </div>
                        );
                    }}
                />
            </MediaUploadCheck>
        </div>
    );
}
