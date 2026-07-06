<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\Exceptions\ZernioApiException;
use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Support\ZernioConfig;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function zernioTestConfig(): ZernioConfig
{
    return new ZernioConfig('sk_test_key', 'https://zernio.com/api/v1', 30);
}

it('sends a bearer token, joins the base URL, and decodes JSON on GET', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/accounts', Mockery::on(function (array $opts): bool {
            return $opts['headers']['Authorization'] === 'Bearer sk_test_key'
                && $opts['timeout'] === 30
                && $opts['query'] === ['profileId' => 'p1'];
        }))
        ->andReturn(zernioResponse(200, '{"accounts":[{"id":"a1"}]}'));

    $client = new ZernioClient($http);
    $result = $client->get('/accounts', ['profileId' => 'p1'], zernioTestConfig());

    expect($result)->toBe(['accounts' => [['id' => 'a1']]]);
});

it('sends a JSON body on POST', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $opts): bool {
            return $opts['json'] === ['content' => 'hi'];
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_1"}'));

    $client = new ZernioClient($http);

    expect($client->post('/posts', ['content' => 'hi'], zernioTestConfig()))->toBe(['id' => 'post_1']);
});

it('merges extra per-request headers with the auth + accept defaults', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $opts): bool {
            return $opts['headers']['Authorization'] === 'Bearer sk_test_key'
                && $opts['headers']['Accept'] === 'application/json'
                && ($opts['headers']['X-Request-Id'] ?? null) === '11111111-2222-4333-8444-555555555555';
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_1"}'));

    $client = new ZernioClient($http);
    $client->post(
        '/posts',
        ['content' => 'hi'],
        zernioTestConfig(),
        ['X-Request-Id' => '11111111-2222-4333-8444-555555555555'],
    );
});

it('returns an empty array for an empty body (e.g. 204 on DELETE)', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(zernioResponse(204, ''));

    $client = new ZernioClient($http);

    expect($client->delete('/accounts/abc', [], zernioTestConfig()))->toBe([]);
});

it('throws a ZernioApiException carrying the status on HTTP >= 400', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(zernioResponse(401, '{"error":"unauthorized"}'));

    $client = new ZernioClient($http);

    $caught = null;
    try {
        $client->get('/accounts', [], zernioTestConfig());
    } catch (ZernioApiException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->statusCode)->toBe(401)
        ->and($caught->getMessage())->toContain('HTTP 401');
});

it('throws when the body is not valid JSON', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(zernioResponse(200, '<html>not json</html>'));

    $client = new ZernioClient($http);

    expect(fn() => $client->get('/accounts', [], zernioTestConfig()))
        ->toThrow(ZernioApiException::class);
});

it('retries once on a 503 and then succeeds', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->twice()
        ->andReturn(zernioResponse(503, ''), zernioResponse(200, '{"ok":true}'));

    $client = new ZernioClient($http);

    expect($client->get('/queue/slots', [], zernioTestConfig()))->toBe(['ok' => true]);
});

it('wraps a transport exception in a ZernioApiException after exhausting retries', function (): void {
    $transport = new class ('boom') extends RuntimeException implements TransportExceptionInterface {};

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->times(3)->andThrow($transport);

    $client = new ZernioClient($http);

    expect(fn() => $client->get('/accounts', [], zernioTestConfig()))
        ->toThrow(ZernioApiException::class, 'Zernio API request failed: boom');
});
