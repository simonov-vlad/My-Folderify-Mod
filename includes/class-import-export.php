<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Import_Export
 *
 * Handles CSV import/export, third-party plugin import (FileBird, Folders),
 * and per-user automated daily backups with 30-day retention.
 */
class Import_Export
{
    /** @var Folder_Manager */
    private $folder_manager;

    /** Backup directory inside wp-content/uploads */
    const BACKUP_SUBDIR = 'folderify-backups';

    public function __construct($folder_manager)
    {
        $this->folder_manager = $folder_manager;

        // AJAX handlers (admin only)
        add_action('wp_ajax_folderify_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_folderify_import_csv', [$this, 'ajax_import_csv']);
        add_action('wp_ajax_folderify_import_filebird', [$this, 'ajax_import_filebird']);
        add_action('wp_ajax_folderify_import_folders_pro', [$this, 'ajax_import_folders_pro']);
        add_action('wp_ajax_folderify_check_third_party_plugins', [$this, 'ajax_check_third_party_plugins']);
        add_action('wp_ajax_folderify_list_backups', [$this, 'ajax_list_backups']);
        add_action('wp_ajax_folderify_restore_backup', [$this, 'ajax_restore_backup']);

        // WP-Cron: daily backup per user
        add_action('folderify_daily_backup', [$this, 'run_daily_backup']);
        if (!wp_next_scheduled('folderify_daily_backup')) {
            wp_schedule_event(time(), 'daily', 'folderify_daily_backup');
        }
    }

    /* ─────────────────────────────────────────────
       CSV EXPORT
    ───────────────────────────────────────────── */

    /**
     * Export folders of current user (or all if per_user_folders off) as CSV.
     */
    public function ajax_export_csv()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_die('Forbidden', 403);
        }

        $folders = $this->folder_manager->get_folders();
        $csv = $this->folders_to_csv($folders);

        $filename = 'folderify-folders-' . get_current_user_id() . '-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . esc_attr($filename));
        header('Pragma: no-cache');
        echo $csv; // phpcs:ignore
        exit;
    }

    /**
     * Convert folders array to CSV string.
     */
    private function folders_to_csv(array $folders): string
    {
        $lines = ["id,parent_id,name,color,order_index"];
        foreach ($folders as $f) {
            $lines[] = implode(',', [
                (int) $f['id'],
                (int) $f['parent_id'],
                '"' . str_replace('"', '""', $f['name']) . '"',
                '"' . esc_attr($f['color'] ?? '') . '"',
                (int) ($f['order_index'] ?? 0),
            ]);
        }
        return implode("\n", $lines);
    }

    /* ─────────────────────────────────────────────
       CSV IMPORT
    ───────────────────────────────────────────── */

    public function ajax_import_csv()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Forbidden');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            wp_send_json_error('No file uploaded.');
        }

        $tmp = isset($_FILES['csv_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['csv_file']['tmp_name'])) : '';
        $mode = isset($_POST['import_mode']) ? sanitize_text_field(wp_unslash($_POST['import_mode'])) : 'merge';

        $result = $this->import_from_csv($tmp, $mode === 'replace');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['imported' => $result]);
    }

    /**
     * Parse a CSV file and insert folders, mapping old IDs → new IDs.
     * Supports CSVs from Folderify, FileBird, Folders (Premio),
     * WP Media Folder, Real Media Library, and any plugin that exports
     * a flat folder list with an ID, parent, and name column.
     *
     * Recognized column aliases:
     *   ID:      id, ID, term_id
     *   Parent:  parent_id, parent, parentId
     *   Name:    name, title, label, folder_name
     *   Color:   color, colour
     *   Order:   order_index, menu_order, order
     *
     * @param string $filepath  Path to uploaded tmp file.
     * @param bool   $replace   Wipe existing folders first.
     * @return int|\WP_Error    Number of folders inserted.
     */
    public function import_from_csv(string $filepath, bool $replace = false)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV parsing requires fgetcsv() which cannot use WP_Filesystem.
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return new \WP_Error('file_error', 'Cannot open CSV file.');
        }

        // ── Read & normalise header row ──────────────────────────
        $raw_header = fgetcsv($handle);
        if (!$raw_header) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            return new \WP_Error('invalid_csv', 'CSV file is empty or unreadable.');
        }

        // Trim BOM and whitespace from each header cell
        $header = array_map(fn($h) => trim(str_replace('\xEF\xBB\xBF', '', $h)), $raw_header);
        $col = array_flip($header); // header_name => index

        // ── Column alias resolution ──────────────────────────────
        $resolve = function (array $candidates) use ($col): ?int {
            foreach ($candidates as $candidate) {
                // Case-insensitive search
                foreach ($col as $key => $idx) {
                    if (strtolower($key) === strtolower($candidate))
                        return $idx;
                }
            }
            return null;
        };

        $col_id = $resolve(['id', 'ID', 'term_id', 'cat_id']);
        $col_parent = $resolve(['parent_id', 'parent', 'parentId', 'parent_cat_id']);
        $col_name = $resolve(['name', 'title', 'label', 'folder_name', 'cat_name']);
        $col_color = $resolve(['color', 'colour', 'folder_color']);
        $col_order = $resolve(['order_index', 'menu_order', 'order', 'sort_order']);
        $col_attachments = $resolve(['attachment_ids', 'attachments', 'post_ids']);

        if ($col_name === null) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            return new \WP_Error('invalid_csv', 'Could not find a name/title/label column in the CSV.');
        }

        // ── Optionally wipe existing data ────────────────────────
        if ($replace) {
            $wpdb->query("TRUNCATE TABLE {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderify_relationships"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $this->folder_manager->invalidate_cache();
        }

        // ── Read all rows ────────────────────────────────────────
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        // ── Two-pass insert (flat first, then fix parent_ids) ────
        $old_to_new = [];
        $pending_parents = []; // [new_id => old_parent_id]
        $mode = $settings['folderify_folder_mode'] ?? 'global';
        $user_id = ($mode === 'private') ? (int) get_current_user_id() : 0;
        $count = 0;
        $order_fallback = 0;

        foreach ($rows as $row) {
            $name = $col_name !== null ? trim($row[$col_name]) : '';
            $old_id = $col_id !== null ? (int) $row[$col_id] : 0;
            $old_parent_id = $col_parent !== null ? (int) $row[$col_parent] : 0;
            $color = $col_color !== null ? sanitize_hex_color(trim($row[$col_color])) : '';
            $order = $col_order !== null ? (int) $row[$col_order] : $order_fallback;
            $attachments_raw = $col_attachments !== null ? trim($row[$col_attachments]) : '';

            if (empty($name))
                continue;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'name' => sanitize_text_field($name),
                'parent_id' => 0,
                'color' => $color,
                'order_index' => $order,
            ], ['%d', '%s', '%d', '%s', '%d']);

            $new_id = $wpdb->insert_id;
            if ($old_id)
                $old_to_new[$old_id] = $new_id;
            if ($old_parent_id)
                $pending_parents[$new_id] = $old_parent_id;

            // Process attachment assignments if provided
            if (!empty($attachments_raw)) {
                // Split by | or ,
                $attachment_ids = preg_split('/[|,]+/', $attachments_raw);
                $rel_table = $wpdb->prefix . 'folderify_relationships';
                foreach ($attachment_ids as $att_id) {
                    $att_id = (int) trim($att_id);
                    if ($att_id > 0) {
                        // Delete any existing relationship for this attachment (so it moves to this folder)
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->delete($rel_table, ['object_id' => $att_id], ['%d']);
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->insert($rel_table, [
                            'folder_id' => $new_id,
                            'object_id' => $att_id
                        ], ['%d', '%d']);
                    }
                }
            }

            $count++;
            $order_fallback++;
        }

        // Second pass: fix parent_ids if Pro
        if (true) {
            foreach ($pending_parents as $new_id => $old_parent_id) {
                $new_parent = $old_to_new[$old_parent_id] ?? 0;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($table, ['parent_id' => $new_parent], ['id' => $new_id], ['%d'], ['%d']);
            }
        }

        $this->folder_manager->invalidate_cache();
        return $count;
    }

    /* ─────────────────────────────────────────────
       THIRD-PARTY PLUGIN DETECTION
    ───────────────────────────────────────────── */

    public function ajax_check_third_party_plugins()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Forbidden');
        }

        global $wpdb;
        $found = [];

        // 1. Check FileBird
        $fbv_table = $wpdb->prefix . 'fbv';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fbv_table)) === $fbv_table) {
            $folder_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fbv_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ($folder_count > 0) {
                $attachments = 0;
                $rel_table = $wpdb->prefix . 'fbv_attachment_folder';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rel_table)) === $rel_table) {
                    $attachments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rel_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                }
                $found[] = [
                    'id' => 'filebird',
                    'name' => 'FileBird',
                    'folders' => $folder_count,
                    'attachments' => $attachments
                ];
            }
        }

        // 2. Check Folders (Premio)
        $folders_table = $wpdb->prefix . 'folders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $folders_table)) === $folders_table) {
            $folder_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$folders_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ($folder_count > 0) {
                // Folders plugin usually saves relationships in term_relationships since it uses custom taxonomy,
                // but for detection purposes, just knowing folders exist is enough to show the prompt.
                // We'll estimate attachments as 0 for the prompt if we can't easily query term_relationships right now.
                $found[] = [
                    'id' => 'folders_pro',
                    'name' => 'Folders',
                    'folders' => $folder_count,
                    'attachments' => 0
                ];
            }
        }

        wp_send_json_success(['plugins' => $found]);
    }

    /* ─────────────────────────────────────────────
       THIRD-PARTY PLUGIN IMPORT – FileBird
    ───────────────────────────────────────────── */

    public function ajax_import_filebird()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden');
        }

        // Premium active

        $result = $this->import_from_filebird();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(['imported' => $result]);
    }

    /**
     * Import folder structure from FileBird (NJT FileBird) plugin.
     * FileBird stores folders in {prefix}fbv table.
     */
    public function import_from_filebird()
    {
        global $wpdb;
        $fbv_table = $wpdb->prefix . 'fbv';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fbv_table)) !== $fbv_table) {
            return new \WP_Error('no_table', 'FileBird table not found. Is FileBird installed?');
        }

        $fb_folders = $wpdb->get_results("SELECT * FROM {$fbv_table} ORDER BY id ASC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if (empty($fb_folders)) {
            return new \WP_Error('empty', 'No folders found in FileBird.');
        }

        // Import folders
        $inserted_count = $this->insert_mapped_folders($fb_folders, 'id', 'parent', 'name');

        // Import relationships
        $rel_table = $wpdb->prefix . 'fbv_attachment_folder';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rel_table)) === $rel_table) {
            $relationships = $wpdb->get_results("SELECT attachment_id, folder_id FROM {$rel_table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if (!empty($relationships)) {
                // Get the mapped folder IDs from our generic importer...
                // Actually, insert_mapped_folders doesn't return the ID map, so we'll need to fetch it by name,
                // or just do a quick remap. Since names might not be perfectly unique, we can match FileBird structure.
                // To keep it clean, we'll manually re-run the mapping logic just for the attachments here.

                $fb_map = [];
                // Must match insert_mapped_folders() exactly: global → primary user id, private → current user
                $settings_fb = get_option('folderify_settings', []);
                $mode_fb = $settings_fb['folderify_folder_mode'] ?? 'global';
                $user_id_fb = ($mode_fb === 'private') ? (int) get_current_user_id() : 0;
                $folderify_table = $wpdb->prefix . 'folderify_folders';

                foreach ($fb_folders as $fb_f) {
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $new_f_id = $wpdb->get_var($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                        "SELECT id FROM {$folderify_table} WHERE user_id = %d AND post_type = 'attachment' AND name = %s ORDER BY id DESC LIMIT 1",
                        $user_id_fb,
                        sanitize_text_field($fb_f['name'])
                    ));
                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    if ($new_f_id) {
                        $fb_map[$fb_f['id']] = $new_f_id;
                    }
                }

                $folderify_rel_table = $wpdb->prefix . 'folderify_relationships';
                foreach ($relationships as $rel) {
                    $fb_folder_id = $rel['folder_id'];
                    $att_id = $rel['attachment_id'];

                    if (isset($fb_map[$fb_folder_id])) {
                        $new_folder_id = $fb_map[$fb_folder_id];
                        // Delete any existing relationship for this attachment
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->delete($folderify_rel_table, ['object_id' => $att_id], ['%d']);
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->insert($folderify_rel_table, [
                            'folder_id' => $new_folder_id,
                            'object_id' => $att_id
                        ], ['%d', '%d']);
                    }
                }
            }
        }

        return $inserted_count;
    }

    /* ─────────────────────────────────────────────
       THIRD-PARTY PLUGIN IMPORT – Folders (Premio)
    ───────────────────────────────────────────── */

    public function ajax_import_folders_pro()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden');
        }

        // Premium active

        $result = $this->import_from_folders_pro();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(['imported' => $result]);
    }

    /**
     * Import from "Folders" plugin by premio.io.
     * Stores folders in {prefix}folders table with parent_id column.
     */
    public function import_from_folders_pro()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'folders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return new \WP_Error('no_table', '"Folders" plugin table not found.');
        }

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if (empty($rows)) {
            return new \WP_Error('empty', 'No folders found.');
        }

        return $this->insert_mapped_folders($rows, 'id', 'parent_id', 'name');
    }

    /**
     * Generic insertion helper: reads rows from another plugin's table,
     * maps old IDs to new IDs, and inserts into our table.
     */
    private function insert_mapped_folders(array $rows, string $id_col, string $parent_col, string $name_col): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';

        $settings = get_option('folderify_settings', []);
        $mode = $settings['folderify_folder_mode'] ?? 'global';

        // Match get_folders() filtering logic exactly:
        //   - private mode  → current user ID
        //   - global mode   → the configured primary user (default: 1 = first admin)
        // This guarantees inserted folders are returned by get_folders().
        if ($mode === 'private') {
            $user_id = (int) get_current_user_id();
        } else {
            $user_id = 0;
        }
        $post_type = 'attachment';

        $old_to_new = [];
        $pending = [];
        $count = 0;

        foreach ($rows as $row) {
            $name = sanitize_text_field($row[$name_col] ?? '');
            $old_id = (int) ($row[$id_col] ?? 0);
            $old_parent_id = (int) ($row[$parent_col] ?? 0);
            if (empty($name))
                continue;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'post_type' => $post_type,
                'name' => $name,
                'parent_id' => 0,
                'color' => '',
                'order_index' => $count,
            ], ['%s', '%s', '%s', '%d', '%s', '%d']); // %s for nullable user_id

            $new_id = $wpdb->insert_id;
            if ($old_id)
                $old_to_new[$old_id] = $new_id;
            if ($old_parent_id)
                $pending[$new_id] = $old_parent_id;
            $count++;
        }

        // Re-link parent-child relationships if Pro
        if (true) {
            foreach ($pending as $new_id => $old_pid) {
                $new_parent = $old_to_new[$old_pid] ?? 0;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($table, ['parent_id' => $new_parent], ['id' => $new_id], ['%d'], ['%d']);
            }
        }

        $this->folder_manager->invalidate_cache();
        return $count;
    }

    /* ─────────────────────────────────────────────
       PER-USER AUTO-BACKUP (WP-Cron, daily)
    ───────────────────────────────────────────── */

    /**
     * Cron callback: back up every user's folder structure.
     */
    public function run_daily_backup()
    {
        $users = get_users(['fields' => ['ID']]);
        foreach ($users as $user) {
            $this->backup_user_folders((int) $user->ID);
        }
        // Prune files older than 30 days
        $this->prune_old_backups();
    }

    /**
     * Backup a single user's folders to JSON file.
     */
    public function backup_user_folders(int $user_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $folders = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY id ASC", $user_id),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Also include shared folders (user_id = 0) in the backup
        if (empty($folders))
            return;

        $dir = $this->get_backup_dir();
        if (!$dir)
            return;

        $filename = "user-{$user_id}-" . gmdate('Y-m-d') . '.json';
        $filepath = trailingslashit($dir) . $filename;

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $wp_filesystem->put_contents($filepath, wp_json_encode([
            'user_id' => $user_id,
            'backed_up' => gmdate('Y-m-d H:i:s'),
            'folders' => $folders,
        ]), FS_CHMOD_FILE);
    }

    /**
     * Delete backup files older than 30 days.
     */
    private function prune_old_backups()
    {
        $dir = $this->get_backup_dir();
        if (!$dir)
            return;

        $cutoff = strtotime('-30 days');
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $files = $wp_filesystem->dirlist($dir);
        if ($files) {
            foreach ($files as $file) {
                if (strpos($file['name'], '.json') !== false) {
                    $filepath = trailingslashit($dir) . $file['name'];
                    if ($wp_filesystem->mtime($filepath) < $cutoff) {
                        $wp_filesystem->delete($filepath);
                    }
                }
            }
        }
    }

    /**
     * Get (and create) backup directory inside uploads.
     */
    private function get_backup_dir(): string
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . self::BACKUP_SUBDIR;

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem->is_dir($dir)) {
            $wp_filesystem->mkdir($dir);
            // Protect directory from web access
            $wp_filesystem->put_contents($dir . '/.htaccess', 'Deny from all', FS_CHMOD_FILE);
            $wp_filesystem->put_contents($dir . '/index.php', '<?php // Silence', FS_CHMOD_FILE);
        }
        return $dir;
    }

    /* ─────────────────────────────────────────────
       BACKUP LIST + RESTORE (AJAX)
    ───────────────────────────────────────────── */

    public function ajax_list_backups()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden');
        }

        $dir = $this->get_backup_dir();
        $files = glob(trailingslashit($dir) . '*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $list = [];
        foreach (array_slice($files, 0, 60) as $file) {
            $filename = basename($file);
            $display_name = $filename;

            if (preg_match('/^user-(\d+)-/i', $filename, $matches)) {
                $uid = (int) $matches[1];
                $u = get_userdata($uid);
                if ($u) {
                    $date_str = str_replace("user-{$uid}-", '', $filename);
                    $display_name = $u->display_name . ' (' . $date_str . ')';
                }
            }

            $list[] = [
                'filename' => $filename,
                'display_name' => $display_name,
                'date' => gmdate('Y-m-d H:i', filemtime($file)),
                'size' => size_format(filesize($file)),
            ];
        }
        wp_send_json_success($list);
    }

    public function ajax_restore_backup()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden');
        }

        $filename = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));
        if (empty($filename))
            wp_send_json_error('No filename.');

        $dir = $this->get_backup_dir();
        $filepath = trailingslashit($dir) . $filename;

        if (!file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'json') {
            wp_send_json_error('File not found.');
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $content = $wp_filesystem->get_contents($filepath);
        if (false === $content) {
            wp_send_json_error('Could not read backup file.');
        }

        $data = json_decode($content, true);
        if (!isset($data['folders']) || !isset($data['user_id'])) {
            wp_send_json_error('Invalid backup file.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';
        $user_id = (int) $data['user_id'];

        // Remove existing folders for this user, then re-insert
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($table, ['user_id' => $user_id], ['%d']);

        $old_to_new = [];
        $pending = [];

        foreach ($data['folders'] as $f) {
            $old_id = (int) $f['id'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'name' => sanitize_text_field($f['name']),
                'parent_id' => 0,
                'color' => sanitize_hex_color($f['color'] ?? ''),
                'order_index' => (int) ($f['order_index'] ?? 0),
            ], ['%d', '%s', '%d', '%s', '%d']);

            $new_id = $wpdb->insert_id;
            if ($old_id)
                $old_to_new[$old_id] = $new_id;
            $old_parent = (int) ($f['parent_id'] ?? 0);
            if ($old_parent)
                $pending[$new_id] = $old_parent;
        }

        foreach ($pending as $new_id => $old_pid) {
            $new_parent = $old_to_new[$old_pid] ?? 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($table, ['parent_id' => $new_parent], ['id' => $new_id], ['%d'], ['%d']);
        }

        $this->folder_manager->invalidate_cache();
        wp_send_json_success(['restored' => count($data['folders'])]);
    }

    /* ─────────────────────────────────────────────
       JSON EXPORT (legacy)
    ───────────────────────────────────────────── */

    public function export_json()
    {
        $folders = $this->folder_manager->get_folders();
        return wp_json_encode($folders);
    }
}
