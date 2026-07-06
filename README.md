# Zernio Plugin for Spora

Social-media scheduling and publishing for [Spora](https://github.com/spora-ai/spora-core)
agents, powered by the [Zernio](https://docs.zernio.com/) API. Lets an agent discover connected
social accounts, and draft, schedule, or publish posts across 15+ networks (Twitter/X, Instagram,
Facebook, LinkedIn, TikTok, YouTube, Bluesky, Threads, Telegram, Discord, and more).

> **Status:** baseline scaffold. The social-media tools (accounts discovery, post
> create/schedule/publish, queue, analytics) land in the follow-up implementation PR.

## Installation

```bash
php bin/spora plugin:install spora-ai/spora-plugin-zernio
```

For local development, install from a path repository or point `SPORA_PLUGINS_PATHS` at a checkout.

## Configuration

The Zernio API key is read from the tool settings (`api_key`) or, as a fallback, the
`ZERNIO_API_KEY` environment variable — so a self-hosted operator can configure one key for all
Zernio tools. The key is stored encrypted and is never sent to the LLM or written to logs.

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
