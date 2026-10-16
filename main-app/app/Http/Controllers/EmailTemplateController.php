<?php

namespace App\Http\Controllers;

use App\Services\MailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** A company reads, rewrites, switches off and tests the e-mails the modules send in its name. */
class EmailTemplateController extends Controller
{
    public function __construct(private MailTemplates $templates)
    {
    }

    public function index(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-email-templates')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('EmailTemplates/Index', ['templates' => $this->templates->forCompany(creatorId())]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        if (!Auth::user()->can('manage-email-templates')) {
            return back()->with('error', __('Permission denied'));
        }
        abort_unless($this->templates->definition($key), 404);

        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'is_enabled' => 'required|boolean',
        ]);

        // {{something}} that the template cannot fill would be sent as an empty hole: refuse it
        $unknown = $this->templates->unknownVariables($key, $data['subject'], $data['body']);
        if ($unknown) {
            return back()->withErrors(['body' => __('Unknown placeholder(s): :names', ['names' => '{{' . implode('}}, {{', $unknown) . '}}'])]);
        }

        DB::table('email_template_overrides')->updateOrInsert(
            ['created_by' => creatorId(), 'key' => $key],
            ['subject' => $data['subject'], 'body' => $data['body'], 'is_enabled' => $data['is_enabled'], 'updated_at' => now(), 'created_at' => now()],
        );

        return back()->with('success', __('The template has been saved.'));
    }

    /** Back to the wording that ships with the module. */
    public function reset(string $key): RedirectResponse
    {
        if (!Auth::user()->can('manage-email-templates')) {
            return back()->with('error', __('Permission denied'));
        }
        abort_unless($this->templates->definition($key), 404);

        DB::table('email_template_overrides')->where('created_by', creatorId())->where('key', $key)->delete();

        return back()->with('success', __('The template is back to its default wording.'));
    }

    /** Sends the template, filled with sample data, to the logged-in user. */
    public function test(string $key): RedirectResponse
    {
        if (!Auth::user()->can('manage-email-templates')) {
            return back()->with('error', __('Permission denied'));
        }
        $definition = $this->templates->definition($key);
        abort_unless($definition, 404);

        $sent = $this->templates->send($key, creatorId(), Auth::user(), $definition['sample']);

        return $sent
            ? back()->with('success', __('A test e-mail was sent to :email.', ['email' => Auth::user()->email]))
            : back()->with('error', __('Nothing was sent: the template is switched off or the mail server refused it.'));
    }
}
