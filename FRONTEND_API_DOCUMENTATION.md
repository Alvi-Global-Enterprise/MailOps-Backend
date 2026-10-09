# MailForge AI — Frontend REST API Documentation

Comprehensive REST API reference guide for frontend developers building the MailForge AI user interface.

> [!NOTE]
> All endpoints are versioned under `/api/v1`. Authentication uses standard **Bearer Token (Laravel Sanctum)**.

---

## Table of Contents
1. [General Information & Setup](#1-general-information--setup)
2. [Authentication Endpoints](#2-authentication-endpoints) *(Login, Logout, Current User)*
3. [Contacts API](#3-contacts-api)
   - [Upload / Import Spreadsheet (Excel & CSV)](#31-import-contacts-from-spreadsheet)
   - [List Contacts (Pagination, Search, Filter)](#32-list-contacts)
   - [Create Single Contact](#33-create-single-contact)
   - [Get Contact Details](#34-get-contact-details)
   - [Update Contact](#35-update-contact)
   - [Delete Contact](#36-delete-contact)
4. [Campaigns API](#4-campaigns-api)
   - [Campaign Lifecycle & State Machine](#41-campaign-lifecycle--state-machine)
   - [List Campaigns](#42-list-campaigns)
   - [Create Campaign](#43-create-campaign)
   - [Get Campaign Details](#44-get-campaign-details)
   - [Update Campaign](#45-update-campaign)
   - [Delete Campaign](#46-delete-campaign)
   - [Attach Recipients to Campaign](#47-attach-recipients-to-campaign)
   - [List Campaign Recipients](#48-list-campaign-recipients)
   - [Trigger AI Copywriting / Polish](#49-trigger-ai-copywriting--polish)
   - [Approve Campaign](#410-approve-campaign)
   - [Dispatch / Send Campaign](#411-dispatch--send-campaign)
   - [Pause In-Flight Campaign](#412-pause-in-flight-campaign)
   - [Resume Paused Campaign](#413-resume-paused-campaign)
   - [Cancel Campaign](#414-cancel-campaign)
   - [Get Live Campaign Status & Progress](#415-get-live-campaign-status--progress)
   - [Get Aggregate Delivery Statistics](#416-get-aggregate-delivery-statistics)
5. [Standard Response & Error Formats](#5-standard-response--error-formats)
6. [TypeScript Interfaces & Type Definitions](#6-typescript-interfaces--type-definitions)
7. [Axios Client Integration Example](#7-axios-client-integration-example)

---

## 1. General Information & Setup

### Base URL
- **Local Development:** `http://localhost:8000/api/v1`
- **Production:** `https://your-domain.com/api/v1`

### Default Headers
For all JSON requests:
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <TOKEN>
```
For file upload (`/contacts/import`):
```http
Accept: application/json
Content-Type: multipart/form-data
Authorization: Bearer <TOKEN>
```

---

## 2. Authentication Endpoints

### 2.1 Login
Authenticate user with email and password to receive a Sanctum Bearer Token.

- **Method:** `POST`
- **Endpoint:** `/auth/login`
- **Auth Required:** `No`

#### Request Body
```json
{
  "email": "user@example.com",
  "password": "Password123!",
  "device_name": "web-browser" // Optional string (default: mailforge-api-token)
}
```

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "Fahad Alvi",
      "email": "user@example.com",
      "created_at": "2026-10-08T12:00:00.000000Z",
      "updated_at": "2026-10-08T12:00:00.000000Z"
    },
    "token": "1|qX8w...abc123plaintexttoken"
  }
}
```

#### Error Response (`401 Unauthorized`)
```json
{
  "success": false,
  "message": "The provided credentials do not match our records."
}
```

---

### 2.2 Logout
Revoke the current access token.

- **Method:** `POST`
- **Endpoint:** `/auth/logout`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Successfully logged out"
}
```

---

### 2.3 Get Current User Profile
Fetch details of the currently authenticated user.

- **Method:** `GET`
- **Endpoint:** `/auth/user`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "User profile retrieved",
  "data": {
    "user": {
      "id": 1,
      "name": "Fahad Alvi",
      "email": "user@example.com",
      "created_at": "2026-10-08T12:00:00.000000Z",
      "updated_at": "2026-10-08T12:00:00.000000Z"
    }
  }
}
```

---

## 3. Contacts API

### 3.1 Import Contacts from Spreadsheet
Upload and import an Excel (`.xlsx`, `.xls`) or CSV (`.csv`) spreadsheet with contacts.

> [!TIP]
> **Smart Header Detection:** The backend automatically recognizes email column variations: `email`, `subscriber_email`, `contact_email`, `user_email`, `lead_email`, `email_id`, `mail`.
> Name columns like `subscriber_fname`, `first_name`, `fname`, `subscriber_lname`, `last_name`, `phone`, `mobile` are mapped automatically. Extra columns (city, country, ip, source, etc.) are saved automatically into `custom_data`.

- **Method:** `POST`
- **Endpoint:** `/contacts/import`
- **Auth Required:** `Yes` (Bearer Token)
- **Content-Type:** `multipart/form-data`

#### Request (FormData)
| Key | Type | Required | Description |
|---|---|---|---|
| `file` | File (`Binary`) | **Yes** | `.xlsx`, `.xls`, `.csv` file (max 10MB). |

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Contacts imported successfully.",
  "summary": {
    "total_rows": 22987,
    "valid_rows": 22987,
    "created": 22987,
    "duplicates_in_file": 0,
    "already_existing": 0,
    "invalid": 0
  },
  "invalid_rows": []
}
```

#### If Invalid Rows Exist
```json
{
  "success": true,
  "message": "Contacts imported successfully.",
  "summary": {
    "total_rows": 100,
    "valid_rows": 98,
    "created": 95,
    "duplicates_in_file": 3,
    "already_existing": 2,
    "invalid": 2
  },
  "invalid_rows": [
    {
      "row": 15,
      "email": "bad-email-format",
      "reason": "Invalid email address format."
    },
    {
      "row": 42,
      "email": "",
      "reason": "Email address is blank or missing."
    }
  ]
}
```

#### Error Response (`422 Unprocessable Entity`)
```json
{
  "message": "The spreadsheet must contain an \"email\" column header.",
  "errors": {
    "file": [
      "The spreadsheet must contain an \"email\" column header."
    ]
  }
}
```

---

### 3.2 List Contacts
Retrieve a paginated, searchable, and sortable list of contacts for the authenticated user.

- **Method:** `GET`
- **Endpoint:** `/contacts`
- **Auth Required:** `Yes` (Bearer Token)

#### Query Parameters
| Parameter | Type | Default | Description |
|---|---|---|---|
| `page` | integer | `1` | Page number |
| `per_page` | integer | `25` | Number of items per page (1 to 100) |
| `search` | string | `null` | Searches across `email`, `first_name`, `last_name`, `full_name`, `company` |
| `source` | string | `null` | Filter by source: `excel`, `csv`, `manual`, `api`, `google_sheets` |
| `is_active` | boolean | `null` | Filter active status (`true` or `false`) |
| `sort_by` | string | `created_at` | Allowed: `id`, `first_name`, `last_name`, `email`, `created_at`, `updated_at` |
| `sort_dir` | string | `desc` | Allowed: `asc` or `desc` |

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Contacts retrieved successfully.",
  "data": [
    {
      "id": 101,
      "email": "headada0@juno.com",
      "first_name": "Abdul",
      "last_name": "Shields",
      "full_name": "Abdul Shields",
      "company": null,
      "job_title": null,
      "phone": "3134216416",
      "external_id": null,
      "source": "excel",
      "source_reference": null,
      "custom_data": {
        "subscriber_city": "Dearborn",
        "subscriber_state": "MI",
        "subscriber_Country": "USA",
        "subscriber_Category": "Cryptocurrency"
      },
      "is_active": true,
      "created_at": "2026-10-09T03:00:00.000000Z",
      "updated_at": "2026-10-09T03:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 920,
    "per_page": 25,
    "to": 25,
    "total": 22987
  },
  "links": {
    "first": "http://localhost:8000/api/v1/contacts?page=1",
    "last": "http://localhost:8000/api/v1/contacts?page=920",
    "prev": null,
    "next": "http://localhost:8000/api/v1/contacts?page=2"
  }
}
```

---

### 3.3 Create Single Contact
Manually add a single contact.

- **Method:** `POST`
- **Endpoint:** `/contacts`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body
```json
{
  "email": "john.doe@company.com",      // Required, unique per user
  "first_name": "John",                 // Optional string
  "last_name": "Doe",                   // Optional string
  "full_name": "John Doe",              // Optional string (auto-computed if omitted)
  "company": "Acme Corp",               // Optional string
  "job_title": "Marketing Director",    // Optional string
  "phone": "+1-555-0199",               // Optional string
  "external_id": "CRM-1002",            // Optional string
  "custom_data": {                      // Optional JSON object for custom metadata
    "industry": "Tech",
    "tier": "Gold"
  },
  "is_active": true                     // Optional boolean (default true)
}
```

#### Success Response (`201 Created`)
```json
{
  "success": true,
  "message": "Contact created successfully.",
  "data": {
    "id": 102,
    "email": "john.doe@company.com",
    "first_name": "John",
    "last_name": "Doe",
    "full_name": "John Doe",
    "company": "Acme Corp",
    "job_title": "Marketing Director",
    "phone": "+1-555-0199",
    "external_id": "CRM-1002",
    "source": "manual",
    "source_reference": null,
    "custom_data": {
      "industry": "Tech",
      "tier": "Gold"
    },
    "is_active": true,
    "created_at": "2026-10-09T03:15:00.000000Z",
    "updated_at": "2026-10-09T03:15:00.000000Z"
  }
}
```

---

### 3.4 Get Contact Details
Retrieve a single contact by ID.

- **Method:** `GET`
- **Endpoint:** `/contacts/{id}`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Contact retrieved successfully.",
  "data": {
    "id": 102,
    "email": "john.doe@company.com",
    "first_name": "John",
    "last_name": "Doe",
    "full_name": "John Doe",
    "company": "Acme Corp",
    "job_title": "Marketing Director",
    "phone": "+1-555-0199",
    "external_id": "CRM-1002",
    "source": "manual",
    "source_reference": null,
    "custom_data": {
      "industry": "Tech"
    },
    "is_active": true,
    "created_at": "2026-10-09T03:15:00.000000Z",
    "updated_at": "2026-10-09T03:15:00.000000Z"
  }
}
```

---

### 3.5 Update Contact
Update an existing contact.

- **Method:** `PUT` or `PATCH`
- **Endpoint:** `/contacts/{id}`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body
All fields are optional; provide only the attributes you wish to update:
```json
{
  "first_name": "Jonathan",
  "company": "Acme Global",
  "custom_data": {
    "notes": "Spoke on call"
  }
}
```

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Contact updated successfully.",
  "data": {
    "id": 102,
    "email": "john.doe@company.com",
    "first_name": "Jonathan",
    "last_name": "Doe",
    "full_name": "Jonathan Doe",
    "company": "Acme Global",
    "job_title": "Marketing Director",
    "phone": "+1-555-0199",
    "external_id": "CRM-1002",
    "source": "manual",
    "source_reference": null,
    "custom_data": {
      "industry": "Tech",
      "notes": "Spoke on call"
    },
    "is_active": true,
    "created_at": "2026-10-09T03:15:00.000000Z",
    "updated_at": "2026-10-09T03:20:00.000000Z"
  }
}
```

---

### 3.6 Delete Contact
Soft-deletes a contact.

- **Method:** `DELETE`
- **Endpoint:** `/contacts/{id}`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Contact deleted successfully."
}
```

---

## 4. Campaigns API

### 4.1 Campaign Lifecycle & State Machine

MailForge enforces strict state machine transitions to protect campaigns:

```
[DRAFT] ───────► [GENERATING] ──────► [REVIEW] ──────► [APPROVED] ──────► [QUEUED] ──────► [SENDING] ──────► [COMPLETED]
   │                                     │                 │                 │                │
   │                                     ▼                 ▼                 ▼                ▼
   │                                  [PAUSED]          [PAUSED]          [PAUSED]         [PAUSED]
   │                                     │                 │                 │                │
   ▼                                     ▼                 ▼                 ▼                ▼
[CANCELLED]                          [CANCELLED]       [CANCELLED]       [CANCELLED]      [CANCELLED]
```

#### Campaign Email Modes:
1. `direct`: You provide the exact `subject` and `email_body`. AI generation is skipped. Directly goes to `approved` or `review`.
2. `generate`: You provide prompt instructions (`ai_prompt`). AI generates both subject line and persuasive email copy. Status moves to `generating` -> `review`.
3. `polish`: You provide a rough draft in `raw_input_email`. AI refines and formats it into professional copy. Status moves to `generating` -> `review`.

#### Campaign Status Enums:
- `draft`
- `generating` (AI job in background)
- `review` (Copy ready for human review)
- `approved` (Ready to be dispatched)
- `queued` (Enqueued into queue workers)
- `sending` (Actively delivering via SES/SMTP)
- `paused` (Paused by user; workers halt delivery)
- `cancelled` (Cancelled by user; terminal state)
- `completed` (All emails finished)
- `failed` (Encountered permanent system failure)

---

### 4.2 List Campaigns
Retrieve all campaigns for the authenticated user.

- **Method:** `GET`
- **Endpoint:** `/campaigns`
- **Auth Required:** `Yes` (Bearer Token)

#### Query Parameters
| Parameter | Type | Default | Description |
|---|---|---|---|
| `page` | integer | `1` | Page number |
| `per_page` | integer | `25` | Items per page (1 to 100) |
| `search` | string | `null` | Searches `name` and `subject` |
| `status` | string | `null` | Filter by status (`draft`, `review`, `sending`, `completed`, etc.) |
| `email_mode` | string | `null` | Filter by mode (`direct`, `generate`, `polish`) |
| `sort` | string | `created_at` | Allowed: `id`, `name`, `status`, `created_at`, `scheduled_at`, `started_at` |
| `direction`| string | `desc` | `asc` or `desc` |

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaigns retrieved successfully.",
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "name": "Crypto Investor Newsletter #1",
      "subject": "Exclusive Market Insights & Updates",
      "email_body": "<p>Hello {{firstName}}, here are today's top updates...</p>",
      "email_body_text": "Hello, here are today's top updates...",
      "raw_input_email": null,
      "email_mode": "generate",
      "ai_provider": "gemini",
      "ai_prompt": "Write a friendly, high-converting market update for crypto enthusiasts.",
      "status": "completed",
      "source_type": null,
      "source_reference": null,
      "total_recipients": 500,
      "sent_count": 498,
      "failed_count": 2,
      "is_review_required": false,
      "scheduled_at": null,
      "approved_at": "2026-10-09T04:00:00.000000Z",
      "started_at": "2026-10-09T04:01:00.000000Z",
      "completed_at": "2026-10-09T04:05:00.000000Z",
      "created_at": "2026-10-09T03:30:00.000000Z",
      "updated_at": "2026-10-09T04:05:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 25,
    "to": 1,
    "total": 1
  },
  "links": {
    "first": "http://localhost:8000/api/v1/campaigns?page=1",
    "last": "http://localhost:8000/api/v1/campaigns?page=1",
    "prev": null,
    "next": null
  }
}
```

---

### 4.3 Create Campaign
Create a new campaign draft.

- **Method:** `POST`
- **Endpoint:** `/campaigns`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body (Mode: `generate`)
```json
{
  "name": "Crypto Weekly Newsletter",
  "email_mode": "generate",
  "ai_provider": "gemini",                 // Optional: "gemini" | "openai"
  "ai_prompt": "Create an engaging newsletter explaining Bitcoin price action and ETF inflows.",
  "scheduled_at": "2026-10-15T10:00:00Z"   // Optional ISO string
}
```

#### Request Body (Mode: `polish`)
```json
{
  "name": "Product Launch Announcement",
  "email_mode": "polish",
  "ai_provider": "openai",
  "raw_input_email": "Hey guys, our new electric scooter model is ready. Battery lasts 50 miles. Buy now with 10% off code LAUNCH10.",
  "ai_prompt": "Make it sound exciting, sleek, and premium."
}
```

#### Request Body (Mode: `direct`)
```json
{
  "name": "Security Notice",
  "email_mode": "direct",
  "subject": "Important update regarding your account security",
  "email_body": "<p>Dear valued user, please review the security guidelines attached.</p>",
  "email_body_text": "Dear valued user, please review the security guidelines attached."
}
```

#### Success Response (`201 Created`)
```json
{
  "success": true,
  "message": "Campaign created successfully.",
  "data": {
    "id": 2,
    "user_id": 1,
    "name": "Crypto Weekly Newsletter",
    "subject": null,
    "email_body": null,
    "email_body_text": null,
    "raw_input_email": null,
    "email_mode": "generate",
    "ai_provider": "gemini",
    "ai_prompt": "Create an engaging newsletter explaining Bitcoin price action and ETF inflows.",
    "status": "draft",
    "total_recipients": 0,
    "sent_count": 0,
    "failed_count": 0,
    "is_review_required": false,
    "created_at": "2026-10-09T05:00:00.000000Z",
    "updated_at": "2026-10-09T05:00:00.000000Z"
  }
}
```

---

### 4.4 Get Campaign Details
Get comprehensive campaign details by ID.

- **Method:** `GET`
- **Endpoint:** `/campaigns/{id}`
- **Auth Required:** `Yes` (Bearer Token)

---

### 4.5 Update Campaign
Update an editable campaign draft (allowed only when status is `draft`, `review`, or `approved`).

- **Method:** `PUT` or `PATCH`
- **Endpoint:** `/campaigns/{id}`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body
```json
{
  "name": "Updated Campaign Title",
  "subject": "Refined Subject Line",
  "email_body": "<h2>Updated HTML Body</h2>",
  "ai_prompt": "Adjusted prompt instruction"
}
```

#### Conflict Error (`409 Conflict`)
If you try to edit a campaign that is already `sending`, `queued`, or `completed`:
```json
{
  "success": false,
  "message": "Campaign cannot be updated in current status [sending]."
}
```

---

### 4.6 Delete Campaign
Delete a campaign draft.

- **Method:** `DELETE`
- **Endpoint:** `/campaigns/{id}`
- **Auth Required:** `Yes` (Bearer Token)

> [!WARNING]
> You cannot delete a campaign that is actively `sending` or `queued`. You must call `/pause` or `/cancel` first.

---

### 4.7 Attach Recipients to Campaign
Attach existing contacts from the user's contact book to this campaign.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/recipients`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body
```json
{
  "contact_ids": [101, 102, 103, 104, 105]
}
```

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Recipients attached to campaign successfully.",
  "data": {
    "campaign_id": 2,
    "total_recipients": 5
  }
}
```

---

### 4.8 List Campaign Recipients
View the attached recipients for a campaign and their individual delivery statuses.

- **Method:** `GET`
- **Endpoint:** `/campaigns/{id}/recipients`
- **Auth Required:** `Yes` (Bearer Token)

#### Query Parameters
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 25)
- `status`: Filter by recipient status (`draft`, `queued`, `processing`, `sent`, `failed`, `cancelled`)
- `search`: Search recipient email or subject
- `sort_by`: `id`, `email`, `status`, `attempts`, `sent_at`, `failed_at`, `created_at`
- `sort_dir`: `asc` or `desc`

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign recipients retrieved successfully.",
  "data": [
    {
      "id": 1,
      "campaign_id": 2,
      "contact_id": 101,
      "email": "headada0@juno.com",
      "subject": "Exclusive Market Insights",
      "status": "sent",
      "attempts": 1,
      "last_error": null,
      "custom_attributes": {},
      "sent_at": "2026-10-09T05:10:00.000000Z",
      "failed_at": null,
      "created_at": "2026-10-09T05:05:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 1
  }
}
```

---

### 4.9 Trigger AI Copywriting / Polish
Trigger AI generation in the background.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/generate`
- **Auth Required:** `Yes` (Bearer Token)

#### Request Body (Optional overrides)
```json
{
  "ai_prompt": "Optional updated prompt instruction",
  "tone": "enthusiastic",                   // Optional: "professional" | "urgent" | "friendly"
  "target_audience": "Crypto Investors",   // Optional
  "key_points": "10% APY, Zero Fees"        // Optional
}
```

#### Success Response (`202 Accepted`)
```json
{
  "success": true,
  "message": "Campaign AI generation queued.",
  "data": {
    "campaign_id": 2,
    "status": "generating"
  }
}
```

> [!NOTE]
> Frontend should poll `GET /campaigns/{id}/status` until `generation_status` changes from `"generating"` to `"completed"`. The campaign status will automatically move to `"review"`.

---

### 4.10 Approve Campaign
Approve the campaign content (required gate after AI review before dispatching).

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/approve`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign approved successfully.",
  "data": {
    "id": 2,
    "status": "approved",
    "approved_at": "2026-10-09T05:15:00.000000Z"
  }
}
```

---

### 4.11 Dispatch / Send Campaign
Enqueue all approved recipients into the background email queue for delivery.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/dispatch`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`202 Accepted`)
```json
{
  "success": true,
  "message": "Campaign dispatch initiated successfully.",
  "data": {
    "campaign_id": 2,
    "status": "sending",
    "enqueued_recipients": 500
  }
}
```

---

### 4.12 Pause In-Flight Campaign
Immediately stop sending any remaining queued emails.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/pause`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign paused successfully.",
  "data": {
    "id": 2,
    "status": "paused"
  }
}
```

---

### 4.13 Resume Paused Campaign
Resume delivering queued recipients.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/resume`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`202 Accepted`)
```json
{
  "success": true,
  "message": "Campaign resumed successfully.",
  "data": {
    "id": 2,
    "status": "sending"
  }
}
```

---

### 4.14 Cancel Campaign
Permanently cancel a campaign.

- **Method:** `POST`
- **Endpoint:** `/campaigns/{id}/cancel`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign cancelled successfully.",
  "data": {
    "id": 2,
    "status": "cancelled"
  }
}
```

---

### 4.15 Get Live Campaign Status & Progress
Fetch real-time delivery progress metrics (ideal for progress bars and live status polls).

- **Method:** `GET`
- **Endpoint:** `/campaigns/{id}/status`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign status retrieved successfully.",
  "data": {
    "campaign_id": 2,
    "campaign_status": "sending",
    "email_mode": "generate",
    "total_recipients": 500,
    "queued_count": 150,
    "processing_count": 10,
    "sent_count": 338,
    "failed_count": 2,
    "cancelled_count": 0,
    "generation_status": "completed",
    "generation_error": null,
    "delivery_progress": 68.0,
    "timestamps": {
      "created_at": "2026-10-09T05:00:00.000000Z",
      "updated_at": "2026-10-09T05:18:00.000000Z",
      "scheduled_at": null,
      "approved_at": "2026-10-09T05:15:00.000000Z",
      "started_at": "2026-10-09T05:16:00.000000Z",
      "completed_at": null
    }
  }
}
```

---

### 4.16 Get Aggregate Delivery Statistics
Detailed counter of recipient delivery states.

- **Method:** `GET`
- **Endpoint:** `/campaigns/{id}/delivery-status`
- **Auth Required:** `Yes` (Bearer Token)

#### Success Response (`200 OK`)
```json
{
  "success": true,
  "message": "Campaign delivery status retrieved successfully.",
  "data": {
    "campaign_id": 2,
    "total": 500,
    "draft": 0,
    "queued": 150,
    "processing": 10,
    "sent": 338,
    "failed": 2,
    "cancelled": 0
  }
}
```

---

## 5. Standard Response & Error Formats

### Validation Error (`422 Unprocessable Entity`)
Whenever a request body fails Laravel FormRequest validation rules:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email has already been taken."
    ],
    "file": [
      "The file must be a spreadsheet of type: xlsx, xls, or csv."
    ]
  }
}
```

### Authentication Error (`401 Unauthorized`)
When token is missing or expired:
```json
{
  "message": "Unauthenticated."
}
```

### Forbidden Error (`403 Forbidden`)
When attempting to access resources belonging to another user:
```json
{
  "message": "This action is unauthorized."
}
```

### State Conflict Error (`409 Conflict`)
When an action is illegal in the current status:
```json
{
  "success": false,
  "message": "Cannot trigger AI generation for campaign in [sending] status."
}
```

---

## 6. TypeScript Interfaces & Type Definitions

Copy and paste these interfaces into your frontend project (e.g. `src/types/api.ts`):

```typescript
export interface User {
  id: number;
  name: string;
  email: string;
  created_at: string;
  updated_at: string;
}

export type ContactSource = 'excel' | 'csv' | 'manual' | 'api' | 'google_sheets';

export interface Contact {
  id: number;
  email: string;
  first_name: string | null;
  last_name: string | null;
  full_name: string | null;
  company: string | null;
  job_title: string | null;
  phone: string | null;
  external_id: string | null;
  source: ContactSource;
  source_reference: string | null;
  custom_data: Record<string, any>;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface SpreadsheetImportSummary {
  total_rows: number;
  valid_rows: number;
  created: number;
  duplicates_in_file: number;
  already_existing: number;
  invalid: number;
}

export interface SpreadsheetInvalidRow {
  row: number;
  email: string;
  reason: string;
}

export interface SpreadsheetImportResponse {
  success: boolean;
  message: string;
  summary: SpreadsheetImportSummary;
  invalid_rows: SpreadsheetInvalidRow[];
}

export type EmailMode = 'direct' | 'generate' | 'polish';
export type AiProvider = 'gemini' | 'openai';
export type CampaignStatus = 
  | 'draft' 
  | 'generating' 
  | 'review' 
  | 'approved' 
  | 'queued' 
  | 'sending' 
  | 'paused' 
  | 'cancelled' 
  | 'completed' 
  | 'failed';

export interface Campaign {
  id: number;
  user_id: number;
  name: string;
  subject: string | null;
  email_body: string | null;
  email_body_text: string | null;
  raw_input_email: string | null;
  email_mode: EmailMode;
  ai_provider: AiProvider | null;
  ai_prompt: string | null;
  status: CampaignStatus;
  source_type: string | null;
  source_reference: string | null;
  total_recipients: number;
  sent_count: number;
  failed_count: number;
  is_review_required: boolean;
  scheduled_at: string | null;
  approved_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface CampaignStatusReport {
  campaign_id: number;
  campaign_status: CampaignStatus;
  email_mode: EmailMode;
  total_recipients: number;
  queued_count: number;
  processing_count: number;
  sent_count: number;
  failed_count: number;
  cancelled_count: number;
  generation_status: 'none' | 'generating' | 'completed' | 'error';
  generation_error: string | null;
  delivery_progress: number;
  timestamps: {
    created_at: string | null;
    updated_at: string | null;
    scheduled_at: string | null;
    approved_at: string | null;
    started_at: string | null;
    completed_at: string | null;
  };
}

export interface PaginatedResponse<T> {
  success: boolean;
  message: string;
  data: T[];
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
}
```

---

## 7. Axios Client Integration Example

```typescript
import axios from 'axios';

// 1. Create client instance
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  headers: {
    'Accept': 'application/json',
  },
});

// 2. Attach Bearer token to all requests
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// 3. Example Functions

// Auth
export const login = async (email: string, password: string) => {
  const res = await api.post('/auth/login', { email, password });
  localStorage.setItem('auth_token', res.data.data.token);
  return res.data.data.user;
};

export const logout = async () => {
  await api.post('/auth/logout');
  localStorage.removeItem('auth_token');
};

// Contacts
export const uploadSpreadsheet = async (file: File) => {
  const formData = new FormData();
  formData.append('file', file);

  const res = await api.post('/contacts/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return res.data;
};

export const getContacts = async (page = 1, search = '') => {
  const res = await api.get('/contacts', { params: { page, search } });
  return res.data;
};

// Campaigns
export const createCampaign = async (payload: {
  name: string;
  email_mode: 'generate' | 'polish' | 'direct';
  ai_prompt?: string;
  subject?: string;
  email_body?: string;
}) => {
  const res = await api.post('/campaigns', payload);
  return res.data.data;
};

export const attachRecipients = async (campaignId: number, contactIds: number[]) => {
  const res = await api.post(`/campaigns/${campaignId}/recipients`, {
    contact_ids: contactIds,
  });
  return res.data;
};

export const dispatchCampaign = async (campaignId: number) => {
  const res = await api.post(`/campaigns/${campaignId}/dispatch`);
  return res.data;
};

export const getCampaignProgress = async (campaignId: number) => {
  const res = await api.get(`/campaigns/${campaignId}/status`);
  return res.data.data;
};
```
