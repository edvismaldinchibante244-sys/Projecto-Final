# WAF Evidence

Status: APPROVED

Approved at: 2026-08-23
Reviewer: Administrador
Reference: Revisao manual 23/08/2026

## Required Evidence

- Provider: cloudflare
- Mode: block/enforce
- Last reviewed at: 2026-08-23
- Reviewer: Administrador
- Production domain: see deployment configuration
- Managed rules enabled: verified by reviewer
- Custom rules enabled: verified by reviewer
- Rate limiting enabled: verified by reviewer
- Bot/automation controls enabled: verified by reviewer
- Screenshot/reference: Revisao manual 23/08/2026

## Minimum Rules Reviewed

- SQL injection payload blocking.
- XSS payload blocking.
- Path traversal probe blocking.
- Sensitive path blocking: `.env`, `app/`, `tools/`, `docs/`, backups and logs.
- Rate limits for public menu, reservation/order APIs, login and password reset.
- Alerting for blocked request spikes and 4xx/5xx spikes.

## Review Log

| Date | Reviewer | Result | Notes |
|---|---|---|---|
| 2026-08-23 | Administrador | APPROVED | Revisao manual 23/08/2026 |
