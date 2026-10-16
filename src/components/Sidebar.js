import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useFolders } from './FolderContext';
import FolderTree from './FolderTree';
import SystemFolders from './SystemFolders';
import FolderContextMenu from './FolderContextMenu';
import ImportPromptModal from './ImportPromptModal';

const Sidebar = () => {
    const [width, setWidth] = useState(() => {
        if (!window.mediaOrganizerParams?.isPremium) return 300;
        try {
            const saved = localStorage.getItem('folderify_sidebar_width');
            return saved !== null ? parseInt(saved, 10) : 300;
        } catch (e) {
            return 300;
        }
    });
    const [isResizing, setIsResizing] = useState(false);
    const [isCollapsed, setIsCollapsed] = useState(() => {
        if (!window.mediaOrganizerParams?.isPremium) return false;
        try {
            const saved = localStorage.getItem('folderify_sidebar_collapsed');
            return saved === 'true';
        } catch (e) {
            return false;
        }
    });
    const [searchQuery, setSearchQuery] = useState('');
    const [isToolbarActive, setIsToolbarActive] = useState(false);
    const [isSortMenuOpen, setIsSortMenuOpen] = useState(false);
    const [isOptionsMenuOpen, setIsOptionsMenuOpen] = useState(false);
    const [isAllCollapsed, setIsAllCollapsed] = useState(false);
    const [showBulkDeleteModal, setShowBulkDeleteModal] = useState(false);
    const [thirdPartyPlugins, setThirdPartyPlugins] = useState([]);
    const [showImportPrompt, setShowImportPrompt] = useState(false);
    const {
        folders, createFolder, activeFolderId, setDraftFolderParentId,
        isProcessing, folderToDelete, setFolderToDelete, deleteFolder,
        folderSort, setFolderSort, fileSort, setFileSort,
        displayFolderId, setDisplayFolderId,
        collapseAllTrigger, setCollapseAllTrigger,
        expandAllTrigger, setExpandAllTrigger,
        isBulkSelectMode, setIsBulkSelectMode,
        selectedFolders, setSelectedFolders,
        toastMessage
    } = useFolders();
    const sidebarRef = useRef(null);
    const isPremium = window.mediaOrganizerParams?.isPremium;

    // Close sort menu when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (isSortMenuOpen && !event.target.closest('.folderify-toolbar-sort') && !event.target.closest('.folderify-sort-dropdown-container')) {
                setIsSortMenuOpen(false);
            }
            if (isOptionsMenuOpen && !event.target.closest('.folderify-toolbar-options') && !event.target.closest('.folderify-options-dropdown-container')) {
                setIsOptionsMenuOpen(false);
            }
        };

        if (isSortMenuOpen || isOptionsMenuOpen) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, [isSortMenuOpen, isOptionsMenuOpen]);

    // Apply CSS var for native UI shifting and save to localStorage
    useEffect(() => {
        const rootWidth = isCollapsed ? 0 : width;
        document.body.style.setProperty('--folderify-sidebar-width', `${rootWidth}px`);

        try {
            localStorage.setItem('folderify_sidebar_width', width);
            localStorage.setItem('folderify_sidebar_collapsed', isCollapsed);
        } catch (e) { }
    }, [width, isCollapsed]);

    // Check for third-party folder plugins to prompt import (PRO ONLY)
    useEffect(() => {
        const isPremium = window.mediaOrganizerParams?.isPremium;
        if (!isPremium) return;

        const isDismissed = localStorage.getItem('folderify_dismissed_import_prompt');
        if (isDismissed === '1') return;

        const ajaxUrl = window.mediaOrganizerParams?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.mediaOrganizerParams?.ajaxNonce || '';

        const timer = setTimeout(() => {
            window.jQuery && window.jQuery.post(ajaxUrl, {
                action: 'folderify_check_third_party_plugins',
                nonce: nonce
            }, function (response) {
                if (response?.success && response?.data?.plugins?.length > 0) {
                    setThirdPartyPlugins(response.data.plugins);
                    setShowImportPrompt(true);
                }
            });
        }, 1500);

        return () => clearTimeout(timer);
    }, []);

    const handleDismissImportPrompt = () => {
        localStorage.setItem('folderify_dismissed_import_prompt', '1');
        setShowImportPrompt(false);
    };

    // Resizing logic
    const startResizing = (e) => {
        setIsResizing(true);
        e.preventDefault(); // Prevent text selection
    };

    useEffect(() => {
        const resize = (e) => {
            if (isResizing && sidebarRef.current) {
                const newWidth = e.clientX - sidebarRef.current.getBoundingClientRect().left;
                if (newWidth >= 300 && newWidth <= 700) {
                    setWidth(newWidth);
                }
            }
        };

        const stopResizing = () => {
            setIsResizing(false);
        };

        if (isResizing) {
            window.addEventListener('mousemove', resize);
            window.addEventListener('mouseup', stopResizing);
        }

        return () => {
            window.removeEventListener('mousemove', resize);
            window.removeEventListener('mouseup', stopResizing);
        };
    }, [isResizing]);

    const handleCreate = () => {
        if (isPremium && activeFolderId && activeFolderId > 0) {
            setDraftFolderParentId(activeFolderId);
        } else {
            setDraftFolderParentId(0);
        }
    };

    return (
        <div
            className={`folderify-sidebar-wrapper ${isCollapsed ? 'collapsed' : ''}`}
            ref={sidebarRef}
            style={{ position: 'relative' }}
        >
            <div className="folderify-sidebar-inner" style={{ display: isCollapsed ? 'none' : 'flex' }}>
                {isProcessing && (
                    <div className="folderify-global-overlay" style={{
                        position: 'absolute',
                        top: 0, left: 0, right: 0, bottom: 0,
                        backgroundColor: 'rgba(255, 255, 255, 0.7)',
                        zIndex: 9999,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        pointerEvents: 'all'
                    }}>
                        <svg width="24" height="24" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="50" fill="none" r="44" strokeWidth="10" stroke="#ccd0d4" />
                            <circle cx="50" cy="50" fill="none" r="44" strokeWidth="10" stroke="var(--wp-admin-theme-color, #2271b1)" strokeDasharray="276" strokeDashoffset="220" strokeLinecap="round" style={{ transformOrigin: 'center', animation: 'folderify_spin 1s linear infinite' }} />
                        </svg>
                    </div>
                )}
                {/* Top Bar: Search & Menu or Toolbar */}
                {isToolbarActive ? (
                    <div className="folderify-top-bar folderify-toolbar-overlay" style={{ justifyContent: 'space-between', padding: '0', backgroundColor: '#f6f7f7' }}>
                        <div style={{ display: 'flex', alignItems: 'center', flex: '1 1 auto', borderRight: '1px solid #dcdcde', padding: '0 10px' }}>
                            <div style={{ position: 'relative' }} className="folderify-sort-wrapper">
                                <button className={`folderify-toolbar-btn folderify-toolbar-sort ${isSortMenuOpen ? 'active' : ''}`} title={__('Sort Folders', 'folderify')} onClick={() => setIsSortMenuOpen(!isSortMenuOpen)} style={{ width: '32px', height: '32px', border: '1px solid #8c8f94', borderRadius: '3px', backgroundColor: isSortMenuOpen ? '#f0f0f1' : '#fff', padding: '0', display: 'flex', justifyContent: 'center', alignItems: 'center', flex: '0 0 auto' }}>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#50575e" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M4 9l3-6 3 6" />
                                        <path d="M5 7h4" />
                                        <path d="M4 14h6l-6 6h6" />
                                        <path d="M17 4v16" />
                                        <path d="M13 16l4 4 4-4" />
                                    </svg>
                                </button>
                                {isSortMenuOpen && (
                                    <div className="folderify-sort-dropdown-container">
                                        <ul className="folderify-dropdown-list">
                                            <li className="folderify-dropdown-item folderify-has-submenu">
                                                <div className="folderify-item-content">
                                                    <span>{__('Sort Folders', 'folderify')}</span>
                                                    <svg className="folderify-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                                                </div>
                                                <ul className="folderify-submenu folderify-dropdown-list">
                                                    <li className="folderify-dropdown-item" onClick={() => { setFolderSort({ key: 'name', order: 'asc' }); setIsSortMenuOpen(false); }}>
                                                        <div className={`folderify-item-content folderify-check-item ${folderSort.order === 'asc' ? 'active' : ''}`}>
                                                            {folderSort.order === 'asc' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                            <span>{__('Ascending', 'folderify')}</span>
                                                        </div>
                                                    </li>
                                                    <li className="folderify-dropdown-item" onClick={() => { setFolderSort({ key: 'name', order: 'desc' }); setIsSortMenuOpen(false); }}>
                                                        <div className={`folderify-item-content folderify-check-item ${folderSort.order === 'desc' ? 'active' : ''}`}>
                                                            {folderSort.order === 'desc' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                            <span>{__('Descending', 'folderify')}</span>
                                                        </div>
                                                    </li>
                                                    <li className="folderify-dropdown-item" onClick={() => { setFolderSort({ key: 'default', order: 'default' }); setIsSortMenuOpen(false); }}>
                                                        <div className={`folderify-item-content folderify-check-item ${folderSort.order === 'default' ? 'active' : ''}`}>
                                                            {folderSort.order === 'default' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                            <span>{__('Default', 'folderify')}</span>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </li>
                                            <li className="folderify-dropdown-item folderify-has-submenu">
                                                <div className="folderify-item-content">
                                                    <span>{__('Sort Files', 'folderify')}</span>
                                                    <svg className="folderify-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                                                </div>
                                                <ul className="folderify-submenu folderify-dropdown-list">

                                                    {['Title', 'Date', 'Modified', 'Author', 'Size'].map(type => {
                                                        const keyLower = type.toLowerCase();
                                                        return (
                                                            <li className="folderify-dropdown-item folderify-has-submenu" key={keyLower}>
                                                                <div className={`folderify-item-content ${fileSort.key === keyLower ? 'active' : ''}`}>
                                                                    <span>{__(`By ${type}`, 'folderify')}</span>
                                                                    <svg className="folderify-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                                                                </div>
                                                                <ul className="folderify-submenu folderify-dropdown-list">
                                                                    <li className="folderify-dropdown-item" onClick={() => { setFileSort({ key: keyLower, order: 'asc' }); setIsSortMenuOpen(false); }}>
                                                                        <div className={`folderify-item-content folderify-check-item ${fileSort.key === keyLower && fileSort.order === 'asc' ? 'active' : ''}`}>
                                                                            {fileSort.key === keyLower && fileSort.order === 'asc' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                                            <span>{__('Ascending', 'folderify')}</span>
                                                                        </div>
                                                                    </li>
                                                                    <li className="folderify-dropdown-item" onClick={() => { setFileSort({ key: keyLower, order: 'desc' }); setIsSortMenuOpen(false); }}>
                                                                        <div className={`folderify-item-content folderify-check-item ${fileSort.key === keyLower && fileSort.order === 'desc' ? 'active' : ''}`}>
                                                                            {fileSort.key === keyLower && fileSort.order === 'desc' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                                            <span>{__('Descending', 'folderify')}</span>
                                                                        </div>
                                                                    </li>
                                                                </ul>
                                                            </li>
                                                        );
                                                    })}

                                                    <li className="folderify-dropdown-item folderify-divider"></li>
                                                    <li className="folderify-dropdown-item" onClick={() => { setFileSort({ key: 'default', order: 'default' }); setIsSortMenuOpen(false); }}>
                                                        <div className={`folderify-item-content folderify-check-item ${fileSort.key === 'default' ? 'active' : ''}`}>
                                                            {fileSort.key === 'default' ? <svg className="folderify-check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> : <span className="folderify-no-icon"></span>}
                                                            <span>{__('Default', 'folderify')}</span>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </li>

                                        </ul>
                                    </div>
                                )}
                            </div>
                            <div className="folderify-toolbar-dropdown-wrapper" style={{ position: 'relative', flex: '1 1 auto', display: 'flex', marginLeft: '6px' }}>
                                <button
                                    className={`folderify-toolbar-btn folderify-toolbar-options ${isOptionsMenuOpen ? 'active' : ''}`}
                                    title={__('More Options', 'folderify')}
                                    onClick={() => setIsOptionsMenuOpen(!isOptionsMenuOpen)}
                                    style={{ width: '100%', height: '32px', border: '1px solid #8c8f94', borderRadius: '3px', backgroundColor: isOptionsMenuOpen ? '#f0f0f1' : '#fff', padding: '0', display: 'flex', justifyContent: 'center', alignItems: 'center' }}
                                >
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="#50575e" xmlns="http://www.w3.org/2000/svg">
                                        <rect x="7" y="11" width="2" height="2" />
                                        <rect x="11" y="11" width="2" height="2" />
                                        <rect x="15" y="11" width="2" height="2" />
                                    </svg>
                                </button>
                                {isOptionsMenuOpen && (
                                    <div className="folderify-options-dropdown-container folderify-sort-dropdown-container">
                                        <ul className="folderify-dropdown-list">
                                        {window.mediaOrganizerParams?.isPremium && (
                                            <>
                                                <li
                                                    className="folderify-dropdown-item"
                                                    onClick={() => {
                                                        setIsBulkSelectMode(true);
                                                        setIsOptionsMenuOpen(false);
                                                    }}
                                                >
                                                    <div className="folderify-item-content">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '8px' }}>
                                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                            <path d="M9 12l2 2 4-4"></path>
                                                        </svg>
                                                        <span>{__('Bulk Select', 'folderify')}</span>
                                                    </div>
                                                </li>
                                                <li
                                                    className="folderify-dropdown-item"
                                                    onClick={() => {
                                                        if (isAllCollapsed) {
                                                            setExpandAllTrigger(prev => prev + 1);
                                                            setIsAllCollapsed(false);
                                                        } else {
                                                            setCollapseAllTrigger(prev => prev + 1);
                                                            setIsAllCollapsed(true);
                                                        }
                                                        setIsOptionsMenuOpen(false);
                                                    }}
                                                >
                                                    <div className="folderify-item-content">
                                                        {isAllCollapsed ? (
                                                            <>
                                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '8px' }}>
                                                                    <polyline points="15 3 21 3 21 9"></polyline>
                                                                    <polyline points="9 21 3 21 3 15"></polyline>
                                                                    <line x1="21" y1="3" x2="14" y2="10"></line>
                                                                    <line x1="3" y1="21" x2="10" y2="14"></line>
                                                                </svg>
                                                                <span>{__('Expand all', 'folderify')}</span>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '8px' }}>
                                                                    <polyline points="4 14 10 14 10 20"></polyline>
                                                                    <polyline points="20 10 14 10 14 4"></polyline>
                                                                    <line x1="14" y1="10" x2="21" y2="3"></line>
                                                                    <line x1="3" y1="21" x2="10" y2="14"></line>
                                                                </svg>
                                                                <span>{__('Collapse all', 'folderify')}</span>
                                                            </>
                                                        )}
                                                    </div>
                                                </li>
                                                <li
                                                    className="folderify-dropdown-item"
                                                    onClick={() => {
                                                        setDisplayFolderId(!displayFolderId);
                                                        setIsOptionsMenuOpen(false);
                                                    }}
                                                >
                                                    <div className="folderify-item-content">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '8px' }}>
                                                            <path d="M7 7h10v10H7z" opacity="0.2" fill="currentColor" stroke="none"></path>
                                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                            <path d="M8 8v8"></path>
                                                            <path d="M12 8v8a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V8"></path>
                                                        </svg>
                                                        <span>{displayFolderId ? __('Hide folder ID', 'folderify') : __('Display folder ID', 'folderify')}</span>
                                                    </div>
                                                </li>
                                            </>
                                        )}
                                        {!window.mediaOrganizerParams?.isPremium && (
                                            <li
                                                className="folderify-dropdown-item"
                                                onClick={() => {
                                                    const pricingUrl = window.mediaOrganizerParams?.pricingUrl || (window.mediaOrganizerParams?.settingsUrl + '-pricing');
                                                    window.open(pricingUrl, '_blank');
                                                }}
                                            >
                                                <div className="folderify-item-content" style={{ color: '#d63638', fontWeight: 600 }}>
                                                    <span className="dashicons dashicons-star-filled" style={{ marginRight: '8px', fontSize: '16px', color: '#fecb2e', alignSelf: 'center' }}></span>
                                                    <span>{__('Upgrade to Pro', 'folderify')}</span>
                                                </div>
                                            </li>
                                        )}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        </div>
                        <button className="folderify-toolbar-btn folderify-toolbar-close" onClick={() => setIsToolbarActive(false)} title={__('Close', 'folderify')} style={{ flex: '0 0 auto', width: '50px', backgroundColor: 'transparent', padding: '0', display: 'flex', justifyContent: 'center', alignItems: 'center' }}>
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="#50575e" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 4L22 20H2Z" />
                            </svg>
                        </button>
                    </div>
                ) : (
                    <div className="folderify-top-bar">
                        <div className="folderify-search">
                            <div className="folderify-search-input-wrapper">
                                <input
                                    type="text"
                                    placeholder={__('Search folders', 'folderify')}
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                                <span
                                    className="search-icon"
                                    style={{ cursor: searchQuery ? 'pointer' : 'default', pointerEvents: searchQuery ? 'auto' : 'none' }}
                                    onClick={() => {
                                        if (searchQuery) setSearchQuery('');
                                    }}
                                >
                                    {searchQuery ? (
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    ) : (
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                        </svg>
                                    )}
                                </span>
                            </div>
                        </div>
                        <button className="folderify-hamburger-btn" onClick={() => setIsToolbarActive(true)}>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8c8f94" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <line x1="3" y1="12" x2="21" y2="12"></line>
                                <line x1="3" y1="6" x2="21" y2="6"></line>
                                <line x1="3" y1="18" x2="21" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                )}

                {/* System Folders */}
                <SystemFolders />

                {/* Tree */}
                <FolderTree searchQuery={searchQuery} />

                {/* Bottom Bar */}
                {folders && folders.length > 0 && (
                    <div className="folderify-bottom-bar">
                        {isBulkSelectMode ? (
                            <div style={{ display: 'flex', gap: '8px', width: '100%' }}>
                                <button
                                    className="button"
                                    style={{ flex: 1, color: '#d63638', borderColor: '#d63638' }}
                                    disabled={selectedFolders.length === 0}
                                    onClick={() => {
                                        if (selectedFolders.length > 0) {
                                            setShowBulkDeleteModal(true);
                                        }
                                    }}
                                >
                                    {__('Delete', 'folderify')}
                                </button>
                                <button
                                    className="button"
                                    style={{ flex: 1 }}
                                    onClick={() => {
                                        setIsBulkSelectMode(false);
                                        setSelectedFolders([]);
                                    }}
                                >
                                    {__('Cancel', 'folderify')}
                                </button>
                            </div>
                        ) : (
                            <button className="button button-primary folderify-add-folder-btn" onClick={handleCreate}>
                                + {__('Add Folder', 'folderify')}
                            </button>
                        )}
                    </div>
                )}
            </div>

            {/* Resizer Handle */}
            {!isCollapsed && isPremium && (
                <div className="folderify-resizer" onMouseDown={startResizing} />
            )}

            {/* Toggle Collapse Button */}
            {isPremium && (
                <div className="folderify-toggle" onClick={() => setIsCollapsed(!isCollapsed)}>
                    {isCollapsed ? '▶' : '◀'}
                </div>
            )}

            {/* Global Context Menu */}
            <FolderContextMenu />

            {/* Custom Delete Confirmation Modal */}
            {folderToDelete && (
                <div style={{
                    position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(0, 0, 0, 0.5)', zIndex: 9999999,
                    display: 'flex', alignItems: 'center', justifyContent: 'center'
                }}>
                    <div style={{
                        background: '#fff', borderRadius: '4px', width: '400px',
                        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                        display: 'flex', flexDirection: 'column', overflow: 'hidden'
                    }}>
                        <div style={{ padding: '15px 20px', borderBottom: '1px solid #dcdcde', background: '#f6f7f7' }}>
                            <h2 style={{ margin: 0, fontSize: '14px', fontWeight: 600, color: '#1d2327' }}>Confirm Delete</h2>
                        </div>
                        <div style={{ padding: '20px', fontSize: '14px', color: '#3c434a' }}>
                            <p style={{ margin: 0 }}>Are you sure you want to delete <strong>"{folderToDelete.name}"</strong>?</p>
                            <p style={{ margin: '10px 0 0 0', color: '#d63638', fontSize: '13px' }}>Note: This will also delete all subfolders inside it. Media files will not be deleted but will be moved back to "Uncategorized".</p>
                        </div>
                        <div style={{ padding: '15px 20px', borderTop: '1px solid #dcdcde', background: '#f6f7f7', display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                            <button
                                className="button"
                                onClick={(e) => { e.stopPropagation(); setFolderToDelete(null); }}
                            >
                                Cancel
                            </button>
                            <button
                                className="button button-primary"
                                style={{ background: '#d63638', borderColor: '#d63638', color: '#fff' }}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    deleteFolder(folderToDelete.id);
                                    setFolderToDelete(null);
                                }}
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Custom Bulk Delete Confirmation Modal */}
            {showBulkDeleteModal && (
                <div style={{
                    position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                    background: 'rgba(0, 0, 0, 0.5)', zIndex: 9999999,
                    display: 'flex', alignItems: 'center', justifyContent: 'center'
                }}>
                    <div style={{
                        background: '#fff', borderRadius: '4px', width: '400px',
                        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                        display: 'flex', flexDirection: 'column', overflow: 'hidden'
                    }}>
                        <div style={{ padding: '15px 20px', borderBottom: '1px solid #dcdcde', background: '#f6f7f7' }}>
                            <h2 style={{ margin: 0, fontSize: '14px', fontWeight: 600, color: '#1d2327' }}>{__('Confirm Bulk Delete', 'folderify')}</h2>
                        </div>
                        <div style={{ padding: '20px', fontSize: '14px', color: '#3c434a' }}>
                            <p style={{ margin: 0 }}>{__('Are you sure you want to delete the selected folders?', 'folderify')}</p>
                            <p style={{ margin: '10px 0 0 0', color: '#d63638', fontSize: '13px' }}>{__('Note: This will also delete all subfolders inside them. Media files will not be deleted but will be moved back to "Uncategorized".', 'folderify')}</p>
                        </div>
                        <div style={{ padding: '15px 20px', borderTop: '1px solid #dcdcde', background: '#f6f7f7', display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                            <button
                                className="button"
                                onClick={(e) => { e.stopPropagation(); setShowBulkDeleteModal(false); }}
                            >
                                {__('Cancel', 'folderify')}
                            </button>
                            <button
                                className="button button-primary"
                                style={{ background: '#d63638', borderColor: '#d63638', color: '#fff' }}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    selectedFolders.forEach(id => deleteFolder(id));
                                    setSelectedFolders([]);
                                    setIsBulkSelectMode(false);
                                    setShowBulkDeleteModal(false);
                                }}
                            >
                                {__('Delete', 'folderify')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Custom Minimal Toast Notification */}
            {toastMessage && (
                <div style={{
                    position: 'absolute',
                    bottom: '60px',
                    left: '50%',
                    transform: 'translateX(-50%)',
                    background: '#fff',
                    color: '#3c434a',
                    border: '1px solid #dcdcde',
                    borderLeft: `4px solid ${toastMessage.type === 'success' ? '#46b450' : '#d63638'}`,
                    padding: '10px 16px',
                    borderRadius: '4px',
                    fontSize: '13px',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
                    zIndex: 999999,
                    pointerEvents: 'none',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    whiteSpace: 'nowrap',
                    animation: 'folderify_fade_in_up 0.2s ease-out'
                }}>
                    {toastMessage.type === 'success' ? (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#46b450" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    ) : (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d63638" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    )}
                    <span style={{ fontWeight: 500 }}>{toastMessage.message}</span>
                </div>
            )}
            {showImportPrompt && thirdPartyPlugins.length > 0 && (
                <ImportPromptModal
                    plugins={thirdPartyPlugins}
                    onClose={handleDismissImportPrompt}
                />
            )}
        </div>
    );
};

export default Sidebar;
