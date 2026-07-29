<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioValidateTool;
use Spora\Tools\Attributes\ToolParameter;

function validateToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioValidateTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioValidateTool::class);
}

it('binds content to validate_post_length', function (): void {
    $expected = ['validate_post_length'];
    sort($expected);
    $actual = validateToolParameterArgs('content')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds platform to validate_post_length', function (): void {
    $expected = ['validate_post_length'];
    sort($expected);
    $actual = validateToolParameterArgs('platform')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds url to validate_media', function (): void {
    $expected = ['validate_media'];
    sort($expected);
    $actual = validateToolParameterArgs('url')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds subreddit to validate_subreddit', function (): void {
    $expected = ['validate_subreddit'];
    sort($expected);
    $actual = validateToolParameterArgs('subreddit')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
