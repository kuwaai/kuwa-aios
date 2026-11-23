<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        Auth::logoutOtherDevices($request->get('password'));
        $request->session()->regenerate();
        $request->session()->put(
            'password_hash_' . Auth::getDefaultDriver(),
            Auth::guard('web')->hashPasswordForCookie(Auth::user()->getAuthPassword())
        );
        if ($request->input("_token") == null){
            return response()->noContent();
        }
        
        if (Auth::user()->hasPerm('tab_Room')){
            return redirect()->intended("/room");
        }else{
            return redirect()->intended("/");
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request)
    {
        $locale = session()->get('locale');
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();
        if ($request->input("_token") == null){
            return response()->noContent();
        }
        
        session()->put('locale', $locale);
        return redirect('/');
    }
}
