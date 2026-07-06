<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Zernio\Tools\EchoTool;

/**
 * Zernio plugin entry point.
 *
 * Extends {@see AbstractPlugin} so we only override the hooks we use
 * (getName() and tools()); the base class supplies no-op defaults for the
 * rest. The real social-media tools land in the follow-up implementation PR;
 * this baseline ships a placeholder tool so the package boots and CI is green.
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
            EchoTool::class,
        ];
    }
}
