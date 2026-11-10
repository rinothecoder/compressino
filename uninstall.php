<?php
/**
 * Compressino uninstall.
 *
 * Settings, the compression flags on each image and the backed-up originals in
 * wp-content/compressino-backups are kept on purpose, so installing Compressino
 * again picks up where it left off and every original can still be restored.
 *
 * @package Compressino
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'compressino_github_public_release' );
