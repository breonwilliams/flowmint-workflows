<?php
/**
 * Class autoloader for FlowMint Workflows.
 *
 * Maps class names like FMW_Workflow_Executor → includes/Core/class-fmw-workflow-executor.php.
 *
 * Class naming convention:
 *   FMW_<Module>_<Name>           → includes/<Module>/class-fmw-<module>-<name>.php
 *   FMW_Step_<Type>               → includes/Steps/Core/class-step-<type>.php
 *
 * Step classes use a slightly different convention to keep file names short.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Autoloader {

    /**
     * Map of top-level prefixes to subdirectories under includes/.
     *
     * @var array
     */
    private static $namespace_map = [
        // Database — listed first so longest-prefix-match wins for Workflow_Repository.
        'FMW_Workflow_Repository' => 'Database',
        'FMW_Run_Repository'      => 'Database',
        'FMW_Run_Step_Repository' => 'Database',
        'FMW_Schema'              => 'Database',
        'FMW_Credential'          => 'Database',
        // Core
        'FMW_Workflow'       => 'Core',
        'FMW_Step_Registry'  => 'Core',
        'FMW_Step_Base'      => 'Core',
        'FMW_Step_Exception' => 'Core',
        'FMW_Interpolator'   => 'Core',
        'FMW_Expression'     => 'Core',
        'FMW_Logger'         => 'Core',
        'FMW_Submission'     => 'Core',
        // REST
        'FMW_REST'           => 'Connectors/REST',
        // MCP connector (Claude Desktop bridge — admin page + state class).
        // Asset file (.js) ships under Connectors/MCP/assets/ but is not
        // autoloaded; it's served via FMW_Connector_Admin::ajax_download_connector().
        'FMW_Connector'      => 'Connectors/MCP',
        // External service clients
        'FMW_Drive_Client'   => 'Connectors',
        'FMW_Email_Client'   => 'Connectors',
        'FMW_Printavo_Client' => 'Connectors',
        'FMW_Slack_Client'   => 'Connectors',
        'FMW_Http_Client'    => 'Connectors',
        // MCP
        'FMW_Mcp'            => 'Mcp',
        // Admin
        'FMW_Admin'          => 'Admin',
    ];

    /**
     * Register the autoloader with PHP.
     */
    public static function register() {
        spl_autoload_register( [ __CLASS__, 'load_class' ] );
    }

    /**
     * Load a class file by class name.
     *
     * @param string $class_name Fully-qualified class name.
     */
    public static function load_class( $class_name ) {
        if ( strpos( $class_name, 'FMW_' ) !== 0 ) {
            return;
        }

        // Step classes follow a custom convention:
        // FMW_Step_<TypeName> → includes/Steps/Core/class-step-<type-name>.php
        // FMW_Step_Drive_<Name> → includes/Steps/Drive/class-step-drive-<name>.php
        if ( preg_match( '/^FMW_Step_([A-Z][a-z0-9]+)_(.+)$/', $class_name, $matches ) ) {
            $category = $matches[1];
            $name     = $matches[2];

            // Convert CamelCase to kebab-case for the file name suffix
            $kebab_name = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name ) );
            $kebab_name = str_replace( '_', '-', $kebab_name );

            $file = FMW_PLUGIN_DIR . 'includes/Steps/' . $category . '/class-step-' . strtolower( $category ) . '-' . $kebab_name . '.php';

            if ( file_exists( $file ) ) {
                require_once $file;
                return;
            }
        }

        // Bare step types live in Core (e.g., FMW_Step_Set_Variable → includes/Steps/Core/class-step-set-variable.php).
        if ( preg_match( '/^FMW_Step_(.+)$/', $class_name, $matches ) ) {
            $name = $matches[1];
            $kebab_name = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name ) );
            $kebab_name = str_replace( '_', '-', $kebab_name );

            $file = FMW_PLUGIN_DIR . 'includes/Steps/Core/class-step-' . $kebab_name . '.php';

            if ( file_exists( $file ) ) {
                require_once $file;
                return;
            }
        }

        // Standard mapping: find longest matching prefix in namespace_map.
        $best_prefix = '';
        $best_subdir = null;
        foreach ( self::$namespace_map as $prefix => $subdir ) {
            if ( strpos( $class_name, $prefix ) === 0 && strlen( $prefix ) > strlen( $best_prefix ) ) {
                $best_prefix = $prefix;
                $best_subdir = $subdir;
            }
        }

        if ( $best_subdir === null ) {
            return;
        }

        // Convert FMW_Workflow_Executor → class-fmw-workflow-executor.php
        $file_name = strtolower( str_replace( '_', '-', $class_name ) );
        $file      = FMW_PLUGIN_DIR . 'includes/' . $best_subdir . '/class-' . $file_name . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
