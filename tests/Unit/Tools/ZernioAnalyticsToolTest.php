<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioAnalyticsTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function analyticsTool(HttpClientInterface $http): ZernioAnalyticsTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioAnalyticsTool($config, new ZernioClient($http));
}

it('fetches post analytics with a post id and date range', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/posts', Mockery::on(
            fn(array $o): bool => $o['query'] === [
                'startDate' => '2026-07-01',
                'endDate'   => '2026-07-31',
                'postId'    => 'post_1',
            ],
        ))
        ->andReturn(zernioResponse(200, '{"impressions":42}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'post_analytics',
        'post_id'    => 'post_1',
        'start_date' => '2026-07-01',
        'end_date'   => '2026-07-31',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Post analytics');
});

it('requires an account_id for follower analytics', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = analyticsTool($http)->execute(['action' => 'follower_analytics'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('account_id');
});

it('fetches follower analytics for an account', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/followers', Mockery::on(
            fn(array $o): bool => ($o['query']['accountId'] ?? null) === 'a1',
        ))
        ->andReturn(zernioResponse(200, '{"followers":1000}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'follower_analytics',
        'account_id' => 'a1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Follower analytics');
});

it('describes each analytics operation for the approval UI', function (): void {
    $tool = analyticsTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'post_analytics']))->toContain('post analytics')
        ->and($tool->describeAction(['action' => 'follower_analytics']))->toContain('follower analytics');
});
