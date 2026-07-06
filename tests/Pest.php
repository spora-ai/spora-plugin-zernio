<?php

declare(strict_types=1);

use Mockery as M;
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
