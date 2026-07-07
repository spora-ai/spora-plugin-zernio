<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Plugins\Zernio\Tools\ZernioMediaTool;
use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Plugins\Zernio\Tools\ZernioQueueTool;
use Spora\Plugins\Zernio\Tools\ZernioValidateTool;
use Spora\Plugins\Zernio\Tools\ZernioWebhooksTool;
use Spora\Plugins\Zernio\ZernioPlugin;

it('reports its name', function (): void {
    expect((new ZernioPlugin())->getName())->toBe('Zernio');
});

it('contributes the seven Zernio tools in a stable order', function (): void {
    expect((new ZernioPlugin())->tools())->toBe([
        ZernioAccountsTool::class,
        ZernioPostTool::class,
        ZernioQueueTool::class,
        ZernioAnalyticsTool::class,
        ZernioMediaTool::class,
        ZernioWebhooksTool::class,
        ZernioValidateTool::class,
    ]);
});
