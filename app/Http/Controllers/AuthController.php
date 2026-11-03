<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials=$request->validate(['identity'=>'required|string','password'=>'required|string']);
        $field=filter_var($credentials['identity'],FILTER_VALIDATE_EMAIL)?'email':'mobile';
        if (Auth::attempt([$field=>$credentials['identity'],'password'=>$credentials['password'],'active'=>true],$request->boolean('remember'))) { $request->session()->regenerate(); return redirect()->intended(route('dashboard')); }
        return back()->withErrors(['identity'=>'These credentials do not match an active account.'])->onlyInput('identity');
    }
    public function logout(Request $request) { Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); return redirect()->route('login'); }
}
