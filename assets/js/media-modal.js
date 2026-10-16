/**
 * Integration with WordPress Backbone.js media modal.
 *
 * This file handles:
 * 1. Injecting the folder sidebar into the Media Library modal tab
 * 2. Injecting the folder picker into the Upload files modal tab
 * 3. Injecting the folder ID into uploads via wp.Uploader.defaults
 */

export function initMediaModal() {
    const $ = window.jQuery;
    const wp = window.wp;

    if (!wp || !wp.media || !wp.media.view) {
        return;
    }

    // ── 1. Inject sidebar into modal's Media Library tab ──────────────────
    // Only inject inside a media modal frame, NOT the main media page
    // (the main page already has its own sidebar via <App />).
    if (wp.media.view.AttachmentsBrowser) {
        const OriginalAttachmentsBrowser = wp.media.view.AttachmentsBrowser;

        wp.media.view.AttachmentsBrowser = OriginalAttachmentsBrowser.extend({
            render: function () {
                OriginalAttachmentsBrowser.prototype.render.apply(this, arguments);

                // Skip on the main upload.php page — it already has a sidebar via <App />
                if (document.body.classList.contains('upload-php')) {
                    return this;
                }

                this.$el.addClass('folderify-active');

                if (!this.$el.find('#folderify-modal-flex-container').length) {
                    const innerElements = this.$el.children();
                    const flexContainer = $('<div id="folderify-modal-flex-container" class="folderify-flex-container"></div>');
                    const sidebarContainer = $('<div id="folderify-modal-root" class="folderify-sidebar"></div>');
                    const contentContainer = $('<div class="folderify-modal-content"></div>');

                    contentContainer.append(innerElements);
                    flexContainer.append(sidebarContainer);
                    flexContainer.append(contentContainer);
                    this.$el.append(flexContainer);

                    if (window.FolderifyApp) {
                        window.FolderifyApp.render(sidebarContainer[0]);
                    }
                }

                return this;
            }
        });
    }

    // ── 2. Inject folder picker into Upload tab ───────────────────────────
    if (wp.media.view.UploaderInline) {
        const OriginalUploaderInline = wp.media.view.UploaderInline;

        wp.media.view.UploaderInline = OriginalUploaderInline.extend({
            render: function () {
                OriginalUploaderInline.prototype.render.apply(this, arguments);

                if (!this.$el.find('#folderify-upload-folder-picker').length) {
                    const mountPoint = $('<div id="folderify-upload-folder-picker"></div>');

                    const $hint = this.$el.find('.max-upload-size');
                    if ($hint.length) {
                        $hint.before(mountPoint);
                    } else {
                        this.$el.append(mountPoint);
                    }

                    if (window.FolderifyApp && window.FolderifyApp.renderUploadPicker) {
                        window.FolderifyApp.renderUploadPicker(mountPoint[0]);
                    }
                }

                return this;
            }
        });
    }

    // ── 3. Inject folder ID into uploads ──────────────────────────────────
    // WordPress plupload sends files via raw XHR to async-upload.php, NOT
    // through jQuery AJAX. So ajaxPrefilter does NOT work for uploads.
    //
    // The reliable approach (same as FileBird): set the folder on
    // wp.Uploader.defaults.multipart_params. Each new wp.Uploader instance
    // deep-clones these defaults, so the value must be set BEFORE the
    // uploader is instantiated (which happens when the Upload tab opens).
    //
    // To keep the value fresh we update it every 200ms, and also
    // UploadFolderPicker.js sets mediaOrganizerActiveFolderId on selection.
    if (typeof wp.Uploader !== 'undefined') {
        wp.Uploader.defaults = wp.Uploader.defaults || {};
        wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};

        function getFolderId() {
            var id = window.mediaOrganizerUploadFolderId;
            if (id && id > 0) return id;
            id = window.mediaOrganizerActiveFolderId;
            if (id && id > 0) return id;
            return 0;
        }

        // Set initial value
        wp.Uploader.defaults.multipart_params.folderify_folder = getFolderId();

        // Poll to keep it fresh (value is read only at uploader creation time)
        setInterval(function () {
            if (wp.Uploader.defaults && wp.Uploader.defaults.multipart_params) {
                wp.Uploader.defaults.multipart_params.folderify_folder = getFolderId();
            }
        }, 200);

        // When uploads complete, refresh folder counts and grid
        if (wp.Uploader.queue) {
            wp.Uploader.queue.on('all', function () {
                if (wp.Uploader.queue.length === 0) {
                    jQuery(document).trigger('folderify_refresh_folders');
                }
            });
        }
    }
}
