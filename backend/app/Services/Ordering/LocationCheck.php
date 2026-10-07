<?php

namespace App\Services\Ordering;

use App\Models\Branch;

/**
 * "Is this phone in the shop?" for branches that only take QR orders on site.
 *
 * The phone sends its GPS point and how accurate it is. Indoor GPS is often off by
 * tens of metres, so the phone's own accuracy (capped) is added to the branch radius:
 * a real customer at the table passes, someone at home kilometres away does not.
 */
final class LocationCheck
{
    /** At most this much of the phone's reported inaccuracy is forgiven. */
    public const MAX_ACCURACY_BONUS_M = 100;

    /**
     * @param  array{lat: float|string, lng: float|string, accuracy?: float|string|null}|null  $location
     * @return int|null distance in metres, or null when this branch does not check location
     */
    public static function enforce(Branch $branch, ?array $location): ?int
    {
        if (! self::required($branch)) {
            return $location && $branch->latitude !== null ? self::distanceTo($branch, $location) : null;
        }

        if (! $location) {
            self::fail('location_required', 'Please allow location so we can check you are in the shop.');
        }

        $distance = self::distanceTo($branch, $location);
        $bonus = min(self::MAX_ACCURACY_BONUS_M, max(0, (int) round((float) ($location['accuracy'] ?? 0))));

        if ($distance > $branch->order_radius_m + $bonus) {
            self::fail('location_too_far', 'You seem to be outside the shop. Orders can only be sent from inside. If you are here, please ask a staff member.');
        }

        return $distance;
    }

    /** Only when the owner switched it on and set the shop's point. */
    public static function required(Branch $branch): bool
    {
        return $branch->require_location && $branch->latitude !== null && $branch->longitude !== null;
    }

    /** Great-circle distance in metres (haversine). */
    public static function distanceTo(Branch $branch, array $location): int
    {
        $earth = 6_371_000;
        $lat1 = deg2rad((float) $branch->latitude);
        $lat2 = deg2rad((float) $location['lat']);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $location['lng'] - (float) $branch->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** Validation rules for the optional `location` field of public requests. */
    public static function rules(): array
    {
        return [
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** 422 with a machine-readable code, so the phone can show the right help in Khmer or English. */
    private static function fail(string $code, string $message): never
    {
        abort(response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => ['location' => [$message]],
        ], 422));
    }
}
