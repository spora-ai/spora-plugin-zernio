<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Plugins\Zernio\Tools\ZernioQueueTool;
use Spora\Plugins\Zernio\ZernioPlugin;

it('reports its name', function (): void {
    expect((new ZernioPlugin())->getName())->toBe('Zernio');
});

it('contributes the four Zernio tools', function (): void {
    expect((new ZernioPlugin())->tools())->toBe([
        ZernioAccountsTool::class,
        ZernioPostTool::class,
        ZernioQueueTool::class,
        ZernioAnalyticsTool::class,
    ]);
});
