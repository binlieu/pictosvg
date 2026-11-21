<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A user-facing, actionable failure. The message is safe to show to clients:
 * it must never contain filesystem paths or command lines.
 */
class PicToSvgException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function unsupportedImage(string $message = 'This file is not a supported image. Please upload a JPG, PNG or WebP picture.'): self
    {
        return new self($message, 'unsupported_image', 422);
    }

    public static function tooLarge(string $message): self
    {
        return new self($message, 'image_too_large', 422);
    }

    public static function invalidToken(): self
    {
        return new self('This conversion link is not valid.', 'invalid_token', 403);
    }

    public static function expired(): self
    {
        return new self('This image has expired. Please upload it again.', 'expired', 404);
    }

    public static function timeout(): self
    {
        return new self('The conversion took too long. Try lowering Detail or raising Noise Removal.', 'trace_timeout', 504);
    }

    public static function toolMissing(string $tool): self
    {
        return new self("The server is missing a required conversion tool ({$tool}). Please contact the site administrator.", 'tool_missing', 503);
    }

    public static function conversionFailed(string $message = 'The image could not be converted. Try different settings or another image.', int $status = 422): self
    {
        return new self($message, 'conversion_failed', $status);
    }

    public static function invalidSvg(): self
    {
        return new self('The conversion produced an invalid result. Try different settings.', 'invalid_svg', 500);
    }

    public static function aiUnavailable(): self
    {
        return new self('AI Redraw is not set up on this server. Standard conversion works without it.', 'ai_unavailable', 503);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
