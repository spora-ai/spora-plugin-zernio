<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioWebhooksTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function webhooksTool(HttpClientInterface $http): ZernioWebhooksTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioWebhooksTool($config, new ZernioClient($http));
}

it('lists webhooks via GET /webhooks/settings', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/webhooks/settings', Mockery::any())
        ->andReturn(zernioResponse(200, '{"webhooks":[{"_id":"w1","url":"https://example/hook"}]}'));

    expect(webhooksTool($http)->execute(['action' => 'list_webhooks'], agentId: 1)->content)
        ->toContain('Webhooks');
});

it('creates a webhook via POST /webhooks/settings', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/webhooks/settings', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['name'] === 'My Hook'
                && $json['url'] === 'https://example/hook'
                && $json['events'] === ['post.published', 'account.disconnected']
                && ($json['isActive'] ?? null) === true;
        }))
        ->andReturn(zernioResponse(201, '{"webhook":{"_id":"w1","secret":"abc"}}'));

    $result = webhooksTool($http)->execute([
        'action'    => 'create_webhook',
        'name'      => 'My Hook',
        'url'       => 'https://example/hook',
        'events'    => ['post.published', 'account.disconnected'],
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('rejects create_webhook without a name/url/events', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');
    $tool = webhooksTool($http);

    expect($tool->execute(['action' => 'create_webhook'], agentId: 1)->success)->toBeFalse();
    expect($tool->execute(['action' => 'create_webhook', 'name' => 'x'], agentId: 1)->content)->toContain('url');
    expect($tool->execute(['action' => 'create_webhook', 'name' => 'x', 'url' => 'https://x'], agentId: 1)->content)->toContain('events');
});

it('updates a webhook via PUT /webhooks/settings with _id in the body', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/webhooks/settings', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['_id'] ?? null) === 'w1'
                && ($json['isActive'] ?? null) === false
                && !isset($json['url']);
        }))
        ->andReturn(zernioResponse(200, '{"webhook":{"_id":"w1"}}'));

    $result = webhooksTool($http)->execute([
        'action'     => 'update_webhook',
        'webhook_id' => 'w1',
        'is_active'  => false,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('deletes a webhook via DELETE /webhooks/settings with _id as query', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/webhooks/settings', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['_id' => 'w1'],
        ))
        ->andReturn(zernioResponse(200, '{"deleted":true}'));

    $result = webhooksTool($http)->execute([
        'action'     => 'delete_webhook',
        'webhook_id' => 'w1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['webhook_id'])->toBe('w1');
});

it('fetches webhook logs via GET /webhooks/logs', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/webhooks/logs', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['webhookId'] ?? null) === 'w1'
                && ($q['fromDate'] ?? null) === '2026-07-01'
                && ($q['toDate'] ?? null) === '2026-07-31';
        }))
        ->andReturn(zernioResponse(200, '{"logs":[]}'));

    $result = webhooksTool($http)->execute([
        'action'     => 'get_webhook_logs',
        'webhook_id' => 'w1',
        'from_date'  => '2026-07-01',
        'to_date'    => '2026-07-31',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Webhook logs');
});

it('fires a test webhook via POST /webhooks/test', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/webhooks/test', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['url' => 'https://example/hook', 'event' => 'post.published'],
        ))
        ->andReturn(zernioResponse(200, '{"delivered":true}'));

    $result = webhooksTool($http)->execute([
        'action'     => 'test_webhook',
        'url'        => 'https://example/hook',
        'event_name' => 'post.published',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('uses webhook.test as the default event for test_webhook', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/webhooks/test', Mockery::on(
            fn(array $o): bool => ($o['json']['event'] ?? null) === 'webhook.test',
        ))
        ->andReturn(zernioResponse(200, '{}'));

    expect(webhooksTool($http)->execute([
        'action' => 'test_webhook',
        'url'    => 'https://example/hook',
    ], agentId: 1)->success)->toBeTrue();
});

it('describes each webhook operation for the approval UI', function (): void {
    $tool = webhooksTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_webhooks']))->toContain('List')
        ->and($tool->describeAction(['action' => 'create_webhook']))->toContain('Create')
        ->and($tool->describeAction(['action' => 'update_webhook', 'webhook_id' => 'w1']))->toContain('w1')
        ->and($tool->describeAction(['action' => 'delete_webhook', 'webhook_id' => 'w1']))->toContain('Delete')
        ->and($tool->describeAction(['action' => 'get_webhook_logs']))->toContain('logs')
        ->and($tool->describeAction(['action' => 'test_webhook']))->toContain('test');
});
