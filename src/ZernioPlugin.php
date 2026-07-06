<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Plugins\Zernio\Tools\ZernioQueueTool;

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
        ];
    }
}
