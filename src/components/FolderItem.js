import { useState, useRef, useEffect } from '@wordpress/element';
import { useFolders } from './FolderContext';
import FolderInput from './FolderInput';

const FolderItem = ({ folder, index, isSearchResult = false, parentPath = '' }) => {
    const {
        activeFolderId, setActiveFolderId, counts, moveMediaToFolder, createFolder, renameFolder,
        draftFolderParentId, setDraftFolderParentId,
        editingFolderId, setEditingFolderId,
        setContextMenu, contextMenu,
        draggedFolderId, setDraggedFolderId, reorderFolder,
        collapseAllTrigger, expandAllTrigger, displayFolderId,
        isBulkSelectMode, selectedFolders, setSelectedFolders,
        showToast
    } = useFolders();

    // Persistent storage for expanded folders
    const storageKey = 'folderify_expanded_folders';
    const [expanded, setExpanded] = useState(() => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey)) || [];
            return saved.includes(folder.id);
        } catch (e) {
            return false;
        }
    });

    const [dragOverPosition, setDragOverPosition] = useState(null);

    // Watch for Collapse All triggers
    useEffect(() => {
        if (collapseAllTrigger > 0) {
            setExpanded(false);
            try {
                // Clear out of localStorage to remain collapsed on reload
                const saved = JSON.parse(localStorage.getItem(storageKey)) || [];
                const idx = saved.indexOf(folder.id);
                if (idx > -1) {
                    saved.splice(idx, 1);
                    localStorage.setItem(storageKey, JSON.stringify(saved));
                }
            } catch (e) { }
        }
    }, [collapseAllTrigger, folder.id]);

    // Determine if folder has children before using it in the effects
    const hasChildren = !isSearchResult && folder.children && folder.children.length > 0;

    // Watch for Expand All triggers
    useEffect(() => {
        if (expandAllTrigger > 0 && hasChildren) {
            setExpanded(true);
            try {
                // Save to localStorage to remain expanded on reload
                const saved = JSON.parse(localStorage.getItem(storageKey)) || [];
                if (!saved.includes(folder.id)) {
                    saved.push(folder.id);
                    localStorage.setItem(storageKey, JSON.stringify(saved));
                }
            } catch (e) { }
        }
    }, [expandAllTrigger, folder.id, hasChildren]);

    const toggleExpand = (e) => {
        e.stopPropagation();
        const newState = !expanded;
        setExpanded(newState);

        try {
            const saved = JSON.parse(localStorage.getItem(storageKey)) || [];
            if (newState) {
                if (!saved.includes(folder.id)) saved.push(folder.id);
            } else {
                const idx = saved.indexOf(folder.id);
                if (idx > -1) saved.splice(idx, 1);
            }
            localStorage.setItem(storageKey, JSON.stringify(saved));
        } catch (e) { }
    };

    const isCreatingChild = draftFolderParentId === folder.id;
    const isEditing = editingFolderId === folder.id;


    const isActive = activeFolderId === folder.id;
    const count = counts[folder.id] || 0;

    const folderRef = useRef(null);

    const selectFolder = (e) => {
        e.stopPropagation();
        setActiveFolderId(folder.id);
    };

    const handleContextMenu = (e) => {
        if (typeof mediaOrganizerParams !== 'undefined' && !mediaOrganizerParams.canEdit) return;
        if (isBulkSelectMode) return;

        e.preventDefault();
        e.stopPropagation();
        setContextMenu({
            visible: true,
            x: e.clientX,
            y: e.clientY,
            folder: folder
        });
    };

    const saveChildFolder = async (name) => {
        await createFolder(name, folder.id);
        setDraftFolderParentId(null);
        setExpanded(true);
    };

    const saveRename = async (newName) => {
        await renameFolder(folder.id, newName);
        setEditingFolderId(null);
    };

    // Auto-expand folder when a child is being created inside it
    useEffect(() => {
        if (draftFolderParentId === folder.id) {
            setExpanded(true);
        }
    }, [draftFolderParentId, folder.id]);

    useEffect(() => {
        if (!folderRef.current || typeof jQuery === 'undefined' || !jQuery.ui || !jQuery.ui.droppable) return;

        const $el = jQuery(folderRef.current);

        $el.droppable({
            accept: '.folderify-draggable',
            hoverClass: 'folderify-droppable-hover',
            tolerance: 'pointer',
            greedy: true, // Prevent event bubbling to parent folders
            drop: function (event, ui) {
                const $draggable = ui.draggable;
                let draggedIds = [];

                if ($draggable.hasClass('attachment')) {
                    // Grid View
                    if ($draggable.hasClass('selected') && jQuery('.attachment.selected').length > 0) {
                        jQuery('.attachment.selected').each(function () {
                            draggedIds.push(jQuery(this).data('id'));
                        });
                    } else {
                        draggedIds.push($draggable.data('id'));
                    }
                } else if ($draggable.is('tr')) {
                    // List View
                    if ($draggable.find('input[type="checkbox"]').prop('checked') && jQuery('.wp-list-table tbody tr input[type="checkbox"]:checked').length > 0) {
                        jQuery('.wp-list-table tbody tr input[type="checkbox"]:checked').each(function () {
                            draggedIds.push(jQuery(this).val());
                        });
                    } else {
                        draggedIds.push($draggable.find('input[type="checkbox"]').val());
                    }
                }

                if (draggedIds.length > 0) {
                    moveMediaToFolder(draggedIds, folder.id).then((success) => {
                        if (success) {
                            showToast(`Successfully moved`, 'success');
                            // Provide a little UI feedback
                            $el.find('>.folderify-folder-header').css('background', '#dcf0da');
                            setTimeout(() => {
                                $el.find('>.folderify-folder-header').css('background', '');
                            }, 500);
                        } else {
                            showToast(`Failed to move item(s) to "${folder.name}"`, 'error');
                        }
                    });
                }
            }
        });

        return () => {
            try {
                if ($el.hasClass('ui-droppable')) {
                    $el.droppable('destroy');
                }
            } catch (e) {
                console.error('Folderify droppable destroy error:', e);
            }
        };
    }, [folder.id, folder.name, moveMediaToFolder]);

    // HTML5 Drag and Drop Handlers for Folder Sorting
    const handleDragStart = (e) => {
        if (isSearchResult) return;
        if (typeof mediaOrganizerParams !== 'undefined' && !mediaOrganizerParams.canEdit) return;
        setDraggedFolderId(folder.id);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', folder.id);

        document.body.classList.add('folderify-folder-dragging');

        // Create custom narrow drag image
        const dragImage = document.createElement('div');
        dragImage.style.position = 'absolute';
        dragImage.style.top = '-1000px';
        dragImage.style.left = '-1000px';
        dragImage.style.backgroundColor = 'var(--wp-admin-theme-color, #2271b1)';
        dragImage.style.color = '#fff';
        dragImage.style.padding = '4px 10px 4px 6px';
        dragImage.style.borderRadius = '3px';
        dragImage.style.display = 'flex';
        dragImage.style.alignItems = 'center';
        dragImage.style.fontFamily = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif';
        dragImage.style.fontSize = '13px';
        dragImage.style.zIndex = '-1';
        dragImage.style.whiteSpace = 'nowrap';
        dragImage.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';

        dragImage.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 6px;">
                <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
            </svg>
            <span>${folder.name.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</span>
        `;

        document.body.appendChild(dragImage);
        e.dataTransfer.setDragImage(dragImage, 15, 15);

        // Clean up the DOM node after the drag image is snapshotted
        setTimeout(() => {
            if (dragImage.parentNode) {
                dragImage.parentNode.removeChild(dragImage);
            }
        }, 50);

        // Slightly dim the source element
        setTimeout(() => {
            if (e.target) e.target.style.opacity = '0.5';
        }, 0);
    };

    const handleDragOver = (e) => {
        if (!draggedFolderId || draggedFolderId === folder.id || isSearchResult) return;
        if (typeof mediaOrganizerParams !== 'undefined' && !mediaOrganizerParams.canEdit) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        const rect = e.currentTarget.getBoundingClientRect();
        const y = e.clientY - rect.top;
        const height = rect.height;

        let position = 'inside';
        if (y < height * 0.25) {
            position = 'before';
        } else if (y > height * 0.75) {
            position = 'after';
        }

        setDragOverPosition(position);
    };

    const handleDragLeave = (e) => {
        e.preventDefault();
        setDragOverPosition(null);
    };

    const handleDrop = (e) => {
        if (!draggedFolderId || draggedFolderId === folder.id || isSearchResult) return;
        if (typeof mediaOrganizerParams !== 'undefined' && !mediaOrganizerParams.canEdit) return;
        e.preventDefault();

        const position = dragOverPosition;
        setDragOverPosition(null);

        let targetParentId = parseInt(folder.parent_id, 10) || 0;
        let targetIndex = index !== undefined ? index : 0;

        if (position === 'inside') {
            targetParentId = folder.id;
            targetIndex = 0;
        } else if (position === 'after') {
            targetIndex = targetIndex + 1;
        }

        reorderFolder(draggedFolderId, targetParentId, targetIndex);
        setDraggedFolderId(null);
        cleanupWpDragOverlay();
    };

    const handleDragEnd = (e) => {
        e.currentTarget.style.opacity = '1';
        setDraggedFolderId(null);
        setDragOverPosition(null);
        cleanupWpDragOverlay();
    };

    const cleanupWpDragOverlay = () => {
        setTimeout(() => {
            document.body.classList.remove('folderify-folder-dragging');
            if (typeof jQuery !== 'undefined') {
                jQuery('.uploader-window').removeClass('drag-over');
                jQuery('.wp-uploader-active').removeClass('wp-uploader-active');
            }
        }, 50);
    };

    const isContextMenuActive = contextMenu && contextMenu.visible && contextMenu.folder?.id === folder.id;

    let dropStyle = {};
    if (dragOverPosition === 'inside') dropStyle = { background: '#f0f6fc', outline: '1px solid var(--wp-admin-theme-color, #2271b1)', outlineOffset: '-1px' };
    else if (dragOverPosition === 'before') dropStyle = { borderTop: '2px solid var(--wp-admin-theme-color, #2271b1)', marginTop: '-2px' };
    else if (dragOverPosition === 'after') dropStyle = { borderBottom: '2px solid var(--wp-admin-theme-color, #2271b1)', marginBottom: '-2px' };

    const isPremium = window.mediaOrganizerParams?.isPremium;

    return (
        <li className={`folderify-folder-item ${isActive ? 'active' : ''} ${isContextMenuActive ? 'context-menu-active' : ''}`} ref={folderRef}>
            {isEditing ? (
                <FolderInput
                    standalone={false}
                    initialName={folder.name}
                    onSave={saveRename}
                    onCancel={() => setEditingFolderId(null)}
                />
            ) : (
                <div
                    className="folderify-folder-header"
                    onClick={selectFolder}
                    onContextMenu={handleContextMenu}
                    draggable={!isSearchResult && !isBulkSelectMode && (typeof mediaOrganizerParams !== 'undefined' ? !!mediaOrganizerParams.canEdit : true)}
                    onDragStart={handleDragStart}
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    onDragEnd={handleDragEnd}
                    style={{ display: 'flex', alignItems: 'center', cursor: 'pointer', ...dropStyle }}
                >
                    {isBulkSelectMode && (
                        <input
                            type="checkbox"
                            checked={selectedFolders.includes(folder.id)}
                            onChange={(e) => {
                                e.stopPropagation();
                                if (e.target.checked) {
                                    setSelectedFolders([...selectedFolders, folder.id]);
                                } else {
                                    setSelectedFolders(selectedFolders.filter(id => id !== folder.id));
                                }
                            }}
                            onClick={(e) => e.stopPropagation()}
                            style={{
                                margin: '0 8px 0 0',
                                cursor: 'pointer',
                                width: '16px',
                                height: '16px',
                                flexShrink: 0
                            }}
                        />
                    )}
                    <span
                        className={`folder-icon ${hasChildren ? 'folder-toggle-icon' : 'folder-leaf-icon'}`}
                        onClick={(e) => {
                            if (isPremium && hasChildren) {
                                toggleExpand(e);
                            }
                        }}
                        style={{
                            marginRight: '5px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            cursor: 'pointer',
                            color: isActive ? 'var(--wp-admin-theme-color, #2271b1)' : (folder.color || '#a0aab2'),
                            width: '22px',
                            height: '22px',
                            position: 'relative',
                            zIndex: 3
                        }}
                    >
                        {hasChildren && expanded && (
                            <div style={{
                                width: '22px',
                                height: '22px',
                                borderRadius: '50%',
                                backgroundColor: 'currentColor',
                                opacity: 0.12,
                                position: 'absolute',
                                top: 0,
                                left: 0
                            }} />
                        )}
                        {isPremium && hasChildren && expanded ? (
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style={{ position: 'relative', zIndex: 1 }}>
                                <polyline points="6 9 12 15 18 9" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        ) : isPremium && hasChildren && !expanded ? (
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path fillRule="evenodd" clipRule="evenodd" d="M10 4H4C2.9 4 2.01 4.9 2.01 6L2 18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V8C22 6.9 21.1 6 20 6H12L10 4Z M10 13v-2h2v2h2v2h-2v2h-2v-2H8v-2h2z" />
                            </svg>
                        ) : (
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M10 4H4C2.9 4 2.01 4.9 2.01 6L2 18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V8C22 6.9 21.1 6 20 6H12L10 4Z" />
                            </svg>
                        )}

                    </span>

                    <span className="folder-name" style={{
                        color: isActive ? 'var(--wp-admin-theme-color, #2271b1)' : '#50575e',
                        fontWeight: isActive ? '500' : '400',
                        fontSize: '13px',
                        display: 'flex',
                        flexDirection: 'column',
                        lineHeight: '1.3',
                        minWidth: 0,
                    }}>
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'flex', alignItems: 'center' }}>
                            {displayFolderId && (
                                <span style={{ marginRight: '6px', fontSize: '11px', color: '#4b5563', backgroundColor: '#f3f4f6', padding: '1px 4px', borderRadius: '3px', fontWeight: 'normal', fontFamily: 'monospace' }}>
                                    {folder.id}
                                </span>
                            )}
                            {folder.name}
                        </span>
                        {isSearchResult && parentPath && (
                            <span style={{
                                fontSize: '10px',
                                fontWeight: '400',
                                color: '#9ca3af',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                marginTop: '1px',
                                letterSpacing: '0.1px',
                            }}>
                                {parentPath}
                            </span>
                        )}
                    </span>

                    <span className="folder-count" style={{ marginLeft: 'auto' }}>
                        {count}
                    </span>
                </div>
            )}

            {isPremium && (hasChildren || isCreatingChild) && expanded && (
                <ul className="folderify-subfolders">
                    {hasChildren && folder.children.map((child, idx) => (
                        <FolderItem key={child.id} folder={child} index={idx} />
                    ))}
                    {isCreatingChild && <FolderInput onSave={saveChildFolder} onCancel={() => setDraftFolderParentId(null)} />}
                </ul>
            )}
        </li>
    );
};

export default FolderItem;
