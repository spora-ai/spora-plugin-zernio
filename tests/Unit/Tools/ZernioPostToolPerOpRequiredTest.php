<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Tools\Attributes\ToolParameter;

function postToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioPostTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioPostTool::class);
}

it('binds post_id to 7 post-lifecycle ops', function (): void {
    $expected = ['get_post', 'update_post', 'delete_post', 'retry_post', 'unpublish_post', 'edit_post', 'update_post_metadata'];
    sort($expected);
    $actual = postToolParameterArgs('post_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds account_id to sync_external_posts', function (): void {
    $expected = ['sync_external_posts'];
    sort($expected);
    $actual = postToolParameterArgs('account_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds platform_for_unpublish to unpublish_post', function (): void {
    $expected = ['unpublish_post'];
    sort($expected);
    $actual = postToolParameterArgs('platform_for_unpublish')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds edit_content to edit_post', function (): void {
    $expected = ['edit_post'];
    sort($expected);
    $actual = postToolParameterArgs('edit_content')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds csv_content to bulk_upload', function (): void {
    $expected = ['bulk_upload'];
    sort($expected);
    $actual = postToolParameterArgs('csv_content')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
