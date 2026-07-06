<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

afterEach(function () {
    M::close();
});

/**
 * Build a mocked Symfony HTTP response with the given status and body.
 * `getStatusCode()` and `getContent()` are stubbed for any arguments.
 */
function zernioResponse(int $status, string $body = ''): ResponseInterface
{
    $response = M::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);

    return $response;
}

/**
 * Wire a Zernio tool with a mocked ToolConfigService and a real
 * ZernioClient backed by the given (Mockery) HttpClient. Centralised so
 * each test file's local `xxxTool()` factory is one line.
 *
 * @param  class-string<Spora\Plugins\Zernio\Tools\AbstractZernioTool> $toolClass
 * @param  array<string, mixed>                                       $settings
 */
function zernioTool(string $toolClass, HttpClientInterface $http, array $settings = ['api_key' => 'sk_test']): object
{
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn($settings);

    return new $toolClass($config, new ZernioClient($http));
}
