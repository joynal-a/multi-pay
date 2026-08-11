<?php

namespace Abedin\MultiPay\Http\Controllers;

use Abedin\MultiPay\Support\GatewayConfigStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints behind the includable admin UI. Protect them with your own admin
 * middleware via config('multipay.admin_middleware').
 */
class GatewayAdminController
{
    public function toggle(Request $request, string $gateway): JsonResponse
    {
        $active = $request->boolean('active');

        if ($active) {
            $entry = collect(GatewayConfigStore::all())->firstWhere('name', $gateway);

            if ($entry && $entry['missing'] !== []) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Fill the required credentials first: ' . implode(', ', $entry['missing']),
                ], 422);
            }
        }

        GatewayConfigStore::setActive($gateway, $active);

        return response()->json([
            'ok' => true,
            'active' => $active,
            'message' => $active ? 'Gateway activated.' : 'Gateway deactivated.',
        ]);
    }

    public function update(Request $request, string $gateway): JsonResponse
    {
        $fields = (array) $request->input('fields', []);

        GatewayConfigStore::update($gateway, $fields);

        return response()->json([
            'ok' => true,
            'message' => 'Credentials updated.',
        ]);
    }
}
