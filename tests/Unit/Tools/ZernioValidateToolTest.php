<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioValidateTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function validateTool(HttpClientInterface $http): ZernioValidateTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioValidateTool($config, new ZernioClient($http));
}

it('validates a post via POST /tools/validate/post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/tools/validate/post', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['content'] ?? null) === 'hello world'
                && ($json['platform'] ?? null) === 'twitter'
                && ($json['mediaItems'] ?? null) === [['url' => 'https://x/y.jpg', 'type' => 'image']];
        }))
        ->andReturn(zernioResponse(200, '{"valid":true}'));

    $result = validateTool($http)->execute([
        'action'      => 'validate_post',
        'content'     => 'hello world',
        'platform'    => 'twitter',
        'media_items' => [['url' => 'https://x/y.jpg', 'type' => 'image']],
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Post validation');
});

it('rejects validate_post with neither content nor media_items', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = validateTool($http)->execute(['action' => 'validate_post'], agentId: 1);

    expect($result->success)->toBeFalse();
});

it('validates a post length via POST /tools/validate/post-length', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/tools/validate/post-length', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['content' => 'short', 'platform' => 'twitter'],
        ))
        ->andReturn(zernioResponse(200, '{"withinLimit":true,"remaining":270}'));

    $result = validateTool($http)->execute([
        'action'   => 'validate_post_length',
        'content'  => 'short',
        'platform' => 'twitter',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Post length');
});

it('requires content + platform for validate_post_length', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');
    $tool = validateTool($http);

    expect($tool->execute(['action' => 'validate_post_length'], agentId: 1)->content)->toContain('content');
    expect($tool->execute(['action' => 'validate_post_length', 'content' => 'x'], agentId: 1)->content)->toContain('platform');
});

it('validates a media URL via POST /tools/validate/media', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/tools/validate/media', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['url' => 'https://cdn.example/x.jpg'],
        ))
        ->andReturn(zernioResponse(200, '{"reachable":true,"contentType":"image/jpeg"}'));

    $result = validateTool($http)->execute([
        'action' => 'validate_media',
        'url'    => 'https://cdn.example/x.jpg',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Media validation');
});

it('validates a subreddit via POST /tools/validate/subreddit', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/tools/validate/subreddit', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['subreddit' => 'php'],
        ))
        ->andReturn(zernioResponse(200, '{"exists":true,"subscribers":250000}'));

    $result = validateTool($http)->execute([
        'action'    => 'validate_subreddit',
        'subreddit' => 'php',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Subreddit validation');
});

it('describes each validate operation for the approval UI', function (): void {
    $tool = validateTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'validate_post']))->toContain('Validate')
        ->and($tool->describeAction(['action' => 'validate_post_length']))->toContain('length')
        ->and($tool->describeAction(['action' => 'validate_media']))->toContain('media')
        ->and($tool->describeAction(['action' => 'validate_subreddit']))->toContain('subreddit');
});
