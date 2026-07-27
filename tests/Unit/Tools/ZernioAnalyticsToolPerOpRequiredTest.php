<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Tools\Attributes\ToolParameter;

function analyticsToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioAnalyticsTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioAnalyticsTool::class);
}

it('binds post_id to post_analytics and content_decay', function (): void {
    $expected = ['post_analytics', 'content_decay'];
    sort($expected);
    $actual = analyticsToolParameterArgs('post_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds account_id to post_analytics, best_time_to_post, and content_decay', function (): void {
    $expected = ['post_analytics', 'best_time_to_post', 'content_decay'];
    sort($expected);
    $actual = analyticsToolParameterArgs('account_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds platform to post_analytics and best_time_to_post', function (): void {
    $expected = ['post_analytics', 'best_time_to_post'];
    sort($expected);
    $actual = analyticsToolParameterArgs('platform')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds profile_id to daily_metrics and posting_frequency', function (): void {
    $expected = ['daily_metrics', 'posting_frequency'];
    sort($expected);
    $actual = analyticsToolParameterArgs('profile_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
