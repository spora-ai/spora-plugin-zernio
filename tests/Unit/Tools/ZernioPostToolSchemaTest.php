<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

/**
 * The LLM-facing schema of the post tool, narrowed to the operations one
 * agent may invoke — the payload the model actually sees.
 *
 * @param  list<string> $allowedOps
 * @return array<string, mixed>
 */
function zernioPostToolSchema(array $allowedOps): array
{
    return OperationSchemaFilter::filter(
        ToolParameterSchemaBuilder::build(ZernioPostTool::class),
        $allowedOps,
    );
}

it('offers the unpublish account to an agent that may only unpublish', function (): void {
    $properties = (array) zernioPostToolSchema(['unpublish_post'])['properties'];

    expect($properties)->toHaveKey('account_id_for_unpublish')
        ->and($properties['account_id_for_unpublish']['type'])->toBe('string');
});

it('never makes the unpublish account mandatory', function (): void {
    $schema = zernioPostToolSchema(['unpublish_post']);

    expect($schema['required'])->not->toContain('account_id_for_unpublish');
});

it('offers the unpublish account whatever else the agent may do', function (): void {
    $properties = (array) zernioPostToolSchema(['create_post', 'sync_external_posts'])['properties'];

    expect($properties)->toHaveKey('account_id_for_unpublish');
});
