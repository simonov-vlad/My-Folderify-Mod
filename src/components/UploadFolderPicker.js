/**
 * UploadFolderPicker
 *
 * Injected into the WP media modal "Upload files" tab.
 * Renders a folder-tree dropdown so the user can choose where uploaded files land.
 * Selected folder ID is written to window.mediaOrganizerUploadFolderId (0 = Uncategorized).
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

// ─── Tree node renderer ───────────────────────────────────────────────────────

function FolderNode({ folder, allFolders, selectedId, onSelect, depth, isSearch }) {
    const children = allFolders.filter((f) => Number(f.parent_id) === folder.id);
    const isSelected = selectedId === folder.id;
    const color = folder.color || '#94a3b8';

    // Calculate parent path for search results
    let parentPath = '';
    if (isSearch) {
        const parts = [];
        let currentParentId = folder.parent_id;
        while (currentParentId && Number(currentParentId) > 0) {
            const parent = allFolders.find(f => f.id === currentParentId);
            if (parent) {
                parts.unshift(parent.name);
                currentParentId = parent.parent_id;
            } else {
                break;
            }
        }
        parentPath = parts.join(' › ');
    }

    return (
        <li className="folderify-pt-item">
            <button
                type="button"
                className={`folderify-pt-row${isSelected ? ' is-selected' : ''}`}
                onClick={() => onSelect(folder)}
                title={folder.name}
            >
                {/* Folder icon */}
                <svg className="folderify-pt-icon" width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true" style={{ marginTop: parentPath ? '4px' : '0' }}>
                    <path
                        d="M2 5a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V5z"
                        fill={color}
                    />
                </svg>

                <div className="folderify-pt-name" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', lineHeight: '1.2' }}>
                    <span style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: '100%' }}>{folder.name}</span>
                    {isSearch && parentPath && (
                        <span style={{ fontSize: '10px', color: '#9ca3af', marginTop: '2px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: '100%' }}>
                            {parentPath}
                        </span>
                    )}
                </div>

                {isSelected && (
                    <svg className="folderify-pt-check" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M4 10l5 5L16 7" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="2.2"
                            strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                )}
            </button>

            {!isSearch && children.length > 0 && (
                <ul className="folderify-pt-children">
                    {children.map((child) => (
                        <FolderNode
                            key={child.id}
                            folder={child}
                            allFolders={allFolders}
                            selectedId={selectedId}
                            onSelect={onSelect}
                            depth={depth + 1}
                            isSearch={false}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

const UNCATEGORIZED_ID = 0;

export default function UploadFolderPicker() {
    const [allFolders, setAllFolders] = useState([]);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    // null = not yet chosen; { id:0 } = Uncategorized; { id:N, name, color } = real folder
    const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(true);

    const wrapRef = useRef(null);
    const searchRef = useRef(null);

    // ── Fetch folders once ──────────────────────────────────────────────────
    useEffect(() => {
        apiFetch({ path: '/folderify/v1/folders?post_type=attachment' })
            .then((data) => setAllFolders(Array.isArray(data) ? data : []))
            .catch(() => { })
            .finally(() => setLoading(false));
    }, []);

    // ── Sync selected folder to sidebar + live plupload instance ───────────
    // Same approach as FileBird: when user picks a folder in the upload tab,
    // set the sidebar active folder AND patch the live plupload instance's
    // multipart_params directly (because defaults were already cloned).
    useEffect(() => {
        if (selected !== null) {
            const folderId = selected.id;

            // 1. Set globals so any NEW uploader picks it up
            window.mediaOrganizerUploadFolderId = folderId;
            window.mediaOrganizerActiveFolderId = folderId;

            // 2. Update wp.Uploader.defaults for future uploader instances
            if (window.wp && wp.Uploader && wp.Uploader.defaults && wp.Uploader.defaults.multipart_params) {
                wp.Uploader.defaults.multipart_params.folderify_folder = folderId;
            }

            // 3. Patch the LIVE plupload instance that's already running.
            //    The chain is: frame.uploader (WP view) → .uploader (wp.Uploader)
            //    → .uploader (plupload.Uploader). Walk up to 5 levels to find it.
            if (window.wp && wp.media && wp.media.frame && wp.media.frame.uploader) {
                let obj = wp.media.frame.uploader;
                for (let i = 0; i < 5 && obj; i++) {
                    if (obj.settings && obj.settings.multipart_params !== undefined) {
                        obj.settings.multipart_params.folderify_folder = folderId;
                        break;
                    }
                    obj = obj.uploader;
                }
            }

            // 4. Tell the sidebar's FolderContext to activate this folder
            if (typeof jQuery !== 'undefined') {
                jQuery(document).trigger('folderify_set_active_folder', [folderId]);
            }
        }
    }, [selected]);

    // ── Close on outside click ──────────────────────────────────────────────
    useEffect(() => {
        const handler = (e) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // ── Focus search when dropdown opens ───────────────────────────────────
    useEffect(() => {
        if (open) {
            setTimeout(() => searchRef.current && searchRef.current.focus(), 40);
        } else {
            setSearch('');
        }
    }, [open]);

    // ── Filter visible folders by search term ──────────────────────────────
    const visibleFolders = search.trim()
        ? allFolders.filter((f) => f.name.toLowerCase().includes(search.toLowerCase()))
        : allFolders;

    // Root folders (when not searching show proper tree; when searching show flat list)
    const rootFolders = visibleFolders.filter((f) => !f.parent_id || Number(f.parent_id) === 0);

    // ── Handlers ───────────────────────────────────────────────────────────
    const handleSelect = useCallback((folder) => {
        setSelected(folder);    // { id, name, color } OR { id:0, name:'Uncategorized' }
        setOpen(false);
    }, []);

    const handleClear = useCallback((e) => {
        e.stopPropagation();
        setSelected(null);
        setOpen(false);
    }, []);

    // ── Display values ─────────────────────────────────────────────────────
    const displayName = selected ? selected.name : 'Uncategorized';
    const displayColor = (selected && selected.id > 0) ? (selected.color || '#94a3b8') : '#94a3b8';
    const selectedId = selected ? selected.id : null; // null = nothing explicitly chosen

    const uncatSelected = selected !== null && selected.id === UNCATEGORIZED_ID;

    return (
        <div className="folderify-upload-picker" ref={wrapRef}>
            <div className="folderify-upload-picker-inner">

                {/* Label */}
                <div className="folderify-upload-picker-label">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M2 5a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V5z"
                            fill="currentColor" opacity=".75" />
                    </svg>
                    Upload to folder
                </div>

                {/* Trigger button */}
                <button
                    type="button"
                    className={`folderify-folder-select-btn${open ? ' is-open' : ''}`}
                    onClick={() => setOpen((v) => !v)}
                    aria-haspopup="listbox"
                    aria-expanded={open}
                >
                    <span className="folderify-folder-dot" style={{ background: displayColor }} />
                    <span className="folderify-folder-select-name">{displayName}</span>

                    {selected !== null && (
                        <span className="folderify-folder-clear" role="button" tabIndex={-1}
                            onClick={handleClear} title="Reset">×</span>
                    )}

                    <svg className="folderify-chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="M2 4l4 4 4-4" stroke="currentColor" strokeWidth="1.5"
                            strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </button>

                {/* ── Dropdown ─────────────────────────────────────────── */}
                {open && (
                    <div className="folderify-folder-dropdown" role="listbox">

                        {/* Search */}
                        <div className="folderify-folder-search-wrap">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="9" cy="9" r="6" stroke="currentColor" strokeWidth="2" />
                                <path d="M15 15l3 3" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                            </svg>
                            <input
                                ref={searchRef}
                                type="search"
                                placeholder="Search folders…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="folderify-folder-search-input"
                            />
                        </div>

                        {/* Tree list */}
                        <div className="folderify-folder-list">
                            <ul className="folderify-pt-root">

                                {/* Uncategorized — always first */}
                                {(!search.trim() || 'uncategorized'.includes(search.toLowerCase())) && (
                                    <li className="folderify-pt-item">
                                        <button
                                            type="button"
                                            className={`folderify-pt-row${uncatSelected ? ' is-selected' : ''}`}
                                            onClick={() => handleSelect({ id: UNCATEGORIZED_ID, name: 'Uncategorized', color: '#94a3b8' })}
                                        >
                                            <svg className="folderify-pt-icon" width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                <path d="M2 5a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V5z"
                                                    fill="#94a3b8" />
                                            </svg>
                                            <span className="folderify-pt-name">Uncategorized</span>
                                            {uncatSelected && (
                                                <svg className="folderify-pt-check" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                    <path d="M4 10l5 5L16 7" stroke="var(--wp-admin-theme-color, #2271b1)" strokeWidth="2.2"
                                                        strokeLinecap="round" strokeLinejoin="round" />
                                                </svg>
                                            )}
                                        </button>
                                    </li>
                                )}

                                {loading && (
                                    <li className="folderify-folder-option-empty">Loading…</li>
                                )}

                                {!loading && visibleFolders.length === 0 && search.trim() && (
                                    <li className="folderify-folder-option-empty">No folders found</li>
                                )}

                                {/* When searching, skip tree hierarchy and show flat results */}
                                {!loading && search.trim()
                                    ? visibleFolders.map((f) => (
                                        <FolderNode
                                            key={f.id}
                                            folder={f}
                                            allFolders={allFolders}
                                            selectedId={selectedId}
                                            onSelect={handleSelect}
                                            depth={0}
                                            isSearch={true}
                                        />
                                    ))
                                    : rootFolders.map((folder) => (
                                        <FolderNode
                                            key={folder.id}
                                            folder={folder}
                                            allFolders={allFolders}
                                            selectedId={selectedId}
                                            onSelect={handleSelect}
                                            depth={0}
                                            isSearch={false}
                                        />
                                    ))
                                }
                            </ul>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
