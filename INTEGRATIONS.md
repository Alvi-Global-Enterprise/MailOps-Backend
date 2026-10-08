# MailForge AI — External Integrations & Setup Guide

This document describes how to configure and operate the external integration services in MailForge AI: **Google Sheets API**, **AI Copywriting Providers (OpenAI & Google Gemini)**, and **Email Delivery (Laravel Mail / SMTP)**.

---

## 1. Google Sheets Integration

MailForge AI synchronizes contacts directly from Google Sheets using the official Google API Client (`google/apiclient`).

### 1.1 Google Cloud Project & API Activation
1. Navigate to the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new Google Cloud Project (e.g. `MailForge-Outreach`).
3. In the sidebar, navigate to **APIs & Services** > **Library**.
4. Search for **Google Sheets API** and click **Enable**.

### 1.2 Creating Service Account Credentials
1. Go to **APIs & Services** > **Credentials**.
2. Click **Create Credentials** > **Service Account**.
3. Provide a service account name (e.g. `mailforge-sync-agent`).
4. Grant the service account the **Viewer** role (or no project-level role if restricting access solely to shared sheets).
5. Open the created Service Account > **Keys** tab > **Add Key** > **Create new key**.
6. Select **JSON** format and download the file.
7. Place the file securely in your Laravel storage directory (e.g. `storage/app/google/service-account.json`).
8. Add the path to your `.env`:
   ```env
   GOOGLE_SERVICE_ACCOUNT_JSON=storage/app/google/service-account.json
   GOOGLE_APPLICATION_NAME="MailForge AI"
   ```

### 1.3 Sharing the Spreadsheet
1. Open your target Google Sheet in a web browser.
2. Note the service account email from your JSON credentials (e.g. `mailforge-sync-agent@your-project.iam.gserviceaccount.com`).
3. Click the green **Share** button in the top right of your spreadsheet.
4. Paste the service account email and grant **Viewer** permissions.

### 1.4 Obtaining the Spreadsheet ID
The Spreadsheet ID is the alphanumeric identifier located between `/d/` and `/edit` in your Google Sheet URL:
```
https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=0
                                       ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                                                   SPREADSHEET ID
```

### 1.5 Sheet Structure & Column Mapping
* **Header Row:** Row 1 must contain column headers.
* **Standard Fields:** Common column names (`email`, `first_name`, `last_name`, `company`, `phone`, `job_title`) are automatically mapped to normalized Contact attributes.
* **Arbitrary Columns:** Any extra spreadsheet columns (e.g. `Industry`, `Lead Score`, `LinkedIn Profile`, `City`) are automatically extracted and preserved in the contact's `custom_data` JSONB field.
* **Mapping Configuration:** Column names are configurable per integration via `SheetMappingConfig` without requiring code modifications.

---

## 2. AI Copywriting Providers

MailForge AI features an abstracted, provider-agnostic AI copywriting layer managed via `AIEmailManager`. Business actions communicate through `AIEmailProviderInterface`.

### 2.1 Provider Selection
Configure the default provider in your `.env`:
```env
AI_PROVIDER=openai # Options: openai | gemini | mock
AI_MAX_TOKENS=1500
AI_TEMPERATURE=0.7
```

### 2.2 OpenAI Configuration
To use OpenAI (GPT-4o, GPT-4o-mini):
```env
OPENAI_API_KEY=sk-proj-your-openai-api-key-here
OPENAI_ORGANIZATION=
OPENAI_MODEL=gpt-4o-mini
OPENAI_TIMEOUT=30
```
* **Supported Operations:** `generateEmail()`, `polishEmail()`, `rewriteEmail()`.
* **Reliability:** Uses JSON object response formatting to guarantee deterministic schema outputs (`subject`, `body_html`, `body_text`).
* **Error Handling:** Automatically intercepts and normalizes 401 (invalid credentials), 429 (rate limits/insufficient quota), and timeouts into `AIProviderException`.

### 2.3 Google Gemini Configuration
To use Google Gemini (Gemini 1.5 Flash, Gemini 1.5 Pro):
```env
GEMINI_API_KEY=AIzaSyYourGeminiApiKeyHere
GEMINI_MODEL=gemini-1.5-flash
GEMINI_TIMEOUT=30
```
* **Endpoint:** Communicates with Google's REST endpoint (`https://generativelanguage.googleapis.com/v1beta`).
* **Reliability:** Configures `response_mime_type: "application/json"` with prompt contracts to guarantee structured JSON output.
* **Token Tracking:** Automatically captures Gemini token metadata (`promptTokenCount`, `candidatesTokenCount`).

### 2.4 Mock AI Provider (Deterministic Local Development)
For local development and continuous integration without API keys or costs:
```env
AI_PROVIDER=mock
```
* Returns deterministic, structured emails without making external network calls.
* Safely exercises all domain actions, preview interfaces, and campaign lifecycles.

---

## 3. Email Delivery Subsystem

Email transmission is decoupled behind `EmailDeliveryServiceInterface` and managed via `EmailDeliveryManager`.

### 3.1 Supported Drivers
* `smtp` — Standard SMTP server or transactional email relay.
* `laravel` — Default configured mailer in `config/mail.php`.
* `log` — Writes emails to `storage/logs/laravel.log` (ideal for local testing).
* `array` — In-memory mailer used during automated testing.
* `mock` — Mock delivery service tracking delivered message payloads.

### 3.2 SMTP Configuration
```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="outreach@mailforge.ai"
MAIL_FROM_NAME="MailForge AI Outreach"
```

### 3.3 Security & Privacy Protections
* **Credentials:** Passwords, API tokens, and authorization headers are never logged or stored in trace files.
* **PII Masking:** Recipient email addresses in logs are automatically masked (e.g. `j***n@domain.com`).
* **Idempotency:** Email deliveries emit a typed `DeliveryResult` capturing transmission status, message IDs, and provider response codes.

---

## 4. Running Verification Tests

To verify that all abstractions and integrations pass unit and feature tests:

```bash
# Run complete test suite (uses mocked external services)
php artisan test

# Verify code style compliance
php ./vendor/bin/pint --test
```
