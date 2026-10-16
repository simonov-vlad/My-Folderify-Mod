/**
 * Main entry point for the React application.
 */
import { render, Component } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import apiFetch from '@wordpress/api-fetch';
import { initMediaModal } from '../assets/js/media-modal.js';
class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }
    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }
    componentDidCatch(error, errorInfo) {
        console.error("Folderify React Error:", error, errorInfo);
    }
    render() {
        if (this.state.hasError) {
            return (
                <div style={{ padding: '20px', color: '#b32d2e', background: '#fcf0f1', borderLeft: '4px solid #b32d2e', flex: 1 }}>
                    <h3 style={{ marginTop: 0 }}>Folderify Render Error</h3>
                    <p style={{ fontFamily: 'monospace' }}>{this.state.error && this.state.error.toString()}</p>
                </div>
            );
        }
        return this.props.children;
    }
}

import './style.scss'; // if we want to add styles
import App from './components/App';
import { initDraggable } from './draggable';
import Sidebar from './components/Sidebar';
import { FolderProvider } from './components/FolderContext';
import UploadFolderPicker from './components/UploadFolderPicker';


window.FolderifyApp = {
    render: (container) => {
        render(
            <ErrorBoundary>
                <FolderProvider>
                    <Sidebar />
                </FolderProvider>
            </ErrorBoundary>,
            container
        );
    },
    renderUploadPicker: (container) => {
        render(
            <ErrorBoundary>
                <UploadFolderPicker />
            </ErrorBoundary>,
            container
        );
    },
};

// Helper: resolve which folder ID to use for uploads
// Priority: Upload picker selection > Sidebar active folder
const getUploadFolderId = () => {
    if (window.mediaOrganizerUploadFolderId && window.mediaOrganizerUploadFolderId > 0) {
        return window.mediaOrganizerUploadFolderId;
    }
    if (window.mediaOrganizerActiveFolderId && window.mediaOrganizerActiveFolderId > 0) {
        return window.mediaOrganizerActiveFolderId;
    }
    return 0;
};

// Configure apiFetch middleware to inject folder during REST API uploads (e.g., Gutenberg)
apiFetch.use((options, next) => {
    if (
        options.path &&
        options.path.indexOf('/wp/v2/media') !== -1 &&
        options.method &&
        options.method.toUpperCase() === 'POST'
    ) {
        const folderId = getUploadFolderId();
        if (folderId > 0) {
            if (options.body instanceof FormData) {
                options.body.set('folderify_folder', folderId);
            }
        }
    }
    return next(options);
});

// Configure jQuery ajaxPrefilter to inject folder during classic media uploads
if (typeof jQuery !== 'undefined') {
    jQuery.ajaxPrefilter((options, originalOptions, jqXHR) => {
        if (options.url && options.url.indexOf('async-upload.php') !== -1) {
            const folderId = getUploadFolderId();
            if (folderId > 0) {
                if (options.data && typeof options.data === 'string') {
                    options.data += '&folderify_folder=' + folderId;
                } else if (originalOptions.data instanceof FormData) {
                    originalOptions.data.set('folderify_folder', folderId);
                    options.data = originalOptions.data;
                }
            }
        }
    });
}

domReady(() => {
    // Start tracking and making WP media items draggable
    initDraggable();

    // Initialize Media Modal integration exactly when DOM is ready
    if (typeof initMediaModal === 'function') {
        initMediaModal();
    }

    const initApp = () => {
        const wpbody = document.getElementById('wpbody');
        const wpbodyContent = document.getElementById('wpbody-content');

        const isMediaLibrary = document.body.classList.contains('upload-php');
        const isPostList = document.body.classList.contains('edit-php');

        // Check if this CPT is in the allowed list
        const allowedPostTypes = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.allowedPostTypes)
            ? mediaOrganizerParams.allowedPostTypes
            : [];
        const currentPostType = (typeof mediaOrganizerParams !== 'undefined' && mediaOrganizerParams.postType)
            ? mediaOrganizerParams.postType
            : 'attachment';
        const isAllowedCpt = isPostList && allowedPostTypes.includes(currentPostType);

        if (wpbody && wpbodyContent && (isMediaLibrary || isAllowedCpt)) {
            if (!document.getElementById('folderify-root')) {
                const appRoot = document.createElement('div');
                appRoot.id = 'folderify-root';

                // Prepend before wpbody-content
                wpbody.insertBefore(appRoot, wpbodyContent);
                document.body.classList.add('folderify-active');

                render(
                    <ErrorBoundary>
                        <App />
                    </ErrorBoundary>,
                    appRoot
                );
            }
            return true;
        }

        return false;
    };

    console.log('Folderify: Init sequence started. Waiting for DOM containers...');

    if (!initApp()) {
        const checkInterval = setInterval(() => {
            if (initApp()) {
                console.log('Folderify: App initialized successfully from polling.');
                clearInterval(checkInterval);
            }
        }, 100);

        // Timeout after 10s
        setTimeout(() => {
            console.log('Folderify: Polling timeout. Could not find container.');
            clearInterval(checkInterval);
        }, 10000);
    } else {
        console.log('Folderify: App initialized immediately.');
    }
});
