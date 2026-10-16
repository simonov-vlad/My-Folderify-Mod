<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Settings
 *
 * Handles the Folderify settings page (tabbed UI).
 */
class Settings
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('update_option_folderify_settings', [$this, 'on_settings_saved'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_folderify') {
            return;
        }

        // CSS
        wp_enqueue_style('folderify-settings', FOLDERIFY_URL . 'assets/css/settings.css', [], FOLDERIFY_VERSION);

        // JS: a virtual handle (src = false). With an empty string WordPress never registers
        // the handle, and wp_add_inline_script() silently prints nothing.
        wp_register_script('folderify-settings', false, ['jquery'], FOLDERIFY_VERSION, true);
        wp_enqueue_script('folderify-settings');

        ob_start();
        $nonce_val = wp_create_nonce('folderify_ajax');
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        include FOLDERIFY_DIR . 'assets/js/settings-inline.php';
        $js = ob_get_clean();
        
        wp_add_inline_script('folderify-settings', $js);
    }

    /**
     * Triggered when settings are saved.
     */
    public function on_settings_saved($old_value, $new_value)
    {
        Permissions::sync_role_capabilities($new_value);
    }

    public function add_settings_page()
    {
        add_menu_page(
            __('Folderify', 'folderify'),           // Page title
            __('Folderify', 'folderify'),           // Menu label
            'manage_options',                                 // Capability
            'folderify',                                 // Slug
            [$this, 'render_settings_page'],                  // Callback
            'dashicons-category',                             // Icon
            80                                                // Position (after Media = 10, after Appearance = 60)
        );
    }

    public function register_settings()
    {
        register_setting('folderify_settings_group', 'folderify_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input)
    {
        $sanitized = [];
        $checkboxes = ['delete_on_uninstall', 'per_user_folders', 'show_breadcrumb', 'physical_folders'];
        foreach ($checkboxes as $key) {
            $sanitized[$key] = !empty($input[$key]) ? 1 : 0;
        }
        // Folder counter mode: 'direct' or 'recursive'
        $sanitized['folder_counter'] = (isset($input['folder_counter']) && $input['folder_counter'] === 'recursive')
            ? 'recursive'
            : 'direct';

        // Folder Access Mode
        $sanitized['folderify_folder_mode'] = (isset($input['folderify_folder_mode']) && $input['folderify_folder_mode'] === 'private')
            ? 'private'
            : 'global';


        // Role arrays
        $arrays = [
            'folderify_private_allowed_roles',
            'folderify_global_create_roles',
            'folderify_global_edit_roles',
            'folderify_global_delete_roles'
        ];

        foreach ($arrays as $arr_key) {
            if (!empty($input[$arr_key]) && is_array($input[$arr_key])) {
                $sanitized[$arr_key] = array_map('sanitize_key', $input[$arr_key]);
            } else {
                $sanitized[$arr_key] = [];
            }
        }

        // Allowed post types — validate against real registered types
        $all_types = array_keys(get_post_types(['public' => true], 'names'));
        if (!empty($input['allowed_post_types']) && is_array($input['allowed_post_types'])) {
            $sanitized['allowed_post_types'] = array_values(
                array_intersect(array_map('sanitize_key', $input['allowed_post_types']), $all_types)
            );
        } else {
            $sanitized['allowed_post_types'] = [];
        }
        // Pro Gating: Only allow these if premium
        $is_pro = true;
        if (!$is_pro) {
            $sanitized['folderify_folder_mode'] = 'global';
            $sanitized['show_breadcrumb'] = 0;
        }

        return $sanitized;
    }

    public static function get($key, $default = false)
    {
        $options = get_option('folderify_settings', []);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = get_option('folderify_settings', []);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only page.
        $saved = isset($_GET['settings-updated']) && sanitize_key(wp_unslash($_GET['settings-updated']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only page.
        $active = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $nonce_val = wp_create_nonce('folderify_ajax');
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        ?>
        

        <div class="folderify-wrap">

            <!-- Header -->
            <div class="folderify-header">
                <h1><?php esc_html_e('Folderify', 'folderify'); ?></h1>
                <span class="folderify-badge">Settings</span>
            </div>

            <?php if ($saved): ?>
                <div class="folderify-notice" id="folderify-saved-notice">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    <?php esc_html_e('Settings saved successfully.', 'folderify'); ?>
                </div>
            <?php endif; ?>

            <?php
            $is_pro = true;
            $pricing_url = admin_url('admin.php?page=folderify-pricing');
            ?>

            <!-- Tab nav -->
            <nav class="folderify-tabs">
                <a href="?page=folderify&tab=general" class="folderify-tab <?php echo $active === 'general' ? 'active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path
                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
                    <?php esc_html_e('General', 'folderify'); ?>
                </a>
                <a href="?page=folderify&tab=import" class="folderify-tab <?php echo $active === 'import' ? 'active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <?php esc_html_e('Import / Export', 'folderify'); ?>
                </a>
                <a href="?page=folderify&tab=sync" class="folderify-tab <?php echo $active === 'sync' ? 'active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10" />
                        <polyline points="1 20 1 14 7 14" />
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
                    </svg>
                    <?php esc_html_e('Uploads sync', 'folderify'); ?>
                </a>
                <?php if ( true ) : ?>
                <a href="?page=folderify&tab=backups" class="folderify-tab <?php echo $active === 'backups' ? 'active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 9 20 20 4 20 4 9" />
                        <rect x="2" y="3" width="20" height="5" rx="1" />
                        <line x1="12" y1="12" x2="12" y2="17" />
                    </svg>
                    <?php esc_html_e('Backups', 'folderify'); ?>
                </a>
                <?php endif; ?>
            </nav>

            <!-- ── Tab: General ── -->
            <div class="folderify-panel <?php echo $active === 'general' ? 'active' : ''; ?>" id="tab-general">
                <form action="options.php" method="post">
                    <?php settings_fields('folderify_settings_group'); ?>

                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title">
                                <?php esc_html_e('Folder Access Mode', 'folderify'); ?>
                            </p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Choose how folders are shared and managed across your site.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-row" style="flex-direction:column;gap:10px;">
                            <?php $folder_mode = $options['folderify_folder_mode'] ?? 'global'; ?>
                            <label class="folderify-radio-label">
                                <input type="radio" name="folderify_settings[folderify_folder_mode]" value="global"
                                    <?php checked('global', $folder_mode); ?>
                                    onchange="document.getElementById('folderify-role-global').style.display='block';document.getElementById('folderify-role-private').style.display='none';" />
                                <?php esc_html_e('Global Shared Folders (Folders are visible to all users)', 'folderify'); ?>
                            </label>
                            
                            <?php if ( true ) : ?>
                            <label class="folderify-radio-label">
                                <input type="radio" name="folderify_settings[folderify_folder_mode]" value="private"
                                    <?php checked('private', $folder_mode); ?>
                                    onchange="document.getElementById('folderify-role-global').style.display='none';document.getElementById('folderify-role-private').style.display='block';" />
                                <?php esc_html_e('Private Per User Folders (Each user has their own folders, Admin sees all)', 'folderify'); ?>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( true ) : ?>
                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title">
                                <?php esc_html_e('Role Permissions', 'folderify'); ?>
                            </p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Configure which user roles can manage folders.', 'folderify'); ?>
                            </p>
                        </div>

                        <?php
                        $all_roles = wp_roles()->roles;
                        unset($all_roles['administrator']); // Admin always has full access
                        ?>

                        <!-- Global Mode Roles -->
                        <div id="folderify-role-global"
                            style="display: <?php echo $folder_mode === 'global' ? 'block' : 'none'; ?>; padding: 15px 20px;">

                            <div style="display:flex; gap: 40px;">
                                <div>
                                    <p style="font-size:13px; font-weight:600; margin-top:0;">Can Create Folders</p>
                                    <?php
                                    $g_create = $options['folderify_global_create_roles'] ?? ['editor', 'author'];
                                    foreach ($all_roles as $role_key => $role_data):
                                        ?>
                                        <label class="folderify-radio-label" style="margin-bottom:6px;">
                                            <input type="checkbox"
                                                name="folderify_settings[folderify_global_create_roles][]"
                                                value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $g_create), true); ?> />
                                            <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div>
                                    <p style="font-size:13px; font-weight:600; margin-top:0;">Can Edit Folders</p>
                                    <?php
                                    $g_edit = $options['folderify_global_edit_roles'] ?? ['editor'];
                                    foreach ($all_roles as $role_key => $role_data):
                                        ?>
                                        <label class="folderify-radio-label" style="margin-bottom:6px;">
                                            <input type="checkbox"
                                                name="folderify_settings[folderify_global_edit_roles][]"
                                                value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $g_edit), true); ?> />
                                            <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div>
                                    <p style="font-size:13px; font-weight:600; margin-top:0;">Can Delete Folders</p>
                                    <?php
                                    $g_del = $options['folderify_global_delete_roles'] ?? [];
                                    foreach ($all_roles as $role_key => $role_data):
                                        ?>
                                        <label class="folderify-radio-label" style="margin-bottom:6px;">
                                            <input type="checkbox"
                                                name="folderify_settings[folderify_global_delete_roles][]"
                                                value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $g_del), true); ?> />
                                            <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p style="font-size:12px; color:#8c8f94; margin-top:10px;">* Administrators always have total
                                access.</p>
                        </div>

                        <!-- Private Mode Roles -->
                        <div id="folderify-role-private"
                            style="display: <?php echo $folder_mode === 'private' ? 'block' : 'none'; ?>; padding: 15px 20px;">
                            <p style="font-size:13px; font-weight:600; margin-top:0;">Roles allowed to create their own folders
                            </p>
                            <?php
                            $p_create = $options['folderify_private_allowed_roles'] ?? ['editor', 'author'];
                            foreach ($all_roles as $role_key => $role_data):
                                ?>
                                <label class="folderify-radio-label" style="margin-bottom:6px;">
                                    <input type="checkbox" name="folderify_settings[folderify_private_allowed_roles][]"
                                        value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $p_create), true); ?> />
                                    <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                </label>
                            <?php endforeach; ?>
                            <p style="font-size:12px; color:#8c8f94; margin-top:10px;">* Users can only edit/delete folders they
                                own. Administrators see and manage everyone's folders.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title">
                                <?php esc_html_e('Real folders on disk', 'folderify'); ?>
                            </p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Mirror the folder tree as real directories inside wp-content/uploads.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-row">
                            <div class="folderify-row-label">
                                <strong><?php esc_html_e('Store media in real folders', 'folderify'); ?></strong>
                                <span><?php esc_html_e('New uploads go straight into the folder directory. Assigning a file to a folder moves it (with thumbnails) and fixes links in posts. Renaming or moving a folder renames its directory. Works only with Global Shared Folders.', 'folderify'); ?></span>
                            </div>
                            <label class="folderify-setting-toggle">
                                <input type="checkbox" name="folderify_settings[physical_folders]" value="1" <?php checked(1, !empty($options['physical_folders'])); ?> />
                                <span class="folderify-setting-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <?php if ( true ) : ?>
                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title">
                                <?php esc_html_e('Folder Behaviour', 'folderify'); ?>
                            </p>
                        </div>

                        <div class="folderify-row">
                            <div class="folderify-row-label">
                                <strong><?php esc_html_e('Show breadcrumb &amp; subfolders', 'folderify'); ?></strong>
                                <span><?php esc_html_e('Display the current folder path and its direct subfolders above the media grid.', 'folderify'); ?></span>
                            </div>
                            <label class="folderify-setting-toggle">
                                <input type="checkbox" name="folderify_settings[show_breadcrumb]" value="1" <?php checked(1, !empty($options['show_breadcrumb'])); ?> />
                                <span class="folderify-setting-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Post Types -->
                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title">
                                <?php esc_html_e('Which post types to use with Folderify', 'folderify'); ?>
                            </p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Enable the folder sidebar for these post type editors.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-row" style="flex-direction:column;gap:10px;">
                            <?php
                            $allowed = $options['allowed_post_types'] ?? [];
                            $public_types = get_post_types(['public' => true], 'objects');
                            // Exclude 'attachment' — media library always has the sidebar
                            unset($public_types['attachment']);
                            foreach ($public_types as $pt):
                                ?>
                                <label class="folderify-radio-label">
                                    <input type="checkbox" name="folderify_settings[allowed_post_types][]"
                                        value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $allowed, true)); ?> />
                                    <?php echo esc_html($pt->labels->singular_name ?: $pt->name); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($public_types)): ?>
                                <p style="font-size:12px;color:#8c8f94;margin:0;">
                                    <?php esc_html_e('No public post types found.', 'folderify'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Folder Counter -->
                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title"><?php esc_html_e('Folder counter', 'folderify'); ?></p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Choose how the file count badge on each folder is calculated.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-row" style="flex-direction:column;gap:10px;">
                            <?php $counter_mode = $options['folder_counter'] ?? 'direct'; ?>
                            <label class="folderify-radio-label">
                                <input type="radio" name="folderify_settings[folder_counter]" value="direct" <?php checked('direct', $counter_mode); ?> />
                                <?php esc_html_e('Count files in each folder', 'folderify'); ?>
                            </label>
                            <label class="folderify-radio-label">
                                <input type="radio" name="folderify_settings[folder_counter]" value="recursive" <?php checked('recursive', $counter_mode); ?> />
                                <?php esc_html_e('Count files in both parent folder and subfolders', 'folderify'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="folderify-card">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title"><?php esc_html_e('Data & Privacy', 'folderify'); ?></p>
                        </div>
                        <div class="folderify-row">
                            <div class="folderify-row-label">
                                <strong><?php esc_html_e('Delete data on uninstall', 'folderify'); ?></strong>
                                <span><?php esc_html_e('Remove all folders and plugin settings when the plugin is deleted.', 'folderify'); ?></span>
                            </div>
                            <label class="folderify-setting-toggle">
                                <input type="checkbox" name="folderify_settings[delete_on_uninstall]" value="1" <?php checked(1, !empty($options['delete_on_uninstall'])); ?> />
                                <span class="folderify-setting-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="folderify-footer">
                        <?php submit_button(__('Save Changes', 'folderify'), 'primary', 'submit', false); ?>
                        <span
                            class="folderify-footer-hint"><?php esc_html_e('Folderify — Media Library Folders', 'folderify'); ?></span>
                    </div>
                </form>
            </div>

            <!-- ── Tab: Import / Export ── -->
            <div class="folderify-panel <?php echo $active === 'import' ? 'active' : ''; ?>" id="tab-import">
                <div class="folderify-card">
                    <div class="folderify-card-head">
                        <p class="folderify-card-title"><?php esc_html_e('Export', 'folderify'); ?></p>
                        <p class="folderify-card-desc">
                            <?php esc_html_e('Download your current folder structure as a CSV file for backup or migration.', 'folderify'); ?>
                        </p>
                    </div>
                    <div class="folderify-ie-block">
                        <p class="folderify-ie-title"><?php esc_html_e('Export folders (CSV)', 'folderify'); ?></p>
                        <p class="folderify-ie-desc">
                            <?php esc_html_e('Exports your visible folders. The CSV can be re-imported here or into another site.', 'folderify'); ?>
                        </p>
                        <div class="folderify-ie-actions">
                            <a class="button"
                                href="<?php echo esc_url(admin_url('admin-ajax.php?action=folderify_export_csv&nonce=' . $nonce_val)); ?>"
                                download>
                                ↓ <?php esc_html_e('Download CSV', 'folderify'); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ( true ) : ?>
                <div class="folderify-card">
                    <div class="folderify-card-head">
                        <p class="folderify-card-title"><?php esc_html_e('Import', 'folderify'); ?></p>
                        <p class="folderify-card-desc">
                            <?php esc_html_e('Upload a CSV to import folders. Works with any plugin that exports a folder list.', 'folderify'); ?>
                        </p>
                    </div>
                    <div class="folderify-ie-block">
                        <p class="folderify-ie-title"><?php esc_html_e('Upload CSV file', 'folderify'); ?></p>
                        <p class="folderify-ie-desc">
                            <?php esc_html_e('Compatible with: Folderify, FileBird, Folders (Premio), WP Media Folder, Real Media Library, and any plugin that exports a flat CSV with folder name and parent columns.', 'folderify'); ?>
                        </p>
                        <div class="folderify-ie-actions">
                            <input type="file" id="folderify-import-csv-file" accept=".csv" />
                            <select id="folderify-import-csv-mode">
                                <option value="merge"><?php esc_html_e('Merge with existing', 'folderify'); ?></option>
                                <option value="replace"><?php esc_html_e('Replace all folders', 'folderify'); ?></option>
                            </select>
                            <button class="button button-primary" id="folderify-import-csv-btn">
                                ↑ <?php esc_html_e('Import', 'folderify'); ?>
                            </button>
                        </div>
                        <div id="folderify-import-csv-status" style="display:none;margin-top:10px;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tab: Uploads sync ── -->
            <div class="folderify-panel <?php echo $active === 'sync' ? 'active' : ''; ?>" id="tab-sync">
                <?php if (!Physical_Storage::is_global_mode()): ?>
                    <div class="folderify-card">
                        <div class="folderify-ie-block">
                            <p class="folderify-ie-title"><?php esc_html_e('Not available in private mode', 'folderify'); ?></p>
                            <p class="folderify-ie-desc">
                                <?php esc_html_e('Uploads sync needs "Global Shared Folders" mode, because private per-user folders cannot be mirrored as real directories.', 'folderify'); ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>

                    <div class="folderify-card" id="folderify-sync-scan">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title"><?php esc_html_e('Scan uploads → Folderify', 'folderify'); ?></p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Found files and folders you copied into wp-content/uploads (FTP, file manager)? Scan the directory: new files are added to the Media Library, every directory becomes a folder (nested like on disk) and files are assigned to it.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-ie-block">
                            <label class="folderify-radio-label" style="margin-bottom:10px;">
                                <input type="checkbox" class="folderify-sync-date-dirs" />
                                <?php esc_html_e('Also turn WordPress year/month directories (2025/03) into folders', 'folderify'); ?>
                            </label>
                            <div class="folderify-ie-actions">
                                <button type="button" class="button button-primary folderify-sync-analyze" data-mode="scan">
                                    <?php esc_html_e('Scan uploads', 'folderify'); ?>
                                </button>
                            </div>
                            <?php $last_scan = Uploads_Sync::last_sync('scan'); ?>
                            <div class="folderify-sync-result<?php echo $last_scan ? ' ok' : ''; ?>" <?php echo $last_scan ? '' : 'style="display:none;"'; ?>>
                                <?php if ($last_scan): $d = $last_scan['done']; ?>
                                    <div class="folderify-sync-head"><span class="folderify-sync-icon">✓</span>
                                        <strong><?php esc_html_e('Last sync completed', 'folderify'); ?></strong>
                                        <span class="folderify-sync-time"><?php echo esc_html(Uploads_Sync::format_time($last_scan['time'])); ?></span></div>
                                    <ul class="folderify-sync-list">
                                        <li><strong><?php echo (int) $d['registered']; ?></strong> <?php esc_html_e('files added to the Media Library', 'folderify'); ?></li>
                                        <li><strong><?php echo (int) $d['folders']; ?></strong> <?php esc_html_e('folders created', 'folderify'); ?></li>
                                        <li><strong><?php echo (int) $d['linked']; ?></strong> <?php esc_html_e('files linked to folders', 'folderify'); ?></li>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="folderify-card" id="folderify-sync-apply">
                        <div class="folderify-card-head">
                            <p class="folderify-card-title"><?php esc_html_e('Folderify → disk', 'folderify'); ?></p>
                            <p class="folderify-card-desc">
                                <?php esc_html_e('Already organised your media in Folderify before turning on real folders? This creates the directories and moves every categorised file (with thumbnails) into the directory of its folder.', 'folderify'); ?>
                            </p>
                        </div>
                        <div class="folderify-ie-block">
                            <?php if (!Physical_Storage::enabled()): ?>
                                <p class="folderify-ie-desc" style="color:#b32d2e;">
                                    <?php esc_html_e('Enable "Store media in real folders" in the General tab and save first.', 'folderify'); ?>
                                </p>
                            <?php endif; ?>
                            <label class="folderify-radio-label" style="margin-bottom:10px;">
                                <input type="checkbox" class="folderify-sync-replace-urls" checked />
                                <?php esc_html_e('Update links to moved files inside posts and pages', 'folderify'); ?>
                            </label>
                            <div class="folderify-ie-actions">
                                <button type="button" class="button button-primary folderify-sync-analyze" data-mode="apply" <?php disabled(!Physical_Storage::enabled()); ?>>
                                    <?php esc_html_e('Analyze', 'folderify'); ?>
                                </button>
                            </div>
                            <div class="folderify-sync-result" style="display:none;"></div>
                        </div>
                    </div>

                    <p style="font-size:12px;color:#8c8f94;margin:4px 2px 0;">
                        <?php esc_html_e('Tip: make a full backup (database + uploads) before the first big sync. Moving files changes their URLs; links inside posts are updated, but links on other websites will break.', 'folderify'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- ── Tab: Backups ── -->
            <?php if ( true ) : ?>
            <div class="folderify-panel <?php echo $active === 'backups' ? 'active' : ''; ?>" id="tab-backups">
                <div class="folderify-card">
                    <div class="folderify-card-head">
                        <p class="folderify-card-title">
                            <?php esc_html_e('Automatic Backups', 'folderify'); ?>
                        </p>
                        <p class="folderify-card-desc">
                            <?php esc_html_e('Each user\'s folder structure is backed up automatically every 24 hours. Backups older than 30 days are deleted.', 'folderify'); ?>
                        </p>
                    </div>
                    <div class="folderify-ie-block">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <p style="margin:0;font-size:13px;color:#1d2327;font-weight:500;">
                                <?php esc_html_e('Backup history', 'folderify'); ?>
                            </p>
                            <button class="button"
                                id="folderify-load-backups-btn"><?php esc_html_e('Load backups', 'folderify'); ?></button>
                        </div>
                        <div id="folderify-backup-list">
                            <p class="folderify-backup-empty">
                                <?php esc_html_e('Click "Load backups" to view available backups.', 'folderify'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- .folderify-wrap -->

        
        <?php
    }
}
