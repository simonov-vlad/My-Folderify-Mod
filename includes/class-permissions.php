<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Permissions
 *
 * Handles capability checks.
 */
class Permissions
{
    /**
     * Install default capabilities on plugin activation.
     */
    public static function install_default_capabilities()
    {
        $roles = [
            'administrator' => ['folderify_create_folder', 'folderify_edit_folder', 'folderify_delete_folder', 'folderify_manage_all_folders'],
            'editor' => ['folderify_create_folder', 'folderify_edit_folder'],
            'author' => ['folderify_create_folder'],
        ];

        foreach ($roles as $role_name => $caps) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Sync role capabilities dynamically when settings are saved.
     *
     * @param array $settings
     */
    public static function sync_role_capabilities($settings)
    {
        $mode = $settings['folderify_folder_mode'] ?? 'global';
        $all_roles = wp_roles()->roles;

        if ($mode === 'private') {
            $allowed_to_create = $settings['folderify_private_allowed_roles'] ?? [];
            // In private mode:
            // "Manage all folders" stays with administrator.
            // "Edit/Delete" is derived by folder ownership logic, so global capabilities for edit/delete aren't strictly required
            // but we'll apply "create" to those who are allowed to create.
            foreach ($all_roles as $role_name => $role_data) {
                $role = get_role($role_name);
                if (!$role)
                    continue;

                if (in_array($role_name, $allowed_to_create, true) || $role_name === 'administrator') {
                    $role->add_cap('folderify_create_folder');
                } else {
                    $role->remove_cap('folderify_create_folder');
                }

                // Keep manage all folders for admins
                if ($role_name === 'administrator') {
                    $role->add_cap('folderify_manage_all_folders');
                    $role->add_cap('folderify_edit_folder');
                    $role->add_cap('folderify_delete_folder');
                } else {
                    $role->remove_cap('folderify_manage_all_folders');
                    $role->remove_cap('folderify_edit_folder');
                    $role->remove_cap('folderify_delete_folder');
                }
            }
        } else {
            // Global Mode
            $create_roles = $settings['folderify_global_create_roles'] ?? [];
            $edit_roles = $settings['folderify_global_edit_roles'] ?? [];
            $delete_roles = $settings['folderify_global_delete_roles'] ?? [];

            foreach ($all_roles as $role_name => $role_data) {
                $role = get_role($role_name);
                if (!$role)
                    continue;

                $admin = ($role_name === 'administrator');

                if ($admin || in_array($role_name, $create_roles, true)) {
                    $role->add_cap('folderify_create_folder');
                } else {
                    $role->remove_cap('folderify_create_folder');
                }

                if ($admin || in_array($role_name, $edit_roles, true)) {
                    $role->add_cap('folderify_edit_folder');
                } else {
                    $role->remove_cap('folderify_edit_folder');
                }

                if ($admin || in_array($role_name, $delete_roles, true)) {
                    $role->add_cap('folderify_delete_folder');
                } else {
                    $role->remove_cap('folderify_delete_folder');
                }

                if ($admin) {
                    $role->add_cap('folderify_manage_all_folders');
                } else {
                    $role->remove_cap('folderify_manage_all_folders');
                }
            }
        }
    }

    /**
     * Check if current user can view folders.
     *
     * @return bool
     */
    public static function can_view_folders()
    {
        if (current_user_can('administrator') || current_user_can('folderify_manage_all_folders')) {
            return true;
        }

        // If they have at least one folder capability, they can view the sidebar
        if (
            current_user_can('folderify_create_folder') ||
            current_user_can('folderify_edit_folder') ||
            current_user_can('folderify_delete_folder')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if a user can edit a specific folder
     *
     * @param int $folder_id
     * @return bool
     */
    public static function user_can_edit_folder($folder_id)
    {
        if (current_user_can('folderify_manage_all_folders')) {
            return true;
        }

        $settings = get_option('folderify_settings', []);
        $mode = $settings['folderify_folder_mode'] ?? 'global';
        // Premium active


        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';
        $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %d", $folder_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ($mode === 'private') {
            return $user_id !== null && (int) $user_id === (int) get_current_user_id();
        }

        // Global mode
        if ($user_id !== null && (int) $user_id !== 0) {
            return false; // This is a private folder, but we are in global mode
        }

        return current_user_can('folderify_edit_folder');
    }

    /**
     * Check if a user can delete a specific folder
     *
     * @param int $folder_id
     * @return bool
     */
    public static function user_can_delete_folder($folder_id)
    {
        if (current_user_can('folderify_manage_all_folders')) {
            return true;
        }

        $settings = get_option('folderify_settings', []);
        $mode = $settings['folderify_folder_mode'] ?? 'global';
        // Premium active


        global $wpdb;
        $table = $wpdb->prefix . 'folderify_folders';
        $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %d", $folder_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ($mode === 'private') {
            return $user_id !== null && (int) $user_id === (int) get_current_user_id();
        }

        // Global mode
        if ($user_id !== null && (int) $user_id !== 0) {
            return false; // This is a private folder, but we are in global mode
        }

        return current_user_can('folderify_delete_folder');
    }

    /**
     * Legacy generic check for REST/AJAX.
     * You should prefer specific capability checks instead.
     *
     * @return bool|\WP_Error
     */
    public static function check_api_permissions()
    {
        if (!current_user_can('folderify_create_folder') && !current_user_can('folderify_edit_folder') && !current_user_can('folderify_delete_folder')) {
            return new \WP_Error(
                'rest_forbidden',
                __('Sorry, you are not allowed to do that.', 'folderify'),
                ['status' => rest_authorization_required_code()]
            );
        }
        return true;
    }
}
