import { useFolders } from './FolderContext';
import FolderItem from './FolderItem';
import FolderInput from './FolderInput';

const EmptyFolderIcon = () => (
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M10 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V8C22 6.9 21.1 6 20 6H12L10 4Z" fill="#c3c4c7" />
        <path d="M12 11V17M9 14H15" stroke="#fff" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
);

const FolderTree = ({ searchQuery }) => {
    const { folders, loading, draftFolderParentId, setDraftFolderParentId, createFolder, folderSort } = useFolders();

    if (loading) {
        return (
            <div className="folderify-loading" style={{ padding: '40px 20px', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: '#8c8f94', fontSize: '13px' }}>
                <svg width="24" height="24" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style={{ marginBottom: '10px' }}>
                    <circle cx="50" cy="50" fill="none" r="44" strokeWidth="10" stroke="#ccd0d4" />
                    <circle cx="50" cy="50" fill="none" r="44" strokeWidth="10" stroke="var(--wp-admin-theme-color, #2271b1)" strokeDasharray="276" strokeDashoffset="220" strokeLinecap="round" style={{ transformOrigin: 'center', animation: 'folderify_spin 1s linear infinite' }} />
                </svg>
                <span>Loading folders...</span>
            </div>
        );
    }

    const onCreationSave = async (name) => {
        await createFolder(name, 0);
        setDraftFolderParentId(null);
    };

    const onCreationCancel = () => {
        setDraftFolderParentId(null);
    };

    // Search filter
    if (searchQuery && searchQuery.trim() !== '') {
        const lowerQuery = searchQuery.toLowerCase();
        const displayFolders = folders.filter(f => f.name.toLowerCase().includes(lowerQuery));

        const folderMap = {};
        folders.forEach(f => { folderMap[f.id] = f; });

        const getParentPath = (folder) => {
            const parts = [];
            let current = folderMap[folder.parent_id];
            while (current) {
                parts.unshift(current.name);
                current = folderMap[current.parent_id];
            }
            return parts.join(' › ');
        };

        return (
            <div className="folderify-tree search-results">
                <ul>
                    {displayFolders.map(folder => (
                        <FolderItem
                            key={folder.id}
                            folder={folder}
                            isSearchResult={true}
                            parentPath={getParentPath(folder)}
                        />
                    ))}
                </ul>
            </div>
        );
    }

    // Empty state
    if (folders.length === 0 && draftFolderParentId === null) {
        const settingsUrl = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.settingsUrl)
            ? mediaOrganizerParams.settingsUrl
            : 'options-general.php?page=folderify&tab=import';

        return (
            <div className="folderify-empty-state">
                <EmptyFolderIcon />
                <p className="folderify-empty-title">No folders yet</p>
                <p className="folderify-empty-desc">Start organizing your media by creating a folder, or import an existing structure.</p>
                <div className="folderify-empty-actions">
                    <button
                        className="folderify-empty-btn-primary"
                        onClick={() => setDraftFolderParentId(0)}
                    >
                        + Add First Folder
                    </button>
                    <a className="folderify-empty-btn-secondary" href={settingsUrl}>
                        Import Folders
                    </a>
                </div>
            </div>
        );
    }

    // Show input at root when Add New or Add First Folder clicked with no folders yet
    if (folders.length === 0 && draftFolderParentId === 0) {
        return (
            <div className="folderify-tree">
                <ul>
                    <FolderInput onSave={onCreationSave} onCancel={onCreationCancel} />
                </ul>
            </div>
        );
    }

    // Build tree
    const isPremium = window.mediaOrganizerParams?.isPremium;
    const buildTree = (items, parentId = 0) => {
        if (!isPremium) {
            // For Free version, we don't build a tree. 
            // We just return everything flat if parentId is 0, otherwise empty for children loops.
            return parentId === 0 ? items.map(item => ({ ...item, children: [] })) : [];
        }

        let filtered = items.filter(item => item.parent_id === parentId);

        // Apply folder sorting based on Context
        if (folderSort && folderSort.order !== 'default') {
            filtered.sort((a, b) => {
                let valA = a.name ? a.name.toLowerCase() : '';
                let valB = b.name ? b.name.toLowerCase() : '';

                if (folderSort.order === 'asc') return valA.localeCompare(valB);
                if (folderSort.order === 'desc') return valB.localeCompare(valA);

                return 0;
            });
        }

        return filtered.map(item => ({
            ...item,
            children: buildTree(items, item.id)
        }));
    };

    const tree = buildTree(folders, 0);

    return (
        <div className="folderify-tree">
            <ul>
                {tree.map((folder, index) => (
                    <FolderItem key={folder.id} folder={folder} index={index} />
                ))}
                {draftFolderParentId === 0 && <FolderInput onSave={onCreationSave} onCancel={onCreationCancel} />}
            </ul>
        </div>
    );
};

export default FolderTree;
