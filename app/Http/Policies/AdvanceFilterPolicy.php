<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request;

/**
 * Unlike the other policies here, this one deliberately does not read the
 * route's permission meta.
 *
 * The endpoint it guards serves many unrelated data sets behind one URL —
 * product variations, labels, attributes, tax states — so a single route-level
 * permission would have to be the union of all of them, and would let anyone
 * holding the weakest one read the data behind the strongest. The check that
 * matters is per data key, and only the controller knows which key was asked
 * for.
 *
 * So this is the outer door: it keeps non-admins out entirely, and leaves
 * AdvanceFilterController::resolvePermission() to decide who may read what.
 *
 * @package FluentCart\App\Http\Policies
 *
 * @version 1.0.0
 */
class AdvanceFilterPolicy extends Policy
{
    /**
     * @param Request $request
     * @return bool
     */
    public function verifyRequest(Request $request): bool
    {
        /*
         * getUserPermissions() is empty for anyone without a FluentCart role,
         * which is the distinction wanted here — a logged-in customer must not
         * reach the controller at all. Deliberately not Helper::getCurrentUser(),
         * which resolves for every logged-in WordPress user.
         */
        return !empty(PermissionManager::getUserPermissions());
    }
}
