<?php

namespace Workdo\Lead\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadActivityLog;
use Workdo\Lead\Models\LeadStage;

/** Lead rules: stage / pipeline consistency, ordering inside a stage column, assignees and the activity trail. */
class LeadService
{
    /** @return \Illuminate\Support\Collection<int, int> ids of the users a lead of this company can be assigned to (the owner and the staff) */
    public function assignableUserIds(int $tenant)
    {
        return User::where(fn ($q) => $q->where('id', $tenant)->orWhere('created_by', $tenant))->whereIn('type', ['company', 'staff'])->pluck('id');
    }

    /**
     * @param  array{subject: string, name: string, email?: ?string, phone?: ?string, notes?: ?string, follow_up_date?: ?string, pipeline_id: int, lead_stage_id?: ?int}  $data
     * @param  array<int, int>  $userIds
     */
    public function create(int $tenant, int $actorId, array $data, array $userIds): Lead
    {
        return DB::transaction(function () use ($tenant, $actorId, $data, $userIds) {
            $stage = isset($data['lead_stage_id'])
                ? LeadStage::where('pipeline_id', $data['pipeline_id'])->find($data['lead_stage_id'])
                : LeadStage::where('pipeline_id', $data['pipeline_id'])->orderBy('order')->first();

            if (!$stage) {
                throw new LeadException(__('This pipeline has no such lead stage.'));
            }

            $lead = Lead::create([
                'subject' => $data['subject'], 'name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null, 'follow_up_date' => $data['follow_up_date'] ?? null,
                'pipeline_id' => $data['pipeline_id'], 'lead_stage_id' => $stage->id,
                'order' => (int) Lead::where('lead_stage_id', $stage->id)->max('order') + 1,
                'creator_id' => $actorId, 'created_by' => $tenant,
            ]);

            $lead->users()->sync($userIds);
            $this->log($lead, $actorId, 'created', __('Lead created in :stage', ['stage' => $stage->name]));

            return $lead;
        });
    }

    /** @param array<int, int> $userIds */
    public function update(Lead $lead, int $actorId, array $data, array $userIds): Lead
    {
        return DB::transaction(function () use ($lead, $actorId, $data, $userIds) {
            $lead->update($data);

            $changes = $lead->users()->sync($userIds);
            if ($changes['attached'] || $changes['detached']) {
                $names = User::whereIn('id', $userIds)->orderBy('name')->pluck('name')->implode(', ');
                $this->log($lead, $actorId, 'assigned', $names !== '' ? __('Assigned to :names', ['names' => $names]) : __('Everybody was unassigned'));
            }
            if ($lead->wasChanged()) {
                $this->log($lead, $actorId, 'updated', __('Lead details were updated'));
            }

            return $lead;
        });
    }

    /**
     * Drag and drop: put the lead into a stage of ITS pipeline and rewrite the positions of the given column.
     *
     * @param  array<int, int>  $orderedIds  the ids of the destination column, top to bottom, including the moved lead
     */
    public function move(Lead $lead, int $stageId, array $orderedIds, int $actorId): Lead
    {
        return DB::transaction(function () use ($lead, $stageId, $orderedIds, $actorId) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $stage = LeadStage::where('pipeline_id', $lead->pipeline_id)->find($stageId);

            if (!$stage) {
                throw new LeadException(__('A lead can only move between the stages of its own pipeline.'));
            }
            if ($lead->is_converted) {
                throw new LeadException(__('A converted lead cannot be moved.'));
            }
            if (!in_array($lead->id, $orderedIds, true)) {
                throw new LeadException(__('The moved lead is missing from the list.'));
            }

            // the list may only contain leads that are in (or arrive in) that column of that company
            $allowed = Lead::where('lead_stage_id', $stage->id)->where('created_by', $lead->created_by)->pluck('id')->push($lead->id)->all();
            if (array_diff($orderedIds, $allowed)) {
                throw new LeadException(__('The lead list is out of date. Reload the page.'));
            }

            $from = $lead->stage;
            $lead->lead_stage_id = $stage->id;
            $lead->save();

            foreach (array_values($orderedIds) as $i => $id) {
                Lead::whereKey($id)->update(['order' => $i + 1]);
            }

            if ($from->id !== $stage->id) {
                $this->log($lead, $actorId, 'moved', __('Moved from :from to :to', ['from' => $from->name, 'to' => $stage->name]));
            }

            return $lead->refresh();
        });
    }

    public function log(Lead $lead, ?int $actorId, string $type, string $remark): LeadActivityLog
    {
        return LeadActivityLog::create(['lead_id' => $lead->id, 'user_id' => $actorId, 'type' => $type, 'remark' => mb_substr($remark, 0, 500), 'created_by' => $lead->created_by]);
    }
}
