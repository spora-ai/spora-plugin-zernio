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

it('fetches post analytics via GET /analytics with postId', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['postId'] ?? null) === 'post_1'
                && ($q['fromDate'] ?? null) === '2026-07-01'
                && ($q['toDate'] ?? null) === '2026-07-31';
        }))
        ->andReturn(zernioResponse(200, '{"postId":"post_1","analytics":{"impressions":42}}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'post_analytics',
        'post_id'    => 'post_1',
        'from_date'  => '2026-07-01',
        'to_date'    => '2026-07-31',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Post analytics');
});

it('fetches post analytics for an account via GET /analytics with accountId + platform', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['accountId'] ?? null) === 'a1'
                && ($q['platform'] ?? null) === 'twitter'
                && ($q['sortBy'] ?? null) === 'engagement'
                && ($q['order'] ?? null) === 'desc'
                && ($q['page'] ?? null) === 1
                && ($q['limit'] ?? null) === 50;
        }))
        ->andReturn(zernioResponse(200, '{"posts":[]}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'post_analytics',
        'account_id' => 'a1',
        'platform'   => 'twitter',
        'sort_by'    => 'engagement',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('rejects post_analytics when neither post_id nor account_id+platform is given', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = analyticsTool($http)->execute(['action' => 'post_analytics'], agentId: 1);

    expect($result->success)->toBeFalse();
});

it('fetches follower analytics via GET /accounts/follower-stats with accountIds and fromDate/toDate', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts/follower-stats', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['accountIds'] ?? null) === 'a1,a2'
                && ($q['fromDate'] ?? null) === '2026-07-01'
                && ($q['toDate'] ?? null) === '2026-07-31'
                && ($q['granularity'] ?? null) === 'weekly';
        }))
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $result = analyticsTool($http)->execute([
        'action'      => 'follower_analytics',
        'account_ids' => 'a1,a2',
        'from_date'   => '2026-07-01',
        'to_date'     => '2026-07-31',
        'granularity' => 'weekly',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Follower analytics');
});

it('fetches best-time-to-post via GET /analytics/best-time', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/best-time', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['accountId'] ?? null) === 'a1' && ($q['platform'] ?? null) === 'twitter';
        }))
        ->andReturn(zernioResponse(200, '{"bestTimes":[{"dayOfWeek":3,"hour":10}]}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'best_time_to_post',
        'account_id' => 'a1',
        'platform'   => 'twitter',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Best time to post');
});

it('fetches content decay via GET /analytics/content-decay', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/content-decay', Mockery::on(
            fn(array $o): bool => ($o['query']['postId'] ?? null) === 'post_1',
        ))
        ->andReturn(zernioResponse(200, '{"decay":[]}'));

    $result = analyticsTool($http)->execute([
        'action'  => 'content_decay',
        'post_id' => 'post_1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Content decay');
});

it('fetches daily metrics via GET /analytics/daily-metrics with profileId + date range', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/daily-metrics', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['profileId'] ?? null) === 'p1'
                && ($q['fromDate'] ?? null) === '2026-07-01'
                && ($q['toDate'] ?? null) === '2026-07-31';
        }))
        ->andReturn(zernioResponse(200, '{"days":[]}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'daily_metrics',
        'profile_id' => 'p1',
        'from_date'  => '2026-07-01',
        'to_date'    => '2026-07-31',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('fetches posting frequency via GET /analytics/posting-frequency', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/analytics/posting-frequency', Mockery::on(
            fn(array $o): bool => ($o['query']['profileId'] ?? null) === 'p1',
        ))
        ->andReturn(zernioResponse(200, '{}'));

    expect(analyticsTool($http)->execute([
        'action'     => 'posting_frequency',
        'profile_id' => 'p1',
    ], agentId: 1)->success)->toBeTrue();
});

it('fetches account health via GET /accounts/health', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts/health', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['profileId'] ?? null) === 'p1' && ($q['status'] ?? null) === 'error';
        }))
        ->andReturn(zernioResponse(200, '{"summary":{},"accounts":[]}'));

    $result = analyticsTool($http)->execute([
        'action'     => 'account_health',
        'profile_id' => 'p1',
        'status'     => 'error',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Account health');
});

it('describes each analytics operation for the approval UI', function (): void {
    $tool = analyticsTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'post_analytics']))->toContain('post analytics')
        ->and($tool->describeAction(['action' => 'follower_analytics']))->toContain('follower analytics')
        ->and($tool->describeAction(['action' => 'best_time_to_post']))->toContain('best times')
        ->and($tool->describeAction(['action' => 'content_decay']))->toContain('content decay')
        ->and($tool->describeAction(['action' => 'daily_metrics']))->toContain('daily metrics')
        ->and($tool->describeAction(['action' => 'posting_frequency']))->toContain('frequency')
        ->and($tool->describeAction(['action' => 'account_health']))->toContain('health');
});
