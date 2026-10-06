<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuBuilder;
use Illuminate\Http\JsonResponse;

/** What a customer's phone loads after scanning a table QR. No login. */
class PublicMenuController extends Controller
{
    public function show(string $token, MenuBuilder $menus): JsonResponse
    {
        $data = $menus->forToken($token);

        if ($data === null) {
            // Same answer for "no such table" and "restaurant suspended": don't reveal which.
            return response()->json(['message' => 'This QR code is not active.'], 404);
        }

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'no-store');
    }
}
