<?php
/**
 * Step type registry.
 *
 * Singleton. Maintains a map of step type identifier → step class name.
 * Used by:
 *   - Workflow validator (verify referenced step types exist)
 *   - Workflow executor (instantiate step classes by type)
 *   - REST API (list available step types)
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Registry {

    /**
     * @var FMW_Step_Registry|null
     */
    private static $instance = null;

    /**
     * Map of type → class name.
     *
     * @var array<string,string>
     */
    private $registry = [];

    /**
     * Get the singleton instance.
     *
     * @return FMW_Step_Registry
     */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register a step class by its type() identifier.
     *
     * @param string $class_name Fully qualified step class name.
     * @return bool True on success, false if already registered or class doesn't exist.
     */
    public function register( $class_name ) {
        if ( ! class_exists( $class_name ) ) {
            return false;
        }

        if ( ! is_subclass_of( $class_name, 'FMW_Step_Base' ) ) {
            return false;
        }

        $type = call_user_func( [ $class_name, 'type' ] );

        if ( isset( $this->registry[ $type ] ) ) {
            return false; // Already registered.
        }

        $this->registry[ $type ] = $class_name;
        return true;
    }

    /**
     * Register all the v1 Core step types (control flow, logging, FormEngine).
     *
     * Called once during plugin init. Each step file is loaded by the
     * autoloader on first reference.
     */
    public function register_core_steps() {
        $core_steps = [
            'FMW_Step_Set_Variable',
            'FMW_Step_Conditional',
            'FMW_Step_Try_Catch',
            'FMW_Step_Delay',
            'FMW_Step_Log_Info',
            'FMW_Step_Log_Warning',
            'FMW_Step_Log_Error',
            'FMW_Step_Fre_Get_Entry',
            'FMW_Step_Fre_Get_File',
            'FMW_Step_Fre_Update_Entry_Status',
            'FMW_Step_Fre_Delete_Entry',
            // v0.6.0 — scheduled triggers: bulk query + bulk delete
            // for retention workflows and other batch operations.
            'FMW_Step_Fre_List_Entries',
            'FMW_Step_Fre_Delete_Entries',
        ];

        foreach ( $core_steps as $class ) {
            $this->register( $class );
        }
    }

    /**
     * Register the Phase 2 Google Drive step types.
     */
    public function register_drive_steps() {
        $drive_steps = [
            'FMW_Step_Drive_Find_Folder',
            'FMW_Step_Drive_Find_Or_Create_Folder',
            'FMW_Step_Drive_Create_Folder',
            'FMW_Step_Drive_Upload_File',
            'FMW_Step_Drive_Create_Text_File',
            'FMW_Step_Drive_Share_Link',
        ];

        foreach ( $drive_steps as $class ) {
            $this->register( $class );
        }
    }

    /**
     * Register the Phase 2 Email step types.
     */
    public function register_email_steps() {
        $email_steps = [
            'FMW_Step_Email_Send',
            'FMW_Step_Email_Send_Template',
        ];

        foreach ( $email_steps as $class ) {
            $this->register( $class );
        }
    }

    /**
     * Register the Phase 3 Printavo step types.
     */
    public function register_printavo_steps() {
        $printavo_steps = [
            'FMW_Step_Printavo_Find_Customer',
            'FMW_Step_Printavo_Create_Customer',
            'FMW_Step_Printavo_Find_Or_Create_Customer',
            'FMW_Step_Printavo_Create_Quote',
        ];

        foreach ( $printavo_steps as $class ) {
            $this->register( $class );
        }
    }

    /**
     * Register the Phase 3 HTTP step types.
     */
    public function register_http_steps() {
        $http_steps = [
            'FMW_Step_Http_Get',
            'FMW_Step_Http_Post',
            'FMW_Step_Http_Request',
        ];

        foreach ( $http_steps as $class ) {
            $this->register( $class );
        }
    }

    /**
     * Check if a step type is registered.
     *
     * @param string $type
     * @return bool
     */
    public function exists( $type ) {
        return isset( $this->registry[ $type ] );
    }

    /**
     * Get the class name for a step type.
     *
     * @param string $type
     * @return string|null
     */
    public function get_class( $type ) {
        return isset( $this->registry[ $type ] ) ? $this->registry[ $type ] : null;
    }

    /**
     * Get all registered types.
     *
     * @return array<string,string> map of type → class name
     */
    public function all() {
        return $this->registry;
    }

    /**
     * Get the registry as a list of step type metadata, suitable for the
     * REST step-types listing endpoint.
     *
     * @return array
     */
    public function describe_all() {
        $out = [];

        foreach ( $this->registry as $type => $class ) {
            $out[] = [
                'type'             => $type,
                'category'         => call_user_func( [ $class, 'category' ] ),
                'display_name'     => call_user_func( [ $class, 'display_name' ] ),
                'description'      => call_user_func( [ $class, 'description' ] ),
                'has_side_effects' => call_user_func( [ $class, 'has_side_effects' ] ),
                'config_schema'    => call_user_func( [ $class, 'config_schema' ] ),
                'output_schema'    => call_user_func( [ $class, 'output_schema' ] ),
            ];
        }

        return $out;
    }

    /**
     * Get one step type's metadata.
     *
     * @param string $type
     * @return array|null
     */
    public function describe( $type ) {
        if ( ! isset( $this->registry[ $type ] ) ) {
            return null;
        }

        $class = $this->registry[ $type ];

        return [
            'type'             => $type,
            'category'         => call_user_func( [ $class, 'category' ] ),
            'display_name'     => call_user_func( [ $class, 'display_name' ] ),
            'description'      => call_user_func( [ $class, 'description' ] ),
            'has_side_effects' => call_user_func( [ $class, 'has_side_effects' ] ),
            'config_schema'    => call_user_func( [ $class, 'config_schema' ] ),
            'output_schema'    => call_user_func( [ $class, 'output_schema' ] ),
        ];
    }

    /**
     * Reset the registry (for testing).
     */
    public function reset() {
        $this->registry = [];
    }
}
