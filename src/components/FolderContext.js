import { createContext, useState, useEffect, useContext } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const FolderContext = createContext();

export const useFolders = () => useContext(FolderContext);

export const FolderProvider = ({ children }) => {
    const [folders, setFolders] = useState([]);
    const [counts, setCounts] = useState({});
    const [loading, setLoading] = useState(true);

    const [draftFolderParentId, setDraftFolderParentId] = useState(null);
    const [editingFolderId, setEditingFolderId] = useState(null);
    const [contextMenu, setContextMenu] = useState(null);
    const [folderToDelete, setFolderToDelete] = useState(null);
    const [toastMessage, setToastMessage] = useState(null);

    const showToast = (message, type = 'success') => {
        const id = Date.now();
        setToastMessage({ message, type, id });
        setTimeout(() => {
            setToastMessage((prev) => prev && prev.id === id ? null : prev);
        }, 3000);
    };

    // Clipboard tracking for Cut & Paste
    const [clipboardFolderId, setClipboardFolderId] = useState(null);

    // Safely parse initial folder or default to All Files (-1)
    const initialFolder = mediaOrganizerParams.currentFolderId !== undefined
        ? parseInt(mediaOrganizerParams.currentFolderId, 10)
        : -1;
    const [activeFolderId, setActiveFolderId] = useState(initialFolder);

    const [folderSort, setFolderSort] = useState(() => {
        try {
            const saved = window.localStorage.getItem('folderify_folder_sort');
            return saved ? JSON.parse(saved) : { key: 'default', order: 'default' };
        } catch (e) { return { key: 'default', order: 'default' }; }
    });

    const [fileSort, setFileSort] = useState(() => {
        try {
            const saved = window.localStorage.getItem('folderify_file_sort');
            return saved ? JSON.parse(saved) : { key: 'date', order: 'desc' };
        } catch (e) { return { key: 'date', order: 'desc' }; }
    });

    const [displayFolderId, setDisplayFolderId] = useState(() => {
        try {
            const saved = window.localStorage.getItem('folderify_display_folder_id');
            return saved === 'true';
        } catch (e) { return false; }
    });

    const [collapseAllTrigger, setCollapseAllTrigger] = useState(0);
    const [expandAllTrigger, setExpandAllTrigger] = useState(0);
    const [isBulkSelectMode, setIsBulkSelectMode] = useState(false);
    const [selectedFolders, setSelectedFolders] = useState([]);

    useEffect(() => {
        try { window.localStorage.setItem('folderify_folder_sort', JSON.stringify(folderSort)); } catch (e) { }
    }, [folderSort]);

    useEffect(() => {
        try { window.localStorage.setItem('folderify_file_sort', JSON.stringify(fileSort)); } catch (e) { }
    }, [fileSort]);

    useEffect(() => {
        try { window.localStorage.setItem('folderify_display_folder_id', displayFolderId); } catch (e) { }
    }, [displayFolderId]);

    const fetchFolders = async () => {
        try {
            const postType = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.postType)
                ? mediaOrganizerParams.postType
                : 'attachment';
            const counterMode = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.folderCounter)
                ? mediaOrganizerParams.folderCounter
                : 'direct';

            const foldersPath = '/folderify/v1/folders?post_type=' + encodeURIComponent(postType);
            const fetchedFolders = await apiFetch({ path: foldersPath });
            setFolders(fetchedFolders);

            const countsPath = '/folderify/v1/media/counts?post_type=' + encodeURIComponent(postType)
                + (counterMode === 'recursive' ? '&recursive=1' : '');
            const fetchedCounts = await apiFetch({ path: countsPath });
            setCounts(fetchedCounts);
        } catch (error) {
            console.error('Error fetching folders:', error);
        } finally {
            setLoading(false);
        }
    };

    const refreshGrid = () => {
        if (typeof wp !== 'undefined' && wp.media && wp.media.frame && wp.media.frame.content) {
            const view = wp.media.frame.content.get();
            if (view && view.collection && view.collection.props) {
                // Force Backbone to refetch even if ID didn't change (e.g., when contents changed via backend)
                view.collection.props.set({ ignore: (+ new Date()) });
                view.collection.reset();
                view.collection.more();
            }
        }
    };

    useEffect(() => {
        fetchFolders();

        // Listen for external uploads completing
        const handleRefresh = () => {
            fetchFolders();
            refreshGrid();
        };

        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('folderify_refresh_folders', handleRefresh);
            jQuery(document).on('folderify_set_active_folder', (e, folderId) => {
                setActiveFolderId(folderId);
            });
        }

        return () => {
            if (typeof jQuery !== 'undefined') {
                jQuery(document).off('folderify_refresh_folders', handleRefresh);
                jQuery(document).off('folderify_set_active_folder');
            }
        };
    }, []);

    // Sync activeFolderId to window and trigger WP media reloads
    useEffect(() => {
        // Expose globally for the ajaxPrefilter in index.js
        window.mediaOrganizerActiveFolderId = activeFolderId;

        // 1. Grid View Trigger (Watches activeFolderId and fileSort)
        if (typeof wp !== 'undefined' && wp.media && wp.media.frame && wp.media.frame.content) {
            const view = wp.media.frame.content.get();
            if (view && view.collection && view.collection.props) {
                // Determine orderby/order overrides if fileSort is not 'default'
                let orderOptions = {
                    folderify_folder: activeFolderId,
                    ignore: (+ new Date())
                };

                // Map UI sort keys to WP Query keys
                if (fileSort.key !== 'default') {
                    // Translate our custom keys if needed, fallback natively
                    let wpOrderby = fileSort.key === 'modified' ? 'modified' : fileSort.key;
                    orderOptions.orderby = wpOrderby;
                    orderOptions.order = fileSort.order.toUpperCase();
                }

                view.collection.props.set(orderOptions);
                // Reset collection and force fetching to clear out the grid instantly
                view.collection.reset();
                view.collection.more();
            }
        }
    }, [activeFolderId, fileSort]);

    // Separate useEffect for URL/DB syncing so sorting doesn't trigger unrelated API calls
    useEffect(() => {

        // 2. List View Redirect
        if (document.body.classList.contains('upload-php') && window.location.search.indexOf('mode=list') > -1) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentUrlId = urlParams.get('folderify_folder') || "0";

            if (activeFolderId.toString() !== currentUrlId) {
                // Determine if we need to reload. We don't want to reload if it's just the initial render.
                if (activeFolderId !== mediaOrganizerParams.currentFolderId) {
                    urlParams.set('folderify_folder', activeFolderId);
                    window.location.search = urlParams.toString();
                }
            }
        }

        // 3. Save Active Folder to User Meta via REST
        if (activeFolderId !== mediaOrganizerParams.currentFolderId) {
            apiFetch({
                path: '/folderify/v1/user/active-folder',
                method: 'POST',
                data: { folder_id: activeFolderId }
            }).catch(err => console.error('Error saving active folder state:', err));

            // Update the localized param so we don't infinitely trigger
            mediaOrganizerParams.currentFolderId = activeFolderId;
        }

    }, [activeFolderId]);

    const [isProcessing, setIsProcessing] = useState(false);

    const createFolder = async (name, parentId = 0) => {
        setIsProcessing(true);
        const postType = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.postType)
            ? mediaOrganizerParams.postType
            : 'attachment';
        try {
            await apiFetch({
                path: '/folderify/v1/folders',
                method: 'POST',
                data: { name, parent_id: parentId, post_type: postType }
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error creating folder:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const deleteFolder = async (id) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/${id}`,
                method: 'DELETE'
            });
            await fetchFolders();
            refreshGrid();
            if (activeFolderId == id) setActiveFolderId(0);
        } catch (error) {
            console.error('Error deleting folder:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const renameFolder = async (id, newName) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/${id}`,
                method: 'PUT',
                data: { name: newName }
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error renaming folder:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const changeFolderColor = async (id, colorHex) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/${id}/color`,
                method: 'PUT',
                data: { color: colorHex }
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error changing folder color:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const moveFolder = async (id, newParentId) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/${id}`,
                method: 'PUT',
                data: { parent_id: newParentId }
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error moving folder:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const moveMediaToFolder = async (mediaIds, folderId) => {
        // Do nothing if moving to the folder we are already in
        if (activeFolderId === folderId) return false;

        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/media/assign`,
                method: 'POST',
                data: { attachments: mediaIds, folders: [folderId] }
            });
            // Update counts after moving media
            const fetchedCounts = await apiFetch({ path: '/folderify/v1/media/counts' });
            setCounts(fetchedCounts);

            // Instantly remove items from the view if we are not in "All Files" (-1)
            if (activeFolderId !== -1) {
                // 1. Grid View Trigger (remove from Backbone collection)
                if (typeof wp !== 'undefined' && wp.media && wp.media.frame && wp.media.frame.content) {
                    const view = wp.media.frame.content.get();
                    if (view && view.collection) {
                        mediaIds.forEach(id => {
                            const model = view.collection.get(id);
                            if (model) {
                                view.collection.remove(model);
                            }
                        });
                    }
                }

                // 2. List View Trigger (remove DOM elements)
                if (typeof jQuery !== 'undefined') {
                    mediaIds.forEach(id => {
                        jQuery('#post-' + id).fadeOut(300, function () {
                            jQuery(this).remove();
                        });
                    });
                }
            }
            return true;
        } catch (error) {
            console.error('Error moving media to folder:', error);
            return false;
        } finally {
            setIsProcessing(false);
        }
    };

    const [draggedFolderId, setDraggedFolderId] = useState(null);

    const reorderFolder = async (folderId, targetParentId, targetIndex) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/reorder`,
                method: 'POST',
                data: { folder_id: folderId, target_parent_id: targetParentId, target_order_index: targetIndex }
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error reordering folder:', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const duplicateFolder = async (id) => {
        setIsProcessing(true);
        try {
            await apiFetch({
                path: `/folderify/v1/folders/${id}/duplicate`,
                method: 'POST'
            });
            await fetchFolders();
        } catch (error) {
            console.error('Error duplicating folder:', error);
            alert('Failed to duplicate folder.');
        } finally {
            setIsProcessing(false);
        }
    };

    const value = {
        folders,
        counts,
        loading,
        isProcessing,
        activeFolderId,
        setActiveFolderId,
        draftFolderParentId,
        setDraftFolderParentId,
        editingFolderId,
        setEditingFolderId,
        contextMenu,
        setContextMenu,
        createFolder,
        deleteFolder,
        renameFolder,
        changeFolderColor,
        duplicateFolder,
        moveFolder,
        reorderFolder,
        moveMediaToFolder,
        draggedFolderId,
        setDraggedFolderId,
        clipboardFolderId,
        setClipboardFolderId,
        folderToDelete,
        setFolderToDelete,
        folderSort,
        setFolderSort,
        fileSort,
        setFileSort,
        displayFolderId,
        setDisplayFolderId,
        collapseAllTrigger,
        setCollapseAllTrigger,
        expandAllTrigger,
        setExpandAllTrigger,
        isBulkSelectMode,
        setIsBulkSelectMode,
        selectedFolders,
        setSelectedFolders,
        toastMessage,
        showToast,
        refresh: fetchFolders
    };

    return (
        <FolderContext.Provider value={value}>
            {children}
        </FolderContext.Provider>
    );
};
