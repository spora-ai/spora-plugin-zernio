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

it('sends a raw string body with an explicit Content-Type via postRaw', function (): void {
    // Regression for the bulk_upload bug: post() always JSON-wraps the body,
    // so the old ['headers' => ..., 'body' => ...] shape was sent as JSON
    // fields, not as actual request headers/body. postRaw must forward the
    // raw string with the explicit Content-Type and *must not* JSON-encode it.
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/bulk-upload', Mockery::on(function (array $opts): bool {
            return !isset($opts['json'])
                && ($opts['body'] ?? null) === "content,account_id\nhello,a1"
                && ($opts['headers']['Authorization'] ?? null) === 'Bearer sk_test_key'
                && ($opts['headers']['Content-Type'] ?? null) === 'text/csv';
        }))
        ->andReturn(zernioResponse(200, '{"total":1,"valid":1}'));

    $client = new ZernioClient($http);
    $client->postRaw('/posts/bulk-upload', "content,account_id\nhello,a1", 'text/csv', zernioTestConfig());
});

it('lets option-level headers coexist with auth defaults (regression for request() merge)', function (): void {
    // Direct test of the request() merge: when the caller passes 'headers'
    // inside $options (postRaw does this with Content-Type), request() must
    // merge those headers with the auth + Accept defaults instead of
    // silently overwriting them. Bug pre-fix: array_merge($options, [...])
    // overwrote $options['headers'] with just the auth defaults, dropping
    // Content-Type from the wire request.
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/bulk-upload', Mockery::on(function (array $opts): bool {
            return ($opts['headers']['Authorization'] ?? null) === 'Bearer sk_test_key'
                && ($opts['headers']['Accept'] ?? null) === 'application/json'
                && ($opts['headers']['Content-Type'] ?? null) === 'text/csv'
                && ($opts['body'] ?? null) === 'a,b,c';
        }))
        ->andReturn(zernioResponse(200, '{}'));

    $client = new ZernioClient($http);
    // postRaw is the easiest way to exercise this path without exposing
    // request() publicly; if its Content-Type ever drops again, this test fails.
    $client->postRaw('/posts/bulk-upload', 'a,b,c', 'text/csv', zernioTestConfig());
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

it('does not retry on a 503 and surfaces the HTTP status', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andReturn(zernioResponse(503, 'service unavailable'));

    $client = new ZernioClient($http);

    expect(fn() => $client->get('/queue/slots', [], zernioTestConfig()))
        ->toThrow(ZernioApiException::class, 'HTTP 503');
});

it('wraps a transport exception in a ZernioApiException without retrying', function (): void {
    $transport = new class ('boom') extends RuntimeException implements TransportExceptionInterface {};

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')->once()->andThrow($transport);

    $client = new ZernioClient($http);

    expect(fn() => $client->get('/accounts', [], zernioTestConfig()))
        ->toThrow(ZernioApiException::class, 'Zernio API request failed: boom');
});
