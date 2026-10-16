import { useEffect, useState } from '@wordpress/element';
import { createPortal } from '@wordpress/element';
import { useFolders } from './FolderContext';

// SVG folder icon matching sidebar style
const FolderIcon = ({ color = '#a0aab2', size = 18 }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill={color}>
        <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" />
    </svg>
);

const HomeIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" style={{ flexShrink: 0 }}>
        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />
    </svg>
);

const ChevronIcon = () => (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="9 18 15 12 9 6" />
    </svg>
);

const MediaAreaHeader = () => {
    const { folders, activeFolderId, setActiveFolderId, counts } = useFolders();
    const [container, setContainer] = useState(null);

    // Find or create the portal container
    useEffect(() => {
        let el = document.getElementById('folderify-media-header');
        if (!el) {
            el = document.createElement('div');
            el.id = 'folderify-media-header';
            // Attach to a highly stable area outside of Backbone's dynamic views
            const headerEnd = document.querySelector('.wp-header-end');
            if (headerEnd && headerEnd.parentNode) {
                headerEnd.parentNode.insertBefore(el, headerEnd.nextSibling);
            } else {
                const wrap = document.querySelector('.wrap');
                if (wrap) {
                    wrap.insertBefore(el, wrap.children[1] || wrap.firstChild);
                } else {
                    document.body.appendChild(el);
                }
            }
        }
        setContainer(el);
    }, []);

    // Don't show breadcrumb path for -1 or 0, but DO show subfolders for -1 (root)
    const isRoot = activeFolderId === -1;
    const isUncategorized = activeFolderId === 0;

    // Respect the "Show breadcrumb" setting AND Premium status
    // wp_localize_script sends PHP 0 as string "0", so use Number() to normalize
    const isPremium = typeof mediaOrganizerParams !== 'undefined'
        ? !!mediaOrganizerParams.isPremium
        : false;

    const breadcrumbEnabled = (typeof mediaOrganizerParams !== 'undefined'
        ? Number(mediaOrganizerParams.showBreadcrumb) !== 0
        : true) && isPremium;

    if (!container) return null;
    if (!breadcrumbEnabled) return null;
    if (isUncategorized || isRoot) return null; // Uncategorized and All Files have no breadcrumbs/subfolders

    // Build folder lookup
    const folderMap = {};
    folders.forEach(f => { folderMap[f.id] = f; });

    // Build breadcrumb chain
    const buildBreadcrumb = (id) => {
        const chain = [];
        let current = folderMap[id];
        while (current) {
            chain.unshift(current);
            current = current.parent_id ? folderMap[current.parent_id] : null;
        }
        return chain;
    };

    const breadcrumb = !isRoot ? buildBreadcrumb(activeFolderId) : [];

    // Get immediate children subfolders
    const parentToFind = isRoot ? 0 : activeFolderId;
    const subfolders = folders.filter(f => parseInt(f.parent_id, 10) === parentToFind);

    const content = (
        <div className="folderify-media-header">
            {/* Breadcrumb */}
            <nav className="folderify-breadcrumb" aria-label="Folder path">
                <button
                    className="folderify-breadcrumb-home"
                    onClick={() => setActiveFolderId(-1)}
                    title="All Files"
                >
                    <HomeIcon />
                </button>
                {breadcrumb.map((f, idx) => (
                    <span key={f.id} className="folderify-breadcrumb-segment">
                        <span className="folderify-breadcrumb-sep"><ChevronIcon /></span>
                        {idx === breadcrumb.length - 1 ? (
                            <span className="folderify-breadcrumb-current">
                                {f.name}
                            </span>
                        ) : (
                            <button
                                className="folderify-breadcrumb-link"
                                onClick={() => setActiveFolderId(f.id)}
                            >
                                {f.name}
                            </button>
                        )}
                    </span>
                ))}
            </nav>

            {/* Subfolder cards */}
            {subfolders.length > 0 && (
                <div className="folderify-subfolders-grid">
                    {subfolders.map(sf => (
                        <button
                            key={sf.id}
                            className="folderify-subfolder-card"
                            onClick={() => setActiveFolderId(sf.id)}
                            title={sf.name}
                        >
                            <span className="folderify-subfolder-icon">
                                <FolderIcon color={sf.color || '#8c8f94'} size={16} />
                            </span>
                            <span className="folderify-subfolder-name">{sf.name}</span>
                            <span className="folderify-subfolder-count">{counts[sf.id] || 0}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );

    return createPortal(content, container);
};

export default MediaAreaHeader;
