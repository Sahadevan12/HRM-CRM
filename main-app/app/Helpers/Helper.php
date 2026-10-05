<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('creatorId')) {
    /**
     * Tenant (company) id of the logged-in user.
     * superadmin / company => own id, any sub-user => the company that created them.
     */
    function creatorId()
    {
        $user = Auth::user();

        if (in_array($user->type, ['superadmin', 'company'])) {
            return $user->id;
        }

        return $user->created_by;
    }
}
