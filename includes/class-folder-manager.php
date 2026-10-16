<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Folder_Manager
 *
 * Core CRUD operations for folders.
 */
class Folder_Manager
{

    public function __construct()
    {
        add_action('add_attachment', [$this, 'handle_new_attachment']);
        add_action('deleted_user', [$this, 'handle_deleted_user'], 10, 2);
    }

    /**
     * Physical storage service (mirrors folders as directories in uploads).
     *
     * @return Physical_Storage
     */
    private function storage()
    {
        return Plugin::get_instance()->physical_storage;
    }

    /**
     * Is this folder an attachment (media library) folder?
     */
    private function is_media_folder($folder_id)
    {
        $folder = $this->get_folder($folder_id);
        return $folder && ($folder['post_type'] ?? 'attachment') === 'attachment';
    }

    /**
     * Get table names.
     */
    private static function get_tables()
    {
        global $wpdb;
        return [
            'folders' => $wpdb->prefix . 'folderify_folders',
            'relationships' => $wpdb->prefix . 'folderify_relationships',
        ];
    }

    public function get_folders($args = [])
    {
        global $wpdb;
        $tables = self::get_tables();

        $order_by = 'order_index ASC, id ASC';
        if (isset($args['orderby']) && wp_unslash($args['orderby']) === 'name') {
            $order_by = 'name ASC';
        }

        $post_type = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'attachment';

        $where_clauses = [];
        $settings = get_option('folderify_settings', []);

        $mode = $settings['folderify_folder_mode'] ?? 'global';
        // Premium active


        if ($mode === 'private') {
            if (!current_user_can('folderify_manage_all_folders')) {
                $owner_id = (int) get_current_user_id();
                $where_clauses[] = $wpdb->prepare('(user_id = %d OR user_id IS NULL)', $owner_id);
            }
        } else {
            // Global folders have user_id = 0 or NULL (legacy)
            $where_clauses[] = "(user_id = 0 OR user_id IS NULL)";
        }

        $where_clauses[] = $wpdb->prepare('post_type = %s', $post_type);

        $where = $where_clauses ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

        $sql = "SELECT * FROM {$tables['folders']}{$where} ORDER BY $order_by";

        // Generate versioned cache key (include user_id + post_type so cache is scoped correctly)
        $cache_version = get_option('folderify_db_version_hash', '1');
        $current_uid = get_current_user_id();
        $cache_key = 'folderify_all_folders_' . md5(serialize($args) . $cache_version . $current_uid . $post_type);
        $folders = wp_cache_get($cache_key, 'folderify');

        if (false === $folders) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $folders = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            if ($folders) {
                foreach ($folders as &$folder) {
                    $folder['id'] = (int) $folder['id'];
                    $folder['parent_id'] = (int) $folder['parent_id'];
                    $folder['order_index'] = (int) $folder['order_index'];
                }
            } else {
                $folders = [];
            }
            wp_cache_set($cache_key, $folders, 'folderify', 0);
        }

        return $folders;
    }

    /**
     * Get folder by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function get_folder($id)
    {
        global $wpdb;
        $tables = self::get_tables();

        $sql = $wpdb->prepare("SELECT * FROM {$tables['folders']} WHERE id = %d", $id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $folder = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

        return $folder ? $folder : null;
    }

    /**
     * Create a folder.
     *
     * @param array $data
     * @return int|\WP_Error
     */
    public function create_folder($data)
    {
        if (!current_user_can('folderify_create_folder')) {
            return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to create folders.', 'folderify'));
        }

        global $wpdb;
        $tables = self::get_tables();

        $name = isset($data['name']) ? folderify_sanitize_folder_name($data['name']) : __('New Folder', 'folderify');
        $parent_id = isset($data['parent_id']) ? absint($data['parent_id']) : 0;

        // Force root level for Free version
        // Premium active


        $color = isset($data['color']) ? sanitize_hex_color($data['color']) : '';
        $post_type = isset($data['post_type']) ? sanitize_key($data['post_type']) : 'attachment';

        if (empty($name)) {
            return new \WP_Error('empty_name', __('Folder name cannot be empty.', 'folderify'));
        }

        // Get max order.
        $max_order = $wpdb->get_var($wpdb->prepare("SELECT MAX(order_index) FROM {$tables['folders']} WHERE parent_id = %d", $parent_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order = intval($max_order) + 1;

        // Folder ownership mode
        $settings = get_option('folderify_settings', []);
        $mode = $settings['folderify_folder_mode'] ?? 'global';
        $user_id = ($mode === 'private') ? (int) get_current_user_id() : 0;

        // Directory name for this folder on disk (unique among siblings).
        $slug = ($post_type === 'attachment') ? $this->storage()->make_unique_slug($name, $parent_id) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $tables['folders'],
            [
                'user_id' => $user_id,
                'post_type' => $post_type,
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parent_id,
                'color' => $color,
                'order_index' => $order,
            ]
        );

        if ($inserted) {
            $new_id = $wpdb->insert_id;
            Physical_Storage::reset_cache();
            if ($post_type === 'attachment') {
                $this->storage()->ensure_directory($new_id);
            }
            $this->invalidate_cache();
            return $new_id;
        }

        return new \WP_Error('insert_failed', __('Could not insert folder.', 'folderify'));
    }

    /**
     * Update a folder.
     *
     * @param int   $id
     * @param array $data
     * @return bool|\WP_Error
     */
    public function update_folder($id, $data)
    {
        if (!Permissions::user_can_edit_folder($id)) {
            return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to edit this folder.', 'folderify'));
        }

        global $wpdb;
        $tables = self::get_tables();
        $id = absint($id);

        $update_data = [];
        $format = [];

        if (isset($data['name'])) {
            $name = folderify_sanitize_folder_name($data['name']);
            if (empty($name)) {
                return new \WP_Error('empty_name', __('Folder name cannot be empty.', 'folderify'));
            }
            $update_data['name'] = $name;
            $format[] = '%s';
        }

        if (isset($data['parent_id'])) {
            $parent_id = absint($data['parent_id']);

            // Force root level for Free version
            // Premium active


            // Prevent assigning parent to itself
            if ($parent_id === $id) {
                return new \WP_Error('invalid_parent', __('Folder cannot be its own parent.', 'folderify'));
            }
            $update_data['parent_id'] = $parent_id;
            $format[] = '%d';
        }

        if (isset($data['color'])) {
            $update_data['color'] = sanitize_hex_color($data['color']);
            $format[] = '%s';
        }

        if (isset($data['order_index'])) {
            $update_data['order_index'] = intval($data['order_index']);
            $format[] = '%d';
        }

        if (empty($update_data)) {
            return true; // Nothing to update
        }

        // Remember where the directory is now, so it can follow the rename / move.
        $path_changes = isset($update_data['name']) || isset($update_data['parent_id']);
        $old_path = ($path_changes && $this->is_media_folder($id)) ? $this->storage()->snapshot_path($id) : null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $tables['folders'],
            $update_data,
            ['id' => $id],
            $format,
            ['%d']
        );

        if (false !== $updated) {
            if ($old_path !== null) {
                $this->storage()->sync_folder_path($id, $old_path, isset($update_data['name']));
            }
            $this->invalidate_cache();
            return true;
        }

        return new \WP_Error('update_failed', __('Could not update folder.', 'folderify'));
    }

    /**
     * Reorder folder and its siblings gracefully within a new/same parent.
     *
     * @param int $folder_id
     * @param int $target_parent_id
     * @param int $target_index
     * @return bool|\WP_Error
     */
    public function reorder_folder($folder_id, $target_parent_id, $target_index)
    {
        if (!Permissions::user_can_edit_folder($folder_id)) {
            return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to move this folder.', 'folderify'));
        }

        global $wpdb;
        $tables = self::get_tables();

        $folder_id = absint($folder_id);
        $target_parent_id = absint($target_parent_id);
        $target_index = absint($target_index);

        // Force root level for Free version
        // Premium active


        if ($folder_id === $target_parent_id) {
            return new \WP_Error('invalid_parent', __('Folder cannot be its own parent.', 'folderify'));
        }

        // Prevent moving a parent into its own child (circular reference check)
        $current_parent_check = $target_parent_id;
        while ($current_parent_check > 0) {
            if ($current_parent_check === $folder_id) {
                return new \WP_Error('circular_reference', __('Cannot move a folder into its own subfolder.', 'folderify'));
            }
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $parent_row = $wpdb->get_row($wpdb->prepare("SELECT parent_id FROM {$tables['folders']} WHERE id = %d", $current_parent_check), ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if (!$parent_row)
                break;
            $current_parent_check = (int) $parent_row['parent_id'];
        }

        // We need the post_type and user_id to ensure we only order siblings in the same scope
        $original_folder = $this->get_folder($folder_id);
        if (!$original_folder) {
            return new \WP_Error('not_found', __('Folder not found.', 'folderify'));
        }

        // Get all children of target parent EXCEPT the one being moved, scoped by user_id and post_type
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $children = $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id FROM {$tables['folders']} WHERE parent_id = %d AND id != %d AND user_id = %d AND post_type = %s ORDER BY order_index ASC, id ASC",
            $target_parent_id,
            $folder_id,
            $original_folder['user_id'],
            $original_folder['post_type']
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $children_ids = array_column($children, 'id');

        // Remember the current directory so it can follow the move.
        $old_path = ($original_folder['post_type'] === 'attachment') ? $this->storage()->snapshot_path($folder_id) : null;

        // Splice the folder into the requested position
        array_splice($children_ids, $target_index, 0, [$folder_id]);

        // Loop array and bulk update the indices and parent
        foreach ($children_ids as $index => $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $tables['folders'],
                ['parent_id' => $target_parent_id, 'order_index' => $index],
                ['id' => $id],
                ['%d', '%d'],
                ['%d']
            );
        }

        if ($old_path !== null) {
            $this->storage()->sync_folder_path($folder_id, $old_path, false);
        }

        $this->invalidate_cache();
        return true;
    }

    /**
     * Duplicate a folder recursively.
     *
     * @param int $id
     * @return int|\WP_Error
     */
    public function duplicate_folder($id)
    {
        if (!current_user_can('folderify_create_folder')) {
            return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to create folders.', 'folderify'));
        }
        return $this->_duplicate_folder_recursive($id, null, true);
    }

    private function _duplicate_folder_recursive($id, $new_parent_id = null, $is_root = false)
    {
        global $wpdb;
        $tables = self::get_tables();

        $original_folder = $this->get_folder($id);
        if (!$original_folder) {
            return new \WP_Error('not_found', __('Folder not found.', 'folderify'));
        }

        $parent_id = $new_parent_id !== null ? $new_parent_id : $original_folder['parent_id'];

        $name = $original_folder['name'];
        if ($is_root) {
            $name .= ' (Copy)';
            // Shift subsequent folders down to make room right after the original folder
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                "UPDATE {$tables['folders']} SET order_index = order_index + 1 WHERE parent_id = %d AND order_index > %d",
                $parent_id,
                $original_folder['order_index']
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $order_index = intval($original_folder['order_index']) + 1;
        } else {
            $order_index = $original_folder['order_index'];
        }

        $slug = ($original_folder['post_type'] === 'attachment') ? $this->storage()->make_unique_slug($name, (int) $parent_id) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $tables['folders'],
            [
                'user_id' => $original_folder['user_id'],
                'post_type' => $original_folder['post_type'],
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parent_id,
                'color' => $original_folder['color'],
                'order_index' => $order_index,
            ]
        );

        if (!$inserted) {
            return new \WP_Error('insert_failed', __('Could not duplicate folder.', 'folderify'));
        }

        $new_folder_id = $wpdb->insert_id;
        Physical_Storage::reset_cache();
        if ($original_folder['post_type'] === 'attachment') {
            // The copy gets an (empty) directory of its own; files are not duplicated.
            $this->storage()->ensure_directory($new_folder_id);
        }

        // Fetch children
        $children_sql = $wpdb->prepare("SELECT id FROM {$tables['folders']} WHERE parent_id = %d ORDER BY order_index ASC", $id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $children = $wpdb->get_col($children_sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!empty($children)) {
            foreach ($children as $child_id) {
                $this->_duplicate_folder_recursive($child_id, $new_folder_id, false);
            }
        }

        if ($is_root) {
            $this->invalidate_cache();
        }

        return $new_folder_id;
    }

    /**
     * Delete a folder.
     *
     * @param int  $id
     * @param bool $recursive Whether to delete children or move them to top.
     * @return bool|\WP_Error
     */
    public function delete_folder($id, $recursive = true)
    {
        if (!Permissions::user_can_delete_folder($id)) {
            return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to delete this folder.', 'folderify'));
        }

        global $wpdb;
        $tables = self::get_tables();
        $id = absint($id);

        $is_media = $this->is_media_folder($id);
        $old_path = $is_media ? $this->storage()->snapshot_path($id) : null;

        // We need to re-assign children if not recursive, or delete them if recursive.
        $children_sql = $wpdb->prepare("SELECT id FROM {$tables['folders']} WHERE parent_id = %d", $id); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $children = $wpdb->get_col($children_sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!empty($children)) {
            if ($recursive) {
                foreach ($children as $child_id) {
                    $this->delete_folder($child_id, true);
                }
            } else {
                // Move children to root (their directories follow).
                $child_paths = [];
                if ($is_media) {
                    foreach ($children as $child_id) {
                        $child_paths[(int) $child_id] = $this->storage()->snapshot_path((int) $child_id);
                    }
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $tables['folders'],
                    ['parent_id' => 0],
                    ['parent_id' => $id],
                    ['%d'],
                    ['%d']
                );
                foreach ($child_paths as $child_id => $child_old_path) {
                    $this->storage()->sync_folder_path($child_id, $child_old_path, false);
                }
            }
        }

        // Send this folder's files back to where they came from (nothing is ever deleted).
        if ($is_media) {
            $this->storage()->release_folder_files($id);
        }

        // Delete associations
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($tables['relationships'], ['folder_id' => $id], ['%d']);

        // Delete folder
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete($tables['folders'], ['id' => $id], ['%d']);

        if ($deleted) {
            Physical_Storage::reset_cache();
            if ($old_path !== null) {
                // Removes the directory only when it is empty.
                $this->storage()->remove_directory_if_empty($old_path);
            }
            $this->invalidate_cache();
            return true;
        }

        return new \WP_Error('delete_failed', __('Could not delete folder.', 'folderify'));
    }

    /**
     * Set attachments folders.
     *
     * @param int|array $attachment_ids
     * @param int|array $folder_ids
     * @param bool      $append
     * @return bool
     */
    public function set_attachments_folders($attachment_ids, $folder_ids, $append = false)
    {
        global $wpdb;
        $tables = self::get_tables();

        $attachment_ids = (array) $attachment_ids;
        $attachment_ids = array_map('absint', $attachment_ids);

        $folder_ids = (array) $folder_ids;
        $folder_ids = array_map('absint', $folder_ids);

        if (empty($attachment_ids)) {
            return false;
        }

        $this->invalidate_cache();

        foreach ($attachment_ids as $attachment_id) {
            if (!$append) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($tables['relationships'], ['object_id' => $attachment_id], ['%d']);
            }
            foreach ($folder_ids as $folder_id) {
                // We don't insert 0 folder id, 0 is 'Uncategorized' typically logic-wise.
                if ($folder_id > 0) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->replace(
                        $tables['relationships'],
                        [
                            'object_id' => $attachment_id,
                            'folder_id' => $folder_id,
                        ],
                        ['%d', '%d']
                    );
                }
            }
        }

        // Physically move the files so the disk follows the folder tree.
        $storage = $this->storage();
        foreach ($attachment_ids as $attachment_id) {
            $storage->apply_assignment($attachment_id, $folder_ids, $append);
        }

        return true;
    }

    /**
     * Get attachments count per folder.
     *
     * When 'folder_counter' setting is 'recursive', each folder's count
     * includes all files in its direct and descendant sub-folders.
     *
     * @return array [ folder_id => count, 'total' => total_count ]
     */
    public function get_folder_counts($post_type = 'attachment')
    {
        global $wpdb;
        $tables = self::get_tables();
        $settings = get_option('folderify_settings', []);
        $mode = ($settings['folder_counter'] ?? 'direct') === 'recursive' ? 'recursive' : 'direct';
        $post_type = sanitize_key($post_type ?: 'attachment');

        $is_attachment = ($post_type === 'attachment');
        $current_user_id = get_current_user_id();
        $hash = get_option('folderify_db_version_hash', '1');

        $settings = get_option('folderify_settings', []);
        $folder_mode = $settings['folderify_folder_mode'] ?? 'global';

        $cache_key = 'folderify_folder_counts_v3_' . $mode . '_' . $folder_mode . '_' . $post_type . '_' . $current_user_id . '_' . $hash;
        $counts = wp_cache_get($cache_key, 'folderify');

        if (false === $counts) {
            $folder_user_join = " INNER JOIN {$tables['folders']} f ON r.folder_id = f.id";
            $folder_user_sql = '';

            $settings = get_option('folderify_settings', []);
            $folder_mode = $settings['folderify_folder_mode'] ?? 'global';

            if ($folder_mode === 'private') {
                if (!current_user_can('folderify_manage_all_folders')) {
                    $folder_user_sql = $wpdb->prepare(' AND (f.user_id = %d OR f.user_id IS NULL)', $current_user_id);
                }
            } else {
                // Global folders have user_id = 0 or NULL (legacy)
                $folder_user_sql = ' AND (f.user_id = 0 OR f.user_id IS NULL)';
            }

            $status_sql = $is_attachment ? "p.post_status != 'trash'" : "p.post_status NOT IN ('trash', 'auto-draft', 'inherit')";
            // $folder_user_join, $folder_user_sql and $status_sql are built internally from wpdb->prepare() or safe literals.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT r.folder_id, COUNT(r.object_id) as count
                 FROM {$tables['relationships']} r
                 INNER JOIN {$wpdb->posts} p ON r.object_id = p.ID" .
                $folder_user_join . "
                 WHERE p.post_type = %s AND {$status_sql}
                   AND r.folder_id > 0" . $folder_user_sql . "
                 GROUP BY r.folder_id",
                $post_type
            ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $direct = [];
            foreach ((array) $results as $row) {
                $direct[(int) $row['folder_id']] = (int) $row['count'];
            }

            // Total: We count what's in the DB globally because WP grid shows all media.
            $status_sql = $is_attachment ? "post_status != 'trash'" : "post_status NOT IN ('trash', 'auto-draft', 'inherit')";
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND {$status_sql}",
                $post_type
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if ($mode === 'recursive') {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $all_folders = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->prepare(
                        "SELECT id, parent_id FROM {$tables['folders']} WHERE post_type = %s",
                        $post_type
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                $children = [];
                foreach ((array) $all_folders as $f) {
                    $children[(int) $f['parent_id']][] = (int) $f['id'];
                }

                $recursive_helper = function (int $folder_id) use (&$recursive_helper, $children, $direct): int {
                    $t = $direct[$folder_id] ?? 0;
                    foreach ($children[$folder_id] ?? [] as $child_id) {
                        $t += $recursive_helper($child_id);
                    }
                    return $t;
                };

                $counts = [
                    'total' => $total,
                    'categorized' => array_sum($direct)
                ];
                foreach ((array) $all_folders as $f) {
                    $fid = (int) $f['id'];
                    $counts[$fid] = $recursive_helper($fid);
                }
            } else {
                $counts = [
                    'total' => $total,
                    'categorized' => array_sum($direct)
                ];
                foreach ($direct as $fid => $cnt) {
                    $counts[$fid] = $cnt;
                }
            }

            wp_cache_set($cache_key, $counts, 'folderify', 120);
        }

        return $counts;
    }

    public function invalidate_cache()
    {
        // Bump version option to effectively discard all older versioned cache keys.
        update_option('folderify_db_version_hash', time());
        wp_cache_delete('folderify_folder_counts', 'folderify');
    }

    /**
     * Auto-assign uploaded media to a folder if 'folderify_folder' is passed in the request.
     * 
     * @param int $post_id The newly uploaded attachment ID.
     */
    public function handle_new_attachment($post_id)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_REQUEST['folderify_folder'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $folder_id = absint(wp_unslash($_REQUEST['folderify_folder']));

            if ($folder_id > 0) {
                // Assign the new attachment to this folder
                $this->set_attachments_folders([$post_id], [$folder_id]);

                // Clear the counts cache so the UI updates immediately
                $this->invalidate_cache();
            }
        }
    }

    public function handle_deleted_user($id, $reassign = null)
    {
        global $wpdb;
        $tables = self::get_tables();

        // Reassign to target user, or fallback to an administrator
        $new_owner = $reassign ? (int) $reassign : $this->get_default_admin_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $tables['folders'],
            ['user_id' => $new_owner],
            ['user_id' => $id],
            ['%d'],
            ['%d']
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $tables['folders'],
            ['user_id' => $new_owner],
            ['user_id' => $id],
            ['%d'],
            ['%d']
        );

        $this->invalidate_cache();
    }

    private function get_default_admin_id()
    {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
        return !empty($admins) ? $admins[0]->ID : 1;
    }
}
