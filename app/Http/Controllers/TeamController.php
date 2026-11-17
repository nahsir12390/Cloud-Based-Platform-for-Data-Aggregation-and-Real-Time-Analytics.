<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Rules\PasswordInput;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function edit(Request $request, User $user)
    {
        $this->authorizeStaff($request, $user);

        return response()->view('team-edit', ['page' => 'team', 'member' => $user, 'branches' => Branch::orderBy('name')->get()])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeStaff($request, $user);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:200', Rule::unique('users')->ignore($user->id)], 'branch_id' => ['required', 'exists:branches,id'], 'role' => ['prohibited'], 'is_active' => ['prohibited'], 'auth_version' => ['prohibited']]);
        DB::transaction(function () use ($request, $user, $data) {
            $member = User::lockForUpdate()->findOrFail($user->id);
            $this->authorizeStaff($request, $member);
            $oldBranch = $member->branch_id;
            $member->fill($data);
            if ($member->isDirty()) {
                $this->invalidateSessions($member);
                $member->save();
                $this->audit($request, $member, 'user.updated', 'Updated staff profile; previous branch #'.($oldBranch ?? 'none').', current branch #'.$member->branch_id);
            }
        }, 5);

        return redirect('/team')->with('success', 'Staff profile updated. Changed accounts must sign in again.');
    }

    public function status(Request $request, User $user)
    {
        $this->authorizeStaff($request, $user);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $user, $data) {
            $member = User::lockForUpdate()->findOrFail($user->id);
            $this->authorizeStaff($request, $member);
            if ($member->is_active !== (bool) $data['active']) {
                $member->is_active = (bool) $data['active'];
                $this->invalidateSessions($member);
                $member->save();
                $this->audit($request, $member, $member->is_active ? 'user.activated' : 'user.deactivated', ($member->is_active ? 'Activated' : 'Deactivated').' staff account');
            }
        }, 5);

        return redirect('/team')->with('success', $data['active'] ? 'Staff account activated. The staff member can sign in again.' : 'Staff account deactivated. Access has been revoked; transaction history is preserved.');
    }

    public function password(Request $request, User $user)
    {
        $this->authorizeStaff($request, $user);
        $data = $request->validate(['current_password' => ['bail', 'required', 'string', 'max:200', 'current_password'], 'password' => ['required', 'string', 'min:8', 'max:72', new PasswordInput, 'confirmed']]);
        DB::transaction(function () use ($request, $user, $data) {
            $member = User::lockForUpdate()->findOrFail($user->id);
            $this->authorizeStaff($request, $member);
            $member->password = Hash::make($data['password']);
            $this->invalidateSessions($member);
            $member->save();
            $this->audit($request, $member, 'user.password_reset', 'Reset staff password');
        }, 5);

        return redirect('/team/'.$user->id.'/edit')->with('success', 'Staff password reset. Share the new password privately; previous sessions have been signed out.');
    }

    private function authorizeStaff(Request $request, User $member): void
    {
        BranchAccess::owner($request->user());
        abort_unless($member->role === 'staff' && $member->id !== $request->user()->id, 403, 'Only staff accounts can be managed here.');
    }

    private function invalidateSessions(User $member): void
    {
        $member->auth_version++;
        $member->remember_token = Str::random(60);
    }

    private function audit(Request $request, User $member, string $action, string $description): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'branch_id' => $member->branch_id, 'action' => $action, 'description' => $description.' for user #'.$member->id]);
    }
}
