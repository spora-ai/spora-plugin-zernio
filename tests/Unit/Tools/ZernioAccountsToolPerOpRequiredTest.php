<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Tools\Attributes\ToolParameter;

function accountsToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioAccountsTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioAccountsTool::class);
}

it('binds profile_id to update_profile, delete_profile, and move_account', function (): void {
    $expected = ['update_profile', 'delete_profile', 'move_account'];
    sort($expected);
    $actual = accountsToolParameterArgs('profile_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds account_id to update_account, move_account, disconnect_account, and get_account_health', function (): void {
    $expected = ['update_account', 'move_account', 'disconnect_account', 'get_account_health'];
    sort($expected);
    $actual = accountsToolParameterArgs('account_id')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds name to create_profile', function (): void {
    $expected = ['create_profile'];
    sort($expected);
    $actual = accountsToolParameterArgs('name')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
