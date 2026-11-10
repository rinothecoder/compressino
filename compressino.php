<?php
/**
 * Plugin Name: Compressino
 * Plugin URI: https://github.com/rinothecoder/compressino
 * Description: Compress images to WebP at upload, in the browser and on the server.
 * Version: 1.5.0
 * Author: Rino de Boer
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: compressino
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/rinothecoder/compressino/
 */

defined( 'ABSPATH' ) || exit;

define( 'COMPRESSINO_VERSION', '1.5.0' );
define( 'COMPRESSINO_FILE', __FILE__ );
define( 'COMPRESSINO_DIR', plugin_dir_path( __FILE__ ) );
define( 'COMPRESSINO_URL', plugin_dir_url( __FILE__ ) );

require_once COMPRESSINO_DIR . 'includes/class-compressino.php';
require_once COMPRESSINO_DIR . 'includes/class-compressino-links.php';
require_once COMPRESSINO_DIR . 'includes/class-compressino-admin.php';
require_once COMPRESSINO_DIR . 'includes/class-compressino-updates.php';
require_once COMPRESSINO_DIR . 'includes/abilities.php';

register_activation_hook( __FILE__, array( 'Compressino', 'activate' ) );

Compressino::init();
Compressino_Links::init();
Compressino_Admin::init();
Compressino_Abilities::init();
add_action( 'plugins_loaded', array( 'Compressino_Updates', 'boot' ) );
