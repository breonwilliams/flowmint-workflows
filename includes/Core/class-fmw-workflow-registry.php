<?php
/**
 * Workflow registry.
 *
 * Thin wrapper over FMW_Workflow_Repository that returns FMW_Workflow value
 * objects instead of raw arrays. Used by the submission listener and executor.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Registry {

    /**
     * Get a workflow by ID.
     *
     * @param string $id
     * @return FMW_Workflow|null
     */
    public function get( $id ) {
        $row = FMW_Workflow_Repository::get( $id );
        return $row ? new FMW_Workflow( $row ) : null;
    }

    /**
     * Get the workflow registered for a given form_id (first enabled match).
     *
     * @param string $form_id
     * @return FMW_Workflow|null
     */
    public function get_for_form( $form_id ) {
        $row = FMW_Workflow_Repository::get_for_form( $form_id );
        return $row ? new FMW_Workflow( $row ) : null;
    }

    /**
     * Check if a workflow is registered for a given form_id.
     *
     * @param string $form_id
     * @return bool
     */
    public function has_workflow_for_form( $form_id ) {
        return $this->get_for_form( $form_id ) !== null;
    }
}
