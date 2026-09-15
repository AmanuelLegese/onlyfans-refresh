<?php

namespace App\Refresh;

use App\Refresh\Exceptions\MalformedResponse;

class ProfilePayload
{
    private function __construct(
        public readonly int $likes,
        public readonly int $revision,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly ?int $postsCount,
        public readonly ?int $photosCount,
        public readonly ?int $videosCount,
        public readonly ?array $profileData,
        public readonly ?int $upstreamId = null,
    ) {}

    public static function fromJson(array $json): self
    {
        $likes = $json['profile']['likes'] ?? $json['likes'] ?? null;

        if ($likes === null) {
            throw new MalformedResponse('Missing likes field');
        }

        if (! is_int($likes)) {
            throw new MalformedResponse('Likes must be an integer, got '.get_debug_type($likes));
        }

        if ($likes < 0) {
            throw new MalformedResponse('Likes must be non-negative, got '.$likes);
        }

        $revision = $json['revision'] ?? null;

        if ($revision === null) {
            throw new MalformedResponse('Missing revision field');
        }

        if (! is_int($revision)) {
            throw new MalformedResponse('Revision must be an integer, got '.get_debug_type($revision));
        }

        if ($revision < 1) {
            throw new MalformedResponse('Revision must be >= 1, got '.$revision);
        }

        return new self(
            likes: $likes,
            revision: $revision,
            name: self::validateOptionalString($json['name'] ?? null, 'name'),
            avatarUrl: self::validateOptionalString($json['avatar_url'] ?? null, 'avatar_url'),
            postsCount: self::validateOptionalInt($json['posts_count'] ?? null, 'posts_count', min: 0),
            photosCount: self::validateOptionalInt($json['photos_count'] ?? null, 'photos_count', min: 0),
            videosCount: self::validateOptionalInt($json['videos_count'] ?? null, 'videos_count', min: 0),
            profileData: $json['profile_data'] ?? null,
            upstreamId: self::validateOptionalInt($json['upstream_id'] ?? null, 'upstream_id', min: 1),
        );
    }

    private static function validateOptionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new MalformedResponse("{$field} must be a string or null");
        }

        return $value;
    }

    private static function validateOptionalInt(mixed $value, string $field, int $min = 0): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new MalformedResponse("{$field} must be an integer or null");
        }

        if ($value < $min) {
            throw new MalformedResponse("{$field} must be >= {$min}, got {$value}");
        }

        return $value;
    }
}
