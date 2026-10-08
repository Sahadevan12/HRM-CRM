<?php

namespace Workdo\Lead\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Workdo\Lead\Events\LeadConverted;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\Lead;

/**
 * Lead -> deal. The deal takes over the lead's name, phone, notes and staff, gets a client (an existing one, a brand new one, or none),
 * and copies what the user ticks: products, sources, labels (only when the deal is in the same pipeline), tasks, calls, e-mails, comments
 * and files (the files are physically copied). The lead stays, marked converted, and can no longer be moved.
 */
class LeadConversion
{
    public const COPY = ['products', 'sources', 'labels', 'tasks', 'calls', 'emails', 'discussions', 'files'];

    public function __construct(private DealService $deals)
    {
    }

    /**
     * @param  array{price: mixed, pipeline_id: int, client_mode: string, client_id?: ?int, client_name?: ?string, client_email?: ?string, copy?: array<int, string>}  $options
     */
    public function convert(Lead $lead, array $options, int $actorId): Deal
    {
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($lead, $options, $actorId, &$copiedFiles) {
                $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

                if ($lead->is_converted || Deal::where('lead_id', $lead->id)->exists()) {
                    throw new LeadException(__('This lead has already been converted to a deal.'));
                }

                $tenant = $lead->created_by;
                $clientIds = $this->client($tenant, $actorId, $options);

                $deal = $this->deals->create($tenant, $actorId, [
                    'name' => $lead->subject, 'price' => $options['price'], 'phone' => $lead->phone, 'notes' => $lead->notes,
                    'pipeline_id' => $options['pipeline_id'], 'lead_id' => $lead->id,
                ], $lead->users()->pluck('users.id')->all(), $clientIds);

                $copy = array_intersect(self::COPY, $options['copy'] ?? []);
                $copiedFiles = $this->copyChildren($lead, $deal, $copy);

                $lead->update(['is_converted' => true]);

                $this->deals->log($deal, $actorId, 'converted', __('Converted from lead :lead', ['lead' => $lead->subject]));
                app(LeadService::class)->log($lead, $actorId, 'converted', __('Converted to deal :deal', ['deal' => $deal->name]));
                LeadConverted::dispatch($lead, $deal);

                return $deal;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($copiedFiles); // the rolled back deal must not leave orphan files behind
            throw $e;
        }
    }

    /** @return array<int, int> ids of the client users of the deal */
    private function client(int $tenant, int $actorId, array $o): array
    {
        $mode = $o['client_mode'];

        if ($mode === 'existing') {
            if (!$this->deals->clientIds($tenant)->contains((int) ($o['client_id'] ?? 0))) {
                throw new LeadException(__('Choose one of your clients.'));
            }

            return [(int) $o['client_id']];
        }

        if ($mode !== 'new') {
            return [];
        }

        if (User::where('email', $o['client_email'] ?? '')->exists()) {
            throw new LeadException(__('A user with this e-mail address already exists. Choose it as an existing client.'));
        }
        $limit = canCreateUser();
        if (!$limit['can_create']) {
            throw new LeadException($limit['message']);
        }

        // a customer record without a login: nobody knows the password and the account is switched off
        $client = User::create([
            'name' => $o['client_name'], 'email' => $o['client_email'], 'password' => Hash::make(Str::random(40)), 'type' => 'client',
            'email_verified_at' => now(), 'is_enable_login' => false, 'creator_id' => $actorId, 'created_by' => $tenant,
        ]);
        $client->assignRole('client');

        return [$client->id];
    }

    /** @return array<int, string> storage paths of the copied files (so they can be removed again if the conversion fails) */
    private function copyChildren(Lead $lead, Deal $deal, array $copy): array
    {
        $files = [];
        $own = fn ($row) => ['deal_id' => $deal->id, 'creator_id' => $row->creator_id, 'created_by' => $row->created_by];

        if (in_array('products', $copy, true)) {
            $deal->products()->sync($lead->products()->pluck('products.id')->all());
        }
        if (in_array('sources', $copy, true)) {
            $deal->sources()->sync($lead->sources()->pluck('sources.id')->all());
        }
        // a label belongs to a pipeline: it only fits a deal in the same pipeline
        if (in_array('labels', $copy, true) && $deal->pipeline_id === $lead->pipeline_id) {
            $deal->labels()->sync($lead->labels()->pluck('labels.id')->all());
        }

        foreach ($lead->tasks as $r) {
            if (in_array('tasks', $copy, true)) {
                $deal->tasks()->create($own($r) + $r->only(['name', 'due_date', 'due_time', 'priority', 'status']));
            }
        }
        foreach ($lead->calls as $r) {
            if (in_array('calls', $copy, true)) {
                $deal->calls()->create($own($r) + $r->only(['subject', 'call_type', 'duration_minutes', 'description', 'result']));
            }
        }
        foreach ($lead->emails as $r) {
            if (in_array('emails', $copy, true)) {
                $deal->emails()->create($own($r) + $r->only(['to', 'subject', 'description']));
            }
        }
        foreach ($lead->discussions as $r) {
            if (in_array('discussions', $copy, true)) {
                $deal->discussions()->create($own($r) + $r->only(['comment']));
            }
        }
        foreach ($lead->files as $r) {
            if (!in_array('files', $copy, true)) {
                continue;
            }
            $disk = Storage::disk('local');
            $source = $r->makeVisible('file_path')->file_path;
            if (!$disk->exists($source)) {
                continue; // a file that is already gone is simply not copied
            }

            $target = 'deal-files/' . $deal->created_by . '/' . Str::random(40) . '.' . pathinfo($source, PATHINFO_EXTENSION);
            $disk->copy($source, $target);
            $files[] = $target;
            $deal->files()->create($own($r) + ['file_name' => $r->file_name, 'file_path' => $target, 'file_size' => $r->file_size]);
        }

        return $files;
    }
}
