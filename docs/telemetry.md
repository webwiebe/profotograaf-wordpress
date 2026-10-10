# Telemetry Design

## Overview

The Profotograaf plugin sends usage and error telemetry when the site owner explicitly opts in. This document defines what data is collected, how it is protected, where it goes, and how owners can revoke consent at any time.

Telemetry is off by default. It respects Do Not Track (DNT) and Global Privacy Control (GPC) headers when available. No data is sent without consent. Revoking consent stops sending immediately and clears queued payloads.

## What We Collect

### Usage telemetry (daily batch)

Every 24 hours, one batch contains:

- **Installation identifier**: a random UUID assigned on first connect, rotated on disconnect
- **Plugin version**: the installed version of Profotograaf
- **WordPress version**: the major and minor version (not micro)
- **PHP version**: the major and minor version (not micro)
- **Site locale**: the WordPress locale, for example `nl_NL` or `en_US`
- **Active modules**: a list of enabled Profotograaf modules (for example: `gallery_block`, `lead_forms`, `client_gallery`)
- **Refresh outcomes**: count of successful, failed and retried token refresh attempts in the last 24 hours
- **Delivery outcomes**: count of successful and failed lead deliveries in the last 24 hours
- **Error codes**: a count of each distinct error code encountered during token refresh or lead delivery

No other fields are included. Every count begins at zero if no events occurred.

### Error telemetry (when an error occurs)

When a plugin error is reported (see "Error collection" below), the event contains:

- **Error code**: the slug that identifies this error type (for example: `api_timeout`, `invalid_token`, `lead_delivery_failed`)
- **HTTP status code**: when a request failed, the HTTP status code (0 if no response)
- **Error location**: the file name and line number where the error was handled
- **Plugin version**: the installed version of Profotograaf
- **WordPress version**: the major and minor version (not micro)
- **PHP version**: the major and minor version (not micro)
- **Timestamp**: when the error was detected

Additional context is removed: no access tokens, no API responses, no request bodies, no stack traces containing variables, no file paths outside the plugin directory.

Error events are rate-limited and deduplicated by (error_code, error_location, http_status). A fingerprint that appears more than once per minute is only sent once per 24-hour batch.

## What We Never Collect

Never collected, even from error events:

- Site URL or domain name
- Page URLs that users visit
- User accounts, names or email addresses (except as context when a form submission failed, and only the field name, not the value)
- Gallery content (titles, photos, descriptions, lead data)
- Lead form submissions (names, email addresses, phone numbers, message text)
- IP addresses (except the server logs of the destination, which is outside the plugin)
- Cookies or session identifiers
- Request or response bodies from API calls
- File paths or code outside the plugin directory

## Destination and Endpoint

Telemetry goes to two services operated by the plugin author, one request per event:

| Data | Service | Base URL | Request |
|------|---------|----------|---------|
| Error events | BugBarn | from the platform status call, for example `https://bb.profotograaf.nl` | `POST /api/v1/events` |
| Daily usage | FunnelBarn | `https://f.profotograaf.nl` | `POST /api/v1/events` |

The plugin appends `/api/v1/events` to the base URL. The error destination (`endpoint`, `project`, `key`, `environment`) is the `error_reporting` block of the platform status call, and without that block no error is sent. Each base URL can be replaced with a filter, and an empty string turns that destination off:

```php
add_filter( 'profotograaf_telemetry_endpoint', function () {
    return 'https://errors.example.com';
} );
add_filter( 'profotograaf_telemetry_usage_endpoint', function () {
    return 'https://usage.example.com';
} );
```

`profotograaf_telemetry_endpoint` sets the error destination and `profotograaf_telemetry_usage_endpoint` sets the usage destination.

If an endpoint is empty, nothing is sent to it even when consent is given.

## Authentication and Batching

Both services take an ingest-only collector key. The key can write events and cannot read anything. The FunnelBarn key ships in the plugin source, the BugBarn key comes from the status call. The plugin sends no `Authorization` or `x-api-key` header, both services answer 401 to them.

| Service | Headers |
|---------|---------|
| BugBarn | `X-BugBarn-Api-Key` and `X-BugBarn-Project`, both from the status call block |
| FunnelBarn | `X-FunnelBarn-Api-Key`, `X-FunnelBarn-Project: profotograaf-wordpress` |

Common rules:

- **Frequency**: once every 24 hours, via WP-Cron scheduled job `profotograaf_send_telemetry_batch`. The job queues one usage event and one event per error, then sends the queue.
- **Format**: JSON POST, content-type `application/json`. A service answers 202 when it accepts an event.
- **Timeout**: 10 seconds (respects the `profotograaf_http_timeout` filter)
- **Retry**: a failed event is retried up to 3 times, with exponential backoff (5 seconds, 10 seconds, 30 seconds), then the queue is dropped
- **Throttling**: on a 429 or 503 answer the plugin stops sending until the time in the `Retry-After` header (five minutes without one, at most one day) and keeps the queue. These answers do not count towards the 3 attempts.

### Usage event (FunnelBarn)

One `daily_usage` event per day. The install id is the session id. Counters stay numbers, the module list is one comma-separated string and every error code becomes a `error_code_<code>` count.

```json
{
  "name": "daily_usage",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "environment": "production",
  "properties": {
    "plugin_version": "0.2.0",
    "wordpress_version": "6.9",
    "php_version": "8.3",
    "locale": "en_US",
    "active_modules": "client_gallery,gallery_embed,lead_forms",
    "refresh_success": 24,
    "refresh_failed": 0,
    "refresh_retried": 0,
    "delivery_success": 15,
    "delivery_failed": 2,
    "error_code_lead_delivery_timeout": 1
  }
}
```

### Error events (BugBarn)

- **Timing**: queued with the daily job
- **Rate limiting**: one per (error_code, error_location, http_status) per 24-hour batch, deduplicated by earliest timestamp
- **Format**: one BugBarn event per error, at most 50 per day (the newest are kept). The plugin stores them in `profotograaf_telemetry_queued_errors` until the daily job runs. The error code is the `body`, the exception type and the message. The stack trace has one frame, the plugin file and line.

```json
{
  "body": "lead_delivery_timeout",
  "severityText": "error",
  "timestamp": "2024-10-01T09:30:00Z",
  "exception": {
    "type": "lead_delivery_timeout",
    "message": "lead_delivery_timeout",
    "stacktrace": [
      { "function": "unknown", "filename": "includes/leads/class-lead-sender.php", "lineno": 87 }
    ]
  },
  "attributes": {
    "install_id": "550e8400-e29b-41d4-a716-446655440000",
    "http_status": 0,
    "error_location": "includes/leads/class-lead-sender.php:87",
    "plugin_version": "0.2.0",
    "wordpress_version": "6.9",
    "php_version": "8.3"
  }
}
```

## Error Collection

Errors are collected from these hooks and processes:

- `profotograaf_refresh_failed`: triggered when token refresh fails
- `profotograaf_lead_failed`: triggered when lead delivery fails
- `profotograaf_lead_delivered`: triggered when a lead is sent successfully
- `profotograaf_lead_skipped`: triggered when a lead is skipped
- `profotograaf_lead_not_queued`: triggered when a lead cannot be queued
- `profotograaf_disconnected`: triggered when the connection ends

Additionally, errors logged to the WordPress error log (via `WP_Error` with a plugin-specific error code) are scraped and included.

Each error event is processed through scrubbing to ensure no tokens, URLs, email addresses or sensitive data leak into the telemetry. Scrubbing replaces common patterns:

- Tokens and API keys: replaced with `[TOKEN]`
- Email addresses: replaced with `[EMAIL]`
- URLs and domains: replaced with `[URL]`
- File paths outside the plugin directory: replaced with `[PATH]`

## Data Retention

The telemetry destination (server) retains:

- **Usage telemetry**: 13 months
- **Error events**: 13 months

Older data is deleted automatically. The plugin owner can delete all telemetry for a specific install ID via the `profotograaf_clear_telemetry` filter and the plugin settings page.

The plugin itself keeps in `wp_options`:

- `profotograaf_install_id`: the random UUID, used to identify this installation across telemetry events
- `profotograaf_telemetry_queued_*`: pending batches (cleared on successful send or after 3 failed attempts)

Both are removed when the plugin is uninstalled (see `uninstall.php`).

## Opt-In and Consent

### Initial consent prompt

After the site owner connects to Profotograaf for the first time, the plugin displays a one-time, dismissible consent prompt on the WordPress admin. The prompt:

- Explains that the plugin will send anonymous usage and error data to improve quality
- Includes a link to this document (`docs/telemetry.md`)
- Offers an "Enable telemetry" button and a "Not now" button
- Does not appear on sites that have never connected

The prompt can be dismissed without enabling telemetry. Choosing "Not now" does not prevent the prompt from appearing again the next week.

### Consent setting

The setting `profotograaf_telemetry_enabled` in WordPress options controls telemetry:

- **Default**: false (off)
- **Translatable name**: "Share anonymous usage data"
- **Description**: "Help improve Profotograaf by sharing anonymous usage and error data. This is completely optional and can be disabled at any time. No personal information is collected. See the telemetry design for details."
- **Link**: renders a link to `docs/telemetry.md` in the setting description

The setting is visible in the WordPress settings screen under Settings > Profotograaf.

### Programmatic control

Hosts can force telemetry off with the filter:

```php
add_filter( 'profotograaf_telemetry_enabled', '__return_false' );
```

### Consent with Do Not Track and Global Privacy Control

If the WordPress site receives a request with the DNT or GPC header set, the plugin respects it:

- When `DNT: 1` or `Sec-GPC: 1` is detected, telemetry is not sent in that request context (though the batch is still queued)
- For scheduled telemetry (WP-Cron), DNT and GPC are not checked (the job runs in the background with no HTTP client context)

Hosts should respect Do Not Track via `$_SERVER['HTTP_DNT']` and Global Privacy Control via `$_SERVER['HTTP_SEC_GPC']` if their use case requires it.

## Revoke and Data Deletion

### Revoking consent

Toggling `profotograaf_telemetry_enabled` off:

- Stops all telemetry immediately (queued batches are discarded)
- Does not delete already-sent telemetry (cannot be recalled)
- Can be toggled back on at any time

### Disconnecting the plugin

When the site disconnects from Profotograaf (the `profotograaf_disconnected` hook fires):

- The install ID is rotated (a new UUID is generated)
- All queued telemetry is discarded
- Already-sent telemetry is not deleted (it is associated with the old install ID)

A new install ID means the old telemetry is no longer associated with this WordPress site.

### Uninstalling the plugin

When the plugin is uninstalled:

- All telemetry options are removed (`profotograaf_install_id`, `profotograaf_telemetry_enabled`, `profotograaf_telemetry_queued_*`)
- Already-sent telemetry remains on the server (cannot be deleted by the plugin)

Hosts can request deletion of a specific install ID's data by contacting the telemetry endpoint operator.

## readme.txt External services entry

Add the following section to the External services section in `readme.txt`:

```
= Telemetry =

When you opt in to telemetry, Profotograaf sends anonymous usage and error data daily to https://f.profotograaf.nl (usage) and https://bb.profotograaf.nl (errors), or to the endpoints you configure. The data includes plugin, WordPress and PHP versions, locale, active modules, counts of successes and failures, and anonymized error codes. The site URL, user data, and gallery or lead content are never sent. The data is retained for 13 months. You can revoke consent or delete all telemetry by disconnecting from Profotograaf or toggling the telemetry setting off. See the telemetry design document for full details.
```

For the default configuration, also add:

```
The default destinations are https://bb.profotograaf.nl (BugBarn, errors, handed out by the platform) and https://f.profotograaf.nl (FunnelBarn, usage). Both are operated by the plugin author.
```

## Summary

| Aspect | Detail |
|--------|--------|
| **Consent** | Off by default, opt-in required, one-time prompt after connect |
| **What is sent** | Plugin, WordPress, PHP versions, locale, module list, refresh/delivery counts, error codes |
| **What is not sent** | Site URL, user data, lead content, gallery content, IP addresses, tokens |
| **Destination** | Errors to BugBarn (`bb.profotograaf.nl`), usage to FunnelBarn (`f.profotograaf.nl`), both filterable |
| **Frequency** | Daily batch via WP-Cron, 24-hour batching window |
| **Authentication** | Ingest-only collector keys in the `X-BugBarn-Api-Key` and `X-FunnelBarn-Api-Key` headers |
| **Retry** | Up to 3 attempts with exponential backoff on failure, a pause on 429 and 503 |
| **Revoke** | Toggle off in settings, or disconnect (rotates install ID) |
| **Retention** | 13 months on server, removed when plugin uninstalls locally |
| **Identifier** | Random install UUID, rotated on disconnect |
| **Rate limiting** | One error per fingerprint per 24-hour batch |
| **Respects** | Do Not Track, Global Privacy Control, WordPress preferences |
