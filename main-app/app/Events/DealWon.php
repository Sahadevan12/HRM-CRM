<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A CRM deal was won. Plain data, so CRM and the sales module stay independent: a listener (sales) may draft a proposal for the client
 * and reports back through `documentId` / `note`, which the CRM writes into the deal's activity trail.
 */
class DealWon
{
    use Dispatchable;

    /** id of the document a listener created (null = nothing created) */
    public ?int $documentId = null;

    /** what happened, in words for the activity trail */
    public ?string $note = null;

    /**
     * @param  array<int, int>  $productIds  products attached to the deal
     */
    public function __construct(
        public int $tenantId,
        public int $dealId,
        public string $dealName,
        public ?int $clientId,
        public array $productIds,
        public ?int $actorId,
    ) {
    }
}
