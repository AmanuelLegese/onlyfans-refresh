<?php

namespace App\Upstream;

/**
 * Maps an OnlyFans /api2/v2/users/{username} response to the shape ProfilePayload validates.
 *
 * Only public profile fields are kept. `likes` is set only when favoritedCount is present, so a
 * missing value is rejected as malformed instead of becoming 0.
 */
class ResponseMapper
{
    /** Public fields stored in profiles.profile_data. */
    private const PROFILE_DATA_FIELDS = [
        'id', 'username', 'about', 'header', 'isVerified', 'isPerformer', 'subscribePrice',
        'joinDate', 'location', 'website', 'lastSeen', 'audiosCount', 'archivedPostsCount',
        'mediasCount', 'favoritesCount',
    ];

    /**
     * @param  array<string, mixed>  $json
     * @param  int  $revision  Request start time in epoch milliseconds (OnlyFans has no revision).
     * @return array<string, mixed>
     */
    public function map(array $json, int $revision): array
    {
        $profile = [
            'revision' => $revision,
            'upstream_id' => $json['id'] ?? null,
            'name' => $json['name'] ?? null,
            'avatar_url' => $json['avatar'] ?? null,
            'posts_count' => $json['postsCount'] ?? null,
            'photos_count' => $json['photosCount'] ?? null,
            'videos_count' => $json['videosCount'] ?? null,
            'profile_data' => array_intersect_key($json, array_flip(self::PROFILE_DATA_FIELDS)),
        ];

        if (array_key_exists('favoritedCount', $json)) {
            $profile['likes'] = $json['favoritedCount'];
        }

        return $profile;
    }
}
