/**
 * Initializes jQuery UI draggable on WordPress native media items.
 * Works for both Grid View and List View.
 */

export const initDraggable = () => {
    if (typeof jQuery === 'undefined' || !jQuery.ui || !jQuery.ui.draggable) {
        console.warn('Folderify: jQuery UI Draggable not loaded.');
        return;
    }

    const makeDraggable = () => {
        // Grid View attachments
        let items = jQuery('.attachment:not(.folderify-draggable)');

        // Only allow List View rows to be draggable on media or allowed post type screens
        let allowedListTable = false;
        if (window.pagenow === 'upload') {
            allowedListTable = true;
        } else if (window.pagenow && window.pagenow.indexOf('edit-') === 0 && typeof mediaOrganizerParams !== 'undefined') {
            const pt = window.pagenow.replace('edit-', '') || 'post';
            if (mediaOrganizerParams.allowedPostTypes && mediaOrganizerParams.allowedPostTypes.includes(pt)) {
                allowedListTable = true;
            }
        }

        if (allowedListTable) {
            items = items.add('.wp-list-table tbody tr:not(.folderify-draggable)');
        }

        if (items.length > 0) {
            items.addClass('folderify-draggable').draggable({
                cancel: '.check, .media-modal-icon, input, textarea, button, select, a',
                distance: 3,
                appendTo: 'body',
                cursor: 'move',
                cursorAt: { top: 10, left: 10 },
                helper: function () {
                    const $el = jQuery(this);
                    let selectedCount = 1;

                    // Bulk selection logic — works in media library and media modal
                    if ($el.hasClass('attachment')) {
                        const $selected = jQuery('.attachment.selected');
                        if ($el.hasClass('selected') && $selected.length > 0) {
                            selectedCount = $selected.length;
                        }
                    } else if ($el.is('tr')) {
                        const $checked = jQuery('.wp-list-table tbody tr input[type="checkbox"]:checked');
                        if ($el.find('input[type="checkbox"]').prop('checked') && $checked.length > 0) {
                            selectedCount = $checked.length;
                        }
                    }

                    const $helper = jQuery(`
                        <div class="folderify-drag-helper">
                            <span class="folderify-drag-count">${selectedCount}</span>
                            ${selectedCount === 1 ? 'file' : 'files'}
                        </div>
                    `);

                    return $helper;
                },
                start: function (event, ui) {
                    jQuery(this).addClass('folderify-is-dragging');
                    // Ensure helper is on top of everything including WP modal
                    setTimeout(() => {
                        jQuery('.folderify-drag-helper').css('z-index', 999999);
                    }, 0);
                },
                stop: function (event, ui) {
                    jQuery(this).removeClass('folderify-is-dragging');
                }
            });
        }
    };

    // Make items draggable immediately and set interval for dynamic Grid items
    makeDraggable();
    setInterval(makeDraggable, 500);
};
