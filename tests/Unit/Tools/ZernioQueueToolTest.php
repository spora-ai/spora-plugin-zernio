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

it('lists the default queue via GET /queue/slots?profileId', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"exists":true,"schedule":{"_id":"q1","slots":[]},"nextSlots":[]}'));

    $result = queueTool($http)->execute(['action' => 'list_slots', 'profile_id' => 'p1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Queue:');
});

it('lists every queue via GET /queue/slots?profileId&all=true', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/slots', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['profileId'] ?? null) === 'p1' && ($q['all'] ?? null) === 'true';
        }))
        ->andReturn(zernioResponse(200, '{"queues":[{"_id":"q1","name":"Morning","slots":[]}],"count":1}'));

    $result = queueTool($http)->execute([
        'action'     => 'list_slots',
        'profile_id' => 'p1',
        'all'        => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Queues (1)');
});

it('previews the queue with profileId and count via GET /queue/preview', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/preview', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['profileId'] ?? null) === 'p1'
                && ($q['queueId'] ?? null) === 'q1'
                && ($q['count'] ?? null) === 10;
        }))
        ->andReturn(zernioResponse(200, '{"slots":["2026-08-01T09:00:00Z"]}'));

    $result = queueTool($http)->execute([
        'action'     => 'preview_queue',
        'profile_id' => 'p1',
        'queue_id'   => 'q1',
        'count'      => 10,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Queue preview');
});

it('gets the next slot via GET /queue/next-slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/queue/next-slot', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1'],
        ))
        ->andReturn(zernioResponse(200, '{"nextSlot":"2026-08-01T09:00:00Z","timezone":"Europe/Berlin"}'));

    $result = queueTool($http)->execute([
        'action'     => 'next_slot',
        'profile_id' => 'p1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Next slot');
});

it('creates a queue via POST /queue/slots with slots[] built from day+time', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/queue/slots', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['profileId'] === 'p1'
                && $json['name'] === 'Evening Posts'
                && $json['timezone'] === 'Europe/Berlin'
                && $json['slots'] === [
                    ['dayOfWeek' => 1, 'time' => '18:00'],
                ]
                && ($json['active'] ?? null) === true;
        }))
        ->andReturn(zernioResponse(201, '{"success":true,"schedule":{"_id":"q1"}}'));

    $result = queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'Evening Posts',
        'day'        => 'monday',
        'time'       => '18:00',
        'timezone'   => 'Europe/Berlin',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Created queue');
});

it('accepts numeric dayOfWeek as well as day names', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => ($o['json']['slots'] ?? null) === [['dayOfWeek' => 0, 'time' => '09:00']],
        ))
        ->andReturn(zernioResponse(201, '{}'));

    expect(queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'Sunday',
        'day'        => '0',
        'time'       => '09:00',
        'timezone'   => 'UTC',
    ], agentId: 1)->success)->toBeTrue();
});

it('accepts a full slots[] array directly', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/queue/slots', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['slots'] === [
                ['dayOfWeek' => 1, 'time' => '09:00'],
                ['dayOfWeek' => 3, 'time' => '09:00'],
                ['dayOfWeek' => 5, 'time' => '10:00'],
            ];
        }))
        ->andReturn(zernioResponse(201, '{}'));

    expect(queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'Morning',
        'slots'      => [
            ['dayOfWeek' => 1, 'time' => '09:00'],
            ['dayOfWeek' => 3, 'time' => '09:00'],
            ['dayOfWeek' => 5, 'time' => '10:00'],
        ],
        'timezone' => 'UTC',
    ], agentId: 1)->success)->toBeTrue();
});

it('rejects an invalid time format', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'X',
        'day'        => 'monday',
        'time'       => '9am',
        'timezone'   => 'UTC',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HH:MM');
});

it('rejects an out-of-range dayOfWeek', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'X',
        'day'        => '7',
        'time'       => '09:00',
        'timezone'   => 'UTC',
    ], agentId: 1);

    expect($result->success)->toBeFalse();
});

it('requires a name for create_slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'day'        => 'monday',
        'time'       => '09:00',
        'timezone'   => 'UTC',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('name');
});

it('requires a profile_id for create_slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute([
        'action' => 'create_slot',
        'name'   => 'X',
        'day'    => 'monday',
        'time'   => '09:00',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('profile_id');
});

it('requires both day and time when neither slots[] is given', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = queueTool($http)->execute([
        'action'     => 'create_slot',
        'profile_id' => 'p1',
        'name'       => 'X',
        'day'        => 'monday',
    ], agentId: 1);

    expect($result->success)->toBeFalse();
});

it('updates a queue via PUT /queue/slots with queueId in the body', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/queue/slots', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['queueId'] ?? null) === 'q1'
                && ($json['setAsDefault'] ?? null) === true
                && ($json['reshuffleExisting'] ?? null) === false
                && $json['slots'] === [['dayOfWeek' => 2, 'time' => '10:00']];
        }))
        ->andReturn(zernioResponse(200, '{"success":true}'));

    $result = queueTool($http)->execute([
        'action'             => 'update_slot',
        'profile_id'         => 'p1',
        'queue_id'           => 'q1',
        'day'                => 'tuesday',
        'time'               => '10:00',
        'set_as_default'     => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('deletes a queue via DELETE /queue/slots?profileId&queueId', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/queue/slots', Mockery::on(
            fn(array $o): bool => ($o['query'] ?? []) === ['profileId' => 'p1', 'queueId' => 'q1'],
        ))
        ->andReturn(zernioResponse(200, '{"success":true,"deleted":true}'));

    $result = queueTool($http)->execute([
        'action'     => 'delete_slot',
        'profile_id' => 'p1',
        'queue_id'   => 'q1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['queue_id'])->toBe('q1')
        ->and($result->data['profile_id'])->toBe('p1');
});

it('requires queue_id and profile_id for delete_slot', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');
    $tool = queueTool($http);

    expect($tool->execute(['action' => 'delete_slot'], agentId: 1)->content)
        ->toContain('profile_id');
    expect($tool->execute(['action' => 'delete_slot', 'profile_id' => 'p1'], agentId: 1)->content)
        ->toContain('queue_id');
});

it('describes each queue operation for the approval UI', function (): void {
    $tool = queueTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_slots', 'profile_id' => 'p1']))->toContain('p1')
        ->and($tool->describeAction(['action' => 'preview_queue', 'profile_id' => 'p1']))->toContain('Preview')
        ->and($tool->describeAction(['action' => 'next_slot', 'profile_id' => 'p1']))->toContain('next')
        ->and($tool->describeAction(['action' => 'create_slot', 'profile_id' => 'p1']))->toContain('Create')
        ->and($tool->describeAction(['action' => 'update_slot', 'profile_id' => 'p1', 'queue_id' => 'q1']))->toContain('q1')
        ->and($tool->describeAction(['action' => 'delete_slot', 'profile_id' => 'p1', 'queue_id' => 'q1']))->toContain('Delete');
});
