#!/usr/bin/env node

/**
 * FlowMint Workflows — Cowork MCP Connector
 *
 * A stdio MCP server that bridges Claude Cowork (via Claude Desktop) to the
 * FlowMint Workflows REST API on a WordPress site. Runs on the user's local
 * machine so it can make HTTP(S) requests to any WordPress install with
 * Application Password authentication.
 *
 * The bridge exists because the Cowork sandbox cannot make outbound HTTP
 * requests to arbitrary hosts. By running Node.js locally and speaking the
 * MCP protocol over stdio to Claude Desktop, we give Claude Cowork full REST
 * access to the user's WordPress install without a server-side agent.
 *
 * Contract: this server maps one-to-one to the REST endpoints documented in
 * docs/CONNECTOR_API.md v1. Any change to the tool set here must also be
 * reflected in the spec document — and vice versa.
 *
 * Environment variables:
 *   FLOWMINT_SITE_URL      - WordPress site URL (e.g., https://example.com)
 *   FLOWMINT_USERNAME      - WordPress user login
 *   FLOWMINT_APP_PASSWORD  - WordPress Application Password (spaces OK, stripped on use)
 *
 * This file is forked from the Form Runtime Engine connector
 * (form-runtime-engine/includes/Connector/assets/form-engine-connector.js),
 * which itself was forked from the Promptless WP connector. The MCP stdio
 * framing, Basic Auth + ModSecurity workarounds, and protocol-version echo
 * in the initialize handler are preserved verbatim because the fixes there
 * were hard-won and apply equally to this connector.
 */

const http = require("http");
const https = require("https");
const { URL } = require("url");

// ---------------------------------------------------------------------------
// Tool definitions. Must mirror docs/CONNECTOR_API.md.
// ---------------------------------------------------------------------------

const TOOLS = [
  {
    name: "flowmint_preflight",
    description:
      "Verify the FlowMint Workflows connector is reachable and report its state. MUST be called first in any session that will use this connector. " +
      "Returns plugin_version, connector_api_version, connector_enabled, fre_active (FlowMint depends on Form Runtime Engine), action_scheduler_active, authenticated_as, user_capabilities, schema_document_url (URL of the markdown contract), and diagnostics (database health, configured credentials). " +
      "ALWAYS WebFetch the schema_document_url before creating workflows — it covers the workflow JSON shape, available step types, on_error policies (retry / continue / fail), expression syntax, interpolation rules, and the FRE submission listener wiring that triggers workflow runs.",
    inputSchema: {
      type: "object",
      properties: {},
      required: [],
    },
  },

  // --- Workflows -----------------------------------------------------------
  {
    name: "flowmint_list_workflows",
    description:
      "List workflows stored on the site. Paginated. Optional filters: form_id (workflows bound to a particular FRE form), enabled (true/false), managed_by ('admin' for hand-authored, 'connector:cowork' for ones you previously created via this API). Use the managed_by filter to avoid inadvertently modifying workflows the site owner maintains by hand. The list view omits the 'config' field — call flowmint_get_workflow to fetch the full config.",
    inputSchema: {
      type: "object",
      properties: {
        form_id: {
          type: "string",
          description: "Filter to workflows bound to a specific FRE form_id.",
        },
        enabled: { type: "boolean" },
        managed_by: {
          type: "string",
          enum: ["admin", "connector:cowork"],
          description:
            "Filter by workflow origin. Omit to include workflows of any origin.",
        },
        page: { type: "integer", minimum: 1, default: 1 },
        per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
      },
      required: [],
    },
  },
  {
    name: "flowmint_get_workflow",
    description:
      "Fetch a single workflow by ID. Returns the full record including the raw config JSON string (parse it with JSON.parse), connector_version (bumps on every update), managed_by tag, and metadata.",
    inputSchema: {
      type: "object",
      properties: {
        workflow_id: {
          type: "string",
          description:
            "Workflow identifier. Lowercase alphanumeric with dashes or underscores.",
        },
      },
      required: ["workflow_id"],
    },
  },
  {
    name: "flowmint_create_workflow",
    description:
      "Create a new workflow. BEFORE your first create in a session, call flowmint_preflight and WebFetch the returned schema_document_url so you understand the workflow JSON shape and step-type contracts. " +
      "The 'config' argument MUST be a JSON STRING (not an object) conforming to the FlowMint workflow schema. JSON.stringify your config object before passing it in. The config defines a steps array — each step has { id, type, config, on_error?, when? }. Use flowmint_list_step_types to see what types are available and their config shapes. " +
      "FlowMint workflows trigger off the fre_submission_complete action — bind a workflow to an FRE form via the form_id field. The workflow runs asynchronously through Action Scheduler, never blocking the form submission. " +
      "Workflows created via this tool are automatically tagged managed_by='connector:cowork' (unless overridden) and start at connector_version=1. Conflicts on an existing ID return already_exists (409) — use flowmint_update_workflow instead.",
    inputSchema: {
      type: "object",
      properties: {
        id: {
          type: "string",
          description:
            "Unique workflow identifier. Must match ^[a-z0-9\\-_]+$.",
        },
        title: {
          type: "string",
          description: "Human-readable title shown in the admin UI.",
        },
        form_id: {
          type: "string",
          description:
            "FRE form ID this workflow listens to. When that form is submitted, this workflow's run is enqueued.",
        },
        config: {
          type: "string",
          description:
            "JSON string describing the workflow steps and metadata. Required keys: steps (array). Each step: { id, type, config, on_error?, when? }. Tip: JSON.stringify your config object before passing it in.",
        },
        enabled: {
          type: "boolean",
          default: true,
          description:
            "Whether the workflow runs when triggered. Set false to stage a workflow without it firing.",
        },
        managed_by: {
          type: "string",
          enum: ["admin", "connector:cowork"],
          description:
            "Origin tag. Defaults to 'connector:cowork' when called via this MCP.",
        },
      },
      required: ["id", "config"],
    },
  },
  {
    name: "flowmint_update_workflow",
    description:
      "Update an existing workflow. All fields except workflow_id are optional — omitted fields retain their current values. If you supply a new 'config', it MUST be a JSON STRING (not an object) and it REPLACES the existing config; include every step you want to preserve. " +
      "The connector_version bumps on every update. managed_by is immutable through this API.",
    inputSchema: {
      type: "object",
      properties: {
        workflow_id: {
          type: "string",
          description: "Workflow identifier to update.",
        },
        title: { type: "string" },
        form_id: { type: "string" },
        config: {
          type: "string",
          description:
            "Replacement config JSON string. If omitted, the existing config is preserved.",
        },
        enabled: { type: "boolean" },
      },
      required: ["workflow_id"],
    },
  },
  {
    name: "flowmint_delete_workflow",
    description:
      "Delete a workflow by ID. By default leaves run history intact (FlowMint's data-preservation policy). Pass cascade=true to also delete all run + step records for this workflow — irreversible.",
    inputSchema: {
      type: "object",
      properties: {
        workflow_id: { type: "string" },
        cascade: {
          type: "boolean",
          default: false,
          description:
            "If true, also delete all runs and run-step records for this workflow.",
        },
      },
      required: ["workflow_id"],
    },
  },
  {
    name: "flowmint_test_workflow",
    description:
      "Validate a workflow config without executing it. Returns { valid, errors, warnings }. Pass an explicit config (JSON STRING) to test changes before saving, OR omit config to validate the currently-stored workflow.",
    inputSchema: {
      type: "object",
      properties: {
        workflow_id: { type: "string" },
        config: {
          type: "string",
          description:
            "Optional JSON string to validate. If omitted, validates the saved workflow's current config.",
        },
      },
      required: ["workflow_id"],
    },
  },

  // --- Runs ----------------------------------------------------------------
  {
    name: "flowmint_list_runs",
    description:
      "List workflow run history. Paginated. Filters: workflow_id, form_id, entry_id, status (queued / running / completed / failed / cancelled), date_from / date_to (YYYY-MM-DD). Each run shows the workflow it ran, the form submission that triggered it, and per-step timing.",
    inputSchema: {
      type: "object",
      properties: {
        workflow_id: { type: "string" },
        form_id: { type: "string" },
        entry_id: { type: "integer" },
        status: {
          type: "string",
          enum: ["queued", "running", "completed", "failed", "cancelled"],
        },
        date_from: { type: "string", description: "ISO date YYYY-MM-DD." },
        date_to: { type: "string", description: "ISO date YYYY-MM-DD." },
        page: { type: "integer", minimum: 1, default: 1 },
        per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
      },
      required: [],
    },
  },
  {
    name: "flowmint_get_run",
    description:
      "Fetch a single run by ID. Includes the per-step list with each step's status, output, and error (if any). Use this to debug failed runs.",
    inputSchema: {
      type: "object",
      properties: {
        run_id: { type: "integer" },
      },
      required: ["run_id"],
    },
  },
  {
    name: "flowmint_replay_run",
    description:
      "Replay a finalized run (failed, cancelled, or completed). Creates a new run that references the parent run, then enqueues it via Action Scheduler. Returns the new_run_id and parent_run_id. Use after fixing a configuration bug to retry a failed run.",
    inputSchema: {
      type: "object",
      properties: {
        run_id: { type: "integer" },
      },
      required: ["run_id"],
    },
  },

  // --- Step types ----------------------------------------------------------
  {
    name: "flowmint_list_step_types",
    description:
      "List every step type registered on this site, with their config schemas, descriptions, and required credentials. Call this BEFORE building a workflow so you know which step types are available (Drive, Email, Printavo, HTTP, conditional, etc.) and what each step's config requires.",
    inputSchema: {
      type: "object",
      properties: {},
      required: [],
    },
  },
  {
    name: "flowmint_get_step_type",
    description:
      "Fetch full details on a single step type, including its config schema, output shape, and any required credentials.",
    inputSchema: {
      type: "object",
      properties: {
        type: {
          type: "string",
          description:
            "Step type identifier (e.g. 'drive_upload_file', 'printavo_create_quote', 'log_info').",
        },
      },
      required: ["type"],
    },
  },

  // --- Credentials ---------------------------------------------------------
  {
    name: "flowmint_list_credentials",
    description:
      "List the credential keys this site supports (drive_service_account, printavo_api_token, slack_webhook, notification_email) and whether each is configured. NEVER returns plaintext values — only configured-state booleans. Credential VALUES are set through the WordPress admin, not through this MCP.",
    inputSchema: {
      type: "object",
      properties: {},
      required: [],
    },
  },
  {
    name: "flowmint_test_credential",
    description:
      "Test a stored credential against its target service. For drive_service_account, lists Drive about info. For printavo_api_token, calls the Printavo /account endpoint. Returns { test_result: 'ok' | 'failed', details? | error? }. Use this to verify a credential works before binding a workflow that depends on it.",
    inputSchema: {
      type: "object",
      properties: {
        key: {
          type: "string",
          enum: [
            "drive_service_account",
            "printavo_api_token",
            "slack_webhook",
            "notification_email",
          ],
        },
      },
      required: ["key"],
    },
  },

  // --- Templates -----------------------------------------------------------
  {
    name: "flowmint_list_templates",
    description:
      "List email/message templates stored on the site. Templates are .txt or .html files with {{ }} interpolation placeholders that workflow steps reference by name. Returns name, extension, size, and modified timestamp for each.",
    inputSchema: {
      type: "object",
      properties: {},
      required: [],
    },
  },
  {
    name: "flowmint_get_template",
    description:
      "Fetch a single template's content by name. Returns { name, extension, content, size }. Use this to read what a template currently contains before proposing edits.",
    inputSchema: {
      type: "object",
      properties: {
        name: {
          type: "string",
          description:
            "Template name (without extension). Must match [a-z0-9_-]+ and ≤ 64 chars.",
        },
      },
      required: ["name"],
    },
  },
];

// ---------------------------------------------------------------------------
// HTTP client.
// ---------------------------------------------------------------------------

function getConfig() {
  const siteUrl = process.env.FLOWMINT_SITE_URL;
  const username = process.env.FLOWMINT_USERNAME;
  const appPassword = process.env.FLOWMINT_APP_PASSWORD;

  if (!siteUrl) {
    throw new Error(
      "FLOWMINT_SITE_URL is not set. Set it to your WordPress site URL (e.g. https://example.com)."
    );
  }
  if (!username || !appPassword) {
    throw new Error(
      "FLOWMINT_USERNAME and FLOWMINT_APP_PASSWORD must both be set. Generate an Application Password through the FlowMint Workflows → Claude Connection admin page."
    );
  }

  // WordPress Application Passwords display with spaces for readability, but
  // the actual credential is the space-stripped form. Strip before encoding.
  const cleanPassword = appPassword.replace(/\s+/g, "");
  const auth = Buffer.from(`${username}:${cleanPassword}`).toString("base64");

  return { siteUrl: siteUrl.replace(/\/+$/, ""), auth };
}

/**
 * Build a request to the connector's REST base.
 *
 * Notable headers — all inherited from the FRE / Promptless connector's
 * hard-won experience with shared hosts (see MCP_CONNECTOR_SETUP.md):
 *   - User-Agent starts with "WordPress/" so ModSecurity WAFs don't block
 *     the request as a suspicious Node.js client. Includes "Cowork" so the
 *     REST API can auto-tag managed_by='connector:cowork' on creates.
 *   - Connection: close prevents chunked transfer encoding on the request
 *     body, which some WAFs reject for POST requests.
 *   - Content-Length is set explicitly on requests with a body for the
 *     same reason — let Node.js compute it, but set the header rather than
 *     relying on chunked.
 */
function makeRequest(method, path, body = null) {
  return new Promise((resolve, reject) => {
    const config = getConfig();
    const url = new URL(
      `${config.siteUrl}/wp-json/flowmint/v1/connector${path}`
    );

    const isHttps = url.protocol === "https:";
    const transport = isHttps ? https : http;

    const bodyStr = body ? JSON.stringify(body) : null;

    const headers = {
      Authorization: `Basic ${config.auth}`,
      "Content-Type": "application/json",
      Accept: "application/json",
      "User-Agent":
        "WordPress/FlowMintWorkflows-Connector/1.0 (compatible; Cowork MCP)",
      Connection: "close",
    };

    if (bodyStr) {
      headers["Content-Length"] = Buffer.byteLength(bodyStr).toString();
    }

    const options = {
      hostname: url.hostname,
      port: url.port || (isHttps ? 443 : 80),
      path: url.pathname + url.search,
      method: method,
      headers: headers,
    };

    const req = transport.request(options, (res) => {
      let data = "";
      res.on("data", (chunk) => (data += chunk));
      res.on("end", () => {
        try {
          const json = JSON.parse(data);
          if (res.statusCode >= 400) {
            resolve({
              error: true,
              status: res.statusCode,
              ...(typeof json === "object" ? json : { message: data }),
            });
          } else {
            resolve(json);
          }
        } catch {
          resolve({
            error: true,
            status: res.statusCode,
            message: data.substring(0, 500),
          });
        }
      });
    });

    req.on("error", (e) => {
      reject(
        new Error(
          `Connection failed: ${e.message}. Is the site URL correct? (${config.siteUrl})`
        )
      );
    });

    req.setTimeout(30000, () => {
      req.destroy();
      reject(new Error("Request timed out after 30 seconds"));
    });

    if (bodyStr) {
      req.write(bodyStr);
    }
    req.end();
  });
}

// ---------------------------------------------------------------------------
// Tool → REST route mapping.
// ---------------------------------------------------------------------------

async function handleTool(name, args) {
  switch (name) {
    case "flowmint_preflight":
      return await makeRequest("GET", "/preflight");

    // --- Workflows -------------------------------------------------------
    case "flowmint_list_workflows": {
      const qs = new URLSearchParams();
      if (args.form_id) qs.set("form_id", args.form_id);
      if (args.enabled !== undefined) qs.set("enabled", args.enabled ? "true" : "false");
      if (args.managed_by) qs.set("managed_by", args.managed_by);
      if (args.page) qs.set("page", String(args.page));
      if (args.per_page) qs.set("per_page", String(args.per_page));
      const suffix = qs.toString() ? `?${qs.toString()}` : "";
      return await makeRequest("GET", `/workflows${suffix}`);
    }

    case "flowmint_get_workflow":
      return await makeRequest(
        "GET",
        `/workflows/${encodeURIComponent(args.workflow_id)}`
      );

    case "flowmint_create_workflow": {
      const payload = {
        id: args.id,
        config: args.config,
      };
      if (args.title !== undefined) payload.title = args.title;
      if (args.form_id !== undefined) payload.form_id = args.form_id;
      if (args.enabled !== undefined) payload.enabled = args.enabled;
      if (args.managed_by !== undefined) payload.managed_by = args.managed_by;
      return await makeRequest("POST", "/workflows", payload);
    }

    case "flowmint_update_workflow": {
      const payload = {};
      if (args.title !== undefined) payload.title = args.title;
      if (args.form_id !== undefined) payload.form_id = args.form_id;
      if (args.config !== undefined) payload.config = args.config;
      if (args.enabled !== undefined) payload.enabled = args.enabled;
      return await makeRequest(
        "PATCH",
        `/workflows/${encodeURIComponent(args.workflow_id)}`,
        payload
      );
    }

    case "flowmint_delete_workflow": {
      const qs = new URLSearchParams();
      if (args.cascade) qs.set("cascade", "true");
      const suffix = qs.toString() ? `?${qs.toString()}` : "";
      return await makeRequest(
        "DELETE",
        `/workflows/${encodeURIComponent(args.workflow_id)}${suffix}`
      );
    }

    case "flowmint_test_workflow": {
      const payload = {};
      if (args.config !== undefined) payload.config = args.config;
      return await makeRequest(
        "POST",
        `/workflows/${encodeURIComponent(args.workflow_id)}/test`,
        payload
      );
    }

    // --- Runs ------------------------------------------------------------
    case "flowmint_list_runs": {
      const qs = new URLSearchParams();
      [
        "workflow_id",
        "form_id",
        "entry_id",
        "status",
        "date_from",
        "date_to",
        "page",
        "per_page",
      ].forEach((k) => {
        if (args[k] !== undefined && args[k] !== null && args[k] !== "") {
          qs.set(k, String(args[k]));
        }
      });
      const suffix = qs.toString() ? `?${qs.toString()}` : "";
      return await makeRequest("GET", `/runs${suffix}`);
    }

    case "flowmint_get_run":
      return await makeRequest(
        "GET",
        `/runs/${encodeURIComponent(args.run_id)}`
      );

    case "flowmint_replay_run":
      return await makeRequest(
        "POST",
        `/runs/${encodeURIComponent(args.run_id)}/replay`
      );

    // --- Step types ------------------------------------------------------
    case "flowmint_list_step_types":
      return await makeRequest("GET", "/step-types");

    case "flowmint_get_step_type":
      return await makeRequest(
        "GET",
        `/step-types/${encodeURIComponent(args.type)}`
      );

    // --- Credentials -----------------------------------------------------
    case "flowmint_list_credentials":
      return await makeRequest("GET", "/credentials");

    case "flowmint_test_credential":
      return await makeRequest(
        "POST",
        `/credentials/${encodeURIComponent(args.key)}/test`
      );

    // --- Templates -------------------------------------------------------
    case "flowmint_list_templates":
      return await makeRequest("GET", "/templates");

    case "flowmint_get_template":
      return await makeRequest(
        "GET",
        `/templates/${encodeURIComponent(args.name)}`
      );

    default:
      throw new Error(`Unknown tool: ${name}`);
  }
}

// ---------------------------------------------------------------------------
// MCP stdio transport — auto-detects Content-Length or newline-delimited framing.
//
// This block is ported verbatim from the FRE / Promptless connector. Claude
// Desktop historically shipped versions that used different framing modes;
// auto-detection is necessary for cross-version compatibility. Do not
// simplify without also updating the upstream FRE connector.
// ---------------------------------------------------------------------------

let buffer = Buffer.alloc(0);
let detectedMode = null; // "content-length" or "newline"

process.stdin.on("data", (chunk) => {
  buffer = Buffer.concat([buffer, chunk]);
  processBuffer();
});

function processBuffer() {
  if (detectedMode === null && buffer.length > 0) {
    const peek = buffer.toString("utf8", 0, Math.min(buffer.length, 20));
    if (peek.startsWith("Content-Length:")) {
      detectedMode = "content-length";
    } else {
      detectedMode = "newline";
    }
  }

  if (detectedMode === "content-length") {
    processContentLength();
  } else if (detectedMode === "newline") {
    processNewline();
  }
}

let contentLength = -1;

function processContentLength() {
  while (true) {
    if (contentLength === -1) {
      const headerEnd = buffer.indexOf("\r\n\r\n");
      if (headerEnd === -1) return;

      const header = buffer.slice(0, headerEnd).toString("utf8");
      const match = header.match(/Content-Length:\s*(\d+)/i);
      if (!match) {
        buffer = buffer.slice(headerEnd + 4);
        continue;
      }

      contentLength = parseInt(match[1], 10);
      buffer = buffer.slice(headerEnd + 4);
    }

    if (buffer.length < contentLength) return;

    const messageBytes = buffer.slice(0, contentLength);
    buffer = buffer.slice(contentLength);
    contentLength = -1;

    parseAndHandle(messageBytes.toString("utf8"));
  }
}

function processNewline() {
  const str = buffer.toString("utf8");
  let newlineIndex;
  while ((newlineIndex = str.indexOf("\n")) !== -1) {
    const line = str.slice(0, newlineIndex).trim();
    buffer = Buffer.from(str.slice(newlineIndex + 1), "utf8");

    if (line.length === 0) {
      return processNewline();
    }
    parseAndHandle(line);
    return processNewline();
  }
}

function parseAndHandle(text) {
  try {
    const message = JSON.parse(text);
    handleMessage(message);
  } catch (e) {
    sendError(null, -32700, "Parse error: " + e.message);
  }
}

function send(obj) {
  const body = JSON.stringify(obj);
  if (detectedMode === "content-length") {
    const header = `Content-Length: ${Buffer.byteLength(body)}\r\n\r\n`;
    process.stdout.write(header + body);
  } else {
    process.stdout.write(body + "\n");
  }
}

function sendResult(id, result) {
  send({ jsonrpc: "2.0", id, result });
}

function sendError(id, code, message) {
  send({ jsonrpc: "2.0", id, error: { code, message } });
}

async function handleMessage(msg) {
  const { id, method, params } = msg;

  switch (method) {
    case "initialize": {
      // Echo the client's protocol version back verbatim. Claude Desktop
      // expects this; hardcoding a different version causes connection
      // negotiation to fail silently. See FRE / Promptless connector setup
      // docs.
      const clientVersion =
        (params && params.protocolVersion) || "2024-11-05";
      sendResult(id, {
        protocolVersion: clientVersion,
        capabilities: { tools: {} },
        serverInfo: {
          name: "flowmint-workflows-connector",
          version: "1.0.0",
        },
      });
      break;
    }

    case "notifications/initialized":
      // No response required.
      break;

    case "tools/list":
      sendResult(id, { tools: TOOLS });
      break;

    case "tools/call": {
      const toolName = params?.name;
      const toolArgs = params?.arguments || {};

      try {
        const result = await handleTool(toolName, toolArgs);
        sendResult(id, {
          content: [
            {
              type: "text",
              text: JSON.stringify(result, null, 2),
            },
          ],
        });
      } catch (e) {
        sendResult(id, {
          content: [
            {
              type: "text",
              text: JSON.stringify({ error: true, message: e.message }),
            },
          ],
          isError: true,
        });
      }
      break;
    }

    default:
      if (id !== undefined) {
        sendError(id, -32601, `Method not found: ${method}`);
      }
  }
}

process.on("SIGINT", () => process.exit(0));
process.on("SIGTERM", () => process.exit(0));
process.stdin.on("end", () => process.exit(0));
