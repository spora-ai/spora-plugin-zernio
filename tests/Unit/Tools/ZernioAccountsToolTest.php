<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioAccountsTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function accountsTool(HttpClientInterface $http, array $settings = ['api_key' => 'sk_test']): ZernioAccountsTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn($settings);

    return new ZernioAccountsTool($config, new ZernioClient($http));
}

it('fails with a helpful message when no API key is configured', function (): void {
    putenv('ZERNIO_API_KEY');
    $http = Mockery::mock(HttpClientInterface::class);
    $tool = accountsTool($http, []);

    $result = $tool->execute([], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not configured');
});

it('lists accounts, optionally filtered by profile', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"data":[{"id":"a1","platform":"twitter"}]}'));

    $result = accountsTool($http)->execute(['action' => 'list_accounts', 'profile_id' => 'p1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Accounts (1)')
        ->and($result->data['count'])->toBe(1);
});

it('lists profiles', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/profiles', Mockery::any())
        ->andReturn(zernioResponse(200, '{"data":[{"id":"p1"}]}'));

    $result = accountsTool($http)->execute(['action' => 'list_profiles'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Profiles (1)');
});

it('falls back to the ZERNIO_API_KEY environment variable', function (): void {
    putenv('ZERNIO_API_KEY=sk_env_key');
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(
            fn(array $o): bool => $o['headers']['Authorization'] === 'Bearer sk_env_key',
        ))
        ->andReturn(zernioResponse(200, '{"data":[]}'));

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
        ->andReturn(zernioResponse(200, '{"data":[]}'));

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

it('describes each account operation for the approval UI', function (): void {
    $tool = accountsTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_accounts']))->toContain('accounts')
        ->and($tool->describeAction(['action' => 'list_profiles']))->toContain('profiles');
});
