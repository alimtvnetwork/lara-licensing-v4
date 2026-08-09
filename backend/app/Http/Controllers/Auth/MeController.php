<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Lauthz\Facades\Enforcer;

class MeController extends Controller
{
    /**
     * Return the Casbin capabilities for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function capabilities(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        
        // In a real environment with lauthz, getImplicitPermissionsForUser 
        // retrieves all permissions the user has, including inherited ones.
        // For the FE capability mirror, Casbin seed rows use "Capability:*" objects.
        $permissions = Enforcer::getImplicitPermissionsForUser($userId);
        
        $capabilities = [];
        foreach ($permissions as $p) {
            // p is typically [sub, obj, act, eft]
            if (isset($p[1]) && str_starts_with($p[1], 'Capability:')) {
                // If the effect is deny, we might need to handle it, but for now 
                // the FE just needs a list of allowed capabilities.
                // In a robust implementation, we would evaluate each capability string.
                if (!isset($p[3]) || $p[3] === 'allow') {
                    $capabilities[] = str_replace('Capability:', '', $p[1]);
                }
            }
        }

        return response()->json([
            'Capabilities' => array_values(array_unique($capabilities)),
        ]);
    }
}
