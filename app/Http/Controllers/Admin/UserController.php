<?php

namespace App\Http\Controllers\Admin;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = User::with('supplier')->orderBy('created_at', 'desc');

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('name_display', function ($user) {
                    $html = '<div class="fw-semibold text-body">'.e($user->name).'</div>';
                    if ($user->role === 'supplier' && $user->supplier) {
                        $html .= '<div class="small text-muted mt-1">'.e($user->supplier->company_name).'</div>';
                    }

                    return $html;
                })
                ->addColumn('role_badge', function ($user) {
                    return match ($user->role) {
                        'admin' => '<span class="ui-status-chip ui-status-chip--neutral">Admin</span>',
                        'purchasing' => '<span class="ui-status-chip ui-status-chip--neutral">Purchasing</span>',
                        'supplier' => '<span class="ui-status-chip ui-status-chip--neutral">Supplier</span>',
                        'qc' => '<span class="ui-status-chip ui-status-chip--neutral">QC</span>',
                        default => '<span class="ui-status-chip ui-status-chip--neutral">'.e($user->role).'</span>',
                    };
                })
                ->addColumn('status_badge', fn ($user) => $user->is_active
                    ? '<span class="ui-status-chip ui-status-chip--success">Active</span>'
                    : '<span class="ui-status-chip ui-status-chip--neutral">Inactive</span>')
                ->addColumn('mfa_badge', fn ($user) => $user->hasTwoFactorAuthentication()
                    ? '<span class="ui-status-chip ui-status-chip--success">Enabled</span>'
                    : '<span class="ui-status-chip ui-status-chip--neutral">Not enabled</span>')
                ->addColumn('created_date', fn ($user) => $user->created_at->format('d M Y'))
                ->addColumn('action', function ($user) {
                    $html = '<div class="d-inline-flex align-items-center gap-1">'
                        .'<a href="'.route('admin.users.edit', $user).'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="Edit '.e($user->name).'">Edit</a>';
                    if ($user->id !== auth()->id()) {
                        $html .= '<div class="dropdown">'
                            .'<button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for '.e($user->name).'">More</button>'
                            .'<ul class="dropdown-menu dropdown-menu-end">'
                            .'<li><form action="'.route('admin.users.destroy', $user).'" method="POST" class="delete-form">'.csrf_field().method_field('DELETE').'<button type="button" class="dropdown-item text-danger btn-delete">Delete user</button></form></li>'
                            .'</ul></div>';
                    }

                    return $html.'</div>';
                })
                ->rawColumns(['name_display', 'role_badge', 'status_badge', 'mfa_badge', 'action'])
                ->make(true);
        }

        return view('admin.users.index');
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'role' => 'required|in:admin,purchasing,supplier,qc',
            'is_active' => 'boolean',

            // Supplier specific fields
            'company_name' => 'required_if:role,supplier|nullable|string|max:255',
            'address' => 'required_if:role,supplier|nullable|string',
            'phone' => 'required_if:role,supplier|nullable|string|max:50',
            'npwp' => 'required_if:role,supplier|nullable|string|max:50',
            'category' => 'required_if:role,supplier|nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $request->name,
                'email' => Str::lower(trim($request->email)),
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'is_active' => $request->has('is_active') ? true : false,
            ]);

            if ($request->role === 'supplier') {
                Supplier::create([
                    'user_id' => $user->id,
                    'company_name' => $request->company_name,
                    'address' => $request->address,
                    'phone' => $request->phone,
                    'npwp' => $request->npwp,
                    'category' => $request->category,
                ]);
            }

            DB::commit();

            return redirect()->route('admin.users.index')->with('success', 'User successfully added.');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', "An error occurred: {$e->getMessage()}");
        }
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $user->load('supplier');

        return view('admin.users.edit', compact('user'));
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            'role' => 'required|in:admin,purchasing,supplier,qc',
            'is_active' => 'boolean',

            // Supplier specific fields
            'company_name' => 'required_if:role,supplier|nullable|string|max:255',
            'address' => 'required_if:role,supplier|nullable|string',
            'phone' => 'required_if:role,supplier|nullable|string|max:50',
            'npwp' => 'required_if:role,supplier|nullable|string|max:50',
            'category' => 'required_if:role,supplier|nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            $oldRole = $user->role;
            $oldActive = (bool) $user->is_active;
            $passwordChanged = $request->filled('password');
            $data = [
                'name' => $request->name,
                'email' => Str::lower(trim($request->email)),
                'role' => $request->role,
                'is_active' => $request->has('is_active') ? true : false,
            ];

            if ($passwordChanged) {
                $data['password'] = Hash::make($request->password);
            }

            $securityChanged = $passwordChanged
                || $oldRole !== $request->role
                || $oldActive !== $request->has('is_active');

            if ($securityChanged) {
                $data['auth_session_version'] = ((int) $user->auth_session_version) + 1;
                $data['remember_token'] = Str::random(60);
            }

            $user->forceFill($data)->save();

            if ($request->role === 'supplier') {
                Supplier::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => $request->company_name,
                        'address' => $request->address,
                        'phone' => $request->phone,
                        'npwp' => $request->npwp,
                        'category' => $request->category,
                    ]
                );
            } else {
                // If role changed from supplier to something else, we might want to delete the supplier record,
                // but for safety, we can just leave it or soft delete if applicable.
                // We will delete it to keep data clean.
                if ($user->supplier) {
                    $user->supplier()->delete();
                }
            }

            DB::commit();

            if ($oldActive && ! $user->is_active) {
                event(new AuthSecurityEvent('account_deactivated', $user, metadata: [
                    'actor_user_id' => auth()->id(),
                ]));
            }

            if ($oldRole !== $user->role) {
                event(new AuthSecurityEvent('role_changed', $user, metadata: [
                    'actor_user_id' => auth()->id(),
                    'old_role' => $oldRole,
                    'new_role' => $user->role,
                ]));
            }

            if ($passwordChanged) {
                event(new AuthSecurityEvent('password_changed', $user, metadata: [
                    'actor_user_id' => auth()->id(),
                    'reason' => 'admin_update',
                ]));
            }

            return redirect()->route('admin.users.index')->with('success', 'User successfully updated.');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', "An error occurred: {$e->getMessage()}");
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        try {
            DB::beginTransaction();
            if ($user->supplier) {
                $user->supplier()->delete();
            }
            $user->delete();
            DB::commit();

            return redirect()->route('admin.users.index')->with('success', 'User successfully deleted.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Failed to delete user. Make sure there is no tightly related data.');
        }
    }
}
