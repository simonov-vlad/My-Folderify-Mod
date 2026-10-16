<?php
/**
 * Procedural helper functions for Folderify.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('folderify_get_setting')) {
    /**
     * Get a specific setting from the plugin options.
     *
     * @param string $key     The setting key.
     * @param mixed  $default Default value if not set.
     * @return mixed
     */
    function folderify_get_setting($key, $default = null)
    {
        $options = get_option('folderify_settings', []);
        return isset($options[$key]) ? $options[$key] : $default;
    }
}

if (!function_exists('folderify_sanitize_folder_name')) {
    /**
     * Sanitize a folder name.
     *
     * @param string $name
     * @return string
     */
    function folderify_sanitize_folder_name($name)
    {
        return sanitize_text_field(wp_unslash($name));
    }
}
