<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function accountsTool(HttpClientInterface $http, array $settings = ['api_key' => 'sk_test']): ZernioAccountsTool
{
    return zernioTool(ZernioAccountsTool::class, $http, $settings);
}

it('fails with a helpful message when no API key is configured', function (): void {
    putenv('ZERNIO_API_KEY');
    $http = Mockery::mock(HttpClientInterface::class);
    $tool = accountsTool($http, []);

    $result = $tool->execute([], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not configured');
});

it('lists accounts, reading the `accounts` key from the response envelope', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"accounts":[{"id":"a1","platform":"twitter"}],"hasAnalyticsAccess":true}'));

    $result = accountsTool($http)->execute(['action' => 'list_accounts', 'profile_id' => 'p1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Accounts (1)')
        ->and($result->data['count'])->toBe(1);
});

it('passes platform, status, and includeOverLimit filters to GET /accounts', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return $q['platform'] === 'twitter'
                && $q['status'] === 'connected'
                && ($q['includeOverLimit'] ?? null) === true;
        }))
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $result = accountsTool($http)->execute([
        'action'            => 'list_accounts',
        'platform'          => 'twitter',
        'status'            => 'connected',
        'include_over_limit' => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('lists profiles, reading the `profiles` key from the response envelope', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/profiles', Mockery::any())
        ->andReturn(zernioResponse(200, '{"profiles":[{"id":"p1"}]}'));

    $result = accountsTool($http)->execute(['action' => 'list_profiles'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Profiles (1)');
});

it('creates a profile via POST /profiles', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/profiles', Mockery::on(function (array $o): bool {
            return $o['json'] === [
                'name'        => 'Marketing',
                'description' => 'Our brand',
                'color'       => '#4CAF50',
            ];
        }))
        ->andReturn(zernioResponse(201, '{"profile":{"_id":"p_new"}}'));

    $result = accountsTool($http)->execute([
        'action'      => 'create_profile',
        'name'        => 'Marketing',
        'description' => 'Our brand',
        'color'       => '#4CAF50',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Created profile');
});

it('requires a name for create_profile', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = accountsTool($http)->execute(['action' => 'create_profile'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('name');
});

it('updates a profile via PUT /profiles/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/profiles/p1', Mockery::on(function (array $o): bool {
            return $o['json'] === ['name' => 'New Name', 'isDefault' => true];
        }))
        ->andReturn(zernioResponse(200, '{"profile":{"_id":"p1"}}'));

    $result = accountsTool($http)->execute([
        'action'     => 'update_profile',
        'profile_id' => 'p1',
        'name'       => 'New Name',
        'is_default' => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('deletes a profile via DELETE /profiles/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/profiles/p1', Mockery::any())
        ->andReturn(zernioResponse(200, '{"deleted":true}'));

    $result = accountsTool($http)->execute([
        'action'     => 'delete_profile',
        'profile_id' => 'p1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['profile_id'])->toBe('p1');
});

it('updates an account via PUT /accounts/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/accounts/a1', Mockery::on(function (array $o): bool {
            return $o['json'] === [
                'displayName'   => 'Acme',
                'xCapabilities' => ['analytics' => true, 'inbox' => false],
            ];
        }))
        ->andReturn(zernioResponse(200, '{"account":{"_id":"a1"}}'));

    $result = accountsTool($http)->execute([
        'action'         => 'update_account',
        'account_id'     => 'a1',
        'display_name'   => 'Acme',
        'x_capabilities' => ['analytics' => true, 'inbox' => false],
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('moves an account to another profile via PATCH /accounts/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PATCH', 'https://zernio.com/api/v1/accounts/a1', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['profileId' => 'p2'],
        ))
        ->andReturn(zernioResponse(200, '{"account":{"_id":"a1"}}'));

    $result = accountsTool($http)->execute([
        'action'     => 'move_account',
        'account_id' => 'a1',
        'profile_id' => 'p2',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('disconnects an account via DELETE /accounts/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/accounts/a1', Mockery::any())
        ->andReturn(zernioResponse(200, '{"deleted":true}'));

    $result = accountsTool($http)->execute([
        'action'     => 'disconnect_account',
        'account_id' => 'a1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['account_id'])->toBe('a1');
});

it('fetches bulk account health via GET /accounts/health', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts/health', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['profileId'] ?? null) === 'p1'
                && ($q['status'] ?? null) === 'warning';
        }))
        ->andReturn(zernioResponse(200, '{"summary":{},"accounts":[]}'));

    $result = accountsTool($http)->execute([
        'action'     => 'account_health',
        'profile_id' => 'p1',
        'status'     => 'warning',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Account health');
});

it('fetches per-account health via GET /accounts/{id}/health', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts/a1/health', Mockery::any())
        ->andReturn(zernioResponse(200, '{"tokenStatus":"ok"}'));

    $result = accountsTool($http)->execute([
        'action'     => 'get_account_health',
        'account_id' => 'a1',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('falls back to the ZERNIO_API_KEY environment variable', function (): void {
    putenv('ZERNIO_API_KEY=sk_env_key');
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(
            fn(array $o): bool => $o['headers']['Authorization'] === 'Bearer sk_env_key',
        ))
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $result = accountsTool($http, [])->execute(['action' => 'list_accounts'], agentId: 1);

    expect($result->success)->toBeTrue();
    putenv('ZERNIO_API_KEY');
});

it('applies the http_timeout setting to the request', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(
            fn(array $o): bool => $o['timeout'] === 45,
        ))
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $tool = accountsTool($http, ['api_key' => 'sk_test', 'http_timeout' => '45']);
    expect($tool->execute(['action' => 'list_accounts'], agentId: 1)->success)->toBeTrue();
});

it('converts an unexpected transport-layer throwable into a failed ToolResult', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andThrow(new RuntimeException('kaboom'));

    $result = accountsTool($http)->execute(['action' => 'list_accounts'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Zernio tool error');
});

it('falls back to the default base URL when the configured one is malformed', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::any())
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $tool = accountsTool($http, ['api_key' => 'sk_test', 'base_url' => 'not a url']);

    expect($tool->execute(['action' => 'list_accounts'], agentId: 1)->success)->toBeTrue();
});

it('honours a valid custom base URL', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://api.staging.zernio.test/v2/accounts', Mockery::any())
        ->andReturn(zernioResponse(200, '{"accounts":[]}'));

    $tool = accountsTool($http, ['api_key' => 'sk_test', 'base_url' => 'https://api.staging.zernio.test/v2']);

    expect($tool->execute(['action' => 'list_accounts'], agentId: 1)->success)->toBeTrue();
});

it('describes each account operation for the approval UI', function (): void {
    $tool = accountsTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_accounts']))->toContain('accounts')
        ->and($tool->describeAction(['action' => 'list_profiles']))->toContain('profiles')
        ->and($tool->describeAction(['action' => 'create_profile']))->toContain('Create')
        ->and($tool->describeAction(['action' => 'update_profile', 'profile_id' => 'p1']))->toContain('p1')
        ->and($tool->describeAction(['action' => 'delete_profile', 'profile_id' => 'p1']))->toContain('Delete')
        ->and($tool->describeAction(['action' => 'update_account', 'account_id' => 'a1']))->toContain('a1')
        ->and($tool->describeAction(['action' => 'move_account', 'account_id' => 'a1']))->toContain('Move')
        ->and($tool->describeAction(['action' => 'disconnect_account', 'account_id' => 'a1']))->toContain('Disconnect')
        ->and($tool->describeAction(['action' => 'account_health']))->toContain('health')
        ->and($tool->describeAction(['action' => 'get_account_health', 'account_id' => 'a1']))->toContain('a1');
});
