<?php

namespace Workdo\Pos\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Counter sales without a named customer need a customer on the invoice (documents.party_id is mandatory).
 * Each company gets ONE reserved "Walk-in Customer" user: it cannot log in, is hidden from the Users list and does
 * not use a seat of the plan. Its id is kept in the company setting `walkInCustomerId`.
 */
class WalkInCustomer
{
    public const SETTING = 'walkInCustomerId';

    public static function id(int $tenantId): int
    {
        $stored = (int) (tenantSettings($tenantId)[self::SETTING] ?? 0);

        if ($stored && User::where('id', $stored)->where('created_by', $tenantId)->where('type', 'client')->exists()) {
            return $stored;
        }

        $customer = User::create([
            'name' => 'Walk-in Customer',
            'email' => 'walkin-' . $tenantId . '-' . Str::lower(Str::random(8)) . '@walkin.invalid',
            'password' => Str::random(40),
            'type' => 'client',
            'email_verified_at' => now(),
            'is_enable_login' => false,
            'creator_id' => $tenantId,
            'created_by' => $tenantId,
        ]);
        $customer->assignRole('client');

        setSetting(self::SETTING, $customer->id, $tenantId, false); // not public: never sent to guests

        return $customer->id;
    }
}
