<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioQueueTool;
use Spora\Tools\Attributes\ToolParameter;

function queueToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioQueueTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioQueueTool::class);
}

it('binds profile_id to all 6 queue ops', function (): void {
    $expected = ['list_slots', 'preview_queue', 'next_slot', 'create_slot', 'update_slot', 'delete_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('profile_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds queue_id to delete_slot', function (): void {
    $expected = ['delete_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('queue_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds name to create_slot', function (): void {
    $expected = ['create_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('name')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds day to create_slot and update_slot', function (): void {
    $expected = ['create_slot', 'update_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('day')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds time to create_slot and update_slot', function (): void {
    $expected = ['create_slot', 'update_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('time')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds slots to create_slot and update_slot', function (): void {
    $expected = ['create_slot', 'update_slot'];
    sort($expected);
    $actual = queueToolParameterArgs('slots')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
