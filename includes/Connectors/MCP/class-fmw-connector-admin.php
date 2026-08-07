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

        // AJAX handlers — only users with FMW_Capabilities::MANAGE_WORKFLOWS can hit them.
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
        // Menu label is the neutral "Connector" to match Promptless / PRE /
        // FRE and stay future-proof for additional AI client integrations
        // (Codex, ChatGPT Desktop, etc.). The page H1 carries the
        // plugin-specific name ("The FlowMint Connector"). Renamed from
        // "Claude Connection" 2026-05-16 — the connector itself is vendor-
        // neutral; only the current default client happens to be Claude.
        add_submenu_page(
            'fmw-runs',
            __( 'The FlowMint Connector', 'flowmint-workflows' ),
            __( 'Connector', 'flowmint-workflows' ),
            FMW_Capabilities::MANAGE_WORKFLOWS,
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
        if ( ! current_user_can( FMW_Capabilities::MANAGE_WORKFLOWS ) ) {
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

        // App-password availability check — see PRE/Promptless equivalents
        // for the rationale. Returns true on HTTPS sites OR local dev
        // environments via WP_ENVIRONMENT_TYPE='local'.
        $app_passwords_available = wp_is_application_passwords_available();
        ?>
        <div class="wrap fmw-connector-settings">
            <h1><?php esc_html_e( 'The FlowMint Connector', 'flowmint-workflows' ); ?></h1>
            <p class="fmw-connector-subtitle">
                <?php esc_html_e( 'Connect Claude Desktop to your WordPress site so it can create and manage FlowMint workflows.', 'flowmint-workflows' ); ?>
            </p>

            <?php if ( ! $app_passwords_available ) : ?>
                <div class="notice notice-warning" style="margin: 12px 0 20px;">
                    <p><strong><?php esc_html_e( 'Application passwords not available on this site.', 'flowmint-workflows' ); ?></strong>
                    <?php esc_html_e( "WordPress requires either HTTPS or a local environment to issue application passwords. Until that's set up, the \"Generate Connection\" button will return an error.", 'flowmint-workflows' ); ?></p>
                    <ul style="margin: 6px 0 6px 24px; list-style: disc;">
                        <li><?php echo wp_kses( __( '<strong>On a production site:</strong> enable HTTPS / install an SSL certificate.', 'flowmint-workflows' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( "<strong>For local development:</strong> add <code>define('WP_ENVIRONMENT_TYPE', 'local');</code> to your <code>wp-config.php</code>. Most local environments (Local by Flywheel, wp-env, LocalWP) set this automatically.", 'flowmint-workflows' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Connection Status card. Status pill + kill-switch toggle.
                 Tucks the security toggle next to the visual status so the
                 setup flow below stays at 3 clean steps. -->
            <div class="fmw-connector-card" id="fmw-connector-status-card">
                <h2><?php esc_html_e( 'Connection Status', 'flowmint-workflows' ); ?></h2>
                <div class="fmw-connector-status-row">
                    <span class="fmw-connector-status-badge <?php echo $configured_at > 0 ? 'fmw-connector-status-active' : 'fmw-connector-status-inactive'; ?>" id="fmw-connector-status-pill">
                        <?php echo $configured_at > 0 ? esc_html__( 'Configured', 'flowmint-workflows' ) : esc_html__( 'Not Connected', 'flowmint-workflows' ); ?>
                    </span>
                    <label class="fmw-connector-killswitch">
                        <input type="checkbox"
                            id="fmw-connector-enabled"
                            <?php checked( $is_enabled ); ?>
                        >
                        <span><?php esc_html_e( 'Allow Claude Cowork to call this site', 'flowmint-workflows' ); ?></span>
                        <span class="fmw-connector-toggle-status" id="fmw-enabled-status" aria-live="polite"></span>
                    </label>
                </div>
                <p class="fmw-connector-status-help">
                    <?php if ( $configured_at > 0 ) : ?>
                        <?php
                        printf(
                            /* translators: %s: localized timestamp */
                            esc_html__( 'Last configured: %s. Generate a new connection below if you need to reconfigure.', 'flowmint-workflows' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Follow the steps below to connect Claude Desktop to your site.', 'flowmint-workflows' ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Step 1: Generate Connection -->
            <div class="fmw-connector-card">
                <h2><?php esc_html_e( 'Step 1: Generate Connection', 'flowmint-workflows' ); ?></h2>
                <p><?php esc_html_e( 'This creates a secure application password that allows Claude to communicate with your site. Any existing connection will be replaced.', 'flowmint-workflows' ); ?></p>
                <p>
                    <button type="button" id="fmw-generate-password-btn" class="button button-primary">
                        <?php
                        echo $configured_at > 0
                            ? esc_html__( 'Regenerate Connection', 'flowmint-workflows' )
                            : esc_html__( 'Generate Connection', 'flowmint-workflows' );
                        ?>
                    </button>
                    <?php if ( $configured_at > 0 ) : ?>
                        <button type="button" id="fmw-revoke-password-btn" class="button">
                            <?php esc_html_e( 'Revoke Connection', 'flowmint-workflows' ); ?>
                        </button>
                    <?php endif; ?>
                </p>

                <div id="fmw-credential-display" class="fmw-connector-success-notice" style="display:none;">
                    <p><strong><?php esc_html_e( 'Connection generated successfully!', 'flowmint-workflows' ); ?></strong> <?php esc_html_e( 'Now proceed to Step 2.', 'flowmint-workflows' ); ?></p>
                </div>
            </div>

            <!-- Step 2: Run Setup Command -->
            <div class="fmw-connector-card">
                <h2><?php esc_html_e( 'Step 2: Run Setup Command', 'flowmint-workflows' ); ?></h2>
                <p><?php esc_html_e( 'Copy the command below and paste it into', 'flowmint-workflows' ); ?> <strong><?php esc_html_e( 'Terminal', 'flowmint-workflows' ); ?></strong> <?php esc_html_e( 'on your Mac. This automatically installs and configures the FlowMint Connector.', 'flowmint-workflows' ); ?></p>

                <div class="fmw-connector-requirements">
                    <strong><?php esc_html_e( 'Requirements:', 'flowmint-workflows' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'macOS with Terminal', 'flowmint-workflows' ); ?></li>
                        <li><?php esc_html_e( 'Node.js installed (v14 or higher)', 'flowmint-workflows' ); ?></li>
                        <li><?php esc_html_e( 'Claude Desktop app installed', 'flowmint-workflows' ); ?></li>
                    </ul>
                </div>

                <div id="fmw-setup-command-container" style="display:none;">
                    <div class="fmw-connector-code-block">
                        <pre id="fmw-setup-command"></pre>
                    </div>
                    <button type="button" class="button fmw-connector-copy-btn" id="fmw-copy-setup-command"><?php esc_html_e( 'Copy Command', 'flowmint-workflows' ); ?></button>
                    <p class="description"><?php esc_html_e( 'After running the command, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next session.', 'flowmint-workflows' ); ?></p>
                </div>

                <div id="fmw-setup-command-placeholder">
                    <p class="description" style="color:#999;">
                        <?php if ( $configured_at > 0 ) : ?>
                            <?php esc_html_e( 'Your connection is configured. To see the setup command again, click "Regenerate Connection" in Step 1.', 'flowmint-workflows' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Generate a connection in Step 1 first, then your setup command will appear here.', 'flowmint-workflows' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Step 3: Verify Connection -->
            <div class="fmw-connector-card">
                <h2><?php esc_html_e( 'Step 3: Verify Connection', 'flowmint-workflows' ); ?></h2>
                <p><?php esc_html_e( 'After running the setup command and restarting Claude Desktop, start a new conversation and type:', 'flowmint-workflows' ); ?></p>
                <div class="fmw-connector-code-block">
                    <pre><?php esc_html_e( 'List the FlowMint workflows on my site.', 'flowmint-workflows' ); ?></pre>
                </div>
                <p><?php esc_html_e( 'Claude should respond with your workflows, confirming the connection is active.', 'flowmint-workflows' ); ?></p>
            </div>

            <!-- Developer info — collapsed by default. -->
            <details class="fmw-connector-dev-info">
                <summary><?php esc_html_e( 'Developer info', 'flowmint-workflows' ); ?></summary>
                <dl>
                    <dt><?php esc_html_e( 'REST base URL', 'flowmint-workflows' ); ?></dt>
                    <dd><code><?php echo esc_html( $rest_base_url ); ?></code></dd>
                    <dt><?php esc_html_e( 'Authenticated user', 'flowmint-workflows' ); ?></dt>
                    <dd><code><?php echo esc_html( $current_user->user_login ); ?></code></dd>
                    <dt><?php esc_html_e( 'Documentation', 'flowmint-workflows' ); ?></dt>
                    <dd>
                        <a href="<?php echo esc_url( $api_doc_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connector API documentation', 'flowmint-workflows' ); ?></a>
                        &middot;
                        <a href="<?php echo esc_url( $setup_doc_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MCP setup notes', 'flowmint-workflows' ); ?></a>
                    </dd>
                </dl>
            </details>
        </div>

        <style>
            /* FlowMint connector admin styles — mirrors Promptless's .aisb-*
             * visual treatment with .fmw-connector-* prefix so the three
             * Promptless-family plugins all look like siblings. Refactored
             * 2026-05-16 from a flat-text 3-step layout to this card-based
             * 3-step layout with the kill-switch tucked into the Status
             * card. */
            .fmw-connector-settings { max-width: 800px; }
            .fmw-connector-subtitle { font-size: 14px; color: #646970; margin-top: -5px; }

            .fmw-connector-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px 24px;
                margin-bottom: 20px;
            }
            .fmw-connector-card h2 {
                margin-top: 0;
                padding-top: 0;
                font-size: 16px;
                border-bottom: none;
            }

            .fmw-connector-status-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 8px;
            }
            .fmw-connector-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 13px;
                font-weight: 500;
            }
            .fmw-connector-status-active { background: #d4edda; color: #155724; }
            .fmw-connector-status-inactive { background: #f8d7da; color: #721c24; }
            .fmw-connector-status-help { margin: 0; color: #50575e; }

            .fmw-connector-killswitch { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #1d2327; }
            .fmw-connector-toggle-status { font-style: italic; color: #50575e; min-width: 60px; }

            .fmw-connector-success-notice {
                margin: 12px 0 0 0;
                padding: 8px 12px;
                background: #edf7ed;
                border-left: 3px solid #46b450;
                border-radius: 0 4px 4px 0;
            }
            .fmw-connector-success-notice p { margin: 0; }

            .fmw-connector-requirements {
                background: #f0f6fc;
                border: 1px solid #c8d8e4;
                border-radius: 4px;
                padding: 12px 16px;
                margin: 12px 0;
            }
            .fmw-connector-requirements ul { margin: 4px 0 0 20px; }
            .fmw-connector-requirements li { margin-bottom: 2px; }

            .fmw-connector-code-block {
                position: relative;
                background: #1d2327;
                color: #50c878;
                padding: 16px 20px;
                border-radius: 6px;
                margin: 12px 0;
                overflow-x: auto;
            }
            .fmw-connector-code-block pre {
                margin: 0;
                white-space: pre-wrap;
                word-break: break-all;
                font-family: 'SF Mono', 'Monaco', 'Menlo', 'Consolas', monospace;
                font-size: 13px;
                line-height: 1.6;
                color: #50c878;
            }
            .fmw-connector-copy-btn {
                /* Sits BELOW the code block. Never position this over the
                   command text: the old absolute top-right placement covered
                   the command and was illegible on the dark background. */
                display: block !important;
                margin: 8px 0 4px !important;
                font-size: 12px !important;
                padding: 2px 10px !important;
                min-height: 28px !important;
            }

            .fmw-connector-dev-info {
                margin-top: 20px;
                padding: 12px 16px;
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .fmw-connector-dev-info summary {
                cursor: pointer;
                font-weight: 600;
                color: #1d2327;
                outline: none;
            }
            .fmw-connector-dev-info[open] summary { margin-bottom: 8px; }
            .fmw-connector-dev-info dl { margin: 0; }
            .fmw-connector-dev-info dt {
                font-weight: 600;
                color: #50575e;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin-top: 10px;
            }
            .fmw-connector-dev-info dt:first-child { margin-top: 0; }
            .fmw-connector-dev-info dd { margin: 4px 0 0 0; font-size: 13px; }
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
                const container = document.getElementById('fmw-setup-command-container');
                if (container) container.style.display = 'block';
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
                    // No confirm() dialog — Promptless doesn't use one and
                    // the blocking modal adds friction. Misclicks are
                    // recoverable (click Generate again — the prior
                    // password is already revoked atomically server-side).
                    const originalLabel = genBtn.textContent;
                    genBtn.disabled = true;
                    genBtn.textContent = '<?php echo esc_js( __( 'Generating...', 'flowmint-workflows' ) ); ?>';
                    const r = await post('fmw_connector_generate_password');
                    genBtn.disabled = false;
                    if (r.success) {
                        // Reveal the success notice in Step 1 card.
                        const display = document.getElementById('fmw-credential-display');
                        if (display) display.style.display = 'block';

                        // Build + reveal the setup command in Step 2 card.
                        showSetupCommand(r.data.username, r.data.password);

                        // Flip the status pill in the Connection Status card
                        // from red "Not Connected" to green "Configured".
                        const pill = document.getElementById('fmw-connector-status-pill');
                        if (pill) {
                            pill.textContent = '<?php echo esc_js( __( 'Configured', 'flowmint-workflows' ) ); ?>';
                            pill.classList.remove('fmw-connector-status-inactive');
                            pill.classList.add('fmw-connector-status-active');
                        }

                        genBtn.textContent = '<?php echo esc_js( __( 'Regenerate Connection', 'flowmint-workflows' ) ); ?>';
                    } else {
                        genBtn.textContent = originalLabel;
                        alert((r.data && r.data.message) || 'Error');
                    }
                });
            }

            const copyBtn = document.getElementById('fmw-copy-setup-command');
            if (copyBtn) {
                // Capture original label so the restore-after-flash matches
                // the template's rendered text (e.g. 'Copy Command') instead
                // of being hardcoded to 'Copy'.
                const originalCopyLabel = copyBtn.textContent;
                const flashCopied = () => {
                    copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'flowmint-workflows' ) ); ?>';
                    setTimeout(() => { copyBtn.textContent = originalCopyLabel; }, 2000);
                };
                copyBtn.addEventListener('click', async () => {
                    const pre = document.getElementById('fmw-setup-command');
                    const cmd = pre.textContent;
                    // Path 1: modern Clipboard API. Only available on HTTPS
                    // sites and true localhost — NOT on HTTP custom
                    // hostnames like `mysite.local`.
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        try {
                            await navigator.clipboard.writeText(cmd);
                            flashCopied();
                            return;
                        } catch (e) { /* fall through */ }
                    }
                    // Path 2: legacy execCommand fallback. Works on HTTP.
                    const sel = window.getSelection();
                    const range = document.createRange();
                    range.selectNodeContents(pre);
                    sel.removeAllRanges();
                    sel.addRange(range);
                    try {
                        const ok = document.execCommand('copy');
                        sel.removeAllRanges();
                        if (ok) flashCopied();
                    } catch (e) { /* leave selection so user can Cmd+C */ }
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

        if ( ! current_user_can( FMW_Capabilities::MANAGE_WORKFLOWS ) ) {
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
