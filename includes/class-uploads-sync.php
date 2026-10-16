<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Uploads_Sync
 *
 * Two-way synchronisation between wp-content/uploads and the Folderify folder tree.
 *
 *  mode "scan"  disk -> plugin
 *      Walks uploads, registers unknown files in the Media Library, creates Folderify
 *      folders for every directory (nested like on disk) and assigns files to them.
 *
 *  mode "apply" plugin -> disk
 *      Moves already categorised media into the directory of their folder.
 *
 * Work is split into small tasks that the admin screen processes in batches via AJAX,
 * so large libraries do not hit PHP time limits.
 */
class Uploads_Sync
{
    const JOB_KEY = 'folderify_sync_job_';
    const BATCH_SECONDS = 8;

    /** @var Folder_Manager */
    private $folders;

    /** @var Physical_Storage */
    private $storage;

    /** @var array<string,int> relative dir => folder id (per request) */
    private $dir_cache = [];

    public function __construct($folder_manager, $storage)
    {
        $this->folders = $folder_manager;
        $this->storage = $storage;

        add_action('wp_ajax_folderify_sync_start', [$this, 'ajax_start']);
        add_action('wp_ajax_folderify_sync_step', [$this, 'ajax_step']);
        add_action('wp_ajax_folderify_sync_cancel', [$this, 'ajax_cancel']);
    }

    /* ─────────────────────────────────────────────
       AJAX
    ───────────────────────────────────────────── */

    private function guard()
    {
        check_ajax_referer('folderify_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'folderify'));
        }
        if (!Physical_Storage::is_global_mode()) {
            wp_send_json_error(__('Uploads sync works only with "Global Shared Folders" mode. Private per-user folders cannot be mirrored on disk.', 'folderify'));
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
        @set_time_limit(60);
    }

    private function job_key()
    {
        return self::JOB_KEY . get_current_user_id();
    }

    private function tasks_key()
    {
        return 'folderify_sync_tasks_' . get_current_user_id();
    }

    /**
     * The task list can be huge (every file in uploads), so it is kept out of the transient:
     * stored once, compressed, in a non-autoloaded option.
     */
    private function save_tasks(array $tasks)
    {
        $json = wp_json_encode($tasks);
        if (function_exists('gzcompress')) {
            $json = 'gz:' . base64_encode(gzcompress($json, 6)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }
        update_option($this->tasks_key(), $json, false);
    }

    private function load_tasks()
    {
        $raw = get_option($this->tasks_key(), '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        if (strpos($raw, 'gz:') === 0) {
            $raw = function_exists('gzuncompress') ? @gzuncompress(base64_decode(substr($raw, 3))) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        }
        $tasks = json_decode((string) $raw, true);
        return is_array($tasks) ? $tasks : [];
    }

    private function clear_job()
    {
        delete_transient($this->job_key());
        delete_option($this->tasks_key());
    }

    /**
     * Start: analyse and build the task list. Does not change anything yet when preview=1.
     */
    public function ajax_start()
    {
        $this->guard();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $mode = (isset($_POST['mode']) && $_POST['mode'] === 'apply') ? 'apply' : 'scan';
        $opts = [
            'include_date_dirs' => !empty($_POST['include_date_dirs']),
            'replace_urls' => !isset($_POST['replace_urls']) || !empty($_POST['replace_urls']),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($mode === 'apply' && !Physical_Storage::enabled()) {
            wp_send_json_error(__('Turn on "Store media in real folders" in the General tab and save settings first.', 'folderify'));
        }

        $job = ($mode === 'apply') ? $this->plan_apply($opts) : $this->plan_scan($opts);
        if (is_wp_error($job)) {
            wp_send_json_error($job->get_error_message());
        }

        $tasks = $job['tasks'];
        unset($job['tasks']);
        $this->save_tasks($tasks);

        $job['mode'] = $mode;
        $job['opts'] = $opts;
        $job['pos'] = 0;
        $job['total'] = count($tasks);
        $job['done'] = [
            'registered' => 0, 'linked' => 0, 'folders' => 0, 'moved' => 0, 'skipped' => 0, 'errors' => [],
        ];
        set_transient($this->job_key(), $job, HOUR_IN_SECONDS);

        // Remember that the analysis itself finished (shown on the admin screen).
        update_option('folderify_last_analysis', [
            'mode' => $mode,
            'time' => time(),
            'summary' => $job['summary'],
            'total' => count($tasks),
        ], false);

        wp_send_json_success([
            'mode' => $mode,
            'total' => count($tasks),
            'summary' => $job['summary'],
            'time' => $this->format_time(time()),
        ]);
    }

    /**
     * Process the next batch of tasks.
     */
    public function ajax_step()
    {
        $this->guard();

        $job = get_transient($this->job_key());
        $tasks = $job ? $this->load_tasks() : [];
        if (!$job || empty($tasks)) {
            wp_send_json_error(__('Nothing to do. Run the scan first.', 'folderify'));
        }

        $started = microtime(true);
        $total = count($tasks);
        $max_per_batch = ($job['mode'] === 'scan') ? 25 : 40;
        $n = 0;

        $this->storage->reset_cache();

        while ($job['pos'] < $total && $n < $max_per_batch && (microtime(true) - $started) < self::BATCH_SECONDS) {
            $task = $tasks[$job['pos']];
            $this->run_task($task, $job);
            $job['pos']++;
            $n++;
        }

        $finished = ($job['pos'] >= $total);
        $finished_at = '';
        if ($finished) {
            $this->clear_job();
            $this->folders->invalidate_cache();

            // Persist the result so the screen can show "last sync" after a reload.
            update_option('folderify_last_sync', [
                'mode' => $job['mode'],
                'time' => time(),
                'done' => $job['done'],
                'total' => $total,
            ], false);
            $finished_at = $this->format_time(time());
        } else {
            set_transient($this->job_key(), $job, HOUR_IN_SECONDS);
        }

        wp_send_json_success([
            'finished' => $finished,
            'processed' => $job['pos'],
            'total' => $total,
            'done' => $job['done'],
            'mode' => $job['mode'],
            'time' => $finished_at,
        ]);
    }

    /**
     * Human readable time in the site's timezone.
     */
    public static function format_time($timestamp)
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $timestamp);
    }

    /**
     * Result of the last finished sync for a mode (for the settings screen).
     *
     * @return array|null
     */
    public static function last_sync($mode)
    {
        $data = get_option('folderify_last_sync');
        if (is_array($data) && ($data['mode'] ?? '') === $mode) {
            return $data;
        }
        return null;
    }

    public function ajax_cancel()
    {
        $this->guard();
        $this->clear_job();
        wp_send_json_success();
    }

    /* ─────────────────────────────────────────────
       PLAN: disk -> plugin
    ───────────────────────────────────────────── */

    /**
     * Directories that must never be treated as media folders.
     */
    private function excluded_dirs()
    {
        $defaults = [
            'cache', 'wc-logs', 'woocommerce_uploads', 'elementor', 'wpforms', 'et-cache', 'sucuri',
            'ai1wm-backups', 'updraft', 'wpcf7_uploads', 'wpo-cache', 'folderify-backups', 'fonts',
            'bb-plugin', 'smush', 'rank-math', 'wp-import-export-lite', 'ewww', 'redirection',
        ];
        return array_map('strtolower', (array) apply_filters('folderify_scan_excluded_dirs', $defaults));
    }

    private function is_date_dir($rel)
    {
        return (bool) preg_match('#^\d{4}(/\d{2})?$#', $rel);
    }

    /**
     * Recursively list directories (relative paths) and files (relative paths) in uploads.
     *
     * @return array{dirs:string[], files:string[]}
     */
    private function walk($basedir, $include_date_dirs)
    {
        $excluded = $this->excluded_dirs();
        $dirs = [];
        $files = [];

        $stack = [''];
        while ($stack) {
            $rel = array_pop($stack);
            $abs = $basedir . ($rel !== '' ? '/' . $rel : '');
            $items = @scandir($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ($items === false) {
                continue;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item[0] === '.') {
                    continue;
                }
                $item_rel = ($rel !== '' ? $rel . '/' : '') . $item;
                $item_abs = $abs . '/' . $item;

                if (is_link($item_abs)) {
                    continue;
                }
                if (is_dir($item_abs)) {
                    if ($rel === '' && in_array(strtolower($item), $excluded, true)) {
                        continue;
                    }
                    $dirs[] = $item_rel;
                    $stack[] = $item_rel;
                } elseif (is_file($item_abs)) {
                    $files[] = $item_rel;
                }
            }
        }
        sort($dirs);
        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * All files that belong to attachments already (main files + generated sizes + originals).
     *
     * @return array{main:array<string,int>, derived:array<string,bool>}
     */
    private function known_files()
    {
        global $wpdb;

        $main = [];
        $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'", ARRAY_N); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ((array) $rows as $r) {
            $main[ltrim(wp_normalize_path($r[1]), '/')] = (int) $r[0];
        }

        $derived = [];
        $last_id = 0;
        do {
            $batch = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_id > %d ORDER BY meta_id ASC LIMIT 500",
                $last_id
            ), ARRAY_A);
            foreach ((array) $batch as $row) {
                $last_id = (int) $row['meta_id'];
                $meta = maybe_unserialize($row['meta_value']);
                if (!is_array($meta) || empty($meta['file'])) {
                    continue;
                }
                $dir = dirname($meta['file']);
                $dir = ($dir === '.') ? '' : $dir . '/';
                if (!empty($meta['original_image'])) {
                    $derived[$dir . $meta['original_image']] = true;
                }
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $s) {
                        if (!empty($s['file'])) {
                            $derived[$dir . $s['file']] = true;
                        }
                    }
                }
            }
        } while (!empty($batch));

        return ['main' => $main, 'derived' => $derived];
    }

    /**
     * Folders where a file with a thumbnail-like name is surely generated (name-300x200.jpg
     * next to name.jpg) — used as an extra safety net on top of metadata.
     */
    private function looks_generated($rel, $all_files_set)
    {
        if (preg_match('/^(.*)-(\d+x\d+|rotated|e\d{10,})(\.[A-Za-z0-9]+)$/', $rel, $m)) {
            return isset($all_files_set[$m[1] . $m[3]]) || isset($all_files_set[$m[1] . '-scaled' . $m[3]]);
        }
        // Original of a "-scaled" image.
        if (preg_match('/^(.*)(\.[A-Za-z0-9]+)$/', $rel, $m) && isset($all_files_set[$m[1] . '-scaled' . $m[2]])) {
            return true;
        }
        return false;
    }

    private function plan_scan($opts)
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return new \WP_Error('upload_dir', $uploads['error']);
        }
        $basedir = wp_normalize_path($uploads['basedir']);

        $walk = $this->walk($basedir, $opts['include_date_dirs']);
        $known = $this->known_files();
        $all_files_set = array_fill_keys($walk['files'], true);

        $tasks = [];
        $new_files = 0;
        $link_files = 0;
        $folder_dirs = [];
        $stats = ['known' => 0, 'generated' => 0, 'unsupported' => 0, 'system' => 0];

        $existing_folder_paths = $this->existing_folder_paths();
        $linked_ids = $this->linked_attachment_ids();
        $new_folders = 0;

        // Directories that become folders (a task only for those that have no folder yet).
        foreach ($walk['dirs'] as $dir) {
            if ($this->skip_dir_as_folder($dir, $opts)) {
                continue;
            }
            $folder_dirs[$dir] = true;
            if (!isset($existing_folder_paths[mb_strtolower($dir)])) {
                $tasks[] = ['t' => 'dir', 'd' => $dir];
                $new_folders++;
            }
        }

        foreach ($walk['files'] as $rel) {
            $dir = dirname($rel);
            $dir = ($dir === '.') ? '' : $dir;
            $base = wp_basename($rel);

            if ($base === 'index.php' || $base === '.htaccess') {
                $stats['system']++;
                continue;
            }

            if (isset($known['main'][$rel])) {
                $stats['known']++;
                // Registered already: link it to the matching folder, but only when it has no folder
                // yet (never override an assignment the user made on purpose).
                if ($dir !== '' && isset($folder_dirs[$dir]) && !isset($linked_ids[$known['main'][$rel]])) {
                    $tasks[] = ['t' => 'link', 'id' => $known['main'][$rel], 'd' => $dir];
                    $link_files++;
                }
                continue;
            }
            if (isset($known['derived'][$rel]) || $this->looks_generated($rel, $all_files_set)) {
                $stats['generated']++;
                continue;
            }
            // Only types WordPress would accept in the Media Library.
            $type = wp_check_filetype($base);
            if (empty($type['ext']) || empty($type['type'])) {
                $stats['unsupported']++;
                continue;
            }
            $tasks[] = ['t' => 'file', 'f' => $rel, 'd' => isset($folder_dirs[$dir]) ? $dir : ''];
            $new_files++;
        }

        // Folders first (shallow before deep), then files — keeps parents ahead of children.
        usort($tasks, function ($a, $b) {
            $order = ['dir' => 0, 'link' => 1, 'file' => 2];
            if ($a['t'] === 'dir' && $b['t'] === 'dir') {
                return substr_count($a['d'], '/') <=> substr_count($b['d'], '/');
            }
            return $order[$a['t']] <=> $order[$b['t']];
        });


        return [
            'tasks' => $tasks,
            'summary' => [
                'new_files' => $new_files,
                'new_folders' => $new_folders,
                'link_files' => $link_files,
                'directories' => count($folder_dirs),
                // What the scan looked at, so "nothing to do" is still a visible, checkable result.
                'dirs_found' => count($walk['dirs']),
                'files_found' => count($walk['files']),
                'already_in_library' => $stats['known'],
                'skipped_generated' => $stats['generated'],
                'skipped_unsupported' => $stats['unsupported'],
            ],
        ];
    }

    /**
     * Should this directory NOT become a Folderify folder?
     */
    private function skip_dir_as_folder($dir, $opts)
    {
        if (!$opts['include_date_dirs'] && $this->is_date_dir($dir)) {
            return true;
        }
        // Any ancestor that is a date folder with sub folders deeper than YYYY/MM is a user dir; allow.
        return false;
    }

    /**
     * IDs of attachments that already belong to at least one folder.
     *
     * @return array<int,true>
     */
    private function linked_attachment_ids()
    {
        global $wpdb;
        $rel = $wpdb->prefix . 'folderify_relationships';
        $ids = $wpdb->get_col("SELECT DISTINCT object_id FROM {$rel} WHERE folder_id > 0"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_fill_keys(array_map('intval', (array) $ids), true);
    }

    /**
     * Existing folder tree as relative paths => id (lower-case keys).
     */
    private function existing_folder_paths()
    {
        $out = [];
        foreach ($this->folders->get_folders(['post_type' => 'attachment']) as $f) {
            $path = $this->storage->get_folder_path((int) $f['id']);
            if ($path !== '') {
                $out[mb_strtolower($path)] = (int) $f['id'];
            }
        }
        return $out;
    }

    /* ─────────────────────────────────────────────
       PLAN: plugin -> disk
    ───────────────────────────────────────────── */

    private function plan_apply($opts)
    {
        global $wpdb;
        $rel = $wpdb->prefix . 'folderify_relationships';
        $fol = $wpdb->prefix . 'folderify_folders';

        $tasks = [];

        // Every attachment folder gets a directory.
        $folders = $wpdb->get_col("SELECT id FROM {$fol} WHERE post_type = 'attachment' AND (user_id = 0 OR user_id IS NULL) ORDER BY parent_id ASC, id ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        foreach ((array) $folders as $fid) {
            $tasks[] = ['t' => 'mkdir', 'fid' => (int) $fid];
        }

        // Every categorised attachment whose file is not yet in its folder directory.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT r.object_id, MIN(r.folder_id) AS folder_id
             FROM {$rel} r
             INNER JOIN {$fol} f ON f.id = r.folder_id AND f.post_type = 'attachment' AND (f.user_id = 0 OR f.user_id IS NULL)
             INNER JOIN {$wpdb->posts} p ON p.ID = r.object_id AND p.post_type = 'attachment'
             GROUP BY r.object_id",
            ARRAY_A
        );
        $to_move = 0;
        foreach ((array) $rows as $row) {
            $path = $this->storage->get_folder_path((int) $row['folder_id']);
            $file = get_post_meta((int) $row['object_id'], '_wp_attached_file', true);
            if ($path === '' || !$file) {
                continue;
            }
            $dir = dirname($file);
            $dir = ($dir === '.') ? '' : $dir;
            if ($dir !== $path) {
                $tasks[] = ['t' => 'move', 'id' => (int) $row['object_id'], 'fid' => (int) $row['folder_id']];
                $to_move++;
            }
        }

        return [
            'tasks' => $tasks,
            'summary' => [
                'folders' => count($folders),
                'to_move' => $to_move,
            ],
        ];
    }

    /* ─────────────────────────────────────────────
       TASK RUNNERS
    ───────────────────────────────────────────── */

    private function run_task($task, &$job)
    {
        try {
            switch ($task['t']) {
                case 'dir':
                    $fid = $this->ensure_folder_for_dir($task['d'], $created);
                    if ($created) {
                        $job['done']['folders']++;
                    }
                    break;

                case 'link':
                    $fid = $this->ensure_folder_for_dir($task['d'], $created);
                    if ($fid > 0) {
                        $this->assign_without_moving((int) $task['id'], $fid);
                        $job['done']['linked']++;
                    }
                    break;

                case 'file':
                    $id = $this->register_file($task['f']);
                    if (is_wp_error($id)) {
                        $job['done']['errors'][] = $task['f'] . ': ' . $id->get_error_message();
                        break;
                    }
                    $job['done']['registered']++;
                    if ($task['d'] !== '') {
                        $fid = $this->ensure_folder_for_dir($task['d'], $created);
                        if ($created) {
                            $job['done']['folders']++;
                        }
                        if ($fid > 0) {
                            $this->assign_without_moving($id, $fid);
                        }
                    }
                    break;

                case 'mkdir':
                    $this->storage->ensure_directory((int) $task['fid']);
                    break;

                case 'move':
                    $res = $this->storage->move_attachment_to_folder((int) $task['id'], (int) $task['fid'], ['replace_urls' => !empty($job['opts']['replace_urls'])]);
                    if (is_wp_error($res)) {
                        $job['done']['errors'][] = '#' . $task['id'] . ': ' . $res->get_error_message();
                    } else {
                        $job['done']['moved']++;
                    }
                    break;
            }
        } catch (\Throwable $e) {
            $job['done']['errors'][] = wp_json_encode($task) . ': ' . $e->getMessage();
        }

        if (count($job['done']['errors']) > 50) {
            $job['done']['errors'] = array_slice($job['done']['errors'], -50);
        }
    }

    /**
     * Find (or create) the Folderify folder that matches a directory path, creating every missing level.
     *
     * @param string $dir     Relative directory, e.g. "Photos/Trips".
     * @param bool   $created Set to true when at least one folder was created.
     * @return int Folder id or 0.
     */
    private function ensure_folder_for_dir($dir, &$created = false)
    {
        global $wpdb;
        $created = false;
        $dir = trim($dir, '/');
        if ($dir === '') {
            return 0;
        }
        if (isset($this->dir_cache[$dir])) {
            return $this->dir_cache[$dir];
        }

        $table = $wpdb->prefix . 'folderify_folders';
        $parent_id = 0;
        $built = '';

        foreach (explode('/', $dir) as $segment) {
            $built = ($built === '') ? $segment : $built . '/' . $segment;
            if (isset($this->dir_cache[$built])) {
                $parent_id = $this->dir_cache[$built];
                continue;
            }

            // Look for an existing sibling whose directory name equals this segment.
            $existing = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT id, name, slug FROM {$table} WHERE parent_id = %d AND post_type = 'attachment' AND (user_id = 0 OR user_id IS NULL)",
                $parent_id
            ), ARRAY_A);

            $found = 0;
            foreach ((array) $existing as $f) {
                $slug = $f['slug'] !== '' ? $f['slug'] : $this->storage->get_slug((int) $f['id']);
                if (mb_strtolower($slug) === mb_strtolower($segment)) {
                    $found = (int) $f['id'];
                    break;
                }
            }

            if ($found === 0) {
                $found = $this->create_folder_row($segment, $parent_id);
                if ($found > 0) {
                    $created = true;
                }
            }
            if ($found === 0) {
                return 0;
            }

            $this->dir_cache[$built] = $found;
            $parent_id = $found;
        }

        Physical_Storage::reset_cache();
        return $parent_id;
    }

    /**
     * Create a folder row whose directory name is exactly the on-disk name.
     */
    private function create_folder_row($segment, $parent_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';

        $max_order = $wpdb->get_var($wpdb->prepare("SELECT MAX(order_index) FROM {$table} WHERE parent_id = %d", $parent_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'user_id' => 0,
                'post_type' => 'attachment',
                'name' => sanitize_text_field($segment),
                'slug' => $segment, // keep the real directory name untouched
                'parent_id' => $parent_id,
                'color' => '',
                'order_index' => (int) $max_order + 1,
            ]
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Link an attachment to a folder without touching files (they are already in place).
     */
    private function assign_without_moving($attachment_id, $folder_id)
    {
        global $wpdb;
        $rel = $wpdb->prefix . 'folderify_relationships';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$rel} WHERE object_id = %d AND folder_id = %d", $attachment_id, $folder_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($exists) {
            return;
        }
        // One physical home per file: replace any other assignment.
        $wpdb->delete($rel, ['object_id' => $attachment_id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($rel, ['object_id' => $attachment_id, 'folder_id' => $folder_id], ['%d', '%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    }

    /**
     * Add a file found in uploads to the Media Library.
     *
     * @param string $rel Relative path inside uploads.
     * @return int|\WP_Error Attachment id.
     */
    private function register_file($rel)
    {
        $uploads = wp_upload_dir(null, false);
        $abs = wp_normalize_path($uploads['basedir']) . '/' . $rel;
        if (!is_file($abs)) {
            return new \WP_Error('missing', __('File vanished.', 'folderify'));
        }

        $filetype = wp_check_filetype(wp_basename($abs));
        if (empty($filetype['type'])) {
            return new \WP_Error('type', __('File type is not allowed.', 'folderify'));
        }

        $mtime = filemtime($abs) ?: time();
        $title = preg_replace('/\.[^.]+$/', '', wp_basename($abs));
        $title = trim(preg_replace('/[-_]+/', ' ', $title));

        $attachment = [
            'guid' => trailingslashit($uploads['baseurl']) . str_replace('%2F', '/', rawurlencode($rel)),
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_author' => get_current_user_id(),
            'post_date' => wp_date('Y-m-d H:i:s', $mtime),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $mtime),
        ];

        $id = wp_insert_attachment($attachment, $abs, 0, true);
        if (is_wp_error($id) || !$id) {
            return is_wp_error($id) ? $id : new \WP_Error('insert', __('Could not create attachment.', 'folderify'));
        }

        // Make sure the stored path is relative (wp_insert_attachment does it, but be safe).
        update_post_meta($id, '_wp_attached_file', $rel);

        // Thumbnails and metadata. Mark as already in place so Physical_Storage leaves it alone.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $meta = wp_generate_attachment_metadata($id, $abs);
        if (!empty($meta)) {
            wp_update_attachment_metadata($id, $meta);
        }

        return (int) $id;
    }
}
