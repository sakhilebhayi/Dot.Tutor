<?php

namespace App\Domain\Learning\Services;

/** External video links: https only, and only on the hosts in config('learning.video_hosts'). */
class VideoUrlPolicy
{
    public function problem(string $url): ?string
    {
        if ($url === '') {
            return 'add the video link.';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return 'the video link must start with https://.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'the video link must not contain a user name or password.';
        }
        $hosts = array_map('strtolower', (array) config('learning.video_hosts', []));
        if (! in_array(strtolower($parts['host']), $hosts, true)) {
            return 'videos can only be linked from '.implode(', ', array_unique(array_map(fn ($h) => preg_replace('/^www\./', '', $h), $hosts))).'.';
        }

        return null;
    }
}
