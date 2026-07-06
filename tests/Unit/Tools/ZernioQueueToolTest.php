<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Tools\ZernioQueueTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function queueTool(HttpClientInterface $http): ZernioQueueTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'sk_test']);

    return new ZernioQueueTool($config, new ZernioClient($http));
}

it('lists queue slots, passing a profile filter', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"data":[]}'));

    expect(queueTool($http)->execute(['action' => 'list_slots', 'profile_id' => 'p1'], agentId: 1)->success)
        ->toBeTrue();
});

it('previews the queue', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/preview', Mockery::any())
        ->andReturn(zernioResponse(200, '{"slots":[]}'));

    expect(queueTool($http)->execute(['action' => 'preview_queue'], agentId: 1)->content)
        ->toContain('Queue preview');
});

it('creates a slot from day and time', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/queue/slots', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['day'] === 'monday'
                && $json['time'] === '09:30'
                && ($json['timezone'] ?? null) === 'Europe/Berlin';
        }))
        ->andReturn(zernioResponse(201, '{"id":"slot_1"}'));

    $result = queueTool($http)->execute([
        'action'   => 'create_slot',
        'day'      => 'monday',
        'time'     => '09:30',
        'timezone' => 'Europe/Berlin',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Created queue slot');
});

it('requires both day and time to create a slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute(['action' => 'create_slot', 'day' => 'monday'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('day and time');
});

it('updates a slot, including its id in the payload', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => $o['json']['id'] === 'slot_1' && $o['json']['time'] === '10:00',
        ))
        ->andReturn(zernioResponse(200, '{"id":"slot_1"}'));

    $result = queueTool($http)->execute([
        'action'  => 'update_slot',
        'slot_id' => 'slot_1',
        'day'     => 'tuesday',
        'time'    => '10:00',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('deletes a slot by id', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['id' => 'slot_1'],
        ))
        ->andReturn(zernioResponse(204, ''));

    $result = queueTool($http)->execute(['action' => 'delete_slot', 'slot_id' => 'slot_1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['slot_id'])->toBe('slot_1');
});

it('gets the next available slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/next-slot', Mockery::any())
        ->andReturn(zernioResponse(200, '{"slot":"2026-07-10T09:00:00Z"}'));

    expect(queueTool($http)->execute(['action' => 'next_slot'], agentId: 1)->content)
        ->toContain('Next slot');
});

it('requires a slot_id to update or delete a slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');
    $tool = queueTool($http);

    expect($tool->execute(['action' => 'update_slot', 'day' => 'monday', 'time' => '09:00'], agentId: 1)->content)
        ->toContain('slot_id')
        ->and($tool->execute(['action' => 'delete_slot'], agentId: 1)->content)
        ->toContain('slot_id');
});

it('describes each queue operation for the approval UI', function (): void {
    $tool = queueTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_slots']))->toContain('List')
        ->and($tool->describeAction(['action' => 'preview_queue']))->toContain('Preview')
        ->and($tool->describeAction(['action' => 'next_slot']))->toContain('next')
        ->and($tool->describeAction(['action' => 'create_slot']))->toContain('Create')
        ->and($tool->describeAction(['action' => 'update_slot', 'slot_id' => 's1']))->toContain('s1')
        ->and($tool->describeAction(['action' => 'delete_slot', 'slot_id' => 's1']))->toContain('Delete');
});
