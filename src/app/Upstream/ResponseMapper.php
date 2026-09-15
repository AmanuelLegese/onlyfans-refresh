<?php

namespace App\Upstream;

class ResponseMapper
{
    private const WHITELISTED_FIELDS = [
        'name', 'avatar_url', 'posts_count', 'photos_count', 'videos_count',
    ];

    public function map(array $json): array
    {
        $likes = $this->extractLikes($json);
        $revision = $this->extractRevision();

        $profile = [
            'likes' => $likes,
            'revision' => $revision,
        ];

        foreach (self::WHITELISTED_FIELDS as $field) {
            if (array_key_exists($field, $json)) {
                $value = $json[$field];

                if ($field === 'posts_count' || $field === 'photos_count' || $field === 'videos_count') {
                    $profile[$field] = is_int($value) && $value >= 0 ? $value : null;
                } else {
                    $profile[$field] = is_string($value) ? $value : null;
                }
            }
        }

        return $profile;
    }

    private function extractLikes(array $json): int
    {
        if (isset($json['profile']['likes']) && is_int($json['profile']['likes'])) {
            return $json['profile']['likes'];
        }

        if (isset($json['favoritedCount']) && is_int($json['favoritedCount'])) {
            return $json['favoritedCount'];
        }

        if (isset($json['likes_count']) && is_int($json['likes_count'])) {
            return $json['likes_count'];
        }

        return 0;
    }

    private function extractRevision(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
