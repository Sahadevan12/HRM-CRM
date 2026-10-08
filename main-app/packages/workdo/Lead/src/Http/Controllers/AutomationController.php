<?php

namespace Workdo\Lead\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** CRM automation settings: draft a proposal when a deal is won, and the secret address of the web-to-lead form. */
class AutomationController extends Controller
{
    public const PROPOSAL = 'crmDraftProposalOnWin';
    public const TOKEN = 'crmWebToLeadToken';

    public function update(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-pipelines')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate(['draft_proposal_on_win' => 'required|boolean']);
        setSetting(self::PROPOSAL, $data['draft_proposal_on_win'] ? '1' : '0', creatorId(), false);

        return back()->with('success', __('Saved.'));
    }

    /** A new secret for the web form (the old address stops working at once). */
    public function regenerateToken(): RedirectResponse
    {
        if (!Auth::user()->can('edit-pipelines')) {
            return back()->with('error', __('Permission denied'));
        }

        setSetting(self::TOKEN, Str::random(40), creatorId(), false);

        return back()->with('success', __('The web form address has been renewed. Update the form on your website.'));
    }

    public function disableToken(): RedirectResponse
    {
        if (!Auth::user()->can('edit-pipelines')) {
            return back()->with('error', __('Permission denied'));
        }

        setSetting(self::TOKEN, '', creatorId(), false);

        return back()->with('success', __('The web form has been switched off.'));
    }
}
