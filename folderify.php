<?php
/**
 * Plugin Name: Folderify - Media Library Folders
 * Plugin URI:  https://mo.oppoyo.com
 * Description: Organize your WordPress media library with unlimited folders.
 * Version:     2.0
 * Author:      Eric Nelson
 * Author URI:  https://oppoyo.com
 * Text Domain: folderify
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Tested up to: 7.0
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace Folderify;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
// Define plugin constants.
define('FOLDERIFY_VERSION', '2.0');
define('FOLDERIFY_FILE', __FILE__);
define('FOLDERIFY_DIR', plugin_dir_path(__FILE__));
define('FOLDERIFY_URL', plugin_dir_url(__FILE__));
define('FOLDERIFY_BASENAME', plugin_basename(__FILE__));

// Require procedural helpers.
require_once FOLDERIFY_DIR . 'includes/helpers.php';

// Spl_autoload_register for the plugin classes.
spl_autoload_register(function ($class) {
    // Project-specific namespace prefix.
    $prefix = 'Folderify\\';

    // Base directory for the namespace prefix.
    $base_dir = FOLDERIFY_DIR . 'includes/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name.
    $relative_class = substr($class, $len);

    // Replace naming convention for WordPress.
    // E.g., Folderify\Folder_Manager -> class-folder-manager.php
    $class_file = strtolower(str_replace('_', '-', $relative_class));

    // Replace namespace separators with directory separators.
    $class_file = str_replace('\\', DIRECTORY_SEPARATOR, $class_file);

    $file = $base_dir . 'class-' . $class_file . '.php';

    // If the file exists, require it.
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Initialize the plugin.
 */
function run()
{
    Plugin::get_instance()->init();
}
// Initialize after plugins are loaded.
add_action('plugins_loaded', __NAMESPACE__ . '\\run');

/**
 * Activation hook.
 */
register_activation_hook(__FILE__, [__NAMESPACE__ . '\\Database', 'activate']);
