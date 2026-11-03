<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function profile(Request $request){return view('account.profile',['user'=>$request->user()]);}
    public function updateProfile(Request $request){$user=$request->user();$data=$request->validate(['name'=>'required|string|max:255','email'=>['required','email','max:255',Rule::unique('users','email')->ignore($user->id)],'mobile'=>['nullable','string','max:30',Rule::unique('users','mobile')->ignore($user->id)]]);$user->update($data);return back()->with('success','Your profile was updated.');}
    public function security(){return view('account.security');}
    public function updatePassword(Request $request){$data=$request->validate(['current_password'=>'required|current_password','password'=>'required|string|min:8|confirmed']);$request->user()->update(['password'=>$data['password']]);return back()->with('success','Your password was changed.');}
}
