import { useEffect, useRef, useState } from '@wordpress/element';
import { useFolders } from './FolderContext';

const FB_COLORS = [
    "#0EA5E9", "#06B6D4", "#14B8A6", "#10B981", "#22C55E", "#84CC16",
    "#F59E0B", "#F97316", "#EF4444", "#EC4899", "#A855F7", "#6366F1",
    "#64748B", "#1E293B", "#7C3AED", "#0369A1", "#92400E", "#166534"
];

const FolderContextMenu = () => {
    const {
        contextMenu, setContextMenu, setDraftFolderParentId,
        setEditingFolderId, deleteFolder, changeFolderColor,
        duplicateFolder, moveFolder,
        clipboardFolderId, setClipboardFolderId,
        folderToDelete, setFolderToDelete
    } = useFolders();

    const [showColorPicker, setShowColorPicker] = useState(false);
    const [customColor, setCustomColor] = useState('#454554');
    const menuRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target)) {
                setContextMenu(null);
            }
        };
        const handleScroll = () => setContextMenu(null);

        if (contextMenu && contextMenu.visible) {
            document.addEventListener('mousedown', handleClickOutside);
            window.addEventListener('scroll', handleScroll, true);
        }

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            window.removeEventListener('scroll', handleScroll, true);
        };
    }, [contextMenu, setContextMenu]);

    if (!contextMenu || !contextMenu.visible) return null;

    const { x, y, folder } = contextMenu;

    // Estimate dimensions
    const estimatedHeight = 310;
    const estimatedWidth = 160;
    const flyoutWidth = 220;

    let adjustedY = y;
    if (y + estimatedHeight > window.innerHeight) {
        adjustedY = Math.max(10, window.innerHeight - estimatedHeight - 10);
    }

    let adjustedX = x;
    if (x + estimatedWidth > window.innerWidth) {
        adjustedX = Math.max(10, window.innerWidth - estimatedWidth - 10);
    }

    const flyoutSide = (adjustedX + estimatedWidth + flyoutWidth > window.innerWidth) ? 'left' : 'right';

    const handleAction = (action) => {
        if (action === 'new') {
            setContextMenu(null);
            setDraftFolderParentId(folder.id);
        } else if (action === 'rename') {
            setContextMenu(null);
            setEditingFolderId(folder.id);
        } else if (action === 'delete') {
            setContextMenu(null);
            setFolderToDelete(folder);
        } else if (action === 'cut') {
            setContextMenu(null);
            if (setClipboardFolderId) setClipboardFolderId(folder.id);
        } else if (action === 'duplicate') {
            setContextMenu(null);
            if (duplicateFolder) duplicateFolder(folder.id);
        }
    };

    const handlePaste = (e) => {
        e.stopPropagation();
        setContextMenu(null);
        if (clipboardFolderId && moveFolder) {
            if (clipboardFolderId !== folder.id) {
                moveFolder(clipboardFolderId, folder.id);
            }
            if (setClipboardFolderId) setClipboardFolderId(null);
        }
    };

    const handleDownload = async (e) => {
        e.stopPropagation();
        setContextMenu(null);

        const downloadUrl = `${window.mediaOrganizerParams.restUrl}folders/${folder.id}/download?_wpnonce=${window.mediaOrganizerParams.nonce}`;

        try {
            if (typeof window.Swal !== 'undefined') {
                window.Swal.fire({
                    title: 'Preparing Download...',
                    text: 'Please wait while we zip your files.',
                    allowOutsideClick: false,
                    didOpen: () => {
                        window.Swal.showLoading();
                    }
                });
            }

            const response = await fetch(downloadUrl);

            if (!response.ok) {
                let errorMsg = 'An error occurred';
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorMsg;
                } catch (e) { }
                if (typeof window.Swal !== 'undefined') {
                    window.Swal.fire('Download Failed', errorMsg, 'error');
                } else {
                    alert('Download Failed: ' + errorMsg);
                }
                return;
            }

            const data = await response.json();
            if (data && data.url) {
                window.location.href = data.url;
            }

            if (typeof window.Swal !== 'undefined') {
                window.Swal.close();
            }
        } catch (err) {
            console.error('Download error:', err);
            if (typeof window.Swal !== 'undefined') {
                window.Swal.fire('Error', 'Network or server error occurred during download.', 'error');
            }
        }
    };

    const handleColorChange = (e, color) => {
        e.stopPropagation();
        setContextMenu(null);
        changeFolderColor(folder.id, color);
    };

    return (
        <div
            ref={menuRef}
            className="folderify-context-menu"
            style={{
                position: 'fixed',
                top: adjustedY,
                left: adjustedX,
                background: '#fff',
                border: '1px solid #dcdcde',
                boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
                zIndex: 999999,
                padding: '5px 0',
                minWidth: '160px',
                borderRadius: '3px'
            }}
        >
            {mediaOrganizerParams?.isPremium && (
                <>
                    <div
                        className="folderify-context-item"
                        onClick={() => handleAction('new')}
                        onMouseEnter={e => e.currentTarget.style.background = '#f0f6fc'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e' }}
                    >
                        <span className="dashicons dashicons-plus-alt2" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                        <span>New Subfolder</span>
                    </div>

                    <div style={{ height: '1px', background: '#f0f0f1', margin: '4px 0' }}></div>
                </>
            )}

            <div
                className="folderify-context-item"
                onClick={() => handleAction('rename')}
                onMouseEnter={e => { e.currentTarget.style.background = '#f0f6fc'; setShowColorPicker(false); }}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e' }}
            >
                <span className="dashicons dashicons-edit" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                Rename
            </div>

            <div style={{ height: '1px', background: '#f0f0f1', margin: '4px 0' }}></div>

            {mediaOrganizerParams?.isPremium && (
                <>
                    <div
                        className="folderify-context-item"
                        onClick={() => handleAction('cut')}
                        onMouseEnter={e => { e.currentTarget.style.background = '#f0f6fc'; setShowColorPicker(false); }}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e' }}
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '8px', color: '#a0aab2' }}>
                            <circle cx="6" cy="6" r="3"></circle>
                            <circle cx="6" cy="18" r="3"></circle>
                            <line x1="20" y1="4" x2="8.12" y2="15.88"></line>
                            <line x1="14.47" y1="14.48" x2="20" y2="20"></line>
                            <line x1="8.12" y1="8.12" x2="12" y2="12"></line>
                        </svg>
                        <span>Cut</span>
                    </div>

                    <div
                        className="folderify-context-item"
                        onClick={handlePaste}
                        onMouseEnter={e => {
                            e.currentTarget.style.background = clipboardFolderId ? '#f0f6fc' : 'transparent';
                            setShowColorPicker(false);
                        }}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{
                            padding: '7px 15px',
                            cursor: clipboardFolderId ? 'pointer' : 'default',
                            display: 'flex',
                            alignItems: 'center',
                            fontSize: '13px',
                            color: clipboardFolderId ? '#50575e' : '#a0aab2',
                            opacity: clipboardFolderId ? 1 : 0.6
                        }}
                    >
                        <span className="dashicons dashicons-clipboard" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                        <span>Paste</span>
                    </div>

                    <div style={{ height: '1px', background: '#f0f0f1', margin: '4px 0' }}></div>

                    <div
                        className="folderify-context-item"
                        onClick={() => handleAction('duplicate')}
                        onMouseEnter={e => { e.currentTarget.style.background = '#f0f6fc'; setShowColorPicker(false); }}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e' }}
                    >
                        <span className="dashicons dashicons-admin-page" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                        <span>Duplicate</span>
                    </div>

                    <div
                        className="folderify-context-item"
                        onMouseEnter={e => {
                            e.currentTarget.style.background = '#f0f6fc';
                            setShowColorPicker(true);
                        }}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e', position: 'relative' }}
                    >
                        <span className="dashicons dashicons-art" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                        <span>Change Color</span>
                        <span className="dashicons dashicons-arrow-right-alt2" style={{ marginLeft: 'auto', fontSize: '14px', width: '14px', color: '#a0aab2', marginTop: '5px' }}></span>

                        {showColorPicker && (
                            <div
                                className="folderify-color-picker"
                                onMouseEnter={() => setShowColorPicker(true)}
                                onMouseLeave={() => setShowColorPicker(false)}
                                style={{
                                    position: 'absolute',
                                    left: flyoutSide === 'right' ? '100%' : 'auto',
                                    right: flyoutSide === 'left' ? '100%' : 'auto',
                                    marginLeft: flyoutSide === 'right' ? '2px' : '0',
                                    marginRight: flyoutSide === 'left' ? '2px' : '0',
                                    bottom: '-40px',
                                    background: '#fff',
                                    border: '1px solid #dcdcde',
                                    boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
                                    borderRadius: '3px',
                                    padding: '10px',
                                    display: 'grid',
                                    gridTemplateColumns: 'repeat(6, 24px)',
                                    gap: '8px',
                                    zIndex: 9999999
                                }}
                            >
                                {FB_COLORS.map(color => (
                                    <div
                                        key={color}
                                        onClick={(e) => handleColorChange(e, color)}
                                        style={{
                                            width: '24px',
                                            height: '24px',
                                            backgroundColor: color,
                                            borderRadius: '3px',
                                            cursor: 'pointer',
                                            border: '1px solid rgba(0,0,0,0.1)',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center'
                                        }}
                                    >
                                        {folder.color === color && (
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        )}
                                    </div>
                                ))}

                                <div style={{ gridColumn: '1 / -1', marginTop: '4px', display: 'flex', gap: '4px' }}>
                                    <input
                                        type="text"
                                        value={customColor}
                                        onChange={(e) => setCustomColor(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && customColor) {
                                                handleColorChange(e, customColor);
                                            }
                                        }}
                                        placeholder="#HEX"
                                        style={{
                                            flex: 1, padding: '2px 6px', fontSize: '12px',
                                            border: '1px solid #dcdcde', borderRadius: '3px',
                                            outline: 'none', minWidth: 0
                                        }}
                                        onClick={(e) => e.stopPropagation()}
                                    />
                                    <button
                                        onClick={(e) => handleColorChange(e, customColor)}
                                        style={{
                                            padding: '2px 8px', fontSize: '11px', background: '#f6f7f7',
                                            border: '1px solid #dcdcde', borderRadius: '3px', cursor: 'pointer',
                                            color: '#50575e'
                                        }}
                                    >
                                        Apply
                                    </button>
                                </div>
                                <div
                                    onClick={(e) => handleColorChange(e, '')}
                                    onMouseEnter={e => e.currentTarget.style.color = 'var(--wp-admin-theme-color, #2271b1)'}
                                    onMouseLeave={e => e.currentTarget.style.color = '#50575e'}
                                    style={{
                                        gridColumn: '1 / -1',
                                        textAlign: 'center',
                                        fontSize: '12px',
                                        color: '#50575e',
                                        cursor: 'pointer',
                                        borderTop: '1px solid #f0f0f1',
                                        paddingTop: '8px',
                                        marginTop: '4px',
                                        transition: 'color 0.2s ease'
                                    }}
                                >
                                    Reset Color
                                </div>
                            </div>
                        )}
                    </div>

                    <div
                        className="folderify-context-item"
                        onClick={handleDownload}
                        onMouseEnter={e => e.currentTarget.style.background = '#f0f6fc'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#50575e' }}
                    >
                        <span className="dashicons dashicons-download" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#a0aab2' }}></span>
                        <span>Download</span>
                    </div>
                </>
            )}

            {(typeof window.mediaOrganizerParams === 'undefined' || !!window.mediaOrganizerParams.canDelete) && (
                <>
                    <div style={{ height: '1px', background: '#f0f0f1', margin: '4px 0' }}></div>
                    <div
                        className="folderify-context-item"
                        onClick={() => handleAction('delete')}
                        onMouseEnter={e => e.currentTarget.style.background = '#fcf0f1'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#d63638' }}
                    >
                        <span className="dashicons dashicons-trash" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#d63638' }}></span>
                        Delete
                    </div>
                </>
            )}

            {!mediaOrganizerParams?.isPremium && (
                <>
                    <div style={{ height: '1px', background: '#f0f0f1', margin: '4px 0' }}></div>
                    <div
                        className="folderify-context-item"
                        onClick={() => {
                            const pricingUrl = mediaOrganizerParams?.pricingUrl || (mediaOrganizerParams?.settingsUrl + '-pricing');
                            window.open(pricingUrl, '_blank');
                        }}
                        onMouseEnter={e => e.currentTarget.style.background = '#fcf0f1'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        style={{ padding: '7px 15px', cursor: 'pointer', display: 'flex', alignItems: 'center', fontSize: '13px', color: '#d63638', fontWeight: 600 }}
                    >
                        <span className="dashicons dashicons-star-filled" style={{ marginRight: '8px', fontSize: '16px', width: '16px', color: '#fecb2e' }}></span>
                        Upgrade to Pro
                    </div>
                </>
            )}

        </div>
    );
};

export default FolderContextMenu;
