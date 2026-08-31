<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Upload media (images/videos) to Zernio. The two-step flow:
 *
 *   1. Call `presign_media` to get a presigned S3 URL.
 *   2. PUT the file contents to that URL from your application code (the
 *      plugin does not upload the file itself — the agent is responsible
 *      for the binary transfer).
 *   3. Use the returned `public_url` in `media_items[].url` when creating
 *      a post.
 *
 * For small files, `upload_media` accepts base64-encoded bytes in `content`,
 * decodes them, and forwards them as a JSON `data` field (base64-encoded
 * again) in the body of POST `/v1/media/upload-direct`. The round-trip
 * avoids the multipart path because ZernioClient::post always sends JSON.
 */
#[Tool(
    name: 'zernio_media',
    description: 'Upload media (images/videos) to Zernio to attach to posts. Use presign_media to get a presigned URL, then upload your file to that URL from your application. Use upload_media for small single-shot uploads.',
    displayName: 'Zernio Media',
    category: 'social-media',
)]
#[ToolOperation(name: 'presign_media', description: 'Get a presigned S3 URL for uploading a file', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'upload_media', description: 'Upload a small file directly to Zernio (JSON body, base64 in `data`)', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Zernio API Key', type: 'password', description: 'Bearer token for the Zernio API. Falls back to the ZERNIO_API_KEY environment variable.', required: true)]
#[ToolSetting(key: 'base_url', label: 'Base URL', type: 'text', description: 'Zernio API base URL (default: https://zernio.com/api/v1).', default: 'https://zernio.com/api/v1')]
#[ToolSetting(key: 'http_timeout', label: 'HTTP Timeout', type: 'text', description: 'Seconds before an HTTP request fails (default: 30).')]
#[ToolParameter(name: 'filename', type: 'string', description: 'Original filename, e.g. "hero.jpg".', required: ['presign_media', 'upload_media'])]
#[ToolParameter(name: 'content_type', type: 'string', description: 'MIME type, e.g. "image/jpeg", "video/mp4". Must be one of the supported types (see Zernio docs).', required: ['presign_media', 'upload_media'])]
#[ToolParameter(name: 'size', type: 'integer', description: 'File size in bytes (optional; helps Zernio reserve storage).', required: false)]
#[ToolParameter(name: 'content', type: 'string', description: 'Base64-encoded file contents for upload_media. The plugin decodes them and re-encodes them as a JSON `data` field.', required: ['upload_media'])]
final class ZernioMediaTool extends AbstractZernioTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $ownerId = $context->ownerUserId ?? $userId;
        return $this->withConfig($agentId, $ownerId, fn(ZernioConfig $config): ToolResult => $this->guard(
            fn(): ToolResult => match ($this->getOperationName($arguments)) {
                'upload_media' => $this->uploadDirect($arguments, $config),
                default        => $this->presign($arguments, $config),
            },
        ));
    }

    public function describeAction(array $arguments): string
    {
        return match ($this->getOperationName($arguments)) {
            'upload_media' => 'Upload media to Zernio',
            default        => 'Get a Zernio media presigned URL',
        };
    }

    /** @param array<string, mixed> $arguments */
    private function presign(array $arguments, ZernioConfig $config): ToolResult
    {
        $filename    = $this->requireParam($arguments, 'filename', 'presign_media requires a filename.');
        if ($filename instanceof ToolResult) {
            return $filename;
        }
        $contentType = $this->requireParam($arguments, 'content_type', 'presign_media requires a content_type (MIME).');
        if ($contentType instanceof ToolResult) {
            return $contentType;
        }
        $payload = ['filename' => $filename, 'contentType' => $contentType];
        if (isset($arguments['size']) && (int) $arguments['size'] > 0) {
            $payload['size'] = (int) $arguments['size'];
        }
        return $this->jsonResult("Presigned URL:\n", $this->client->post('/media/presign', $payload, $config));
    }

    /** @param array<string, mixed> $arguments */
    private function uploadDirect(array $arguments, ZernioConfig $config): ToolResult
    {
        $validated = $this->validateUploadArgs($arguments);
        if ($validated instanceof ToolResult) {
            return $validated;
        }
        $response = $this->client->post('/media/upload-direct', [
            'filename'    => $validated['filename'],
            'contentType' => $validated['contentType'],
            'data'        => base64_encode($validated['bytes']),
        ], $config);
        return $this->jsonResult("Uploaded media:\n", $response);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array{filename: string, contentType: string, bytes: string}|ToolResult
     */
    private function validateUploadArgs(array $arguments): array|ToolResult
    {
        $required = $this->requireUploadMetadata($arguments);
        if ($required instanceof ToolResult) {
            return $required;
        }
        $bytes = $this->requireUploadBytes($arguments);
        if ($bytes instanceof ToolResult) {
            return $bytes;
        }
        return ['filename' => $required['filename'], 'contentType' => $required['contentType'], 'bytes' => $bytes];
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array{filename: string, contentType: string}|ToolResult
     */
    private function requireUploadMetadata(array $arguments): array|ToolResult
    {
        $filename = $this->requireParam($arguments, 'filename', 'upload_media requires a filename.');
        if ($filename instanceof ToolResult) {
            return $filename;
        }
        $contentType = $this->requireParam($arguments, 'content_type', 'upload_media requires a content_type (MIME).');
        if ($contentType instanceof ToolResult) {
            return $contentType;
        }
        return ['filename' => $filename, 'contentType' => $contentType];
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return string|ToolResult
     */
    private function requireUploadBytes(array $arguments): string|ToolResult
    {
        $b64 = (string) ($arguments['content'] ?? '');
        if ($b64 === '') {
            return new ToolResult(false, 'upload_media requires `content` (base64-encoded file bytes).');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            return new ToolResult(false, 'upload_media `content` is not valid base64.');
        }
        return $bytes;
    }
}
