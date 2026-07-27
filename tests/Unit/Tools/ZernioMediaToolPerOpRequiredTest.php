<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioMediaTool;
use Spora\Tools\Attributes\ToolParameter;

function mediaToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(ZernioMediaTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . ZernioMediaTool::class);
}

it('binds filename to presign_media and upload_media', function (): void {
    $expected = ['presign_media', 'upload_media'];
    sort($expected);
    $actual = mediaToolParameterArgs('filename')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds content_type to presign_media and upload_media', function (): void {
    $expected = ['presign_media', 'upload_media'];
    sort($expected);
    $actual = mediaToolParameterArgs('content_type')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds content to upload_media', function (): void {
    $expected = ['upload_media'];
    sort($expected);
    $actual = mediaToolParameterArgs('content')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});
