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
 * Each tool's constructor takes only container-resolvable types, so PHP-DI
 * autowires them — this plugin registers no container bindings of its own.
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
     * The plugin's `locales/` directory — one `<lang>.json` per language.
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
