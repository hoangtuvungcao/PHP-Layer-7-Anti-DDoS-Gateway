# Anti-DDoS Gateway – Setup Guide

This guide explains how to install, configure, and deploy the PHP Layer-7 anti-DDoS gateway. Complete each section in order when preparing a new environment.

---

## 1. Prerequisites

- **Web server**: Apache with mod_php or Nginx/Lighttpd using PHP-FPM.
- **PHP version**: 8.0+ (typed properties, hash algorithms, strict mode).
- **Extensions**: `openssl`, `json`, `mbstring`, `session`.
- **Filesystem access**: Web server user must read/write `security/data/` and `security/logs/`.
- **Shell access** *(optional but recommended)*: to rotate secrets, review logs, run maintenance scripts.

---

## 2. Directory Layout

```
htdocs/
├── auto_prepend.php            # Root bootstrap (requires loading.php)
├── loading.php                 # Security pipeline entry point
├── assets/js/
│   ├── challenge.js            # Browser challenge client
│   └── tab-guard.js            # Per-tab heartbeat
├── challenge/
│   ├── auto_prepend.php        # Shim for challenge endpoints
│   └── verify.php              # Challenge verification API
├── security/
│   ├── config.php              # Central configuration
│   ├── SecurityContext.php     # Request/session wrapper
│   ├── SecurityPipeline.php    # Layer coordinator
│   ├── layers/                 # Layer0–Layer5 implementations
│   ├── services/               # Token, scoring, hardening services
│   ├── helpers/                # IP & fingerprint utilities
│   ├── logging/                # SecurityLogger
│   └── data/                   # Persistent state (blocklist, rate limit, maintenance)
├── templates/
│   └── layouts/security_page.php
├── README.md
└── setup.md
```

Keep this structure intact; several services rely on relative paths.

---

## 3. Initial Installation

1. **Copy source** into the document root (e.g., `/var/www/html`).
2. **Ensure writable directories**:
   ```bash
   mkdir -p security/data security/logs
   chown -R www-data:www-data security/data security/logs   # adjust user/group
   chmod -R 775 security/data security/logs
   ```
3. **Generate a challenge secret** and update `security/config.php`:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Replace the placeholder in `challenge.secret` with the generated string.
4. **Configure PHP sessions** (files or shared handler). Sessions must persist between requests.

---

## 4. Web Server Configuration (Auto-Prepend)

### Apache `.htaccess`

```
php_value auto_prepend_file "auto_prepend.php"
```

### Apache VirtualHost / Nginx + PHP-FPM

```
php_admin_value[auto_prepend_file] = /absolute/path/to/htdocs/auto_prepend.php
```

The root `auto_prepend.php` includes `loading.php` using absolute paths, while `challenge/auto_prepend.php` ensures challenge endpoints also load the pipeline correctly.

---

## 5. Key Configuration Knobs (`security/config.php`)

### 5.1 Rate Limiting (`rate_limit`)

- `window_seconds` / `max_requests`: Request burst thresholds.
- `cooldown_seconds`: Penalty decay.
- `block_penalty_threshold` / `challenge_penalty_threshold`: Escalation levels.

### 5.2 Browser Challenge (`challenge`)

- `secret`: HMAC key – rotate every 30 days.
- `bundle_ttl`: Lifetime of issued challenge bundles.
- `token_ttl`: Validity of challenge tokens after verification.
- `max_retries`: Number of verification attempts before temporary lock.
- `bypass_ttl`: Duration of challenge bypass after success.

### 5.3 Behaviour Scoring (`behaviour`)

- `initial_score`, `block_threshold`, `challenge_threshold`, `bypass_threshold`: Score-based decisions.
- `decay_interval`, `decay_step`: Penalty decay when behaviour improves.

### 5.4 Session Hardening (`session_hardening`)

- `max_tabs`: Maximum concurrent tabs per session.
- `tab_inactivity_ttl`: Timeout before stale tabs are purged.
- `max_refresh_per_window` & `refresh_window_seconds`: Refresh/F5 rate limiting.
- `block_on_refresh_overflow`: Whether to hard block or challenge on refresh abuse.

### 5.5 Logging & Maintenance

- `logging.file`: JSON log path (`security/logs/security.log`).
- `maintenance.cleanup_interval`: Minimum seconds between automatic cleanups.
- `maintenance.blocklist_ttl`: Retention for IP block entries.

Tune these values gradually and monitor logs to avoid false positives.

---

## 6. Deploying Changes

1. Update configuration values.
2. Warm the system by visiting the site once (ensure challenge passes).
3. Tail `security/logs/security.log` to confirm events are recorded.
4. Monitor for at least one traffic window (e.g., 30 min) before considering the deployment stable.

---

## 7. Maintenance & Operations

- `MaintenanceService` runs automatically when traffic hits the gateway and the configured interval has elapsed. It rotates `security.log` and prunes blocklists.
- For deterministic cleanup, you can create a cron job that calls a small PHP script invoking `MaintenanceService::runIfNeeded(true)`.
- Rotate your `challenge.secret` regularly; after rotation, invalidate lingering cookies by calling `ChallengeTokenService::clearCookie()` if needed.

---

## 8. Troubleshooting Checklist

| Issue | Cause | Resolution |
| --- | --- | --- |
| Browser challenge loops | Bundle expired or retries exceeded | Increase `bundle_ttl` / `max_retries`, ensure auto-prepend path correct |
| Tab limit blocks persist after closing tabs | Stale heartbeat not purged | Confirm `tab-guard.js` is included, adjust `tab_inactivity_ttl` |
| Frequent Layer2/Layer5 blocks | Aggressive thresholds | Relax rate-limit and refresh settings, review logs to identify patterns |
| No logs written | Permissions or wrong path | Verify ownership of `security/logs/`, update `logging.file` |

---

## 9. Testing Scenarios

- **Normal browsing:** Load homepage, ensure challenge passes within 1–2 attempts and bypass cookie is issued.
- **Burst attack:** `while true; do curl -I https://example.com/; done` – expect Layer2 penalties and eventual block.
- **Refresh abuse:** Rapid F5 in the browser – expect Layer5 refresh guard to trigger.
- **Multiple tabs:** Open > `max_tabs` tabs – Layer5 should challenge with retry timer.

Document results and adjust configuration accordingly.

---

## 10. Next Steps

- Review the `README.md` for architecture and layer deep dive.
- Integrate log shipping (ELK/Datadog) for long-term analytics.
- Plan a runbook for responding to real incidents (e.g., rotate secrets, temporary IP blocks).

---

Setup complete! You are ready to operate and customize the anti-DDoS gateway.
