<?php

declare(strict_types=1);

use Spora\Plugins\Zernio\Tools\ZernioPostTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function postTool(HttpClientInterface $http): ZernioPostTool
{
    return zernioTool(ZernioPostTool::class, $http);
}

// ---------------------------------------------------------------------------
// create_post
// ---------------------------------------------------------------------------

it('rejects create_post when no platforms are given', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'create_post', 'content' => 'hi'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('platform');
});

it('builds a platforms[] body from account_ids + platform and sets X-Request-Id', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            $headers = $o['headers'];
            return $json['platforms'] === [
                ['platform' => 'twitter', 'accountId' => 'a1'],
                ['platform' => 'twitter', 'accountId' => 'a2'],
            ]
                && $json['content'] === 'Hello world'
                && ($json['publishNow'] ?? null) === true
                && is_string($headers['X-Request-Id'] ?? null)
                && preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                    $headers['X-Request-Id'],
                ) === 1;
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_1","status":"published"}'));

    $result = postTool($http)->execute([
        'action'      => 'create_post',
        'account_ids' => ['a1', 'a2'],
        'platform'    => 'twitter',
        'content'     => 'Hello world',
        'publish_now' => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['mode'])->toBe('published')
        ->and($result->data['request_id'])->toMatch(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        );
});

it('uses an explicit platforms[] body without requiring account_ids', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return $json['platforms'] === [
                [
                    'platform'   => 'twitter',
                    'accountId'  => 'a1',
                    'customContent' => 'Hi from X',
                ],
                [
                    'platform'  => 'linkedin',
                    'accountId' => 'a2',
                ],
            ]
                && $json['content'] === 'Default content';
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_1"}'));

    $result = postTool($http)->execute([
        'action'    => 'create_post',
        'platforms' => [
            ['platform' => 'twitter', 'accountId' => 'a1', 'customContent' => 'Hi from X'],
            ['platform' => 'linkedin', 'accountId' => 'a2'],
        ],
        'content' => 'Default content',
        'publish_now' => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('schedules with scheduledFor and timezone and omits publishNow', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['scheduledFor'] ?? null) === '2026-08-01T10:00:00Z'
                && ($json['timezone'] ?? null) === 'Europe/Berlin'
                && ($json['mediaItems'] ?? null) === [
                    ['url' => 'https://cdn.example/img.png', 'type' => 'image'],
                ]
                && !isset($json['publishNow'])
                && !isset($json['queuedFromProfile']);
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_2","status":"scheduled"}'));

    $result = postTool($http)->execute([
        'action'        => 'create_post',
        'account_ids'   => ['a1', 'a2'],
        'platform'      => 'twitter',
        'content'       => 'Later',
        'media_items'   => [['url' => 'https://cdn.example/img.png', 'type' => 'image']],
        'scheduled_for' => '2026-08-01T10:00:00Z',
        'timezone'      => 'Europe/Berlin',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['mode'])->toBe('scheduled');
});

it('uses queuedFromProfile when provided and omits scheduledFor', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['queuedFromProfile'] ?? null) === 'p1'
                && ($json['queueId'] ?? null) === 'q1'
                && !isset($json['scheduledFor'])
                && !isset($json['publishNow']);
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_3"}'));

    $result = postTool($http)->execute([
        'action'             => 'create_post',
        'account_ids'        => ['a1'],
        'platform'           => 'twitter',
        'content'            => 'Queued',
        'queued_from_profile' => 'p1',
        'queue_id'           => 'q1',
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['mode'])->toBe('queue-scheduled');
});

it('drafts when neither publish_now, scheduled_for, nor queued_from_profile is given', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(
            fn(array $o): bool => !isset($o['json']['publishNow'])
                && !isset($o['json']['scheduledFor'])
                && !isset($o['json']['queuedFromProfile']),
        ))
        ->andReturn(zernioResponse(201, '{"id":"post_3","status":"draft"}'));

    $result = postTool($http)->execute([
        'action'      => 'create_post',
        'account_ids' => ['a1'],
        'platform'    => 'twitter',
        'content'     => 'Draft me',
    ], agentId: 1);

    expect($result->data['mode'])->toBe('drafted');
});

it('rejects scheduling without a timezone', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute([
        'action'        => 'create_post',
        'account_ids'   => ['a1'],
        'platform'      => 'twitter',
        'content'       => 'Later',
        'scheduled_for' => '2026-08-01T10:00:00Z',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('timezone');
});

it('forwards tags, hashtags, mentions, recycling, tiktok_settings, facebook_settings', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['tags'] ?? null) === ['launch']
                && ($json['hashtags'] ?? null) === ['#launch']
                && ($json['mentions'] ?? null) === ['@acme']
                && ($json['recycling'] ?? null) === ['gap' => 1, 'gapFreq' => 'week']
                && ($json['tiktokSettings'] ?? null) === ['draft' => true]
                && ($json['facebookSettings'] ?? null) === ['draft' => true];
        }))
        ->andReturn(zernioResponse(201, '{"id":"post_4"}'));

    $result = postTool($http)->execute([
        'action'            => 'create_post',
        'account_ids'       => ['a1'],
        'platform'          => 'tiktok',
        'content'           => 'Evergreen',
        'tags'              => ['launch'],
        'hashtags'          => ['#launch'],
        'mentions'          => ['@acme'],
        'recycling'         => ['gap' => 1, 'gapFreq' => 'week'],
        'tiktok_settings'   => ['draft' => true],
        'facebook_settings' => ['draft' => true],
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

// ---------------------------------------------------------------------------
// list_posts
// ---------------------------------------------------------------------------

it('lists posts with all supported filters translated to API keys', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/posts', Mockery::on(function (array $o): bool {
            $q = $o['query'] ?? [];
            return ($q['status'] ?? null) === 'scheduled'
                && ($q['profileId'] ?? null) === 'p1'
                && ($q['accountId'] ?? null) === 'a1'
                && ($q['platform'] ?? null) === 'twitter'
                && ($q['dateFrom'] ?? null) === '2026-07-01'
                && ($q['dateTo'] ?? null) === '2026-07-31'
                && ($q['search'] ?? null) === 'launch'
                && ($q['sortBy'] ?? null) === 'engagement'
                && ($q['source'] ?? null) === 'external'
                && ($q['includeHidden'] ?? null) === true
                && ($q['page'] ?? null) === 2
                && ($q['limit'] ?? null) === 25;
        }))
        ->andReturn(zernioResponse(200, '{"posts":[{"id":"post_1"}],"pagination":{"page":2,"limit":25,"total":1,"pages":1}}'));

    $result = postTool($http)->execute([
        'action'         => 'list_posts',
        'status'         => 'scheduled',
        'profile_id'     => 'p1',
        'account_id'     => 'a1',
        'platform_filter' => 'twitter',
        'date_from'      => '2026-07-01',
        'date_to'        => '2026-07-31',
        'include_hidden' => true,
        'search'         => 'launch',
        'sort_by'        => 'engagement',
        'source'         => 'external',
        'page'           => 2,
        'limit'          => 25,
    ], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Posts (1)')
        ->and($result->data['count'])->toBe(1);
});

// ---------------------------------------------------------------------------
// get_post / delete_post
// ---------------------------------------------------------------------------

it('gets a single post by id via GET /posts/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://zernio.com/api/v1/posts/post_1', Mockery::any())
        ->andReturn(zernioResponse(200, '{"id":"post_1"}'));

    $result = postTool($http)->execute(['action' => 'get_post', 'post_id' => 'post_1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('post_1');
});

it('deletes a post by id via DELETE /posts/{id}', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('DELETE', 'https://zernio.com/api/v1/posts/post_1', Mockery::any())
        ->andReturn(zernioResponse(200, '{"message":"Post deleted successfully"}'));

    $result = postTool($http)->execute(['action' => 'delete_post', 'post_id' => 'post_1'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['post_id'])->toBe('post_1');
});

// ---------------------------------------------------------------------------
// update_post
// ---------------------------------------------------------------------------

it('updates a post via PUT /posts/{id} with the same body shape as create', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('PUT', 'https://zernio.com/api/v1/posts/post_1', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['content'] ?? null) === 'Updated copy'
                && ($json['tags'] ?? null) === ['v2']
                && !isset($json['platforms']);
        }))
        ->andReturn(zernioResponse(200, '{"post":{"_id":"post_1"}}'));

    $result = postTool($http)->execute([
        'action'  => 'update_post',
        'post_id' => 'post_1',
        'content' => 'Updated copy',
        'tags'    => ['v2'],
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('rejects an empty update_post body', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute([
        'action'  => 'update_post',
        'post_id' => 'post_1',
    ], agentId: 1);

    expect($result->success)->toBeFalse();
});

it('rejects update_post with scheduled_for but no timezone with the scheduling error', function (): void {
    // Regression for the bug where buildUpdatePayload swallowed the
    // PostPayloadBuilder::schedulingPayload ToolResult, so an invalid
    // scheduled_for/timezone pair silently fell through to a generic
    // "empty update" error (or a partial PUT). The caller must see the
    // clear "timezone" message and the API must never be called.
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute([
        'action'        => 'update_post',
        'post_id'       => 'post_1',
        'scheduled_for' => '2026-08-01T10:00:00Z',
    ], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('timezone');
});

// ---------------------------------------------------------------------------
// retry / unpublish / edit / update_post_metadata
// ---------------------------------------------------------------------------

it('retries a failed post via POST /posts/{id}/retry', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/post_1/retry', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === [],
        ))
        ->andReturn(zernioResponse(200, '{"status":"pending"}'));

    $result = postTool($http)->execute(['action' => 'retry_post', 'post_id' => 'post_1'], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('unpublishes a post from a platform via POST /posts/{id}/unpublish', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/post_1/unpublish', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['platform' => 'twitter'],
        ))
        ->andReturn(zernioResponse(200, '{"deleted":true}'));

    $result = postTool($http)->execute([
        'action'               => 'unpublish_post',
        'post_id'              => 'post_1',
        'platform_for_unpublish' => 'twitter',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('edits a Twitter post via POST /posts/{id}/edit', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/post_1/edit', Mockery::on(
            fn(array $o): bool => ($o['json'] ?? []) === ['platform' => 'twitter', 'content' => 'typo fix'],
        ))
        ->andReturn(zernioResponse(200, '{"content":"typo fix"}'));

    $result = postTool($http)->execute([
        'action'       => 'edit_post',
        'post_id'      => 'post_1',
        'edit_content' => 'typo fix',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('updates YouTube post metadata via POST /posts/{id}/update-metadata', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/post_1/update-metadata', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['platform'] ?? null) === 'youtube'
                && ($json['title'] ?? null) === 'New title'
                && ($json['privacyStatus'] ?? null) === 'unlisted'
                && ($json['tags'] ?? null) === ['tag1', 'tag2']
                && ($json['madeForKids'] ?? null) === false;
        }))
        ->andReturn(zernioResponse(200, '{"updated":true}'));

    $result = postTool($http)->execute([
        'action'             => 'update_post_metadata',
        'post_id'            => 'post_1',
        'yt_title'           => 'New title',
        'yt_tags'            => ['tag1', 'tag2'],
        'yt_privacy_status'  => 'unlisted',
        'yt_made_for_kids'   => false,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

// ---------------------------------------------------------------------------
// sync_external_posts / bulk_upload
// ---------------------------------------------------------------------------

it('syncs external posts via POST /posts/sync-external', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/sync-external', Mockery::on(function (array $o): bool {
            $json = $o['json'];
            return ($json['accountId'] ?? null) === 'a1'
                && !isset($json['url'])
                && !isset($json['postId']);
        }))
        ->andReturn(zernioResponse(200, '{"matched":3}'));

    $result = postTool($http)->execute([
        'action'     => 'sync_external_posts',
        'account_id' => 'a1',
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('uploads a CSV via POST /posts/bulk-upload with dryRun=false by default', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/bulk-upload', Mockery::on(function (array $o): bool {
            return !isset($o['json'])
                && is_string($o['body'] ?? null)
                && str_starts_with($o['body'], 'content,account_id')
                && ($o['headers']['Content-Type'] ?? null) === 'text/csv';
        }))
        ->andReturn(zernioResponse(200, '{"total":5,"valid":5,"invalid":0}'));

    $result = postTool($http)->execute([
        'action'      => 'bulk_upload',
        'csv_content' => "content,account_id\nhello,a1",
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

it('appends dryRun=true to /posts/bulk-upload when requested', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://zernio.com/api/v1/posts/bulk-upload?dryRun=true', Mockery::any())
        ->andReturn(zernioResponse(200, '{"valid":2,"invalid":0}'));

    $result = postTool($http)->execute([
        'action'      => 'bulk_upload',
        'csv_content' => "content,account_id\nhello,a1",
        'dry_run'     => true,
    ], agentId: 1);

    expect($result->success)->toBeTrue();
});

// ---------------------------------------------------------------------------
// error handling + describeAction
// ---------------------------------------------------------------------------

it('surfaces an API error as a failed ToolResult without throwing', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(zernioResponse(502, 'bad gateway'));

    $result = postTool($http)->execute(['action' => 'list_posts'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 502');
});

it('requires a post_id for delete_post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'delete_post'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id');
});

it('requires a post_id for get_post', function (): void {
    $http = Mockery::mock(HttpClientInterface::class);
    $http->shouldNotReceive('request');

    $result = postTool($http)->execute(['action' => 'get_post'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id');
});

it('describes each post operation for the approval UI', function (): void {
    $tool = postTool(Mockery::mock(HttpClientInterface::class));

    expect($tool->describeAction(['action' => 'list_posts']))->toContain('List')
        ->and($tool->describeAction(['action' => 'get_post', 'post_id' => 'p1']))->toContain('p1')
        ->and($tool->describeAction(['action' => 'update_post', 'post_id' => 'p1']))->toContain('Update')
        ->and($tool->describeAction(['action' => 'delete_post', 'post_id' => 'p1']))->toContain('Delete')
        ->and($tool->describeAction(['action' => 'retry_post', 'post_id' => 'p1']))->toContain('Retry')
        ->and($tool->describeAction(['action' => 'unpublish_post', 'post_id' => 'p1', 'platform_for_unpublish' => 'twitter']))->toContain('twitter')
        ->and($tool->describeAction(['action' => 'edit_post', 'post_id' => 'p1']))->toContain('Edit')
        ->and($tool->describeAction(['action' => 'update_post_metadata', 'post_id' => 'p1']))->toContain('YouTube')
        ->and($tool->describeAction(['action' => 'sync_external_posts', 'account_id' => 'a1']))->toContain('a1')
        ->and($tool->describeAction(['action' => 'bulk_upload', 'dry_run' => true]))->toContain('Dry-run')
        ->and($tool->describeAction(['action' => 'create_post', 'account_ids' => ['a1'], 'platform' => 'twitter', 'publish_now' => true]))
        ->toContain('Published')
        ->and($tool->describeAction(['action' => 'create_post', 'account_ids' => ['a1'], 'platform' => 'twitter', 'scheduled_for' => 'x']))
        ->toContain('Scheduled')
        ->and($tool->describeAction(['action' => 'create_post', 'account_ids' => ['a1'], 'platform' => 'twitter', 'queued_from_profile' => 'p1']))
        ->toContain('Queue-scheduled');
});
