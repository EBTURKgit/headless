



# Headless API — Installation Guide

A RESTful JSON API layer for phpBB 4, exposing all forum functionality for
modern frontend applications.

---

## Requirements

| Component | Version |
|-----------|---------|
| phpBB | 4.0.0-a2 or later |
| PHP | 8.2 or later |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Web Server | Apache with mod_rewrite or nginx |

---

## Installation ##

### 1. Copy the extension

Copy the `ext/headless/api/` directory into your phpBB installation:

```bash
cp -r ext/headless/api /path/to/phpbb/ext/headless/api
```

The extension must reside at `phpBB_ROOT/ext/headless/api/`.

### 2. Enable the extension

Navigate to **Admin Control Panel → Customise → Extensions**.
Find **Headless API** in the list and click **Enable**.

### 3. Clear the cache

```bash
rm -rf /path/to/phpbb/cache/*
```

Alternatively, clear the cache from the ACP: **General → Purge the cache**.

### 4. Run migrations

Migrations run automatically on enable. If they did not trigger, disable
and re-enable the extension.

---

## Post-Install Configuration ##

### CORS (Cross-Origin Resource Sharing)

The API must know which frontend domain is allowed to make requests.

Set the `API_ALLOWED_ORIGIN` environment variable to your frontend's origin:

```bash
# In your web server or .env file:
API_ALLOWED_ORIGIN=https://my-frontend.example.com
```

**Default:** `*` (all origins) — only safe for development.

### Environment Mode

Set `APP_ENV=production` in production to prevent stack traces and
internal file paths from being exposed in error responses:

```bash
APP_ENV=production
```

When unset or set to `development`, debug details are included in error
responses. **This must be set to `production` on public servers.**

### Rate Limiting

Rate limiting is enabled by default:
- **Global:** 60 requests per 60 seconds per IP
- **Login:** 5 attempts per 60 seconds
- **Registration:** 3 attempts per hour
- **Password reset:** 3 attempts per hour

Configure via ACP or environment variables:
- `headless_api_rate_limit` — global request limit
- `headless_api_rate_window` — window in seconds

### Logging

The extension logs API requests and errors to the `api_debug_log` table.
Log level is configurable via the ACP setting `headless_api_log_level`:
- `0` = DEBUG (all requests)
- `1` = INFO (default)
- `2` = WARN (warnings and errors only)
- `3` = ERROR (errors only)

---

## API Documentation ##

Once installed, interactive API documentation is available at:

```
https://your-forum.com/api/v1/docs/ui
```

The machine-readable OpenAPI 3.0 spec is served at:

```
https://your-forum.com/api/v1/docs
```

---

## Authentication ##

The API uses Bearer token authentication.

1. **Login:** `POST /api/v1/auth/login` with `{ "username": "...", "password": "..." }`
2. **Receive:** `{ "token": "...", "refresh_token": "..." }`
3. **Use:** Include `Authorization: Bearer <token>` in all subsequent requests
4. **Refresh:** `POST /api/v1/auth/refresh` with `{ "refresh_token": "..." }`
5. **Logout:** `POST /api/v1/auth/logout` (revokes the current token)

---

## Available Endpoints ##

| Group | Endpoints |
|-------|-----------|
| Auth | `login`, `logout`, `me`, `refresh`, `forgot-password`, `reset-password` |
| Register | `register`, `verify`, `resend-verification` |
| Forums | `list`, `show` |
| Topics | `list`, `show`, `create`, `update`, `delete`, `subscribe` |
| Posts | `list`, `show`, `create`, `update`, `delete`, `report`, `react` |
| Users | `show`, `update`, `avatar`, `posts`, `topics`, `groups` |
| Messages | `list`, `create`, `show`, `reply`, `delete`, `folders`, `move`, `read` |
| Bookmarks | `list`, `create`, `delete` |
| Drafts | `list`, `create`, `show`, `update`, `delete` |
| Polls | `show`, `vote`, `results`, `change-vote` |
| Groups | `list`, `show`, `members` |
| Friends/Foes | `list`, `add`, `remove` |
| Search | `search`, `save`, `saved`, `delete-saved` |
| Notifications | `list`, `read`, `read-all` |
| Moderation | `lock`, `move`, `pin`, `approve`, `ban`, `reports` |
| Attachments | `upload`, `delete` |
| Events | `list`, `create`, `show`, `update`, `delete`, `attend` |
| Tags | `list`, `topic-tags`, `add-tag`, `remove-tag` |
| Meta | `info`, `stats`, `bbcode-preview`, `online-users` |
| Options | `languages`, `styles` |

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| CORS errors in browser | Set `API_ALLOWED_ORIGIN` to your frontend domain |
| `401 Unauthorized` on every request | Ensure you are sending the `Authorization: Bearer <token>` header |
| Endpoints return 404 | Ensure the extension is enabled and cache is cleared |
| Database errors on enable | Check that the database user has `CREATE TABLE` privileges |

---

## Security Notes ##

- Tokens are stored as SHA-256 hashes — the plain token is never persisted.
- Token in query parameters is explicitly rejected; use the `Authorization` header only.
- Sensitive headers (`Authorization`, `Cookie`, `X-Api-Key`) are redacted from logs.
- CORS must be restricted to your frontend domain in production.
- `APP_ENV` must be set to `production` on public-facing servers.

---

## License

GPL-2.0-only
