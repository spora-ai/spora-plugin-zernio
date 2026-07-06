<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function postTool(HttpClientInterface $http): ZernioPostTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioPostTool($config, new ZernioClient($http));
}

it('requires account_ids and content for create_post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'create_post', 'content' => 'hi'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('account_id');
});

it('publishes immediately when publish_now is true', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['accountIds'] === ['a1']
                && $json['content'] === 'Hello world'
                && ($json['publishNow'] ?? null) === true;
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_1","status":"published"}'));

    $result = postTool($http)->execute([
        'action'      => 'create_post',
        'account_ids' => ['a1'],
        'content'     => 'Hello world',
        'publish_now' => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['mode'])->toBe('published');
});

it('schedules with scheduledFor and timezone', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['scheduledFor'] ?? null) === '2026-08-01T10:00:00Z'
                && ($json['timezone'] ?? null) === 'Europe/Berlin'
                && ($json['mediaUrls'] ?? null) === ['https://cdn.example/img.png']
                && !isset($json['publishNow']);
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_2","status":"scheduled"}'));

    $result = postTool($http)->execute([
        'action'        => 'create_post',
        'account_ids'   => ['a1', 'a2'],
        'content'       => 'Later',
        'media_urls'    => ['https://cdn.example/img.png'],
        'scheduled_for' => '2026-08-01T10:00:00Z',
        'timezone'      => 'Europe/Berlin',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['mode'])->toBe('scheduled');
});

it('drafts when neither publish_now nor scheduled_for is given', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(
            fn(array $o): bool => !isset($o['json']['publishNow']) && !isset($o['json']['scheduledFor']),
        ))
        ->andReturn(zernioResponse(201, '{"id":"post_3","status":"draft"}'));

    $result = postTool($http)->execute([
        'action'      => 'create_post',
        'account_ids' => ['a1'],
        'content'     => 'Draft me',
    ], agentId: 1);

    expect($result->data['mode'])->toBe('drafted');
});

it('rejects scheduling without a timezone', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute([
        'action'        => 'create_post',
        'account_ids'   => ['a1'],
        'content'       => 'Later',
        'scheduled_for' => '2026-08-01T10:00:00Z',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('timezone');
});

it('lists posts with status and profile filters', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/posts', Mockery::on(
            fn(array $o): bool => $o['query'] === ['status' => 'scheduled', 'profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"data":[{"id":"post_1"}]}'));

    $result = postTool($http)->execute([
        'action'     => 'list_posts',
        'status'     => 'scheduled',
        'profile_id' => 'p1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Posts (1)');
});

it('gets a single post by id', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/posts/post_1', Mockery::any())
        ->andReturn(zernioResponse(200, '{"id":"post_1"}'));

    $result = postTool($http)->execute(['action' => 'get_post', 'post_id' => 'post_1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('post_1');
});

it('deletes a post by id', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/posts/post_1', Mockery::any())
        ->andReturn(zernioResponse(204, ''));

    $result = postTool($http)->execute(['action' => 'delete_post', 'post_id' => 'post_1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['post_id'])->toBe('post_1');
});

it('surfaces an API error as a failed ToolResult without throwing', function (): void {
    // 502 is retryable, so the client attempts it MAX_ATTEMPTS times before failing.
    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(zernioResponse(502, 'bad gateway'));

    $result = postTool($http)->execute(['action' => 'list_posts'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 502');
});

it('requires a post_id for delete_post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'delete_post'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id');
});

it('requires a post_id for get_post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'get_post'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id');
});

it('describes each post operation for the approval UI', function (): void {
    $tool = postTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_posts']))->toContain('List')
        ->and($tool->describeAction(['action' => 'get_post', 'post_id' => 'p1']))->toContain('p1')
        ->and($tool->describeAction(['action' => 'delete_post', 'post_id' => 'p1']))->toContain('Delete')
        ->and($tool->describeAction(['action' => 'create_post', 'account_ids' => ['a1'], 'publish_now' => true]))
        ->toContain('Published')
        ->and($tool->describeAction(['action' => 'create_post', 'account_ids' => ['a1'], 'scheduled_for' => 'x']))
        ->toContain('Scheduled');
});
