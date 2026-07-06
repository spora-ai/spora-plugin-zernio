# Zernio Plugin for Spora

Social-media scheduling and publishing for [Spora](https://github.com/spora-ai/spora-core)
agents, powered by the [Zernio](https://docs.zernio.com/) API. Lets an agent discover connected
social accounts, and draft, schedule, or publish posts across 15+ networks (Twitter/X, Instagram,
Facebook, LinkedIn, TikTok, YouTube, Bluesky, Threads, Telegram, Discord, and more), plus manage
the recurring posting queue and read analytics.

## Installation

```bash
php bin/spora plugin:install spora-ai/spora-plugin-zernio
```

For local development, install from a path repository or point `SPORA_PLUGINS_PATHS` at a checkout.

## Configuration

Each tool exposes three settings (configurable globally, per user, or per agent in the admin UI):

| Setting | Type | Default | Notes |
|---|---|---|---|
| `api_key` | password | — | Zernio API key (`sk_…`). Encrypted at rest; never sent to the LLM or logged. |
| `base_url` | text | `https://zernio.com/api/v1` | Override only if Zernio gives you a different host. |
| `http_timeout` | text | `30` | Per-request timeout in seconds. Falls back to `SPORA_TOOL_HTTP_TIMEOUT`. |

If `api_key` is left blank, the tools fall back to the **`ZERNIO_API_KEY`** environment variable —
so a self-hosted operator can set one key for all four Zernio tools instead of configuring each.

Create an API key in your Zernio dashboard (`Settings → API keys`). Keys are shown only once.

## Tools

All tools are grouped multi-operation tools; the LLM selects the operation via the `action`
argument. Publishing and destructive operations require approval by default.

### `zernio_accounts` — discovery (read-only)

| Operation | Purpose |
|---|---|
| `list_accounts` | List connected social accounts (optionally filter by `profile_id`). |
| `list_profiles` | List Zernio profiles (brand/project workspaces). |

Use this first to obtain the `account_ids` and platforms to target.

### `zernio_post` — post lifecycle

| Operation | Approval | Purpose |
|---|---|---|
| `create_post` | ✅ | Create a post. `publish_now: true` publishes immediately; `scheduled_for` + `timezone` schedules; neither saves a draft. Accepts `account_ids` (required), `content` (required), and optional `media_urls` (public URLs). |
| `list_posts` | — | List posts (filter by `status`, `profile_id`). |
| `get_post` | — | Get a single post by `post_id`. |
| `delete_post` | ✅ | Delete a post by `post_id`. |

### `zernio_queue` — recurring schedule

| Operation | Approval | Purpose |
|---|---|---|
| `list_slots` / `preview_queue` / `next_slot` | — | Inspect the posting queue. |
| `create_slot` / `update_slot` / `delete_slot` | ✅ | Manage recurring `day`/`time`/`timezone` slots. |

### `zernio_analytics` — insights (read-only)

| Operation | Purpose |
|---|---|
| `post_analytics` | Post performance metrics (by `post_id` and/or date range). |
| `follower_analytics` | Follower statistics for an `account_id`. |

> The analytics endpoint paths follow Zernio's documented `/analytics/*` surface; confirm the
> exact fields against [docs.zernio.com](https://docs.zernio.com/) for your account.

## Development

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan level 5
composer lint      # php-cs-fixer dry-run
```

## CI

Pest (PHP 8.4 + 8.5), PHPStan, php-cs-fixer, coverage, and a SonarCloud scan run on every push to
`main`, on `v*` tags, and on pull requests. External actions are pinned to full commit SHAs.

## License

MIT — see [LICENSE](LICENSE).
