<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class REST
 *
 * Registers REST API endpoints.
 */
class REST
{

    /**
     * @var Folder_Manager
     */
    private $folder_manager;

    public function __construct($folder_manager)
    {
        $this->folder_manager = $folder_manager;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        $namespace = 'folderify/v1';

        // Get all folders
        register_rest_route($namespace, '/folders', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_folders'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
        ]);

        // Create folder
        register_rest_route($namespace, '/folders', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_folder'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
            'args' => [
                'name' => ['required' => true, 'type' => 'string'],
                'parent_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
                'color' => ['required' => false, 'type' => 'string', 'default' => ''],
                'post_type' => ['required' => false, 'type' => 'string', 'default' => 'attachment'],
            ],
        ]);

        // Update folder
        register_rest_route($namespace, '/folders/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_folder'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
        ]);

        // Delete folder
        register_rest_route($namespace, '/folders/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_folder'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
        ]);

        if ( true ) {
            // Change Folder Color
            register_rest_route($namespace, '/folders/(?P<id>\d+)/color', [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'change_color'],
                'permission_callback' => [Permissions::class, 'check_api_permissions'],
                'args' => [
                    'color' => ['required' => true, 'type' => 'string'],
                ],
            ]);

            // Duplicate Folder
            register_rest_route($namespace, '/folders/(?P<id>\d+)/duplicate', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'duplicate_folder'],
                'permission_callback' => [Permissions::class, 'check_api_permissions'],
            ]);
        }

        if ( true ) {
            // Download Folder
            register_rest_route($namespace, '/folders/(?P<id>\d+)/download', [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'download_folder'],
                // Permissions should allow downloading if authorized
                'permission_callback' => [Permissions::class, 'check_api_permissions'],
            ]);
        }

        // Reorder folder
        register_rest_route($namespace, '/folders/reorder', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reorder_folder'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
            'args' => [
                'folder_id' => ['required' => true, 'type' => 'integer'],
                'target_parent_id' => ['required' => true, 'type' => 'integer'],
                'target_order_index' => ['required' => true, 'type' => 'integer'],
            ],
        ]);

        // Assign media
        register_rest_route($namespace, '/media/assign', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'assign_media'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
        ]);

        // Media counts
        register_rest_route($namespace, '/media/counts', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_folder_counts'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
            'args' => [
                'post_type' => ['required' => false, 'type' => 'string', 'default' => 'attachment'],
                'recursive' => ['required' => false, 'type' => 'integer', 'default' => 0],
            ],
        ]);

        // User state (active folder tracking)
        register_rest_route($namespace, '/user/active-folder', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_active_folder'],
            'permission_callback' => function () {
                return current_user_can('upload_files');
            },
            'args' => [
                'folder_id' => ['required' => true, 'type' => 'integer'],
            ],
        ]);

        // Replace media
        register_rest_route($namespace, '/media/(?P<id>\d+)/replace', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'replace_media'],
            'permission_callback' => [Permissions::class, 'check_api_permissions'],
        ]);
    }

    public function get_folders(\WP_REST_Request $request)
    {
        $post_type = $request->get_param('post_type') ?: 'attachment';
        $folders = $this->folder_manager->get_folders(['post_type' => sanitize_key($post_type)]);
        return rest_ensure_response($folders);
    }

    public function create_folder(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        // Also pick up post_type from query/body params
        if (empty($params['post_type'])) {
            $params['post_type'] = $request->get_param('post_type') ?: 'attachment';
        }
        $id = $this->folder_manager->create_folder($params);

        if (is_wp_error($id)) {
            return $id;
        }

        $folder = $this->folder_manager->get_folder($id);
        return rest_ensure_response($folder);
    }

    public function update_folder(\WP_REST_Request $request)
    {
        $id = $request->get_param('id');
        $params = $request->get_json_params();
        $result = $this->folder_manager->update_folder($id, $params);

        if (is_wp_error($result)) {
            return $result;
        }

        $folder = $this->folder_manager->get_folder($id);
        return rest_ensure_response($folder);
    }

    public function delete_folder(\WP_REST_Request $request)
    {
        $id = $request->get_param('id');
        $result = $this->folder_manager->delete_folder($id, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response(['deleted' => true], 200);
    }

    public function change_color(\WP_REST_Request $request)
    {
        if ( true ) {
            $id = $request->get_param('id');
            if (!Permissions::user_can_edit_folder($id)) {
                return new \WP_Error('rest_forbidden', __('Sorry, you are not allowed to edit this folder.', 'folderify'), ['status' => 403]);
            }
            $color = sanitize_text_field($request->get_param('color'));

            global $wpdb;
            $table_name = $wpdb->prefix . 'folderify_folders';
            $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                "{$table_name}",
                ['color' => $color],
                ['id' => $id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                return new \WP_Error('db_error', __('Could not update folder color', 'folderify'), ['status' => 500]);
            }

            return new \WP_REST_Response(['updated' => true], 200);
        }
    }

    public function duplicate_folder(\WP_REST_Request $request)
    {
        if ( true ) {
            $id = $request->get_param('id');
            $result = $this->folder_manager->duplicate_folder($id);

            if (is_wp_error($result)) {
                return $result;
            }

            return new \WP_REST_Response(['duplicated' => true, 'new_folder_id' => $result], 200);
        }
    }

    public function download_folder(\WP_REST_Request $request)
    {
        if ( true ) {
            global $wpdb;
            $id = $request->get_param('id');
            $id = absint($id);

            // Ensure folder exists and get its name
            $folder_table = $wpdb->prefix . 'folderify_folders';
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $folder_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM `{$folder_table}` WHERE id = %d", $id)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if (!$folder_name) {
                return new \WP_Error('not_found', __('Folder not found', 'folderify'), ['status' => 404]);
            }

            // Build full folder tree
            $all_folders = $wpdb->get_results("SELECT id, parent_id, name FROM `{$folder_table}`", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Build a lookup: folder_id => folder data
            $folder_map = [];
            foreach ($all_folders as $f) {
                $folder_map[(int) $f['id']] = $f;
            }

            // Recursively compute relative path inside ZIP for a given folder
            // Root folder (id=$id) maps to '' (zip root), children map to their name, grandchildren to parent/child, etc.
            $get_zip_path = function (int $folder_id) use (&$get_zip_path, $folder_map, $id): string {
                if ($folder_id === $id) {
                    return ''; // root folder = zip root
                }
                $folder = $folder_map[$folder_id] ?? null;
                if (!$folder) {
                    return '';
                }
                $parent_path = $get_zip_path((int) $folder['parent_id']);
                $name = sanitize_file_name($folder['name']);
                return $parent_path !== '' ? $parent_path . '/' . $name : $name;
            };

            // Collect all sub-folder IDs under the root
            $folder_ids_to_check = [$id];
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($all_folders as $f) {
                    if (in_array((int) $f['parent_id'], $folder_ids_to_check, true) && !in_array((int) $f['id'], $folder_ids_to_check, true)) {
                        $folder_ids_to_check[] = (int) $f['id'];
                        $changed = true;
                    }
                }
            }

            $relationships_table = $wpdb->prefix . 'folderify_relationships';

            // Build zip_entry_path => absolute_file_path for each file, per folder
            $files_to_zip = []; // [ 'zip/relative/path/filename.jpg' => '/abs/path/filename.jpg' ]
            foreach ($folder_ids_to_check as $folder_id) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_col($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
                    "SELECT object_id FROM `{$relationships_table}` WHERE folder_id = %d",
                    $folder_id
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if (empty($rows)) {
                    continue;
                }
                $zip_dir = $get_zip_path($folder_id); // e.g. '' or 'Subfolder' or 'Subfolder/Child'
                foreach ($rows as $att_id) {
                    $path = get_attached_file($att_id);
                    if ($path && file_exists($path)) {
                        $entry_name = ($zip_dir !== '' ? $zip_dir . '/' : '') . basename($path);
                        // Handle duplicate filenames in same directory
                        if (isset($files_to_zip[$entry_name])) {
                            $info = pathinfo($path);
                            $entry_name = ($zip_dir !== '' ? $zip_dir . '/' : '') . $info['filename'] . '_' . $att_id . '.' . ($info['extension'] ?? '');
                        }
                        $files_to_zip[$entry_name] = $path;
                    }
                }
            }

            if (empty($files_to_zip)) {
                return new \WP_Error('no_media', __('None of the media files physically exist on the server', 'folderify'), ['status' => 404]);
            }

            // Save ZIP to WP uploads directory so the browser can download it via a direct URL
            // (Like FileBird's ajaxDownloadFolderZip approach - cleaner than streaming binary via REST)
            $wp_dir = wp_upload_dir();
            $upload_path = $wp_dir['path'] . DIRECTORY_SEPARATOR;
            $zip_name = sanitize_file_name($folder_name) . '-' . uniqid() . '.zip';
            $zip_file = $upload_path . $zip_name;

            // Load ZipStream from own vendor if ZipArchive is missing
            if (!class_exists('ZipArchive') && !class_exists('\\ZipStream\\ZipStream')) {
                $zipstream_autoloaders = [
                    dirname(__DIR__) . '/vendor/autoload.php',
                    WP_PLUGIN_DIR . '/filebird-pro/vendor/autoload.php',
                    WP_PLUGIN_DIR . '/filebird/vendor/autoload.php',
                ];
                foreach ($zipstream_autoloaders as $loader) {
                    if (file_exists($loader)) {
                        require_once $loader;
                        break;
                    }
                }
            }

            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    return new \WP_Error('zip_failed', __('Failed to create ZIP archive', 'folderify'), ['status' => 500]);
                }
                foreach ($files_to_zip as $entry_name => $abs_path) {
                    $zip->addFile($abs_path, $entry_name);
                }
                $zip->close();
            } elseif (class_exists('\ZipStream\ZipStream')) {
                // Use ZipStream-PHP to write to a file stream
                try {
                    // ZipStream requires a native PHP stream handle; WP_Filesystem has no equivalent.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                    $stream = fopen($zip_file, 'wb');
                    if (!$stream) {
                        return new \WP_Error('zip_failed', __('Failed to open ZIP file for writing', 'folderify'), ['status' => 500]);
                    }

                    if (class_exists('\ZipStream\Option\Archive')) {
                        $options = new \ZipStream\Option\Archive();
                        $options->setOutputStream($stream);
                        $options->setSendHttpHeaders(false);
                        $options->setEnableZip64(false);
                        $zip = new \ZipStream\ZipStream($zip_name, $options);
                    } elseif (class_exists('\ZipStream\ArchiveOptions')) {
                        // For ZipStream v3
                        $options = new \ZipStream\ArchiveOptions();
                        $options->sendHttpHeaders = false;
                        $options->enableZip64 = false;
                        if (method_exists($options, 'setOutputStream')) {
                            $options->setOutputStream($stream);
                        }
                        $zip = new \ZipStream\ZipStream($zip_name, $options);
                        if (method_exists($zip, 'setOutputStream')) {
                            $zip->setOutputStream($stream);
                        }
                    } else {
                        // Fallback using positional arguments to avoid named parameter errors
                        $zip = new \ZipStream\ZipStream($zip_name);
                    }

                    foreach ($files_to_zip as $entry_name => $abs_path) {
                        if (file_exists($abs_path)) {
                            $zip->addFileFromPath($entry_name, $abs_path);
                        }
                    }
                    $zip->finish();
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the fopen() above for ZipStream.
                    fclose($stream);
                } catch (\Exception $e) {
                    return new \WP_Error('zipstream_fatal', __('Error creating ZIP: ', 'folderify') . $e->getMessage(), ['status' => 500]);
                }
            } else {
                return new \WP_Error('zip_missing', __('ZipArchive extension is missing on this server. Please enable it in php.ini.', 'folderify'), ['status' => 501]);
            }

            if (!file_exists($zip_file) || filesize($zip_file) === 0) {
                return new \WP_Error('zip_empty', __('ZIP archive was created but is empty or missing.', 'folderify'), ['status' => 500]);
            }

            // Save path for later cleanup (like FileBird does)
            $saved = get_option('folderify_pending_downloads', []);
            if (!is_array($saved)) {
                $saved = [];
            }
            $saved[time()] = $wp_dir['subdir'] . '/' . $zip_name;
            update_option('folderify_pending_downloads', $saved);

            // Return the download URL — browser will navigate to it and download the file natively
            $download_url = trailingslashit($wp_dir['url']) . $zip_name;

            return new \WP_REST_Response(['url' => $download_url], 200);
        }
    }

    public function reorder_folder(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();

        $folder_id = isset($params['folder_id']) ? $params['folder_id'] : 0;
        $target_parent_id = isset($params['target_parent_id']) ? $params['target_parent_id'] : 0;
        $target_order_index = isset($params['target_order_index']) ? $params['target_order_index'] : 0;

        $result = $this->folder_manager->reorder_folder($folder_id, $target_parent_id, $target_order_index);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['success' => true]);
    }

    public function assign_media(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $attachment_ids = isset($params['attachments']) ? $params['attachments'] : [];
        $folder_ids = isset($params['folders']) ? $params['folders'] : [];

        if (empty($attachment_ids)) {
            return new \WP_Error('invalid_params', __('No attachments provided.', 'folderify'));
        }

        $this->folder_manager->set_attachments_folders($attachment_ids, $folder_ids, false);

        return rest_ensure_response(['success' => true]);
    }

    public function get_folder_counts(\WP_REST_Request $request)
    {
        $post_type = sanitize_key($request->get_param('post_type') ?: 'attachment');
        return rest_ensure_response($this->folder_manager->get_folder_counts($post_type));
    }

    public function save_active_folder(\WP_REST_Request $request)
    {
        $folder_id = $request->get_param('folder_id');
        $user_id = get_current_user_id();

        if ($user_id) {
            $is_premium = true;
            if ($is_premium) {
                update_user_meta($user_id, 'folderify_active_folder', $folder_id);
            }
        }

        return rest_ensure_response(['success' => true]);
    }

    public function replace_media(\WP_REST_Request $request)
    {
        $attachment_id = $request->get_param('id');
        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return new \WP_Error('no_file', __('No file uploaded.', 'folderify'), ['status' => 400]);
        }

        $options = [
            'replace_type' => sanitize_text_field($request->get_param('replace_type') ?: 'replace'),
            'timestamp_replace' => sanitize_text_field($request->get_param('timestamp_replace') ?: 'keep'),
            'custom_date' => sanitize_text_field($request->get_param('custom_date') ?: '')
        ];

        $result = Media_Replacer::replace($attachment_id, $files['file'], $options);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(['success' => true]);
    }
}
