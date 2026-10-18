<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Media_Replace_Page
 *
 * Handles the dedicated page for media replacement.
 */
class Media_Replace_Page
{
    private $page_slug = 'folderify-replace';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_post_folderify_replace', [$this, 'handle_submission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Cache busting for replaced media in admin
        add_filter('wp_get_attachment_url', [$this, 'admin_cache_buster'], 10, 2);
    }

    /**
     * Appends a version query string to attachment URLs in the admin to bust browser cache.
     */
    public function admin_cache_buster($url, $post_id)
    {
        if (!is_admin() || empty($url)) {
            return $url;
        }
        // Do not alter URLs for third-party AJAX handlers (e.g. page builders validate the file extension).
        if (wp_doing_ajax()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $action = isset($_REQUEST['action']) ? wp_unslash($_REQUEST['action']) : '';
            if (!in_array($action, ['query-attachments', 'get-attachment', 'save-attachment', 'upload-attachment'], true)) {
                return $url;
            }
        }
        $modified = get_post_modified_time('U', true, $post_id);
        if ($modified) {
            $url = add_query_arg('v', $modified, $url);
        }
        return $url;
    }

    public function enqueue_assets($hook)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page identification for asset enqueuing, no data mutation.
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($current_page !== $this->page_slug) {
            return;
        }

        // CSS
        wp_enqueue_style('folderify-replace', FOLDERIFY_URL . 'assets/css/replace-page.css', [], FOLDERIFY_VERSION);

        // JS - Using a virtual handle for cleaner inline script attachment
        wp_register_script('folderify-replace', false, [], FOLDERIFY_VERSION, true);
        wp_enqueue_script('folderify-replace');

        ob_start();
        include FOLDERIFY_DIR . 'assets/js/replace-page-inline.php';
        $js = ob_get_clean();

        wp_add_inline_script('folderify-replace', $js);
    }

    /**
     * Register a hidden submenu page.
     */
    public function register_page()
    {
        add_submenu_page(
            null, // Hidden from menu
            __('Replace Media', 'folderify'),
            __('Replace Media', 'folderify'),
            'edit_posts',
            $this->page_slug,
            [$this, 'render_page']
        );
    }

    /**
     * Render the replacement page UI.
     */
    public function render_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only page, no data mutation.
        $attachment_id = isset($_GET['attachment_id']) ? intval(wp_unslash($_GET['attachment_id'])) : 0;
        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            wp_die(esc_html__('Invalid attachment ID.', 'folderify'));
        }

        if (!current_user_can('edit_post', $attachment_id)) {
            wp_die(esc_html__('You do not have permission to edit this attachment.', 'folderify'));
        }

        $url = wp_get_attachment_url($attachment_id);
        $filename = basename(get_attached_file($attachment_id));
        $mime = get_post_mime_type($attachment_id);
        $is_image = strpos($mime, 'image/') === 0;

        ?>
        

        <div class="folderify-replace-wrap">
            <header class="folderify-replace-header">
                <h1><?php esc_html_e('Replace Media', 'folderify'); ?></h1>
                <p><?php esc_html_e('Upload a new file and choose how to handle the replacement.', 'folderify'); ?></p>
            </header>

            <form id="folderify-replace-form" method="post" enctype="multipart/form-data"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('folderify_replace_action', 'folderify_replace_nonce'); ?>
                <input type="hidden" name="action" value="folderify_replace">
                <input type="hidden" name="attachment_id" value="<?php echo esc_attr($attachment_id); ?>">

                <div class="folderify-replace-grid">
                    <!-- Current File Card -->
                    <div class="folderify-card">
                        <span class="folderify-card-title"><?php esc_html_e('Current File', 'folderify'); ?></span>
                        <div class="folderify-preview-box">
                            <?php if ($is_image): ?>
                                <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($filename); ?>">
                            <?php else: ?>
                                <div class="folderify-preview-placeholder">
                                    <span class="dashicons dashicons-media-default"
                                        style="font-size: 48px; width: 48px; height: 48px;"></span>
                                    <span><?php echo esc_html($mime); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="folderify-file-info">
                            <p><strong><?php esc_html_e('Name:', 'folderify'); ?></strong>
                                <?php echo esc_html($filename); ?></p>
                            <p><strong><?php esc_html_e('Type:', 'folderify'); ?></strong> <?php echo esc_html($mime); ?>
                            </p>
                        </div>
                    </div>

                    <!-- New File Card (Unified) -->
                    <div class="folderify-card">
                        <span class="folderify-card-title"><?php esc_html_e('New File', 'folderify'); ?></span>

                        <div id="folderify-upload-container" class="folderify-upload-container"
                            onclick="document.getElementById('folderify-file-input').click()">
                            <div id="folderify-upload-zone" class="folderify-upload-zone">
                                <div id="folderify-empty-state" class="folderify-preview-placeholder">
                                    <span class="dashicons dashicons-cloud-upload"></span>
                                    <p><strong><?php esc_html_e('Click or drag to upload', 'folderify'); ?></strong></p>
                                    <p><?php
                                    // translators: %s: MIME type of the current file, e.g. "image/jpeg".
                                    printf(esc_html__('Supports: %s', 'folderify'), esc_html($mime));
                                    ?></p>
                                </div>
                                <div id="folderify-file-preview"
                                    style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                    <!-- Preview content injected here -->
                                </div>
                            </div>

                            <div id="folderify-upload-overlay" class="folderify-upload-overlay">
                                <span class="dashicons dashicons-upload"></span>
                                <span><?php esc_html_e('Click to change file', 'folderify'); ?></span>
                            </div>

                            <input type="file" id="folderify-file-input" name="file" required accept="<?php echo esc_attr($mime); ?>"
                                style="display: none;">
                        </div>

                        <div id="folderify-new-file-info" class="folderify-file-info" style="visibility: hidden;">
                            <p><strong><?php esc_html_e('New Name:', 'folderify'); ?></strong> <span
                                    id="folderify-new-filename">-</span>
                            </p>
                            <p><strong><?php esc_html_e('New Size:', 'folderify'); ?></strong> <span
                                    id="folderify-new-filesize">-</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="folderify-options-container">
                    <!-- Replacement Options -->
                    <div class="folderify-option-group">
                        <h3><?php esc_html_e('Replacement Logic', 'folderify'); ?></h3>

                        <label class="folderify-choice active">
                            <input type="radio" name="replace_type" value="replace" checked>
                            <span class="folderify-choice-text">
                                <span
                                    class="folderify-choice-title"><?php esc_html_e('Just replace the file', 'folderify'); ?></span>
                                <span
                                    class="folderify-choice-desc"><?php esc_html_e('Keeps the original filename. Only the file content updates.', 'folderify'); ?></span>
                            </span>
                        </label>

                        <label class="folderify-choice">
                            <input type="radio" name="replace_type" value="replace_and_search">
                            <span class="folderify-choice-text">
                                <span
                                    class="folderify-choice-title"><?php esc_html_e('Replace and rename', 'folderify'); ?></span>
                                <span
                                    class="folderify-choice-desc"><?php esc_html_e('Uses the new filename and updates all references in your database.', 'folderify'); ?></span>
                            </span>
                        </label>
                    </div>

                    <!-- Date Options -->
                    <div class="folderify-option-group">
                        <h3><?php esc_html_e('Date & Time', 'folderify'); ?></h3>

                        <label class="folderify-choice active">
                            <input type="radio" name="timestamp_replace" value="keep" checked>
                            <span class="folderify-choice-text">
                                <span
                                    class="folderify-choice-title"><?php esc_html_e('Keep original date', 'folderify'); ?></span>
                                <span
                                    class="folderify-choice-desc"><?php esc_html_e('Retain the original upload timestamp.', 'folderify'); ?></span>
                            </span>
                        </label>

                        <label class="folderify-choice">
                            <input type="radio" name="timestamp_replace" value="current">
                            <span class="folderify-choice-text">
                                <span
                                    class="folderify-choice-title"><?php esc_html_e('Set to current time', 'folderify'); ?></span>
                                <span
                                    class="folderify-choice-desc"><?php esc_html_e('Update the date to right now.', 'folderify'); ?></span>
                            </span>
                        </label>

                        <label class="folderify-choice" id="folderify-custom-date-choice">
                            <input type="radio" name="timestamp_replace" value="custom">
                            <span class="folderify-choice-text">
                                <span class="folderify-choice-title"><?php esc_html_e('Set custom date', 'folderify'); ?></span>
                                <span
                                    class="folderify-choice-desc"><?php esc_html_e('Manually specify a historical or future date.', 'folderify'); ?></span>
                            </span>
                            <div class="folderify-date-input">
                                <div class="folderify-date-field">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <input type="date" name="custom_date" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>">
                                </div>
                                <div class="folderify-date-field">
                                    <span class="dashicons dashicons-clock"></span>
                                    <input type="time" name="custom_time" value="<?php echo esc_attr(gmdate('H:i')); ?>">
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="folderify-actions">
                    <button type="submit" class="folderify-btn folderify-btn-primary">
                        <span class="dashicons dashicons-upload" style="margin-right: 8px;"></span>
                        <?php esc_html_e('Start Replacement', 'folderify'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('upload.php')); ?>" class="folderify-btn folderify-btn-secondary">
                        <?php esc_html_e('Cancel', 'folderify'); ?>
                    </a>
                </div>
            </form>
        </div>

        
        <?php
    }

    /**
     * Handle the form submission.
     */
    public function handle_submission()
    {
        if (!isset($_POST['folderify_replace_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['folderify_replace_nonce'])), 'folderify_replace_action')) {
            wp_die(esc_html__('Security check failed.', 'folderify'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? intval(wp_unslash($_POST['attachment_id'])) : 0;

        if (!current_user_can('edit_post', $attachment_id)) {
            wp_die(esc_html__('You do not have permission to edit this attachment.', 'folderify'));
        }

        if (empty($_FILES['file']['name'])) {
            wp_safe_redirect(add_query_arg('error', 'no_file', admin_url('upload.php')));
            exit;
        }

        $custom_date = isset($_POST['custom_date']) ? sanitize_text_field(wp_unslash($_POST['custom_date'])) : '';
        $custom_time = isset($_POST['custom_time']) ? sanitize_text_field(wp_unslash($_POST['custom_time'])) : '';
        $combined_date = '';

        if (!empty($custom_date) && !empty($custom_time)) {
            $combined_date = $custom_date . ' ' . $custom_time;
        }

        $options = [
            'replace_type' => isset($_POST['replace_type']) ? sanitize_text_field(wp_unslash($_POST['replace_type'])) : 'replace',
            'timestamp_replace' => isset($_POST['timestamp_replace']) ? sanitize_text_field(wp_unslash($_POST['timestamp_replace'])) : 'keep',
            'custom_date' => $combined_date,
        ];

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES data handled by wp_handle_upload internally.
        $result = Media_Replacer::replace($attachment_id, $_FILES['file'], $options);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        // Redirect back to attachment editor or library
        wp_safe_redirect(add_query_arg(['item' => $attachment_id, 'replaced' => '1'], admin_url('upload.php')));
        exit;
    }
}
