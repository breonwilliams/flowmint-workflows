# FlowMint Workflows — Reference Patterns

Anonymized worked examples showing how to compose the step library into common service-business workflows. These patterns are vendor-neutral — adapt the specific Drive folder IDs, Printavo IDs, and email templates to your client.

For the EXACT 725 Print Lab workflow definitions, see the client folder at `725 Print Lab/_FlowMint-Workflows-Migration/` (not in the plugin repo — kept separate so the plugin stays generic).

## Pattern 1: Quote-request pipeline (print shop, design agency, custom manufacturer)

A customer fills out a quote-request form with their project details and uploads a design file. The workflow:

1. Find or create a customer record in the order management system (Printavo, ShopVox, Houzz Pro, etc.)
2. Create a per-month folder in Drive
3. Create a per-submission subfolder
4. Upload the design file
5. Create a Quote/Inquiry in the order management system with all form details + Drive folder URL
6. Send the customer a "thank you" email
7. Delete the FormEngine entry (since data now lives in Printavo + Drive)

```json
{
  "version": "1.0",
  "title": "Quote Request → CRM + Drive",
  "form_id": "<form-id>",
  "enabled": true,
  "settings": {
    "max_retries": 3,
    "on_failure_notify": ["slack"]
  },
  "steps": [
    {
      "name": "customer",
      "type": "printavo_find_or_create_customer",
      "config": {
        "email": "{{ data.email }}",
        "name": "{{ data.full_name }}",
        "phone": "{{ data.phone }}",
        "company_name": "{{ data.company }}"
      }
    },
    {
      "name": "month_folder",
      "type": "drive_find_or_create_folder",
      "config": {
        "parent_id": "<DRIVE_PARENT_FOLDER_ID>",
        "name": "{{ now('Y-m') }}"
      }
    },
    {
      "name": "submission_folder",
      "type": "drive_create_folder",
      "config": {
        "parent_id": "{{ steps.month_folder.id }}",
        "name": "{{ data.company || data.full_name }}"
      }
    },
    {
      "name": "design_file",
      "type": "fre_get_file",
      "config": { "field_key": "design_file" }
    },
    {
      "name": "upload_design",
      "type": "drive_upload_file",
      "config": {
        "parent_id": "{{ steps.submission_folder.id }}",
        "file_field": "design_file"
      },
      "skip_if": "{{ !steps.design_file.exists }}"
    },
    {
      "name": "create_quote",
      "type": "printavo_create_quote",
      "config": {
        "customer_id": "{{ steps.customer.id }}",
        "user_id": <SALES_REP_USER_ID>,
        "invoice_status_id": <QUOTE_INQUIRY_STATUS_ID>,
        "nickname": "{{ steps.submission_folder.name }}",
        "description": "{{ template('quote-request-description') }}"
      }
    },
    {
      "name": "customer_ack",
      "type": "send_email_template",
      "config": {
        "to": "{{ data.email }}",
        "from_name": "Orders Team",
        "from_email": "<ORDERS_EMAIL>",
        "subject": "Thank you for your quote request",
        "template": "quote-request-ack"
      }
    },
    {
      "name": "cleanup",
      "type": "fre_delete_entry",
      "config": {}
    }
  ]
}
```

The `quote-request-description` template (lives at `wp-content/uploads/fmw-templates/quote-request-description.txt`):

```
== QUOTE REQUEST — submitted {{ entry.created_at }} ==

CONTACT
1. Full Name: {{ data.full_name }}
2. Email: {{ data.email }}
3. Phone: {{ data.phone }}
4. Company: {{ data.company }}

PROJECT
5. Project Type: {{ data.project_type }}
6. Quantity: {{ data.estimated_quantity }}
7. Timeline: {{ data.target_delivery_date }}
8. Design File: {{ steps.design_file.file_name }}

ADDITIONAL
9. Brief Description: {{ data.additional_details }}

CONSENT
10. Privacy Policy & Terms: Agreed (consent = {{ data.consent_agreement }})

DRIVE FOLDER
{{ steps.submission_folder.web_view_link }}
```

This pattern matches 725 Print Lab's Bulk and Small workflows almost 1:1. For 725, swap in:
- `<DRIVE_PARENT_FOLDER_ID>` → `1aVp_Zhd0OyL5K_h9dNYQ_f6lf_VOC8K8` (Bulk) or `1jY34wAy_E5Kuii7AAIaL3tqH8GPO7UmF` (Small)
- `<SALES_REP_USER_ID>` → `60522` (Alexis Lilly)
- `<QUOTE_INQUIRY_STATUS_ID>` → `416419` (Quote - JotForm Inquiry)
- `<ORDERS_EMAIL>` → `orders@725printlab.com`

## Pattern 2: Lead capture with CRM sync (HVAC, plumbing, roofing, lawn care)

A homeowner fills out a "request a quote" form. The workflow:

1. Send the lead to a CRM (Salesforce, HubSpot, Pipedrive, etc.) via HTTP POST
2. Create a per-day folder in Drive (one folder per day, all leads grouped)
3. If photos were uploaded (e.g., "show us your roof"), upload them
4. Send confirmation email
5. Notify the field team via Slack

```json
{
  "version": "1.0",
  "title": "Service Lead → CRM + Drive + Slack",
  "form_id": "service-quote",
  "enabled": true,
  "steps": [
    {
      "name": "crm_sync",
      "type": "http_post",
      "config": {
        "url": "https://api.crm.example.com/v1/leads",
        "headers": {
          "Authorization": "Bearer {{ env.crm_api_token }}",
          "Content-Type": "application/json"
        },
        "body": {
          "first_name": "{{ data.first_name }}",
          "last_name": "{{ data.last_name }}",
          "email": "{{ data.email }}",
          "phone": "{{ data.phone }}",
          "address": "{{ data.address }}",
          "service_type": "{{ data.service_type }}",
          "notes": "{{ data.additional_details }}"
        }
      }
    },
    {
      "name": "day_folder",
      "type": "drive_find_or_create_folder",
      "config": {
        "parent_id": "<LEADS_DRIVE_FOLDER_ID>",
        "name": "{{ now('Y-m-d') }}"
      }
    },
    {
      "name": "lead_folder",
      "type": "drive_create_folder",
      "config": {
        "parent_id": "{{ steps.day_folder.id }}",
        "name": "{{ data.last_name }}, {{ data.first_name }} - {{ data.service_type }}"
      }
    },
    {
      "name": "upload_photos",
      "type": "drive_upload_file",
      "config": {
        "parent_id": "{{ steps.lead_folder.id }}",
        "file_field": "photos"
      },
      "on_error": "continue"
    },
    {
      "name": "customer_ack",
      "type": "send_email_template",
      "config": {
        "to": "{{ data.email }}",
        "from_name": "Customer Service",
        "from_email": "<COMPANY_EMAIL>",
        "subject": "We received your request",
        "template": "service-lead-ack"
      }
    },
    {
      "name": "team_slack",
      "type": "http_post",
      "config": {
        "url": "{{ env.slack_team_webhook }}",
        "body": {
          "text": "New {{ data.service_type }} lead: {{ data.first_name }} {{ data.last_name }} at {{ data.address }}. CRM: {{ steps.crm_sync.body.lead_id }}"
        }
      }
    },
    {
      "name": "cleanup",
      "type": "fre_delete_entry",
      "config": {}
    }
  ]
}
```

Notes:
- `crm_sync.on_error` defaults to `fail` — if the CRM is down, the workflow retries via Action Scheduler. After max retries, FlowMint is notified.
- `upload_photos.on_error` is `continue` — if photo upload fails, we still want the rest of the lead processing to complete. The lead is logged for manual upload later.
- Notice `env.slack_team_webhook` — Slack webhook URL stored as a credential, not hardcoded.

## Pattern 3: Appointment booking (consultation, service appointment)

A customer fills out a booking form. The workflow:

1. Verify the requested time slot is available (HTTP call to scheduling system)
2. Create the appointment via HTTP POST
3. Send confirmation email with .ics attachment
4. If a deposit field is filled, send a payment link

```json
{
  "version": "1.0",
  "title": "Appointment Booking",
  "form_id": "consultation-booking",
  "enabled": true,
  "steps": [
    {
      "name": "check_availability",
      "type": "http_get",
      "config": {
        "url": "https://api.scheduler.example.com/v1/availability",
        "headers": { "Authorization": "Bearer {{ env.scheduler_token }}" }
      }
    },
    {
      "name": "validate_slot",
      "type": "conditional",
      "config": {
        "if": "{{ contains(steps.check_availability.body.available_slots, data.requested_slot) }}",
        "then": [
          {
            "name": "create_appointment",
            "type": "http_post",
            "config": {
              "url": "https://api.scheduler.example.com/v1/appointments",
              "body": {
                "customer_email": "{{ data.email }}",
                "slot": "{{ data.requested_slot }}",
                "service": "{{ data.service }}"
              }
            }
          },
          {
            "name": "confirm_email",
            "type": "send_email_template",
            "config": {
              "to": "{{ data.email }}",
              "subject": "Your appointment is confirmed",
              "template": "appointment-confirmation"
            }
          }
        ],
        "else": [
          {
            "name": "unavailable_email",
            "type": "send_email_template",
            "config": {
              "to": "{{ data.email }}",
              "subject": "That time is no longer available",
              "template": "appointment-unavailable"
            }
          },
          {
            "name": "log_unavailable",
            "type": "log_warning",
            "config": {
              "message": "Slot {{ data.requested_slot }} unavailable when customer {{ data.email }} tried to book"
            }
          }
        ]
      }
    },
    {
      "name": "cleanup",
      "type": "fre_delete_entry",
      "config": {}
    }
  ]
}
```

This pattern shows conditional branching — different paths for "slot available" vs "slot taken".

## Pattern 4: Contact form (most basic — just send an email)

For clients whose workflow is "form submission → email to the team", you don't even need this plugin. FormEngine's built-in notification settings handle that. Use FlowMint Workflows only when you need MORE than email — Drive uploads, CRM sync, multi-step processing.

But for completeness, here's how a contact-form workflow would look:

```json
{
  "version": "1.0",
  "title": "Contact form notification",
  "form_id": "contact",
  "enabled": true,
  "steps": [
    {
      "name": "team_email",
      "type": "send_email",
      "config": {
        "to": "team@example.com",
        "from_name": "Website Contact Form",
        "from_email": "noreply@example.com",
        "subject": "New contact: {{ data.subject }}",
        "body": "From: {{ data.name }} <{{ data.email }}>\n\n{{ data.message }}",
        "reply_to": "{{ data.email }}"
      }
    },
    {
      "name": "auto_reply",
      "type": "send_email_template",
      "config": {
        "to": "{{ data.email }}",
        "subject": "We got your message",
        "template": "contact-auto-reply"
      }
    }
  ]
}
```

For this trivial case, FormEngine's notification settings + a simple wp_mail filter would also work. Use FlowMint Workflows when you need: Drive uploads, CRM sync, conditional logic, or visibility into success/failure history.

## Pattern 5: Multi-step error handling

When a workflow has a critical step that might fail (external API down, etc.), use `try_catch` to gracefully degrade:

```json
{
  "name": "create_quote_with_fallback",
  "type": "try_catch",
  "config": {
    "try": [
      {
        "name": "primary_create_quote",
        "type": "printavo_create_quote",
        "config": { ... }
      }
    ],
    "catch": [
      {
        "name": "log_failure",
        "type": "log_error",
        "config": {
          "message": "Printavo Quote creation failed for {{ data.email }} — manual follow-up required"
        }
      },
      {
        "name": "notify_team",
        "type": "send_email",
        "config": {
          "to": "<TEAM_EMAIL>",
          "subject": "Failed Printavo Quote — manual entry needed",
          "body": "Customer: {{ data.email }}\nForm data: {{ data | json }}"
        }
      }
    ],
    "catch_codes": ["external_4xx", "external_5xx", "auth_failed"]
  }
}
```

Result: if Printavo creation fails for ANY reason in the catch_codes list, the team is notified and the workflow continues (instead of failing the entire run). The customer still gets their ack email.

## Pattern 6: Idempotency for re-submitted forms

If a form might be submitted multiple times for the same logical entity (e.g., updating a quote rather than creating a new one), use the `printavo_find_customer` + `conditional` combo to update instead of create:

```json
{
  "name": "find_customer",
  "type": "printavo_find_customer",
  "config": { "email": "{{ data.email }}" }
},
{
  "name": "ensure_customer",
  "type": "conditional",
  "config": {
    "if": "{{ steps.find_customer.found }}",
    "then": [
      { "name": "log_existing", "type": "log_info", "config": {"message": "Existing customer {{ steps.find_customer.id }}"} }
    ],
    "else": [
      {
        "name": "create_customer",
        "type": "printavo_create_customer",
        "config": { "email": "{{ data.email }}", "name": "{{ data.full_name }}" }
      }
    ]
  }
}
```

(Or just use `printavo_find_or_create_customer` directly.)

## Pattern 7: Conditional file upload based on form fields

Some forms have multiple file fields where only one is filled depending on the form path. Skip uploads for empty fields:

```json
{
  "name": "design_file_meta",
  "type": "fre_get_file",
  "config": { "field_key": "design_file" }
},
{
  "name": "upload_design",
  "type": "drive_upload_file",
  "config": {
    "parent_id": "{{ steps.submission_folder.id }}",
    "file_field": "design_file"
  },
  "skip_if": "{{ !steps.design_file_meta.exists }}"
},
{
  "name": "tax_doc_meta",
  "type": "fre_get_file",
  "config": { "field_key": "tax_exempt_doc" }
},
{
  "name": "upload_tax_doc",
  "type": "drive_upload_file",
  "config": {
    "parent_id": "{{ steps.submission_folder.id }}",
    "file_field": "tax_exempt_doc",
    "rename_to": "tax_exempt_{{ data.full_name | snake_case }}.pdf"
  },
  "skip_if": "{{ !steps.tax_doc_meta.exists }}"
}
```

Each upload step has a `skip_if` that checks for file existence before attempting. Avoids the "file not found" error path entirely.

## Anti-patterns to avoid

### ❌ Don't put credentials in workflow JSON

Bad:
```json
{ "headers": { "Authorization": "Bearer abc123def456..." } }
```

Good:
```json
{ "headers": { "Authorization": "Bearer {{ env.crm_api_token }}" } }
```

Credentials live encrypted in `wp_options`, accessed via `{{ env.<key> }}`. Never put them in the JSON definition (which gets stored in DB plaintext, returned in REST responses, logged on errors).

### ❌ Don't assume external APIs always succeed

Bad:
```json
{ "name": "customer_id_from_quote", "value": "{{ steps.create_quote.customer_id }}" }
```

If `create_quote` failed, this interpolation produces an empty string and downstream steps get garbage. Use `try_catch` or `on_error: continue` and check explicitly.

### ❌ Don't write workflows that depend on step ORDER outside of data flow

Bad:
```json
[
  { "name": "step_a", "type": "drive_create_folder", "config": {...} },
  { "name": "step_b", "type": "send_email", "config": {...} },
  { "name": "step_c", "type": "drive_upload_file", "config": { "parent_id": "{{ steps.step_a.id }}" } }
]
```

If `send_email` is between `step_a` and `step_c` for no reason other than "we want the email sent first", this is fine — but understand that if `step_b` fails and retries, `step_a` runs again on retry. For idempotent `step_a`, this is fine. For non-idempotent, you've created a bug.

Workflows are linear with retries that restart from step 0. Order matters for correctness in failure modes.

### ❌ Don't put business logic in step output formatting

Bad: writing a `description` template that contains complex conditionals like "if data.budget_range > 5000, mark as VIP".

Good: use `conditional` and `set_variable` steps to compute the VIP flag, then reference `{{ vars.is_vip }}` in the template.

Logic in templates is hard to test and debug. Logic in steps is explicit and shows up in the run history.
