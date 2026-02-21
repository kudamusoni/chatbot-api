# Dashboard SPA Security Checklist

Use this checklist before releasing `/app/*` endpoints to production.

## CSRF + Session (Required)

1. Dashboard client calls `GET /sanctum/csrf-cookie` before login and any mutating request.
2. Requests include credentials and `X-XSRF-TOKEN` header.
3. `SANCTUM_STATEFUL_DOMAINS` includes all dashboard origins.
4. `SESSION_DOMAIN` is set correctly for your domain/subdomain layout.
5. CORS is configured to allow credentials for dashboard origins.
6. Session cookie flags (`secure`, `same_site`) match deployment topology.

## Widget Origin Source Migration

1. `client_settings.allowed_origins` is populated for all active clients.
2. Observe origin logs for at least 24 hours with no fallback-related denies.
3. Remove legacy fallback reads from `clients.settings.allowed_origins` after stable window.
