<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Plugin
 *
 * Singleton class to bootstrap the plugin.
 */
class Plugin
{

    /**
     * @var Plugin
     */
    private static $instance = null;

    /**
     * @var Database
     */
    public $database;

    /**
     * @var Folder_Manager
     */
    public $folder_manager;

    /**
     * @var Settings
     */
    public $settings;

    /**
     * @var Physical_Storage
     */
    public $physical_storage;

    /**
     * Get the instance.
     *
     * @return Plugin
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct()
    {
    }

    /**
     * Initialize all modules.
     */
    public function init()
    {
        $this->load_dependencies();
        $this->define_hooks();
    }

    /**
     * Load necessary classes to be instantiated.
     */
    private function load_dependencies()
    {
        // Instantiate instances
        $this->database = new Database();

        // Mirrors folders as real directories inside wp-content/uploads.
        $this->physical_storage = new Physical_Storage();

        $this->folder_manager = new Folder_Manager();

        // Scan uploads and sync with the folder tree (admin AJAX).
        new Uploads_Sync($this->folder_manager, $this->physical_storage);

        // APIs
        new REST($this->folder_manager);
        new AJAX($this->folder_manager);

        // Interfaces
        new Settings();
        new Import_Export($this->folder_manager);

        // Assets
        new Assets();

        // Query Hooks (Filtering Media Library)
        new Query();

        // Admin Media Hooks
        if (is_admin()) {
            new Media_Hooks();
            new Media_Replace_Page();
        }
    }

    /**
     * Define base hooks for the plugin.
     */
    private function define_hooks()
    {
        // Define localization hook.
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        // Incrementally upgrade DB schema (e.g. adds user_id column).
        add_action('admin_init', ['Folderify\\Database', 'maybe_upgrade']);
    }

    /**
     * Load the plugin text domain for translation.
     */
    public function load_textdomain()
    {
        // WordPress.org handles translations automatically.
    }
}
