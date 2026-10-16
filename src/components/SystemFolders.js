import { useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useFolders } from './FolderContext';

const SystemFolders = () => {
    const { activeFolderId, setActiveFolderId, counts, moveMediaToFolder } = useFolders();
    const uncategorizedRef = useRef(null);

    // counts.total comes from our new REST update
    const allFilesCount = counts.total || 0;

    // categorized exact count now comes freshly calculated from our PHP endpoint
    const categorizedCount = counts.categorized || 0;

    // Uncategorized count is total files minus all categorized files
    const uncategorizedCount = Math.max(0, allFilesCount - categorizedCount);

    useEffect(() => {
        if (!uncategorizedRef.current || typeof jQuery === 'undefined' || !jQuery.ui || !jQuery.ui.droppable) return;

        const $el = jQuery(uncategorizedRef.current);

        $el.droppable({
            accept: '.folderify-draggable',
            hoverClass: 'folderify-droppable-hover',
            tolerance: 'pointer',
            drop: function (event, ui) {
                const $draggable = ui.draggable;
                let draggedIds = [];

                if ($draggable.hasClass('attachment')) {
                    if ($draggable.hasClass('selected') && jQuery('.attachment.selected').length > 0) {
                        jQuery('.attachment.selected').each(function () { draggedIds.push(jQuery(this).data('id')); });
                    } else {
                        draggedIds.push($draggable.data('id'));
                    }
                } else if ($draggable.is('tr')) {
                    if ($draggable.find('input[type="checkbox"]').prop('checked') && jQuery('.wp-list-table tbody tr input[type="checkbox"]:checked').length > 0) {
                        jQuery('.wp-list-table tbody tr input[type="checkbox"]:checked').each(function () { draggedIds.push(jQuery(this).val()); });
                    } else {
                        draggedIds.push($draggable.find('input[type="checkbox"]').val());
                    }
                }

                if (draggedIds.length > 0) {
                    moveMediaToFolder(draggedIds, 0); // 0 corresponds to Uncategorized
                    $el.css('background', '#dcf0da');
                    setTimeout(() => $el.css('background', ''), 500);

                    // Show SweetAlert Toast
                    if (typeof window.Swal !== 'undefined') {
                        const Toast = window.Swal.mixin({
                            toast: true,
                            position: 'bottom-end',
                            showConfirmButton: false,
                            timer: 3000,
                            timerProgressBar: true,
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', window.Swal.stopTimer)
                                toast.addEventListener('mouseleave', window.Swal.resumeTimer)
                            }
                        });

                        Toast.fire({
                            icon: 'success',
                            title: `${draggedIds.length} item(s) moved to Uncategorized`
                        });
                    }
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
    }, [moveMediaToFolder]);

    return (
        <div className="folderify-system-folders-wrapper">
            <div className="folderify-system-folders-grid">
                <div
                    className={`sf-cell sf-left ${activeFolderId === -1 ? 'active' : ''}`}
                    onClick={() => setActiveFolderId(-1)}
                >
                    <span className="sf-title">{__('All Files', 'folderify')}</span>
                    <span className="sf-count">{allFilesCount}</span>
                </div>

                <div
                    className={`sf-cell sf-right ${activeFolderId === 0 ? 'active' : ''}`}
                    onClick={() => setActiveFolderId(0)}
                    ref={uncategorizedRef}
                >
                    <span className="sf-title">{__('Uncategorized', 'folderify')}</span>
                    <span className="sf-count">{uncategorizedCount}</span>
                </div>
            </div>
        </div>
    );
};

export default SystemFolders;
