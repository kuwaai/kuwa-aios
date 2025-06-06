<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\SystemSetting;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|View
    {
        if (!$request->user()->hasVerifiedEmail() && !SystemSetting::smtpConfigured()) {
            $request->user()->markEmailAsVerified();
        }
        if ($request->user()->hasVerifiedEmail()) {
            if ($request->user()->hasPerm('tab_Room')) {
                return redirect()->intended('/room');
            } else {
                return redirect()->intended('/');
            }
        }
        return view('auth.verify-email');
    }
}
