# PHP Layer-7 Anti-DDoS Gateway

> **Status:** Production-ready core with pending enhancements for shared storage / dashboard integration. See [Roadmap](#roadmap) for future work.

The Anti-DDoS Gateway is a drop-in PHP shield that sits in front of legacy or shared-hosting sites. It inspects every request, applies a multi-layer decision pipeline (Layer 0–5), issues stateless browser challenges, scores behaviour, and enforces session hardening without requiring Redis or databases.

This document provides a full overview of architecture, deployment, operations, and tuning.

---

## Table of Contents

1. [High-Level Overview](#high-level-overview)
2. [System Architecture](#system-architecture)
3. [Layer Pipeline](#layer-pipeline)
4. [Request Lifecycle](#request-lifecycle)
5. [Configuration](#configuration)
6. [Operational Practices](#operational-practices)
7. [Logging & Observability](#logging--observability)
8. [Security Considerations](#security-considerations)
9. [Development & Testing](#development--testing)
10. [Roadmap](#roadmap)

---

## High-Level Overview

- **Language/Runtime:** PHP 8+ (no external services required).
- **Entry point:** `loading.php` invoked via `auto_prepend_file` for every PHP request.
- **State storage:** File-based JSON/flat files in `security/data/` (blocklist, rate limit, maintenance state).
- **Client scripts:** `assets/js/challenge.js`, `assets/js/tab-guard.js` for browser verification and tab tracking.
- **Output:** JSON logs (`security/logs/security.log`) for every processed request.

### Core Features

- Multi-layer decision pipeline (IP reputation, traffic filter, rate limiting, browser challenge, behaviour scoring, session hardening).
- Stateless HMAC-signed browser challenge with fingerprinting (navigator, canvas, WebGL, audio, timing, debugger detection).
- Session hardening (UA/IP/fingerprint bind, per-tab heartbeat, refresh guard).
- Maintenance routines for log rotation and blocklist pruning.

---

## System Architecture

```
                        ┌──────────┐
Incoming Request ─────► │loading.php│ ──► SecurityPipeline ──► Layerised decision
                        └──────────┘
                              │
                              ▼
                        SecurityContext (request info + session)
                              │
                              ▼
                       ┌───────────────┐
                       │ SecurityResult│ ──► pass / challenge / block
                       └───────────────┘
                              │
          ┌───────────────────┴──────────────────┐
          ▼                                      ▼
    templates/layouts/security_page.php     challenge_placeholder.php
          │                                      │
          ▼                                      ▼
  Web response (403/429)                  challenge.js + verify.php

```

### Key Components

| Component | Responsibility |
| --- | --- |
| `SecurityContext` | Encapsulates request environment, sessions, fingerprint & tab ID management |
| `SecurityPipeline` | Executes layers sequentially until a decision is made |
| Layers 0–5 | Each layer enforces a specific policy (see table below) |
| Services | Shared logic (challenge tokens, behaviour scoring, session hardening, maintenance) |
| Templates | Unified UI for block/challenge responses |
| Client JS | Challenge verification (`challenge.js`) and per-tab heartbeat (`tab-guard.js`) |

---

## Layer Pipeline

| Layer | Description | Typical Response |
| --- | --- | --- |
| **0 – IP Reputation** | Checks blocklists, CIDR ranges, allowlists | pass / block |
| **1 – Traffic Filter** | Validates headers (Accept, Sec-Fetch), allowed methods, UA length | pass / challenge / block |
| **2 – Rate Limiter** | Sliding window counters with penalties/escalation | pass / challenge / block |
| **3 – Browser Challenge** | Issues JS challenge bundle, rate-limits retries | challenge / block |
| **4 – Behaviour Score** | Adjusts score based on request pattern; can promote bypass | pass / challenge / block |
| **5 – Session Hardening** | Enforces fingerprint/UA/IP lock, tab limits, refresh guard | pass / challenge / block |

Each layer returns a `SecurityResult` which may carry metadata (e.g., `retry_after` for Layer 3, `tabs` for Layer 5). Layers do not know about each other’s internals; they act on the shared `SecurityContext`.

---

## Request Lifecycle

1. **Auto-prepend execution:** `auto_prepend.php` includes `loading.php` before any request logic.
2. **Bootstrap:** Sessions start, configuration loaded, context/services initialised.
3. **Pipeline run:** `SecurityPipeline` executes Layer 0 → Layer 5 until a decision is made.
4. **Decision handling:**
   - PASS/ALLOW → original script continues.
   - CHALLENGE (Layer 3) → render `challenge_placeholder.php` (403).
   - BLOCK → render `security_page.php` with metadata (403/429).
5. **Logging:** Every outcome appended to `security/logs/security.log`.
6. **Maintenance:** On first request after interval, `MaintenanceService` cleans blocklists/logs.

---

## Configuration

See `setup.md` for step-by-step installation. Highlights:

- **Secrets:** `challenge.secret` must be long, random, rotated regularly.
- **Rate pruning:** `maintenance.blocklist_ttl` and `maintenance.cleanup_interval` determine how aggressive cleanup is.
- **Tab guard:** Ensure `assets/js/tab-guard.js` is injected into templates that require Layer 5 enforcement (challenge pages & upstream templates).
- **Auto-prepend:** Works with relative paths thanks to root and challenge shims.

---

## Operational Practices

### 1. Monitoring

- Tail `security/logs/security.log` during incidents to identify offending layers.
- Set up log shipping (ELK, Datadog, CloudWatch) for long term metrics (challenge rate, block rate, score trends).

### 2. Maintenance

- `MaintenanceService` runs opportunistically; can be called via cron if deterministic cleanup required.
- Rotate `security/logs/security.log` beyond `max_size_bytes`; service already does rotation but periodic archive is recommended.
- Purge `security/data/rate_limit_state.json` during staging tests to reset counters.

### 3. Secret Rotation

- Update `challenge.secret`.
- Clear challenge cookies (`ChallengeTokenService::clearCookie()` or instruct clients).
- Consider invalidating existing bypass tokens (e.g., flush sessions) during major incidents.

---

## Logging & Observability

- Each log line (JSON) includes `time`, `ip`, `ua_hash`, `uri`, `method`, `action`, `notes`, `metadata`.
- `notes` indicate triggered conditions (e.g., `layer3:locked`, `layer5:refresh-overflow`).
- Use jq for quick analysis:
  ```bash
  jq -r 'select(.action=="challenge") | [.time, .ip, (.notes|join(";"))] | @tsv' security/logs/security.log
  ```

---

## Security Considerations

- Ensure `auto_prepend.php` cannot be overwritten by untrusted users.
- Keep secret values out of version control.
- Validate that your upstream app cannot bypass the gateway (e.g., check `SECURITY_GATEWAY_INITIALIZED`).
- Deploy over HTTPS; challenge tokens rely on cookies and header integrity.

---

## Development & Testing

- **Local testing:** Use XAMPP/Valet; edit hosts file to simulate domain.
- **Traffic simulation:** `curl`, `ab`, or custom scripts to generate bursts/human patterns.
- **Unit testing:** Mock `SecurityContext` and run individual layers for deterministic behaviour.
- **End-to-end:** Enable verbose logging, ensure UI renders correctly, confirm `challenge.js` executes and verifies.

---

## Roadmap

- [ ] Replace file-backed persistence with pluggable drivers (Redis, MySQL) for scale-out.
- [ ] Add real-time dashboard summarising log metrics.
- [ ] Implement geo/IP reputation feeds.
- [ ] Provide CLI tooling for maintenance (log tail, blocklist admin, config diff).
- [ ] Integrate CAPTCHA fallback when behaviour score drops below threshold.
- [ ] Containerise deployment (Dockerfile + Helm chart).
- [ ] Automated test suite with synthetic traffic scenarios.

Contributions and suggestions are welcome. Fork the repo, open issues, or submit PRs.

---
## License

MIT License  
Copyright (c) 2025 Nguyễn Văn Trọng

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
