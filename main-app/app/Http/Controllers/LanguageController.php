<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    /** Switch the UI language of the logged-in user. */
    public function change(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lang' => ['required', Rule::in(array_keys(availableLanguages()))],
        ]);

        Auth::user()->update(['lang' => $validated['lang']]);

        return back();
    }
}
