<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Physical_Storage
 *
 * Mirrors the Folderify folder tree on disk inside wp-content/uploads.
 *
 * - Every folder has a "slug": the real directory name (column `slug`).
 * - New uploads into a folder land directly in that directory (upload_dir filter).
 * - Assigning existing media to a folder moves the file, its thumbnails and the
 *   original image into the directory and updates the database and post content links.
 * - Renaming / moving / deleting a folder renames / moves / cleans the directory.
 *
 * Works only in "global" folder mode (private per-user folders would collide on disk).
 */
class Physical_Storage
{
    const META_ORIGINAL_DIR = '_folderify_original_dir';

    /** Guard against recursion while we are moving files ourselves. */
    private static $busy = false;

    /** @var array<int,string> per-request cache: folder_id => relative path */
    private static $path_cache = [];

    public function __construct()
    {
        add_filter('upload_dir', [$this, 'filter_upload_dir'], 20);
        add_filter('wp_generate_attachment_metadata', [$this, 'relocate_after_metadata'], 99, 3);
        add_action('add_attachment', [$this, 'remember_default_dir'], 5);
    }

    /**
     * For uploads that go straight into a folder: remember the directory WordPress
     * would normally have used, so files can go "home" when the folder is deleted.
     */
    public function remember_default_dir($attachment_id)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!self::enabled() || empty($_REQUEST['folderify_folder'])) {
            return;
        }
        if (get_post_meta($attachment_id, self::META_ORIGINAL_DIR, true) !== '') {
            return;
        }
        $default = get_option('uploads_use_yearmonth_folders') ? current_time('Y/m') : '';
        // Empty string cannot be stored distinguishably, so use a marker for "uploads root".
        update_post_meta($attachment_id, self::META_ORIGINAL_DIR, $default === '' ? '/' : $default);
    }

    /* ─────────────────────────────────────────────
       STATE / SETTINGS
    ───────────────────────────────────────────── */

    /**
     * Is physical mirroring enabled (setting on and global folder mode)?
     */
    public static function enabled()
    {
        $options = get_option('folderify_settings', []);
        if (empty($options['physical_folders'])) {
            return false;
        }
        return (($options['folderify_folder_mode'] ?? 'global') === 'global');
    }

    public static function is_global_mode()
    {
        $options = get_option('folderify_settings', []);
        return (($options['folderify_folder_mode'] ?? 'global') === 'global');
    }

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'folderify_folders';
    }

    public static function reset_cache()
    {
        self::$path_cache = [];
    }

    /* ─────────────────────────────────────────────
       PATHS
    ───────────────────────────────────────────── */

    /**
     * Turn a folder name into a safe directory name (keeps Unicode, e.g. Cyrillic).
     */
    public static function sanitize_segment($name)
    {
        $name = wp_strip_all_tags((string) $name);
        $name = preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|%#]+/u', '', $name);
        $name = trim($name, " .\t\n\r");
        $name = preg_replace('/\s+/u', ' ', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        return $name;
    }

    /**
     * Return the directory name (slug) for a folder, generating and saving it if missing.
     */
    public function get_slug($folder_id)
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, name, parent_id, slug FROM {$table} WHERE id = %d", $folder_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if (!$row) {
            return '';
        }
        if (!empty($row['slug'])) {
            return $row['slug'];
        }
        $slug = $this->make_unique_slug($row['name'], (int) $row['parent_id'], (int) $row['id']);
        $wpdb->update($table, ['slug' => $slug], ['id' => (int) $row['id']], ['%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $slug;
    }

    /**
     * Build a directory name that does not clash with sibling folders.
     */
    public function make_unique_slug($name, $parent_id, $exclude_id = 0)
    {
        global $wpdb;
        $table = self::table();

        $base = self::sanitize_segment($name);
        if ($base === '') {
            $base = 'folder';
        }

        $siblings = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT slug FROM {$table} WHERE parent_id = %d AND id != %d AND slug != '' AND post_type = 'attachment'",
            $parent_id,
            $exclude_id
        ));
        $taken = array_map('mb_strtolower', (array) $siblings);

        $slug = $base;
        $i = 2;
        while (in_array(mb_strtolower($slug), $taken, true)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * Relative path (inside uploads) of a folder, e.g. "Photos/Trips". Empty string for root/unknown.
     */
    public function get_folder_path($folder_id, $fresh = false)
    {
        $folder_id = (int) $folder_id;
        if ($folder_id <= 0) {
            return '';
        }
        if (!$fresh && isset(self::$path_cache[$folder_id])) {
            return self::$path_cache[$folder_id];
        }

        global $wpdb;
        $table = self::table();
        $segments = [];
        $guard = 0;
        $current = $folder_id;

        while ($current > 0 && $guard < 50) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, parent_id FROM {$table} WHERE id = %d", $current), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if (!$row) {
                break;
            }
            $slug = $this->get_slug($current);
            if ($slug === '') {
                break;
            }
            array_unshift($segments, $slug);
            $current = (int) $row['parent_id'];
            $guard++;
        }

        $path = implode('/', $segments);
        self::$path_cache[$folder_id] = $path;
        return $path;
    }

    private static function basedir()
    {
        $u = wp_upload_dir(null, false);
        return wp_normalize_path($u['basedir']);
    }

    private static function baseurl()
    {
        $u = wp_upload_dir(null, false);
        return untrailingslashit($u['baseurl']);
    }

    /**
     * Make sure the directory for a folder exists.
     */
    public function ensure_directory($folder_id)
    {
        if (!self::enabled()) {
            return false;
        }
        $path = $this->get_folder_path($folder_id, true);
        if ($path === '') {
            return false;
        }
        return $this->mkdir_rel($path);
    }

    private function mkdir_rel($rel)
    {
        $abs = self::basedir() . '/' . ltrim($rel, '/');
        if (is_dir($abs)) {
            return true;
        }
        $ok = wp_mkdir_p($abs);
        if ($ok) {
            $index = $abs . '/index.php';
            if (!file_exists($index)) {
                // Keep directory listings closed, like WordPress does for plugin dirs.
                @file_put_contents($index, "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
        return $ok;
    }

    /* ─────────────────────────────────────────────
       UPLOAD HOOKS
    ───────────────────────────────────────────── */

    /**
     * Send new uploads straight to the active folder's directory.
     */
    public function filter_upload_dir($dirs)
    {
        if (!empty($dirs['error']) || self::$busy || !self::enabled()) {
            return $dirs;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_REQUEST['folderify_folder']) || empty($_FILES)) {
            return $dirs;
        }
        $folder_id = absint(wp_unslash($_REQUEST['folderify_folder']));
        // phpcs:enable WordPress.Security.NonceVerification
        if ($folder_id <= 0) {
            return $dirs;
        }

        global $wpdb;
        $table = self::table();
        $type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$table} WHERE id = %d", $folder_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($type !== 'attachment') {
            return $dirs;
        }

        $rel = $this->get_folder_path($folder_id);
        if ($rel === '') {
            return $dirs;
        }

        $dirs['subdir'] = '/' . $rel;
        $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
        return $dirs;
    }

    /**
     * Fallback for uploads that did not pass through upload_dir (e.g. other upload screens):
     * once thumbnails exist, move the whole set into the folder directory.
     */
    public function relocate_after_metadata($metadata, $attachment_id, $context = 'create')
    {
        if (self::$busy || !self::enabled() || !is_array($metadata)) {
            return $metadata;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_REQUEST['folderify_folder'])) {
            return $metadata;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $folder_id = absint(wp_unslash($_REQUEST['folderify_folder']));
        if ($folder_id <= 0) {
            return $metadata;
        }
        $result = $this->move_attachment_to_folder($attachment_id, $folder_id, ['metadata' => &$metadata, 'replace_urls' => false]);
        return $metadata;
    }

    /* ─────────────────────────────────────────────
       MOVING ATTACHMENTS
    ───────────────────────────────────────────── */

    /**
     * Apply the "physical home" of an attachment after its folders changed.
     *
     * @param int   $attachment_id
     * @param array $folder_ids    Folder ids in the order given by the UI (first one wins).
     * @param bool  $append        In append mode nothing is moved.
     */
    public function apply_assignment($attachment_id, array $folder_ids, $append = false)
    {
        if (!self::enabled() || self::$busy || $append) {
            return;
        }
        // During a fresh upload the thumbnails do not exist yet; relocate_after_metadata() handles that case.
        if (doing_action('add_attachment')) {
            return;
        }
        $folder_ids = array_values(array_filter(array_map('absint', $folder_ids)));
        if (empty($folder_ids)) {
            $this->move_attachment_to_original($attachment_id);
        } else {
            $this->move_attachment_to_folder($attachment_id, $folder_ids[0]);
        }
    }

    /**
     * Move an attachment's files into the directory of a folder.
     *
     * @param int   $attachment_id
     * @param int   $folder_id
     * @param array $args  replace_urls (bool, default true), metadata (array by reference)
     * @return true|\WP_Error
     */
    public function move_attachment_to_folder($attachment_id, $folder_id, $args = [])
    {
        $path = $this->get_folder_path($folder_id);
        if ($path === '') {
            return new \WP_Error('no_path', __('Folder has no directory.', 'folderify'));
        }
        if (!$this->mkdir_rel($path)) {
            return new \WP_Error('mkdir_failed', __('Could not create the folder directory in uploads.', 'folderify'));
        }
        return $this->relocate($attachment_id, $path, $args);
    }

    /**
     * Put the files back where they were before the first move
     * (or leave them if we do not know the original place).
     */
    public function move_attachment_to_original($attachment_id, $args = [])
    {
        $orig = get_post_meta($attachment_id, self::META_ORIGINAL_DIR, true);
        if ($orig === '' || $orig === false) {
            return true;
        }
        $orig = trim((string) $orig, '/');
        if ($orig !== '' && !$this->mkdir_rel($orig)) {
            return new \WP_Error('mkdir_failed', __('Could not create the target directory.', 'folderify'));
        }
        $result = $this->relocate($attachment_id, $orig, $args);
        if ($result === true) {
            delete_post_meta($attachment_id, self::META_ORIGINAL_DIR);
        }
        return $result;
    }

    /**
     * Core mover: relocates main file, all generated sizes, original image and backups.
     *
     * @param int    $attachment_id
     * @param string $target_rel_dir Relative directory inside uploads ('' = uploads root).
     * @param array  $args
     * @return true|\WP_Error
     */
    private function relocate($attachment_id, $target_rel_dir, $args = [])
    {
        $attachment_id = (int) $attachment_id;
        $target_rel_dir = trim(str_replace('\\', '/', $target_rel_dir), '/');
        $replace_urls = array_key_exists('replace_urls', $args) ? (bool) $args['replace_urls'] : true;
        $use_passed_meta = isset($args['metadata']) && is_array($args['metadata']);

        $rel_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!$rel_file || !is_string($rel_file)) {
            return new \WP_Error('no_file', __('Attachment has no file.', 'folderify'));
        }
        $rel_file = ltrim(wp_normalize_path($rel_file), '/');

        $current_rel_dir = dirname($rel_file);
        $current_rel_dir = ($current_rel_dir === '.') ? '' : trim($current_rel_dir, '/');
        if ($current_rel_dir === $target_rel_dir) {
            return true; // already there
        }

        $basedir = self::basedir();
        $baseurl = self::baseurl();
        $old_abs_dir = $basedir . ($current_rel_dir !== '' ? '/' . $current_rel_dir : '');
        $new_abs_dir = $basedir . ($target_rel_dir !== '' ? '/' . $target_rel_dir : '');

        $main_name = wp_basename($rel_file);
        if (!file_exists($old_abs_dir . '/' . $main_name)) {
            return new \WP_Error('missing', __('The file does not exist on disk.', 'folderify'));
        }

        $metadata = $use_passed_meta ? $args['metadata'] : wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        // 1. Collect every file that belongs to this attachment (basenames).
        $files = [$main_name];
        if (!empty($metadata['original_image'])) {
            $files[] = $metadata['original_image'];
        }
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $files[] = $size['file'];
                }
            }
        }
        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $size) {
                if (!empty($size['file'])) {
                    $files[] = $size['file'];
                }
            }
        }
        $files = array_values(array_unique(array_filter($files)));

        // 2. Resolve name clashes in the target directory (rename the whole set consistently).
        $rename_map = [];
        $main_info = pathinfo($main_name);
        $old_stem = $main_info['filename'];
        $new_stem = $old_stem;

        if (file_exists($new_abs_dir . '/' . $main_name)) {
            $unique = wp_unique_filename($new_abs_dir, $main_name);
            $new_stem = pathinfo($unique, PATHINFO_FILENAME);
        }
        foreach ($files as $f) {
            if ($new_stem !== $old_stem && strpos($f, $old_stem) === 0) {
                $rename_map[$f] = $new_stem . substr($f, strlen($old_stem));
            } else {
                $rename_map[$f] = $f;
            }
        }

        // 3. Pre-flight: nothing may overwrite an existing file.
        foreach ($rename_map as $old => $new) {
            if (!file_exists($old_abs_dir . '/' . $old)) {
                continue;
            }
            if (file_exists($new_abs_dir . '/' . $new)) {
                return new \WP_Error('clash', sprintf(
                    /* translators: %s: file name */
                    __('File "%s" already exists in the target folder.', 'folderify'),
                    $new
                ));
            }
        }

        // 4. Move files (roll back if something fails).
        self::$busy = true;
        $moved = [];
        foreach ($rename_map as $old => $new) {
            $from = $old_abs_dir . '/' . $old;
            if (!file_exists($from)) {
                continue;
            }
            $to = $new_abs_dir . '/' . $new;
            if (!$this->move_file($from, $to)) {
                foreach (array_reverse($moved, true) as $back_from => $back_to) {
                    $this->move_file($back_to, $back_from);
                }
                self::$busy = false;
                return new \WP_Error('move_failed', sprintf(
                    /* translators: %s: file name */
                    __('Could not move "%s".', 'folderify'),
                    $old
                ));
            }
            $moved[$from] = $to;
        }

        // 5. Remember where the file originally lived (first move only).
        if (get_post_meta($attachment_id, self::META_ORIGINAL_DIR, true) === '') {
            update_post_meta($attachment_id, self::META_ORIGINAL_DIR, $current_rel_dir === '' ? '/' : $current_rel_dir);
        }

        // 6. Update database: attached file, metadata, size file names.
        $new_main = $rename_map[$main_name];
        $new_rel_file = ($target_rel_dir !== '' ? $target_rel_dir . '/' : '') . $new_main;
        update_post_meta($attachment_id, '_wp_attached_file', $new_rel_file);

        if (!empty($metadata)) {
            $metadata['file'] = $new_rel_file;
            if (!empty($metadata['original_image']) && isset($rename_map[$metadata['original_image']])) {
                $metadata['original_image'] = $rename_map[$metadata['original_image']];
            }
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $key => $size) {
                    if (!empty($size['file']) && isset($rename_map[$size['file']])) {
                        $metadata['sizes'][$key]['file'] = $rename_map[$size['file']];
                    }
                }
            }
            if ($use_passed_meta) {
                $args['metadata'] = $metadata;
            } else {
                update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
            }
        }
        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $key => $size) {
                if (!empty($size['file']) && isset($rename_map[$size['file']])) {
                    $backup_sizes[$key]['file'] = $rename_map[$size['file']];
                }
            }
            update_post_meta($attachment_id, '_wp_attachment_backup_sizes', $backup_sizes);
        }

        // 7. Update links inside posts so nothing breaks.
        if ($replace_urls) {
            $pairs = [];
            foreach ($rename_map as $old => $new) {
                $old_url = $baseurl . ($current_rel_dir !== '' ? '/' . $current_rel_dir : '') . '/' . $old;
                $new_url = $baseurl . ($target_rel_dir !== '' ? '/' . $target_rel_dir : '') . '/' . $new;
                $pairs[$old_url] = $new_url;
            }
            $this->replace_urls($pairs, $baseurl . ($current_rel_dir !== '' ? '/' . $current_rel_dir : '') . '/' . $old_stem);
        }

        // Fix the permanent link stored in guid? WordPress keeps guid untouched by convention.

        clean_post_cache($attachment_id);
        self::$busy = false;

        do_action('folderify_attachment_moved', $attachment_id, $current_rel_dir, $target_rel_dir);
        return true;
    }

    /**
     * Rename within a file system, falling back to copy + delete across devices.
     */
    private function move_file($from, $to)
    {
        if (!is_dir(dirname($to))) {
            wp_mkdir_p(dirname($to));
        }
        if (@rename($from, $to)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
            return true;
        }
        if (@copy($from, $to)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
            wp_delete_file($from);
            return true;
        }
        return false;
    }

    /**
     * Replace URLs in post content and (non-serialized) post meta.
     *
     * @param array  $pairs  old_url => new_url
     * @param string $needle Common URL prefix used to pre-filter rows with LIKE.
     */
    private function replace_urls(array $pairs, $needle)
    {
        global $wpdb;
        if (empty($pairs)) {
            return;
        }
        $like = '%' . $wpdb->esc_like($needle) . '%';

        // Post content (and excerpts).
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type != 'attachment' AND post_status != 'auto-draft' AND (post_content LIKE %s OR post_excerpt LIKE %s)",
            $like,
            $like
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $content = strtr($row['post_content'], $pairs);
            $excerpt = strtr($row['post_excerpt'], $pairs);
            if ($content !== $row['post_content'] || $excerpt !== $row['post_excerpt']) {
                $wpdb->update($wpdb->posts, ['post_content' => $content, 'post_excerpt' => $excerpt], ['ID' => (int) $row['ID']], ['%s', '%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                clean_post_cache((int) $row['ID']);
            }
        }

        // Post meta (page builders keep JSON there). Serialized values are skipped on purpose:
        // a plain string replace would corrupt their length prefixes.
        if (apply_filters('folderify_update_postmeta_urls', true)) {
            $metas = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')
                   AND meta_value LIKE %s
                   AND meta_value NOT LIKE 'a:%%' AND meta_value NOT LIKE 'O:%%' AND meta_value NOT LIKE 's:%%'",
                $like
            ), ARRAY_A);
            foreach ((array) $metas as $m) {
                $new_value = strtr($m['meta_value'], $pairs);
                if ($new_value !== $m['meta_value']) {
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $new_value], ['meta_id' => (int) $m['meta_id']], ['%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                }
            }
        }
    }

    /* ─────────────────────────────────────────────
       FOLDER OPERATIONS (rename / move / delete)
    ───────────────────────────────────────────── */

    /**
     * Call BEFORE a folder is renamed or moved: remembers where its directory is now.
     */
    public function snapshot_path($folder_id)
    {
        if (!self::enabled()) {
            return null;
        }
        return $this->get_folder_path($folder_id, true);
    }

    /**
     * Call AFTER the folder row changed. Renames the directory and fixes all
     * paths / links of attachments that lived below it.
     *
     * @param int         $folder_id
     * @param string|null $old_rel   Value returned by snapshot_path().
     * @param bool        $rename_slug Re-derive the directory name from the folder name.
     */
    public function sync_folder_path($folder_id, $old_rel, $rename_slug = false)
    {
        if (!self::enabled() || $old_rel === null || self::$busy) {
            return;
        }
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare("SELECT name, parent_id, slug FROM {$table} WHERE id = %d", $folder_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Moved under a new parent that already has a sibling with the same directory name?
        if (!$rename_slug && $row && $row['slug'] !== '') {
            $clash = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COUNT(*) FROM {$table} WHERE parent_id = %d AND id != %d AND LOWER(slug) = LOWER(%s) AND post_type = 'attachment'",
                (int) $row['parent_id'],
                (int) $folder_id,
                $row['slug']
            ));
            if ($clash > 0) {
                $rename_slug = true;
            }
        }

        if ($rename_slug) {
            if ($row) {
                $slug = $this->make_unique_slug($row['name'], (int) $row['parent_id'], (int) $folder_id);
                $wpdb->update($table, ['slug' => $slug], ['id' => (int) $folder_id], ['%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
        }

        self::reset_cache();
        $new_rel = $this->get_folder_path($folder_id, true);

        if ($old_rel === $new_rel) {
            return;
        }
        $this->rename_directory($old_rel, $new_rel);
    }

    /**
     * Rename a directory inside uploads and rewrite every stored path below it.
     */
    public function rename_directory($old_rel, $new_rel)
    {
        global $wpdb;
        $basedir = self::basedir();
        $baseurl = self::baseurl();

        $old_rel = trim($old_rel, '/');
        $new_rel = trim($new_rel, '/');
        if ($old_rel === '' || $new_rel === '' || $old_rel === $new_rel) {
            return;
        }

        $old_abs = $basedir . '/' . $old_rel;
        $new_abs = $basedir . '/' . $new_rel;

        self::$busy = true;

        if (is_dir($old_abs)) {
            wp_mkdir_p(dirname($new_abs));
            if (!file_exists($new_abs)) {
                if (!@rename($old_abs, $new_abs)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
                    $this->copy_tree($old_abs, $new_abs);
                    $this->remove_tree($old_abs);
                }
            } else {
                // Target exists already: merge contents, never overwrite.
                $this->merge_tree($old_abs, $new_abs);
                $this->remove_empty_tree($old_abs);
            }
        } else {
            $this->mkdir_rel($new_rel);
        }

        // Rewrite _wp_attached_file and metadata for everything below the old path.
        $like = $wpdb->esc_like($old_rel . '/') . '%';
        $ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            $like
        ));
        foreach ((array) $ids as $pid) {
            $pid = (int) $pid;
            $file = get_post_meta($pid, '_wp_attached_file', true);
            if (strpos($file, $old_rel . '/') === 0) {
                $new_file = $new_rel . '/' . substr($file, strlen($old_rel) + 1);
                update_post_meta($pid, '_wp_attached_file', $new_file);
                $meta = wp_get_attachment_metadata($pid);
                if (is_array($meta) && !empty($meta['file'])) {
                    $meta['file'] = $new_file;
                    update_post_meta($pid, '_wp_attachment_metadata', $meta);
                }
                clean_post_cache($pid);
            }
        }

        // Links in content.
        $old_url = $baseurl . '/' . $old_rel . '/';
        $new_url = $baseurl . '/' . $new_rel . '/';
        $this->replace_urls([$old_url => $new_url], $old_url);

        self::$busy = false;
        self::reset_cache();
        do_action('folderify_directory_renamed', $old_rel, $new_rel);
    }

    /**
     * Before a folder is deleted: send its files back to their original location.
     * Call remove_directory_if_empty() afterwards.
     */
    public function release_folder_files($folder_id)
    {
        if (!self::enabled()) {
            return;
        }
        global $wpdb;
        $rel = $wpdb->prefix . 'folderify_relationships';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$rel} WHERE folder_id = %d", $folder_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ((array) $ids as $id) {
            // Only if this folder is the file's physical home.
            $current_dir = dirname((string) get_post_meta((int) $id, '_wp_attached_file', true));
            $current_dir = ($current_dir === '.') ? '' : trim($current_dir, '/');
            if ($current_dir === $this->get_folder_path($folder_id)) {
                $this->move_attachment_to_original((int) $id);
            }
        }
    }

    public function remove_directory_if_empty($rel)
    {
        if (!self::enabled()) {
            return;
        }
        $rel = trim((string) $rel, '/');
        if ($rel === '') {
            return;
        }
        $abs = self::basedir() . '/' . $rel;
        if (!is_dir($abs)) {
            return;
        }
        // A folder that only holds our index.php counts as empty.
        $entries = array_diff(scandir($abs), ['.', '..', 'index.php']);
        if (empty($entries)) {
            wp_delete_file($abs . '/index.php');
            @rmdir($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }
    }

    /* ─────────────────────────────────────────────
       SMALL FILESYSTEM HELPERS
    ───────────────────────────────────────────── */

    private function copy_tree($src, $dst)
    {
        wp_mkdir_p($dst);
        foreach (array_diff(scandir($src), ['.', '..']) as $item) {
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_dir($s) && !is_link($s)) {
                $this->copy_tree($s, $d);
            } else {
                @copy($s, $d); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
            }
        }
    }

    private function remove_tree($dir)
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $this->remove_tree($p);
            } else {
                wp_delete_file($p);
            }
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    private function merge_tree($src, $dst)
    {
        foreach (array_diff(scandir($src), ['.', '..']) as $item) {
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_dir($s) && !is_link($s)) {
                wp_mkdir_p($d);
                $this->merge_tree($s, $d);
            } elseif (!file_exists($d)) {
                $this->move_file($s, $d);
            }
        }
    }

    private function remove_empty_tree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $this->remove_empty_tree($p);
            }
        }
        $left = array_diff(scandir($dir), ['.', '..', 'index.php']);
        if (empty($left)) {
            wp_delete_file($dir . '/index.php');
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }
    }
}
