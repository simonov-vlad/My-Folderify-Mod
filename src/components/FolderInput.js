import { useState, useRef, useEffect } from '@wordpress/element';

const FolderInput = ({ onSave, onCancel, initialName = '', standalone = true }) => {
    const [name, setName] = useState(initialName);
    const [isSaving, setIsSaving] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => {
        if (inputRef.current) {
            inputRef.current.focus();
            if (initialName) {
                inputRef.current.select();
            }
        }
    }, [initialName]);

    const handleSave = async () => {
        if (isSaving) return;
        if (name.trim() && name.trim() !== initialName) {
            setIsSaving(true);
            try {
                await onSave(name.trim());
            } catch (e) {
                console.error("Save failed", e);
            } finally {
                setIsSaving(false);
            }
        } else {
            onCancel();
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') handleSave();
        if (e.key === 'Escape' && !isSaving) onCancel();
    };

    // Prevent onBlur from firing before button click
    const preventBlur = (e) => e.preventDefault();

    const content = (
        <div className="folderify-folder-header inline-input" onClick={e => e.stopPropagation()} style={{ display: 'flex', alignItems: 'center', width: 'auto', boxSizing: 'border-box', height: '34px' }}>
            <span className="folder-icon" style={{
                color: '#a0aab2',
                marginRight: '5px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '22px',
                height: '22px'
            }}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" />
                </svg>
            </span>
            <input
                ref={inputRef}
                type="text"
                value={name}
                onChange={e => setName(e.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={handleSave}
                disabled={isSaving}
                style={{ flex: 1, height: '24px', padding: '0 4px', border: '1px solid #ccd0d4', borderRadius: '3px', boxShadow: 'none', margin: 0, minWidth: '0', opacity: isSaving ? '0.6' : '1', fontSize: '13px', color: '#50575e', fontWeight: '400', lineHeight: '1.3' }}
            />
            <button
                className="folderify-input-btn confirm-btn"
                onMouseDown={preventBlur}
                onClick={handleSave}
                title="Save"
                disabled={isSaving}
                style={{ width: "24px", flexShrink: 0, height: "24px", cursor: isSaving ? 'wait' : 'pointer', border: '1px solid #ccd0d4', borderRadius: '3px', background: '#e5e5e5', marginLeft: '5px', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#8c8f94' }}
            >
                {isSaving ? (
                    <svg className="components-spinner" width="14" height="14" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <circle className="components-spinner__path" cx="50" cy="50" fill="none" r="44" strokeWidth="12" stroke="currentColor" style={{ opacity: 0.3 }}></circle>
                        <circle className="components-spinner__path-drawing" cx="50" cy="50" fill="none" r="44" strokeWidth="12" stroke="currentColor" strokeDasharray="276" strokeDashoffset="260" strokeLinecap="round" style={{ transformOrigin: 'center', animation: 'components-spinner__animation 1.5s linear infinite' }}></circle>
                        <style>{`@keyframes components-spinner__animation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }`}</style>
                    </svg>
                ) : (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                )}
            </button>
            <button
                className="folderify-input-btn cancel-btn"
                onMouseDown={preventBlur}
                onClick={onCancel}
                title="Cancel"
                style={{ width: "24px", flexShrink: 0, height: "24px", cursor: 'pointer', border: '1px solid #ccd0d4', borderRadius: '3px', background: '#e5e5e5', marginLeft: '4px', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#8c8f94' }}
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
    );

    return standalone ? <li className="folderify-folder-item">{content}</li> : content;
};

export default FolderInput;
