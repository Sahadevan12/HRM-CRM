<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired right before a company (tenant) is deleted. Modules listen to remove data that would
 * otherwise block the cascade (e.g. documents referencing customers with restrictive foreign keys).
 */
class CompanyDeleting
{
    use Dispatchable;

    public function __construct(public User $company)
    {
    }
}