<?php

namespace Workdo\Lead\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Workdo\Lead\Events\DealStatusChanged;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealActivityLog;
use Workdo\Lead\Models\DealStage;

/** Deal rules: stage / pipeline consistency, ordering inside a column, assignees, clients, won / lost and the activity trail. */
class DealService
{
    /** @return \Illuminate\Support\Collection<int, int> the customers (users of type client) of this company; the POS walk-in customer is internal */
    public function clientIds(int $tenant)
    {
        $walkIn = (int) (tenantSettings($tenant)['walkInCustomerId'] ?? 0);

        return User::where('created_by', $tenant)->where('type', 'client')->when($walkIn, fn ($q) => $q->where('id', '!=', $walkIn))->pluck('id');
    }

    /**
     * @param  array{name: string, price?: mixed, phone?: ?string, notes?: ?string, pipeline_id: int, deal_stage_id?: ?int, lead_id?: ?int}  $data
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $clientIds
     */
    public function create(int $tenant, int $actorId, array $data, array $userIds, array $clientIds): Deal
    {
        return DB::transaction(function () use ($tenant, $actorId, $data, $userIds, $clientIds) {
            $stage = isset($data['deal_stage_id'])
                ? DealStage::where('pipeline_id', $data['pipeline_id'])->find($data['deal_stage_id'])
                : DealStage::where('pipeline_id', $data['pipeline_id'])->orderBy('order')->first();

            if (!$stage) {
                throw new LeadException(__('This pipeline has no such deal stage.'));
            }

            $deal = Deal::create([
                'name' => $data['name'], 'price' => $data['price'] ?? 0, 'phone' => $data['phone'] ?? null, 'notes' => $data['notes'] ?? null,
                'pipeline_id' => $data['pipeline_id'], 'deal_stage_id' => $stage->id, 'lead_id' => $data['lead_id'] ?? null,
                'order' => (int) Deal::where('deal_stage_id', $stage->id)->max('order') + 1,
                'creator_id' => $actorId, 'created_by' => $tenant,
            ]);

            $deal->users()->sync($userIds);
            $deal->clients()->sync($clientIds);
            $this->log($deal, $actorId, 'created', __('Deal created in :stage', ['stage' => $stage->name]));

            return $deal;
        });
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $clientIds
     */
    public function update(Deal $deal, int $actorId, array $data, array $userIds, array $clientIds): Deal
    {
        return DB::transaction(function () use ($deal, $actorId, $data, $userIds, $clientIds) {
            $deal->update($data);

            $users = $deal->users()->sync($userIds);
            if ($users['attached'] || $users['detached']) {
                $names = User::whereIn('id', $userIds)->orderBy('name')->pluck('name')->implode(', ');
                $this->log($deal, $actorId, 'assigned', $names !== '' ? __('Assigned to :names', ['names' => $names]) : __('Everybody was unassigned'));
            }

            $clients = $deal->clients()->sync($clientIds);
            if ($clients['attached'] || $clients['detached']) {
                $this->log($deal, $actorId, 'clients', __('The clients of the deal were changed'));
            }

            if ($deal->wasChanged()) {
                $this->log($deal, $actorId, 'updated', __('Deal details were updated'));
            }

            return $deal;
        });
    }

    /**
     * Drag and drop: put the deal into a stage of ITS pipeline and rewrite the positions of the given column.
     *
     * @param  array<int, int>  $orderedIds  the ids of the destination column, top to bottom, including the moved deal
     */
    public function move(Deal $deal, int $stageId, array $orderedIds, int $actorId): Deal
    {
        return DB::transaction(function () use ($deal, $stageId, $orderedIds, $actorId) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $stage = DealStage::where('pipeline_id', $deal->pipeline_id)->find($stageId);

            if (!$stage) {
                throw new LeadException(__('A deal can only move between the stages of its own pipeline.'));
            }
            if ($deal->status !== 'active') {
                throw new LeadException(__('A won or lost deal cannot be moved. Reopen it first.'));
            }
            if (!in_array($deal->id, $orderedIds, true)) {
                throw new LeadException(__('The moved deal is missing from the list.'));
            }

            $allowed = Deal::where('deal_stage_id', $stage->id)->where('created_by', $deal->created_by)->pluck('id')->push($deal->id)->all();
            if (array_diff($orderedIds, $allowed)) {
                throw new LeadException(__('The deal list is out of date. Reload the page.'));
            }

            $from = $deal->stage;
            $deal->deal_stage_id = $stage->id;
            $deal->save();

            foreach (array_values($orderedIds) as $i => $id) {
                Deal::whereKey($id)->update(['order' => $i + 1]);
            }

            if ($from->id !== $stage->id) {
                $this->log($deal, $actorId, 'moved', __('Moved from :from to :to', ['from' => $from->name, 'to' => $stage->name]));
            }

            return $deal->refresh();
        });
    }

    /** active -> won | lost, and back to active ("reopen"). */
    public function setStatus(Deal $deal, string $status, int $actorId): Deal
    {
        if (!in_array($status, Deal::STATUSES, true)) {
            throw new LeadException(__('Unknown deal status.'));
        }

        return DB::transaction(function () use ($deal, $status, $actorId) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $from = $deal->status;

            if ($from === $status) {
                throw new LeadException(__('The deal is already :status.', ['status' => __($status)]));
            }

            // won / lost remember when it was decided, reopening clears it
            $deal->update(['status' => $status, 'closed_at' => $status === 'active' ? null : now()]);
            $this->log($deal, $actorId, 'status', __('Status changed from :from to :to', ['from' => __($from), 'to' => __($status)]));
            DealStatusChanged::dispatch($deal, $from, $status);

            if ($status === 'won') {
                $this->announceWin($deal, $actorId);
            }

            return $deal;
        });
    }

    public function log(Deal $deal, ?int $actorId, string $type, string $remark): DealActivityLog
    {
        return DealActivityLog::create(['deal_id' => $deal->id, 'user_id' => $actorId, 'type' => $type, 'remark' => mb_substr($remark, 0, 500), 'created_by' => $deal->created_by]);
    }

    /**
     * Tell the other modules (core event) that a deal was won. A failing optional automation must never undo the win: the error is reported
     * and written to the activity trail instead.
     */
    private function announceWin(Deal $deal, int $actorId): void
    {
        $event = new \App\Events\DealWon(
            $deal->created_by, $deal->id, $deal->name, $deal->clients()->orderBy('users.id')->value('users.id'),
            $deal->products()->pluck('products.id')->all(), $actorId,
        );

        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
            $event->note = __('The automatic follow-up of the won deal failed: :error', ['error' => $e->getMessage()]);
        }

        if ($event->note) {
            $this->log($deal, $actorId, 'automation', $event->note);
        }
    }
}
