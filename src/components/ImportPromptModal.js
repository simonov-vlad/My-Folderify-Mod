import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

const ImportPromptModal = ({ plugins, onClose }) => {
    const [importingId, setImportingId] = useState(null);
    const [importedIds, setImportedIds] = useState([]);
    const [error, setError] = useState(null);

    const handleImport = (plugin) => {
        const actionMap = {
            'filebird': 'folderify_import_filebird',
            'folders_pro': 'folderify_import_folders_pro'
        };

        const action = actionMap[plugin.id];
        if (!action) return;

        setImportingId(plugin.id);
        setError(null);

        const ajaxUrl = window.mediaOrganizerParams?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.mediaOrganizerParams?.ajaxNonce || '';

        window.jQuery && window.jQuery.post(ajaxUrl, { action, nonce }, function (response) {
            setImportingId(null);
            if (response?.success) {
                setImportedIds(prev => [...prev, plugin.id]);
            } else {
                setError(response?.data || __('Import failed.', 'folderify'));
            }
        }).fail(function () {
            setImportingId(null);
            setError(__('Request failed. Please try again.', 'folderify'));
        });
    };

    return (
        <div className="folderify-import-modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
            <div className="folderify-import-modal">

                {/* Header */}
                <div className="folderify-import-modal-header">
                    <div className="folderify-import-modal-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                    </div>
                    <h3>{__('Import your folders', 'folderify')}</h3>
                    <p>
                        {__("We found folder data from another plugin. Import it in one click — your files will stay in the same folders.", 'folderify')}
                    </p>
                </div>

                {/* Plugin Cards */}
                <div className="folderify-import-modal-body">
                    {plugins.map(plugin => {
                        const isDone = importedIds.includes(plugin.id);
                        const isLoading = importingId === plugin.id;
                        const isPremium = mediaOrganizerParams?.isPremium;
                        const pricingUrl = mediaOrganizerParams?.pricingUrl || (mediaOrganizerParams?.settingsUrl + '-pricing');

                        return (
                            <div key={plugin.id} className={`folderify-import-plugin-card ${isDone ? 'is-done' : ''} ${!isPremium ? 'folderify-pro-lock' : ''}`}>
                                <div className="folderify-import-plugin-card-left">
                                    <div className="folderify-import-plugin-icon">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <span className="folderify-import-plugin-name">
                                            {plugin.name}
                                            {!isPremium && <span className="folderify-pro-badge-mini">PRO</span>}
                                        </span>
                                        <span className="folderify-import-plugin-meta">
                                            {plugin.folders} {__('folders', 'folderify')}
                                            {plugin.attachments > 0 && ` · ${plugin.attachments} ${__('files', 'folderify')}`}
                                        </span>
                                    </div>
                                </div>
                                <div className="folderify-import-plugin-card-right">
                                    {isDone ? (
                                        <span className="folderify-import-status-done">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                                <polyline points="20 6 9 17 4 12" />
                                            </svg>
                                            {__('Done', 'folderify')}
                                        </span>
                                    ) : !isPremium ? (
                                        <a
                                            className="folderify-import-btn folderify-pro-btn-glow"
                                            href={pricingUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            {__('Upgrade to Pro', 'folderify')}
                                        </a>
                                    ) : (
                                        <button
                                            className="folderify-import-btn"
                                            onClick={() => handleImport(plugin)}
                                            disabled={importingId !== null}
                                        >
                                            {isLoading ? (
                                                <>
                                                    <span className="folderify-import-spinner" />
                                                    {__('Importing…', 'folderify')}
                                                </>
                                            ) : __('Import', 'folderify')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {error && (
                        <p className="folderify-import-error">{error}</p>
                    )}
                </div>

                {/* Footer */}
                <div className="folderify-import-modal-footer">
                    <button className="folderify-import-close-btn" onClick={onClose}>
                        {__('Maybe later', 'folderify')}
                    </button>
                    <p className="folderify-import-footer-hint">
                        {__('You can always import manually from', 'folderify')} <a href={window.mediaOrganizerParams?.settingsUrl + '&tab=import'} target="_blank" rel="noreferrer">{__('Settings → Import', 'folderify')}</a>
                    </p>
                </div>

            </div>
        </div>
    );
};

export default ImportPromptModal;
