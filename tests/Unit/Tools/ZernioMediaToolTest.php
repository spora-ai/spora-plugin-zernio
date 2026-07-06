<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioMediaTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function mediaTool(HttpClientInterface $http): ZernioMediaTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioMediaTool($config, new ZernioClient($http));
}

it('fetches a presigned URL via POST /media/presign', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/media/presign', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['filename'] === 'hero.jpg'
                && $json['contentType'] === 'image/jpeg'
                && ($json['size'] ?? null) === 102400;
        }))
        ->andReturn(zernioResponse(200, '{"uploadUrl":"https://s3.example/presigned","publicUrl":"https://cdn.example/hero.jpg","key":"hero.jpg"}'));

    $result = mediaTool($http)->execute([
        'action'       => 'presign_media',
        'filename'     => 'hero.jpg',
        'content_type' => 'image/jpeg',
        'size'         => 102400,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Presigned URL');
});

it('requires a filename for presign_media', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = mediaTool($http)->execute([
        'action'       => 'presign_media',
        'content_type' => 'image/jpeg',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('filename');
});

it('uploads a file via POST /media/upload-direct with base64-decoded body', function (): void {
    $bytes = random_bytes(32);
    $b64   = base64_encode($bytes);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/media/upload-direct', Mockery::on(function (array $o) use ($b64): bool {
            $json = $o['json'];
            return $json['filename'] === 'clip.mp4'
                && $json['contentType'] === 'video/mp4'
                && $json['data'] === $b64;
        }))
        ->andReturn(zernioResponse(201, '{"publicUrl":"https://cdn.example/clip.mp4"}'));

    $result = mediaTool($http)->execute([
        'action'       => 'upload_media',
        'filename'     => 'clip.mp4',
        'content_type' => 'video/mp4',
        'content'      => $b64,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('rejects upload_media with non-base64 content', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = mediaTool($http)->execute([
        'action'       => 'upload_media',
        'filename'     => 'x.bin',
        'content_type' => 'application/octet-stream',
        'content'      => 'not base64 @@@',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('base64');
});

it('describes each media operation for the approval UI', function (): void {
    $tool = mediaTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'presign_media']))->toContain('presigned')
        ->and($tool->describeAction(['action' => 'upload_media']))->toContain('Upload');
});
