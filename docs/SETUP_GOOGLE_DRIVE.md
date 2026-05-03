# Setup: Google Drive

How to configure FlowMint Workflows' Drive integration for a new client install. One-time setup per client.

## Overview

FlowMint Workflows uses a **Google Cloud service account** to access Drive. A service account is a non-human Google identity that owns its own Drive files. Benefits:

- No OAuth user prompt flow
- No token refresh dance
- Files owned by the service account (or shared with it)
- Survives team changes (it's not tied to anyone's personal Google account)

The downside: service accounts have their own Drive quota (15GB free) — for clients with high file volume, files should be shared INTO a workspace folder owned by the client's Google Workspace, not stored in the service account's own Drive.

## Prerequisites

- A Google account that can create projects in Google Cloud Console (free tier is fine)
- A Drive folder where workflow files will be stored (the "workspace folder")
- The client's WordPress install with FlowMint Workflows active

## Step 1: Create the Google Cloud project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Click the project dropdown at top → "New Project"
3. Name: `flowmint-<client-slug>` (e.g., `flowmint-725-print-lab`)
4. Click Create
5. Wait for the project to provision (~30 seconds)

## Step 2: Enable the Drive API

1. With the new project selected, go to APIs & Services → Library
2. Search for "Google Drive API"
3. Click it → Click "Enable"

## Step 3: Create a service account

1. APIs & Services → Credentials
2. "Create Credentials" → "Service account"
3. Name: `flowmint-workflows-prod` (or `-dev` for dev installs)
4. Service account ID auto-generates: `flowmint-workflows-prod@<project>.iam.gserviceaccount.com`
5. Description: "FlowMint Workflows automation service account"
6. Click "Create and continue"
7. Roles: skip (no project-level roles needed; we use Drive sharing instead)
8. Click "Done"

You're back at Credentials. Copy the service account email (`<name>@<project>.iam.gserviceaccount.com`).

## Step 4: Create a JSON key for the service account

1. Click the service account in the Credentials list
2. Tab "Keys" → "Add Key" → "Create new key"
3. Type: JSON
4. Click "Create"
5. A `<project>-<random>.json` file downloads. **This is the credential.** Treat it like a password.

The file looks like:
```json
{
  "type": "service_account",
  "project_id": "flowmint-725-print-lab",
  "private_key_id": "...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
  "client_email": "flowmint-workflows-prod@flowmint-725-print-lab.iam.gserviceaccount.com",
  "client_id": "...",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  ...
}
```

## Step 5: Share the Drive workspace folder with the service account

1. Open Drive in your browser
2. Navigate to the folder where workflow files will be stored (the "workspace folder")
3. Right-click → Share
4. Add the service account email as an editor: `flowmint-workflows-prod@flowmint-725-print-lab.iam.gserviceaccount.com`
5. Uncheck "Notify people" (it's a service account, no inbox)
6. Click "Share"

The service account can now read and write within this folder.

**Important:** the service account ONLY has access to folders explicitly shared with it. It cannot wander into other Drive folders. This is the security boundary.

## Step 6: Get the workspace folder ID

The folder ID is in the Drive URL when you have the folder open:

```
https://drive.google.com/drive/folders/1aVp_Zhd0OyL5K_h9dNYQ_f6lf_VOC8K8
                                         ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                                         this is the folder ID
```

Save this — you'll reference it in workflow definitions as the `parent_id`.

## Step 7: Configure FlowMint Workflows with the credential

Two options:

### Option A: WordPress admin UI (Phase 5 onward)

1. WP Admin → FlowMint Workflows → Settings
2. Section "Google Drive"
3. Paste the entire JSON content of the key file into the "Service Account JSON" field
4. Click "Save"
5. Click "Test Connection" — should report the service account email

### Option B: REST API (works from Phase 1)

```
PUT /wp-json/flowmint/v1/connector/credentials/drive_service_account
Authorization: Basic <base64 of user:apppassword>
Content-Type: application/json

{
  "value": "<paste entire JSON file content here, as a string>"
}
```

Then test:
```
POST /wp-json/flowmint/v1/connector/credentials/drive_service_account/test
```

### Option C: Via Claude / MCP

Just ask Claude:
> "Configure the Drive service account credential. Here's the JSON: <paste>"

Claude calls `workflow_credentials_set` then `workflow_credentials_test`.

## Step 8: Verify with a trivial workflow

Create a test workflow that does nothing but list the workspace folder:

```json
{
  "version": "1.0",
  "title": "Drive smoke test",
  "form_id": "<any test form>",
  "enabled": false,
  "steps": [
    {
      "name": "find",
      "type": "drive_find_folder",
      "config": {
        "parent_id": "<WORKSPACE_FOLDER_ID>",
        "name": "<some subfolder name>"
      }
    }
  ]
}
```

Submit a form (or use the workflow_test endpoint with `dry_run: false`) and verify the run completes successfully. The output should show the folder metadata or `found: false` (also fine — proves the service account can query).

## Sharing strategy for client workspaces

For 725 Print Lab specifically, the workspace folder structure is:

```
725 Print Lab/
├── Bulk/
│   ├── 2026-05/
│   ├── 2026-06/
│   └── ...
├── Small/
│   ├── 2026-05/
│   └── ...
└── (other 725 internal folders)
```

The service account is shared on the `725 Print Lab/` folder (and inherits to children). This means:

- New per-month folders are created INSIDE the existing structure
- Service account doesn't have access to anything outside `725 Print Lab/`
- The Drive folder structure mirrors the team's existing organization

Each new client should follow the same pattern: dedicate a top-level folder, share it with the service account, reference the folder ID in workflow definitions.

## Troubleshooting

### "Permission denied" when uploading

The service account doesn't have edit access to the parent folder. Re-share with "Editor" role.

### "File not found" for an existing folder

The folder is in a Drive that hasn't been shared with the service account. Verify the folder's owner is correct and that the service account is in the share list.

### Quota exceeded

Service accounts have their own free 15GB quota. For high-volume clients:
- Make sure files are uploaded INTO a folder owned by the client's Google Workspace, not the service account itself (sharing inverts ownership in shared drives)
- Or: enable a paid Google Workspace plan and use a Shared Drive (service account quota doesn't apply in shared drives)

For 725 Print Lab and most service businesses, the free quota is sufficient (workflow files are small — design files are typically <50MB and get cleaned up periodically).

### Authentication errors after a long period

Service account JSON keys don't expire by default. If auth suddenly fails:
- Check Google Cloud Console for any disabled keys (admin may have rotated)
- Re-create a key if needed (Step 4) and update the credential

## Security notes

- The service account JSON key IS the credential. Anyone with the file can act as the service account. Treat like a password.
- FlowMint Workflows stores it encrypted in `wp_options` (AES-256-GCM)
- Never commit the JSON file to git
- Never include it in support tickets, screenshots, or shared docs
- Rotate keys annually (Cloud Console → Service Account → Keys → Add new key, then delete the old one)

## Per-client vs shared service account

Two strategies:

**Per-client service account (recommended for production):** each client has their own GCP project + service account. Isolation is total. If one credential leaks, only that client is affected.

**Shared service account (acceptable for dev/test):** one FlowMint-owned service account, shared on each client's workspace folder. Less management overhead but a single point of failure.

For v1.0 production use: per-client service account.

## Future: OAuth flow for end-clients

If a future client wants to use their own personal Drive (not a Google Workspace folder), the plugin would need a real OAuth flow with token refresh. This is deferred to v2+. For v1.0, service accounts only.
