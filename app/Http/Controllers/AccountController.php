<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Rules\PasswordInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        return response()->view('account', ['page' => 'account', 'branch' => Branch::find($request->user()->branch_id)])->header('Cache-Control', 'no-store, private');
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => ['bail', 'required', 'string', 'max:200', 'current_password'], 'password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput, 'confirmed', 'different:current_password']]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->is_active && $user->auth_version === $request->user()->auth_version, 409, 'Account changed. Sign in again.');
            $user->forceFill(['password' => Hash::make($data['password']), 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            AuditLog::create(['user_id' => $user->id, 'branch_id' => $user->branch_id, 'action' => 'account.password_changed', 'description' => 'Changed account password']);

            return $user;
        }, 5);
        Auth::setUser($user);
        $request->session()->put('auth_version', $user->auth_version);
        $request->session()->regenerate();

        return redirect('/account')->with('success', 'Password changed. Other sessions have been signed out.');
    }
}
