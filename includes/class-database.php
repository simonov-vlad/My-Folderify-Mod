<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Database
 *
 * Handles database creation and updates.
 */
class Database
{

    /**
     * Plugin activation hook callback.
     */
    public static function activate()
    {
        self::create_tables();
        self::add_default_settings();
        Permissions::install_default_capabilities();
        set_transient('folderify_activated', 1, 30);
    }

    /**
     * Run on admin_init to upgrade DB schema incrementally.
     */
    public static function maybe_upgrade()
    {
        $current = get_option('folderify_schema_version', '0');
        if (version_compare($current, '1.1', '<')) {
            self::create_tables(); // dbDelta safely adds missing columns
            update_option('folderify_schema_version', '1.1');
        }

        $current = get_option('folderify_schema_version', '0');
        if (version_compare($current, '1.2', '<')) {
            global $wpdb;
            $table_relationships = $wpdb->prefix . 'folderify_relationships';

            // Rename attachment_id to object_id safely
            $row = $wpdb->get_results("SHOW COLUMNS FROM `{$table_relationships}` LIKE 'attachment_id'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if (!empty($row)) {
                $wpdb->query("ALTER TABLE `{$table_relationships}` CHANGE `attachment_id` `object_id` bigint(20) unsigned NOT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
                // Rename index (drop and re-add)
                $wpdb->query("ALTER TABLE `{$table_relationships}` DROP INDEX `attachment_folder`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query("ALTER TABLE `{$table_relationships}` ADD UNIQUE KEY `object_folder` (`object_id`, `folder_id`)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            }

            self::create_tables();
            update_option('folderify_schema_version', '1.2');
        }

        $current = get_option('folderify_schema_version', '0');
        if (version_compare($current, '1.3', '<')) {
            self::create_tables(); // Add owner_user_id column
            Permissions::install_default_capabilities();
            update_option('folderify_schema_version', '1.3');
        }

        $current = get_option('folderify_schema_version', '0');
        if (version_compare($current, '1.4', '<')) {
            self::create_tables(); // Add slug column (real directory name of the folder)
            update_option('folderify_schema_version', '1.4');
        }
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_folders = $wpdb->prefix . 'folderify_folders';
        $table_relationships = $wpdb->prefix . 'folderify_relationships';

        // Folders table
        $sql_folders = "CREATE TABLE $table_folders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			post_type varchar(50) NOT NULL DEFAULT 'attachment',
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL DEFAULT '',
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			color varchar(20) DEFAULT '',
			order_index int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY parent_id (parent_id),
			KEY user_id (user_id),
			KEY owner_user_id (owner_user_id),
			KEY post_type (post_type)
		) $charset_collate;";

        // Relationships table tying objects (posts/pages/media) to folders
        $sql_relationships = "CREATE TABLE $table_relationships (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			folder_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_folder (object_id, folder_id),
			KEY folder_id (folder_id)
		) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_folders);
        dbDelta($sql_relationships);

        update_option('folderify_db_version', FOLDERIFY_VERSION);
    }

    /**
     * Add default settings for the plugin.
     */
    private static function add_default_settings()
    {
        $default_settings = [
            'enable_multiple_folders' => 1,
            'show_breadcrumb' => 1,
            'delete_on_uninstall' => 0,
            'physical_folders' => 1,
            'admin_override' => 1,
            'folderify_folder_mode' => 'global',
            'folderify_private_allowed_roles' => ['administrator'],
            'folderify_global_create_roles' => ['administrator', 'editor', 'author'],
            'folderify_global_edit_roles' => ['administrator', 'editor'],
            'folderify_global_delete_roles' => ['administrator'],
        ];

        if (!get_option('folderify_settings')) {
            add_option('folderify_settings', $default_settings);
        }
    }

    /**
     * Cleanup data on uninstall.
     * Called by Freemius after_uninstall hook.
     */
    public static function uninstall_cleanup()
    {
        // Check if user has opted to delete data on uninstall.
        $options = get_option('folderify_settings', []);
        $delete_data = !empty($options['delete_on_uninstall']);

        if ($delete_data) {
            global $wpdb;

            // Drop custom tables.
            $table_folders = $wpdb->prefix . 'folderify_folders';
            $table_relationships = $wpdb->prefix . 'folderify_relationships';

            $wpdb->query("DROP TABLE IF EXISTS `{$table_folders}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("DROP TABLE IF EXISTS `{$table_relationships}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Delete main options.
            delete_option('folderify_settings');
            delete_option('folderify_db_version');
            delete_option('folderify_schema_version');

            // Delete all user meta set by the plugin.
            // Bulk cleanup during uninstall.
            $wpdb->delete($wpdb->usermeta, ['meta_key' => 'folderify_active_folder']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

            // Delete transients.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk cleanup of transients during uninstall.
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_folderify_%'");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_timeout_folderify_%'");
        }
    }
}
