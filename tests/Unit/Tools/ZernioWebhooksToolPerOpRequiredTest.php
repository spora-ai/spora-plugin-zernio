<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioWebhooksTool;
use Spora\Tools\Attributes\ToolParameter;

function webhooksToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioWebhooksTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioWebhooksTool::class);
}

it('binds webhook_id to update_webhook and delete_webhook', function (): void {
    $expected = ['update_webhook', 'delete_webhook'];
    sort($expected);
    $actual = webhooksToolParameterArgs('webhook_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds name to create_webhook', function (): void {
    $expected = ['create_webhook'];
    sort($expected);
    $actual = webhooksToolParameterArgs('name')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds url to create_webhook and test_webhook', function (): void {
    $expected = ['create_webhook', 'test_webhook'];
    sort($expected);
    $actual = webhooksToolParameterArgs('url')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds events to create_webhook', function (): void {
    $expected = ['create_webhook'];
    sort($expected);
    $actual = webhooksToolParameterArgs('events')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
