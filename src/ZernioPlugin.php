<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Plugins\Zernio\Tools\ZernioMediaTool;
use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Plugins\Zernio\Tools\ZernioQueueTool;
use Spora\Plugins\Zernio\Tools\ZernioValidateTool;
use Spora\Plugins\Zernio\Tools\ZernioWebhooksTool;

/**
 * Zernio plugin entry point.
 *
 * Extends {@see AbstractPlugin} so we only override the hooks we use
 * (getName() and tools()); the base class supplies no-op defaults for the
 * rest. Each tool's constructor takes only container-resolvable types, so
 * PHP-DI autowires them — no register() binding is required.
 */
final class ZernioPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Zernio';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            ZernioAccountsTool::class,
            ZernioPostTool::class,
            ZernioQueueTool::class,
            ZernioAnalyticsTool::class,
            ZernioMediaTool::class,
            ZernioWebhooksTool::class,
            ZernioValidateTool::class,
        ];
    }

    /**
     * Translation catalogues this plugin ships, declared through
     * {@see \Spora\Plugins\PluginInterface::translationsPaths()}.
     *
     * Keys are the English source text — `de.json` reads
     * `{"Zernio Post": "Zernio-Beiträge"}`, not `{"tool": "…"}`. That is
     * what makes a copy edit detectable: the key changes and the old entry
     * orphans. The gate is this plugin's own `LocaleCatalogTest`, not the
     * host's `i18n:check` — that command harvests core's `app/` and reads
     * core's `locales/en.json`, so run against a plugin repo it reports
     * every catalogue key as an orphan.
     *
     * **Only operator copy belongs in here.** A string the model reads is
     * never translated: not any of the seven `#[Tool].description` values,
     * not any of the twenty-odd `#[ToolOperation].description` values, and
     * not any `#[ToolParameter].description`. All seven tools declare the
     * same three `#[ToolSetting]`s, so the flat catalogue holds their
     * three descriptions once rather than twenty-one times.
     *
     * `locales/de.json` holds the seven `#[Tool].displayName` values, the
     * three shared `#[ToolSetting].description` values and the manifest
     * description.
     *
     * Requires `spora-core >= 0.31.0` for the catalogue to be loaded. On an
     * older core the host never calls this hook and the plugin renders in
     * English.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
