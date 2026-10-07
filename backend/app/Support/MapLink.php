<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a point out of what an owner pastes: a Google Maps link (long or the short
 * maps.app.goo.gl link from the Share button) or plain "11.5564, 104.9282".
 */
final class MapLink
{
    /** Short links we may follow. Never fetch any other address someone types. */
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl'];

    /** @return array{lat: float, lng: float}|null */
    public static function coordinates(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if ($point = self::parse($text)) {
            return $point;
        }

        $host = strtolower((string) parse_url($text, PHP_URL_HOST));

        if (in_array($host, self::SHORT_HOSTS, true)) {
            return self::parse(self::follow($text));
        }

        return null;
    }

    /** @return array{lat: float, lng: float}|null */
    public static function parse(string $text): ?array
    {
        $text = urldecode($text);
        $number = '(-?\d{1,3}(?:\.\d+)?)';

        $patterns = [
            // The pin itself: ...!3d11.5564!4d104.9282 (more exact than the map centre)
            "/!3d{$number}!4d{$number}/",
            // ?q=11.55,104.92  ?ll=  ?query=  &destination=
            "/[?&](?:q|ll|query|destination|center)={$number},\s*{$number}/",
            // .../@11.5564,104.9282,17z
            "/@{$number},{$number}/",
            // Plain "11.5564, 104.9282"
            "/^\s*{$number}\s*,\s*{$number}\s*$/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) {
                    return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
                }
            }
        }

        return null;
    }

    /** Where a short link leads (only the final address, the page is not read). */
    private static function follow(string $url): string
    {
        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])->head($url);
            $history = $response->header('X-Guzzle-Redirect-History');

            return $history !== '' ? (string) collect(explode(', ', $history))->last() : $url;
        } catch (Throwable) {
            return $url;
        }
    }
}
