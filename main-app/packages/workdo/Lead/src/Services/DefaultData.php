<?php

namespace Workdo\Lead\Services;

use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;

/**
 * Starter CRM setup of a company: a "Sales" pipeline with its lead and deal stages, three labels and the usual lead sources.
 * Done ONCE per company (setting `crmDefaultsSeeded`) when the CRM setup / dashboard is first opened; deleted defaults never come back.
 */
class DefaultData
{
    private const FLAG = 'crmDefaultsSeeded';

    public const LEAD_STAGES = ['New', 'Contacted', 'Qualified', 'Proposal Sent'];
    public const DEAL_STAGES = ['Initial Contact', 'Qualification', 'Meeting', 'Proposal', 'Negotiation'];
    private const LABELS = ['Hot' => '#dc2626', 'Warm' => '#f59e0b', 'Cold' => '#2563eb'];
    private const SOURCES = ['Website', 'Referral', 'Cold Call', 'Email Campaign', 'Social Media', 'Event'];

    public function ensure(int $tenantId): bool
    {
        if ((tenantSettings($tenantId)[self::FLAG] ?? null) === '1') {
            return false;
        }

        $own = ['creator_id' => $tenantId, 'created_by' => $tenantId];

        $pipeline = Pipeline::firstOrCreate(['created_by' => $tenantId, 'name' => 'Sales'], $own);
        $this->stages($pipeline);

        foreach (self::LABELS as $name => $color) {
            Label::firstOrCreate(['created_by' => $tenantId, 'pipeline_id' => $pipeline->id, 'name' => $name], $own + ['color' => $color]);
        }
        foreach (self::SOURCES as $name) {
            Source::firstOrCreate(['created_by' => $tenantId, 'name' => $name], $own);
        }

        setSetting(self::FLAG, '1', $tenantId, false);

        return true;
    }

    /** The default lead and deal stages of a (new) pipeline, in order. Stages that already exist are not duplicated. */
    public function stages(Pipeline $pipeline): void
    {
        $own = ['creator_id' => $pipeline->created_by, 'created_by' => $pipeline->created_by];

        foreach ([LeadStage::class => self::LEAD_STAGES, DealStage::class => self::DEAL_STAGES] as $model => $names) {
            foreach ($names as $i => $name) {
                $model::firstOrCreate(['pipeline_id' => $pipeline->id, 'name' => $name], $own + ['order' => $i + 1]);
            }
        }
    }
}
