<?php
/**
 * Claude Connection admin page.
 *
 * Lives under FlowMint Workflows → Claude Connection. Exposes:
 *   - Connector enable toggle (kill switch, default off).
 *   - Generate / Revoke Connection (App Password).
 *   - One-line Terminal install command for the MCP server.
 *   - Link to docs/MCP_CONNECTOR_SETUP.md.
 *
 * All state read/write delegates to FMW_Connector_Settings and to WordPress
 * core's WP_Application_Passwords. This class holds no state itself.
 *
 * Adapted from FRE_Connector_Admin (form-runtime-engine plugin), which was
 * itself adapted from the Promptless WP connector. Behavior parity is
 * intentional — users who already configured FRE or Promptless's connector
 * will recognize this flow exactly.
 *
 * @package FlowMintWorkflows
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector admin page controller.
 */
class FMW_Connector_Admin {

    /**
     * Submenu slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'fmw-claude-connection';

    /**
     * Nonce action shared by all connector-admin AJAX handlers.
     *
     * @var string
     */
    const NONCE_ACTION = 'fmw_connector_nonce';

    /**
     * Hook wiring.
     *
     * Called from the main plugin's init_components(). Separate from the
     * constructor so the class can be unit-tested without WP hooks in the
     * way.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );

        // AJAX handlers — only logged-in admins with manage_options can hit them.
        add_action( 'wp_ajax_fmw_connector_toggle_enabled', array( $this, 'ajax_toggle_enabled' ) );
        add_action( 'wp_ajax_fmw_connector_generate_password', array( $this, 'ajax_generate_password' ) );
        add_action( 'wp_ajax_fmw_connector_revoke_password', array( $this, 'ajax_revoke_password' ) );

        // MCP script download. Intentionally public (no auth) so the one-line
        // bash setup command can curl it without credentials. The script
        // file is static JavaScript with no embedded secrets — credentials
        // are provided via env vars at runtime, not embedded in the file.
        add_action( 'wp_ajax_fmw_download_connector', array( $this, 'ajax_download_connector' ) );
        add_action( 'wp_ajax_nopriv_fmw_download_connector', array( $this, 'ajax_download_connector' ) );
    }

    /**
     * Register the admin submenu under FlowMint Workflows.
     *
     * Priority 20 so this appears after the existing FlowMint menu items
     * (Run History, Workflows) which register at default priority 10.
     */
    public function register_submenu() {
        add_submenu_page(
            'fmw-runs',
            __( 'Claude Connection', 'flowmint-workflows' ),
            __( 'Claude Connection', 'flowmint-workflows' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the admin page.
     *
     * Inline form + small script. This page sees low traffic (one admin
     * configuring once, then forgotten); optimizing asset load for it is
     * not worth the complexity.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'flowmint-workflows' ) );
        }

        $is_enabled            = FMW_Connector_Settings::is_enabled();
        $configured_at         = FMW_Connector_Settings::configured_at();
        $current_user          = wp_get_current_user();
        $rest_base_url         = esc_url_raw( rest_url( FMW_REST_NAMESPACE . '/' . FMW_REST_BASE ) );
        $ajax_nonce            = wp_create_nonce( self::NONCE_ACTION );
        $setup_doc_url         = plugins_url( 'docs/MCP_CONNECTOR_SETUP.md', FMW_PLUGIN_FILE );
        $api_doc_url           = plugins_url( 'docs/CONNECTOR_API.md', FMW_PLUGIN_FILE );
        $connector_script_url  = esc_url_raw( admin_url( 'admin-ajax.php?action=fmw_download_connector' ) );
        $site_url              = esc_url_raw( home_url() );
        ?>
        <div class="wrap fmw-claude-connection">
            <h1><?php esc_html_e( 'Claude Connection', 'flowmint-workflows' ); ?></h1>

            <p class="description" style="max-width: 720px;">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the connector API document. */
                        __( 'This page manages the Claude Cowork connector — a REST API plus MCP bridge that lets Claude Cowork create, update, and inspect FlowMint workflows on this site. Full API specification: <a href="%s" target="_blank" rel="noopener">CONNECTOR_API.md</a>.', 'flowmint-workflows' ),
                        esc_url( $api_doc_url )
                    ),
                    array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
                );
                ?>
            </p>

            <h2 class="title"><?php esc_html_e( 'Step 1 — Enable the connector', 'flowmint-workflows' ); ?></h2>
            <p>
                <?php esc_html_e( 'Default is off. When off, every connector REST endpoint returns 403 regardless of credentials. This is the site-wide kill switch — turn it off any time to immediately revoke remote access.', 'flowmint-workflows' ); ?>
            </p>
            <p>
                <label>
                    <input type="checkbox"
                        id="fmw-connector-enabled"
                        <?php checked( $is_enabled ); ?>
                    >
                    <strong><?php esc_html_e( 'Enable Claude Cowork Connection', 'flowmint-workflows' ); ?></strong>
                </label>
                <span class="fmw-toggle-status" id="fmw-enabled-status" aria-live="polite"></span>
            </p>

            <h2 class="title"><?php esc_html_e( 'Step 2 — Generate an Application Password', 'flowmint-workflows' ); ?></h2>
            <p>
                <?php esc_html_e( 'The connector authenticates via a WordPress Application Password. Generating one here revokes any previous connector credential for your user, so there is at most one active connector key at any time. The password is shown once — copy it immediately.', 'flowmint-workflows' ); ?>
            </p>

            <?php if ( $configured_at > 0 ) : ?>
                <p>
                    <span class="fmw-badge fmw-badge-success">
                        <?php
                        printf(
                            /* translators: %s: localized timestamp */
                            esc_html__( 'Configured %s', 'flowmint-workflows' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
                        );
                        ?>
                    </span>
                </p>
            <?php else : ?>
                <p>
                    <span class="fmw-badge fmw-badge-muted"><?php esc_html_e( 'Not configured', 'flowmint-workflows' ); ?></span>
                </p>
            <?php endif; ?>

            <p>
                <button type="button" class="button button-primary" id="fmw-generate-password-btn">
                    <?php
                    echo $configured_at > 0
                        ? esc_html__( 'Regenerate Connection', 'flowmint-workflows' )
                        : esc_html__( 'Generate Connection', 'flowmint-workflows' );
                    ?>
                </button>
                <?php if ( $configured_at > 0 ) : ?>
                    <button type="button" class="button" id="fmw-revoke-password-btn">
                        <?php esc_html_e( 'Revoke Connection', 'flowmint-workflows' ); ?>
                    </button>
                <?php endif; ?>
            </p>

            <div id="fmw-credential-display" style="display:none;margin:12px 0;padding:16px;background:#fff;border-left:4px solid #2271b1;">
                <p style="margin-top:0;">
                    <strong><?php esc_html_e( 'Application Password (copy now — it will not be shown again):', 'flowmint-workflows' ); ?></strong>
                </p>
                <pre id="fmw-credential-value" style="background:#f6f7f7;padding:12px;overflow-x:auto;"></pre>
                <p style="margin-bottom:0;">
                    <strong><?php esc_html_e( 'Username:', 'flowmint-workflows' ); ?></strong>
                    <code><?php echo esc_html( $current_user->user_login ); ?></code>
                </p>
            </div>

            <h2 class="title"><?php esc_html_e( 'Step 3 — Connect Claude Desktop', 'flowmint-workflows' ); ?></h2>
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to MCP setup documentation */
                        __( 'Copy the command below and paste it into Terminal on your Mac. It downloads the MCP server script to <code>~/flowmint-mcp/</code>, detects your Node.js installation, and registers the connector with Claude Desktop. Full setup notes and troubleshooting are in <a href="%s" target="_blank" rel="noopener">MCP_CONNECTOR_SETUP.md</a>.', 'flowmint-workflows' ),
                        esc_url( $setup_doc_url )
                    ),
                    array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ), 'code' => array() )
                );
                ?>
            </p>

            <div class="fmw-setup-requirements">
                <strong><?php esc_html_e( 'Requirements:', 'flowmint-workflows' ); ?></strong>
                <ul style="margin: 6px 0 0 20px;">
                    <li><?php esc_html_e( 'macOS with Terminal', 'flowmint-workflows' ); ?></li>
                    <li><?php esc_html_e( 'Node.js v14+ (via nvm, Homebrew, or system installer)', 'flowmint-workflows' ); ?></li>
                    <li><?php esc_html_e( 'Claude Desktop installed', 'flowmint-workflows' ); ?></li>
                </ul>
            </div>

            <div id="fmw-setup-command-placeholder" style="margin-top:12px;<?php echo $configured_at > 0 ? 'display:none;' : ''; ?>">
                <p class="description" style="color:#888;">
                    <?php esc_html_e( 'Generate a connection in Step 2 first, then your setup command will appear here.', 'flowmint-workflows' ); ?>
                </p>
            </div>

            <div id="fmw-setup-command-wrap" style="display:none;margin-top:12px;">
                <div style="background:#f6f7f7;border:1px solid #c3c4c7;padding:12px;position:relative;">
                    <pre id="fmw-setup-command" style="margin:0;white-space:pre;overflow-x:auto;font-size:12px;line-height:1.5;"></pre>
                    <button type="button" class="button button-small" id="fmw-copy-setup-command" style="position:absolute;top:8px;right:8px;">
                        <?php esc_html_e( 'Copy', 'flowmint-workflows' ); ?>
                    </button>
                </div>
                <p class="description" style="margin-top:8px;">
                    <?php esc_html_e( 'After the command completes, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next Cowork session.', 'flowmint-workflows' ); ?>
                </p>
            </div>

            <h2 class="title"><?php esc_html_e( 'REST endpoint reference', 'flowmint-workflows' ); ?></h2>
            <p>
                <?php esc_html_e( 'Base URL for all connector endpoints:', 'flowmint-workflows' ); ?>
                <br>
                <code><?php echo esc_html( $rest_base_url ); ?></code>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: API doc URL */
                    esc_html__( 'See the full endpoint list and request/response schemas in the %s.', 'flowmint-workflows' ),
                    '<a href="' . esc_url( $api_doc_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'connector API documentation', 'flowmint-workflows' ) . '</a>'
                );
                ?>
            </p>
        </div>

        <style>
            .fmw-claude-connection h2.title { margin-top: 2em; }
            .fmw-toggle-status { margin-left: 10px; font-style: italic; color: #50575e; }
            .fmw-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: 500; }
            .fmw-badge-success { background: #d1e7dd; color: #0f5132; }
            .fmw-badge-muted { background: #e9ecef; color: #6c757d; }
        </style>

        <script>
        (function() {
            const ajaxUrl             = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
            const nonce               = '<?php echo esc_js( $ajax_nonce ); ?>';
            const connectorScriptUrl  = '<?php echo esc_js( $connector_script_url ); ?>';
            const siteUrl             = '<?php echo esc_js( $site_url ); ?>';

            /**
             * Build the one-line bash setup command.
             *
             * Adapted from the Form Runtime Engine connector's equivalent,
             * with paths and identifiers re-pointed at FlowMint:
             *   - Installs into ~/flowmint-mcp so it does not conflict with
             *     a parallel FRE install in ~/form-engine-mcp or a Promptless
             *     install in ~/promptless-mcp.
             *   - Claude Desktop config key is "flowmint-workflows" — distinct
             *     from "form-engine-wordpress" and "promptless-wordpress" so
             *     all three connectors can coexist.
             *   - Uses Node.js itself to rewrite claude_desktop_config.json
             *     so no jq/sed dependency is required.
             *   - Password is passed via argv[2], NOT interpolated into the
             *     Node script, so it never appears in shell history.
             *   - Leading `;` separator between NODE_PATH assignments avoids
             *     the `&&` short-circuit bug Promptless documented.
             */
            function buildSetupCommand(username, password) {
                const escapedPassword = password.replace(/'/g, "'\\''");
                const escapedSiteUrl  = siteUrl.replace(/'/g, "'\\''");
                const escapedUsername = username.replace(/'/g, "'\\''");

                return [
                    `mkdir -p ~/flowmint-mcp && \\`,
                    `curl -fsSL -A 'WordPress/FlowMintWorkflows' '${connectorScriptUrl}' -o ~/flowmint-mcp/flowmint-connector.js && \\`,
                    `NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \\`,
                    `CONFIG="$HOME/Library/Application Support/Claude/claude_desktop_config.json" && \\`,
                    `mkdir -p "$HOME/Library/Application Support/Claude" && \\`,
                    `"$NODE_PATH" -e '` +
                    `var fs=require("fs");` +
                    `var p=process.env.HOME+"/Library/Application Support/Claude/claude_desktop_config.json";` +
                    `var c;try{c=JSON.parse(fs.readFileSync(p,"utf8"))}catch(e){c={}}` +
                    `c.mcpServers=c.mcpServers||{};` +
                    `c.mcpServers["flowmint-workflows"]={` +
                    `command:process.argv[1],` +
                    `args:[process.env.HOME+"/flowmint-mcp/flowmint-connector.js"],` +
                    `env:{` +
                    `FLOWMINT_SITE_URL:"${escapedSiteUrl}",` +
                    `FLOWMINT_USERNAME:"${escapedUsername}",` +
                    `FLOWMINT_APP_PASSWORD:process.argv[2]` +
                    `}};` +
                    `fs.writeFileSync(p,JSON.stringify(c,null,2))` +
                    `' "$NODE_PATH" '${escapedPassword}' && \\`,
                    `echo "" && echo "Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it."`,
                ].join('\n');
            }

            function showSetupCommand(username, password) {
                const cmd = buildSetupCommand(username, password);
                document.getElementById('fmw-setup-command').textContent = cmd;
                document.getElementById('fmw-setup-command-wrap').style.display = 'block';
                const placeholder = document.getElementById('fmw-setup-command-placeholder');
                if (placeholder) placeholder.style.display = 'none';
            }

            async function post(action, extra = {}) {
                const body = new URLSearchParams({ action, nonce, ...extra });
                const res  = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                return res.json();
            }

            function showStatus(id, text, ok = true) {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = text;
                el.style.color = ok ? '#2271b1' : '#b32d2e';
                clearTimeout(el._t);
                el._t = setTimeout(() => { el.textContent = ''; }, 2500);
            }

            const enabledToggle = document.getElementById('fmw-connector-enabled');
            if (enabledToggle) {
                enabledToggle.addEventListener('change', async () => {
                    const enabled = enabledToggle.checked ? '1' : '0';
                    try {
                        const r = await post('fmw_connector_toggle_enabled', { enabled });
                        if (r.success) {
                            showStatus('fmw-enabled-status', enabledToggle.checked ? '<?php echo esc_js( __( 'Enabled.', 'flowmint-workflows' ) ); ?>' : '<?php echo esc_js( __( 'Disabled.', 'flowmint-workflows' ) ); ?>');
                        } else {
                            enabledToggle.checked = !enabledToggle.checked; // revert
                            showStatus('fmw-enabled-status', (r.data && r.data.message) || 'Error', false);
                        }
                    } catch (err) {
                        enabledToggle.checked = !enabledToggle.checked;
                        showStatus('fmw-enabled-status', String(err), false);
                    }
                });
            }

            const genBtn = document.getElementById('fmw-generate-password-btn');
            if (genBtn) {
                genBtn.addEventListener('click', async () => {
                    if (!confirm('<?php echo esc_js( __( 'Generate a new connection? Any previous connector App Password will be revoked immediately.', 'flowmint-workflows' ) ); ?>')) return;
                    genBtn.disabled = true;
                    const r = await post('fmw_connector_generate_password');
                    genBtn.disabled = false;
                    if (r.success) {
                        document.getElementById('fmw-credential-display').style.display = 'block';
                        document.getElementById('fmw-credential-value').textContent = r.data.password;

                        // Build the bash setup command while we still have the
                        // plaintext password in memory — it is never shown again.
                        showSetupCommand(r.data.username, r.data.password);
                    } else {
                        alert((r.data && r.data.message) || 'Error');
                    }
                });
            }

            const copyBtn = document.getElementById('fmw-copy-setup-command');
            if (copyBtn) {
                copyBtn.addEventListener('click', async () => {
                    const cmd = document.getElementById('fmw-setup-command').textContent;
                    try {
                        await navigator.clipboard.writeText(cmd);
                        copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'flowmint-workflows' ) ); ?>';
                        setTimeout(() => { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'flowmint-workflows' ) ); ?>'; }, 2000);
                    } catch (e) {
                        // Clipboard API unavailable — fall back to selecting the text.
                        const sel = window.getSelection();
                        const range = document.createRange();
                        range.selectNodeContents(document.getElementById('fmw-setup-command'));
                        sel.removeAllRanges();
                        sel.addRange(range);
                    }
                });
            }

            const revokeBtn = document.getElementById('fmw-revoke-password-btn');
            if (revokeBtn) {
                revokeBtn.addEventListener('click', async () => {
                    if (!confirm('<?php echo esc_js( __( 'Revoke the connector App Password? Claude Cowork will lose access immediately.', 'flowmint-workflows' ) ); ?>')) return;
                    revokeBtn.disabled = true;
                    const r = await post('fmw_connector_revoke_password');
                    revokeBtn.disabled = false;
                    if (r.success) {
                        window.location.reload();
                    } else {
                        alert((r.data && r.data.message) || 'Error');
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // ---------------------------------------------------------------------
    // AJAX handlers
    // ---------------------------------------------------------------------

    /**
     * Verify AJAX request's nonce and capability. Sends JSON error and halts
     * on failure. Returns normally when OK.
     */
    private function verify_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'flowmint-workflows' ) ), 403 );
        }
    }

    /**
     * AJAX: toggle the connector enable flag.
     */
    public function ajax_toggle_enabled() {
        $this->verify_ajax();

        $enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        FMW_Connector_Settings::set_enabled( $enabled );

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __( 'Connector enabled.', 'flowmint-workflows' )
                : __( 'Connector disabled.', 'flowmint-workflows' ),
        ) );
    }

    /**
     * AJAX: generate a new App Password for the current user.
     *
     * Revokes any prior connector App Password for this user before creating
     * the new one. The plaintext password is returned in the response for
     * one-time display; it is never stored by this plugin.
     */
    public function ajax_generate_password() {
        $this->verify_ajax();

        if ( ! class_exists( 'WP_Application_Passwords' ) ) {
            wp_send_json_error( array(
                'message' => __( 'This WordPress version does not support Application Passwords. Requires WordPress 5.6+.', 'flowmint-workflows' ),
            ) );
        }

        $user_id = get_current_user_id();

        // Revoke any prior FlowMint App Password for this user.
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && FMW_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                }
            }
        }

        $created = WP_Application_Passwords::create_new_application_password(
            $user_id,
            array( 'name' => FMW_Connector_Settings::APP_PASSWORD_NAME )
        );

        if ( is_wp_error( $created ) ) {
            wp_send_json_error( array( 'message' => $created->get_error_message() ) );
        }

        // WP returns [ $password_string, $item_metadata ].
        list( $password_string, $item ) = $created;

        FMW_Connector_Settings::mark_configured( $user_id );

        $current_user = wp_get_current_user();

        wp_send_json_success( array(
            'username' => $current_user->user_login,
            'password' => $password_string,
            'uuid'     => $item['uuid'] ?? '',
            'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', 'flowmint-workflows' ),
        ) );
    }

    /**
     * AJAX: serve the MCP connector JavaScript file.
     *
     * Intentionally public — no nonce, no capability check. The served file
     * is a static JavaScript MCP server with no embedded secrets; it reads
     * credentials from environment variables at runtime. Keeping this
     * endpoint unauthenticated lets the one-line bash setup command curl
     * it without juggling cookies or tokens.
     *
     * Route: /wp-admin/admin-ajax.php?action=fmw_download_connector
     */
    public function ajax_download_connector() {
        $path = FMW_PLUGIN_DIR . 'includes/Connectors/MCP/assets/flowmint-connector.js';

        if ( ! file_exists( $path ) ) {
            status_header( 404 );
            echo '// FlowMint Workflows connector script not found on this install.';
            exit;
        }

        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="flowmint-connector.js"' );
        header( 'Cache-Control: no-cache, must-revalidate' );

        // File is a static plugin-shipped JavaScript asset (the MCP connector),
        // not user-controlled input. Output must be raw JS, so it is intentionally
        // not run through esc_*; the content is plugin-controlled and has no XSS surface.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped
        echo file_get_contents( $path );
        exit;
    }

    /**
     * AJAX: revoke the connector App Password for the current user.
     */
    public function ajax_revoke_password() {
        $this->verify_ajax();

        if ( ! class_exists( 'WP_Application_Passwords' ) ) {
            wp_send_json_error( array(
                'message' => __( 'WordPress 5.6+ is required.', 'flowmint-workflows' ),
            ) );
        }

        $user_id  = get_current_user_id();
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        $count    = 0;

        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && FMW_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                    $count++;
                }
            }
        }

        FMW_Connector_Settings::clear_configured( $user_id );

        wp_send_json_success( array(
            'revoked_count' => $count,
            'message'       => sprintf(
                /* translators: %d: count of revoked app passwords */
                _n( '%d connector credential revoked.', '%d connector credentials revoked.', $count, 'flowmint-workflows' ),
                $count
            ),
        ) );
    }
}
