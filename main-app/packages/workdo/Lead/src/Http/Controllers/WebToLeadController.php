<?php

namespace Workdo\Lead\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\Lead\Services\DefaultData;
use Workdo\Lead\Services\LeadService;

/**
 * Public "contact us" form of a company's website: POST name / email / phone / subject / message to
 * /crm/web-to-lead/{secret} and a lead appears in the first stage of the company's oldest pipeline, source "Website".
 *
 * The secret address belongs to one company and can be renewed or switched off in CRM Setup. No login, no CSRF token (the secret and the
 * throttle protect it); a hidden field `website_url` is a honeypot for bots: when it is filled the request is accepted but ignored.
 */
class WebToLeadController extends Controller
{
    public function __construct(private LeadService $leads, private DefaultData $defaults)
    {
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $request->headers->set('Accept', 'application/json'); // a website form wants JSON errors, not a redirect
        $tenant = $this->tenant($token);
        if (!$tenant) {
            return $this->reply(['ok' => false, 'message' => 'Unknown form.'], 404);
        }

        // a bot filled the hidden field: pretend everything is fine
        if (filled($request->input('website_url'))) {
            return $this->reply(['ok' => true]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:5000',
        ]);

        if (blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            return $this->reply(['ok' => false, 'message' => 'Please give an e-mail address or a phone number.', 'errors' => ['email' => ['An e-mail address or a phone number is needed.']]], 422);
        }

        $this->defaults->ensure($tenant);
        $pipeline = Pipeline::where('created_by', $tenant)->orderBy('id')->firstOrFail();

        $lead = $this->leads->create($tenant, $tenant, [
            'subject' => $data['subject'] ?? 'Website enquiry', 'name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'notes' => $data['message'] ?? null, 'pipeline_id' => $pipeline->id,
        ], []);

        $source = Source::firstOrCreate(['created_by' => $tenant, 'name' => 'Website'], ['creator_id' => $tenant]);
        $lead->sources()->attach($source->id);
        $this->leads->log($lead, null, 'web', __('Lead received from the website form'));

        return $this->reply(['ok' => true]);
    }

    /** Browsers ask before a cross-site POST: the form lives on another domain. */
    public function preflight(): JsonResponse
    {
        return $this->reply(null, 204);
    }

    /** The company that owns this secret, only while the CRM module is active for it. */
    private function tenant(string $token): ?int
    {
        if (strlen($token) < 20) {
            return null;
        }

        $tenant = Setting::where('key', AutomationController::TOKEN)->where('value', $token)->value('created_by');

        return $tenant && Module_is_active('Lead', (int) $tenant) ? (int) $tenant : null;
    }

    private function reply(?array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        ]);
    }
}
