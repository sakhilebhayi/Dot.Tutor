<?php

namespace App\Domain\Classroom\Services;

/**
 * The STUN and TURN servers handed to a browser for a lesson (adapted from Dot.Engage's IceConfig). STUN finds a direct
 * path; TURN relays media when there is none. No TURN server exists yet, so `relay` is false and the room says so.
 */
class IceConfig
{
    /** @return array{servers: list<array<string, mixed>>, relay: bool, stun: bool} */
    public function forUser(int $userId): array
    {
        $servers = [];
        $stun = (array) config('classroom.rtc.stun_urls', []);
        if ($stun) {
            $servers[] = ['urls' => array_values($stun)];
        }

        $turn = (array) config('classroom.rtc.turn_urls', []);
        $relay = false;
        if ($turn) {
            if ($secret = config('classroom.rtc.turn_secret')) {
                $username = (time() + (int) config('classroom.rtc.turn_ttl')).':tutor-'.$userId;
                $servers[] = ['urls' => array_values($turn), 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, (string) $secret, true))];
                $relay = true;
            } elseif (config('classroom.rtc.turn_username') && config('classroom.rtc.turn_credential')) {
                $servers[] = ['urls' => array_values($turn), 'username' => config('classroom.rtc.turn_username'), 'credential' => config('classroom.rtc.turn_credential')];
                $relay = true;
            }
        }

        return ['servers' => $servers, 'relay' => $relay, 'stun' => $stun !== []];
    }
}
