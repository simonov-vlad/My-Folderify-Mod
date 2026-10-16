<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Media_Replacer
 * 
 * Handles the logic of replacing a media file while keeping the same attachment ID.
 */
class Media_Replacer
{
    /**
     * Replace a media attachment with a new file.
     *
     * @param int    $attachment_id The ID of the attachment to replace.
     * @param array  $file_data     The $_FILES entry for the new file.
     * @param array  $options       Optional configuration:
     *                              - 'replace_type': 'replace' (keep name) or 'replace_and_search' (new name)
     *                              - 'timestamp_replace': 'keep', 'current', or 'custom'
     *                              - 'custom_date': Date string for 'custom' mode
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    /**
     * Sanitizes a path string by removing null bytes and non-printable characters.
     */
    private static function sanitize_path($path)
    {
        if (!is_string($path)) return $path;
        // Remove null bytes and any other control characters except standard slashes
        return preg_replace('/[\x00-\x1F\x7F]/', '', $path);
    }

    public static function replace($attachment_id, $file_data, $options = [])
    {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new \WP_Error('invalid_attachment', __('Invalid attachment ID.', 'folderify'));
        }

        $options = wp_parse_args($options, [
            'replace_type' => 'replace',
            'timestamp_replace' => 'keep',
            'custom_date' => ''
        ]);

        $uploads = wp_upload_dir();
        if ($uploads['error']) {
            return new \WP_Error('upload_dir_error', $uploads['error']);
        }

        // 1. Get current data
        // Trust get_attached_file first, as it's the standard WP way
        $old_path = wp_normalize_path(get_attached_file($attachment_id));
        $old_url = wp_get_attachment_url($attachment_id);
        $old_meta = wp_get_attachment_metadata($attachment_id);
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        // CLEANUP: Extremely robust fix for absolute paths in database (prevents doubling bug on Windows)
        $basedir = wp_normalize_path($uploads['basedir']);
        $meta_path = get_post_meta($attachment_id, '_wp_attached_file', true);
        
        if ($meta_path) {
            $normalized_meta = wp_normalize_path($meta_path);
            if (strpos($normalized_meta, $basedir) === 0) {
                // It IS absolute! Strip the basedir and save it back as relative
                $clean_relative = ltrim(str_replace($basedir, '', $normalized_meta), '/');
                update_post_meta($attachment_id, '_wp_attached_file', $clean_relative);
                
                // RE-FETCH the absolute path after fixing the meta
                $old_path = wp_normalize_path(get_attached_file($attachment_id));
            }
        }

        // 2. Handle the upload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form' => false, 'action' => 'folderify_replace_media'];
        $upload = wp_handle_upload($file_data, $overrides);

        if (isset($upload['error'])) {
            return new \WP_Error('upload_error', $upload['error']);
        }

        $temp_path = wp_normalize_path($upload['file']);
        $new_url = $upload['url'];
        $new_type = $upload['type'];

        // 3. Determine target path and move file
        $mode = $options['replace_type'];
        $target_path = ($mode === 'replace_and_search') ? $temp_path : $old_path;

        if ($mode === 'replace') {
            if ($temp_path !== $old_path) {
                // Delete all old files (main and thumbnails) before moving new one
                if (function_exists('wp_delete_attachment_files')) {
                    wp_delete_attachment_files($attachment_id, $old_meta, $backup_sizes, $old_path);
                } else {
                    // Fallback for older WP versions
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($old_path);
                }

                // Native move
                $native_temp = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $temp_path);
                $native_target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target_path);

                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                if (!@rename($native_temp, $native_target)) {
                    if (@copy($native_temp, $native_target)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        @unlink($native_temp);
                    } else {
                        $error = error_get_last();
                        /* translators: %s: PHP error message */
                        return new \WP_Error('move_failed', sprintf(__('Failed to move new file. Error: %s', 'folderify'), $error['message']));
                    }
                }
                $new_url = $old_url; 
            }
        }

        // 4. Update Database
        $update_data = [
            'ID' => $attachment_id,
            'post_mime_type' => $new_type,
        ];

        if ($mode === 'replace_and_search') {
            $file_info = pathinfo($target_path);
            $update_data['post_title'] = $file_info['filename'];
            $update_data['post_name'] = sanitize_title($file_info['filename']);
            
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($wpdb->posts, ['guid' => $new_url], ['ID' => $attachment_id]);
            
            self::update_database_references($old_url, $new_url, $old_meta);
        }

        $current_time = current_time('mysql');
        $current_time_gmt = current_time('mysql', 1);

        if ($options['timestamp_replace'] === 'current') {
            $update_data['post_date'] = $current_time;
            $update_data['post_date_gmt'] = $current_time_gmt;
        } elseif ($options['timestamp_replace'] === 'custom' && !empty($options['custom_date'])) {
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $custom_date = date('Y-m-d H:i:s', strtotime($options['custom_date']));
            $update_data['post_date'] = $custom_date;
            $update_data['post_date_gmt'] = get_gmt_from_date($custom_date);
        }

        $update_data['post_modified'] = $current_time;
        $update_data['post_modified_gmt'] = $current_time_gmt;

        wp_update_post($update_data);

        update_attached_file($attachment_id, $target_path);

        // 5. Regenerate metadata and thumbnails
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $target_path);
        
        wp_update_attachment_metadata($attachment_id, $metadata);

        // 6. Force Cache Invalidation
        clean_post_cache($attachment_id);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        return true;
    }

    /**
     * Update all database references from old URL to new URL.
     */
    private static function update_database_references($old_url, $new_url, $old_meta)
    {
        global $wpdb;

        // 1. Basic URL replacement
        $queries = [
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key != '_wp_attached_file' AND meta_key != '_wp_attachment_metadata'"
        ];

        foreach ($queries as $query) {
            $wpdb->query($wpdb->prepare($query, $old_url, $new_url)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        }

        // 2. Thumbnail URLs replacement
        if (!empty($old_meta['sizes'])) {
            $old_base_url = dirname($old_url);
            $new_base_url = dirname($new_url);

            $old_info = pathinfo($old_url);
            $new_info = pathinfo($new_url);

            $old_base_name = $old_info['filename'];
            $new_base_name = $new_info['filename'];

            $old_ext = isset($old_info['extension']) ? '.' . $old_info['extension'] : '';
            $new_ext = isset($new_info['extension']) ? '.' . $new_info['extension'] : '';

            foreach ($old_meta['sizes'] as $size => $info) {
                // Old thumbnail URL
                $old_thumb_url = $old_base_url . '/' . $info['file'];

                // Try to construct the new thumbnail URL
                // The thumbnail file name is usually: base-name + '-' + WIDTH + 'x' + HEIGHT + '.' + ext
                // But it's safer to just replace the base name and extension parts in the old thumbnail filename
                $new_thumb_filename = $info['file'];

                // Replace base name
                if ($old_base_name !== $new_base_name) {
                    $new_thumb_filename = preg_replace('/^' . preg_quote($old_base_name, '/') . '/', $new_base_name, $new_thumb_filename);
                }

                // Replace extension
                if ($old_ext !== $new_ext) {
                    $new_thumb_filename = preg_replace('/' . preg_quote($old_ext, '/') . '$/', $new_ext, $new_thumb_filename);
                }

                $new_thumb_url = $new_base_url . '/' . $new_thumb_filename;

                if ($old_thumb_url !== $new_thumb_url) {
                    foreach ($queries as $query) {
                        $wpdb->query($wpdb->prepare($query, $old_thumb_url, $new_thumb_url)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
                    }
                }
            }
        }
    }

    /**
     * Delete files associated with an attachment without deleting the attachment itself.
     * 
     * @param int    $attachment_id     The attachment ID.
     * @param bool   $delete_main_file  Whether to delete the main attached file.
     * @param string $exclude_path     An optional path to exclude from deletion (e.g. if it matches the new file).
     */
    private static function delete_attachment_files($attachment_id, $delete_main_file = true, $exclude_path = '')
    {
        $file = get_attached_file($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);

        if (!empty($meta['sizes'])) {
            $path = dirname($file);
            foreach ($meta['sizes'] as $size => $size_info) {
                $intermediate_file = $path . '/' . $size_info['file'];
                if (file_exists($intermediate_file) && $intermediate_file !== $exclude_path) {
                    wp_delete_file($intermediate_file);
                }
            }
        }

        if (!empty($backup_sizes)) {
            $path = dirname($file);
            foreach ($backup_sizes as $size => $size_info) {
                $backup_file = $path . '/' . $size_info['file'];
                if (file_exists($backup_file) && $backup_file !== $exclude_path) {
                    wp_delete_file($backup_file);
                }
            }
        }

        if ($delete_main_file && file_exists($file) && $file !== $exclude_path) {
            wp_delete_file($file);
        }
    }
}
