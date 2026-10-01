<?php
/**
 * WP-CLI command namespace.
 *
 * @package Profotograaf
 */

namespace Profotograaf\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Profotograaf leads and shows its status.
 *
 * Holds no commands of its own. WP-CLI needs a parent for the subcommands
 * `status` and `leads`.
 */
final class Command_Namespace {
}
