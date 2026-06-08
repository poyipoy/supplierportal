<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
                    $html = '<div class="fw-medium">' . e($user->name) . '</div>';
                    if ($user->role === 'supplier' && $user->supplier) {
                        $html .= '<small class="text-muted"><i class="bi bi-building me-1"></i>' . e($user->supplier->company_name) . '</small>';
                    }
                    return $html;
                })
                ->addColumn('role_badge', function ($user) {
                    return match($user->role) {
                        'admin' => '<span class="badge bg-danger text-uppercase">Admin</span>',
                        'purchasing' => '<span class="badge bg-primary text-uppercase">Purchasing</span>',
                        'supplier' => '<span class="badge bg-info text-dark text-uppercase">Supplier</span>',
                        'qc' => '<span class="badge bg-warning text-dark text-uppercase">QC</span>',
                        default => '<span class="badge bg-secondary text-uppercase">' . e($user->role) . '</span>',
                    };
                })
                ->addColumn('status_badge', fn($user) => $user->is_active
                    ? '<span class="badge bg-success bg-opacity-10 text-success border border-success">Active</span>'
                    : '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary">Inactive</span>')
                ->addColumn('created_date', fn($user) => $user->created_at->format('d M Y'))
                ->addColumn('action', function ($user) {
                    $html = '<a href="' . route('admin.users.edit', $user->id) . '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>';
                    if ($user->id !== auth()->id()) {
                        $html .= ' <form action="' . route('admin.users.destroy', $user->id) . '" method="POST" class="d-inline delete-form">' . csrf_field() . method_field('DELETE') . '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" title="Delete"><i class="bi bi-trash"></i></button></form>';
                    }
                    return $html;
                })
                ->rawColumns(['name_display', 'role_badge', 'status_badge', 'action'])
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
            'password' => 'required|string|min:8|confirmed',
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
                'email' => $request->email,
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

            return redirect()->route('admin.users.index')->with('success', "User successfully added.");

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
            'password' => 'nullable|string|min:8|confirmed',
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

            $data = [
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'is_active' => $request->has('is_active') ? true : false,
            ];

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            $user->update($data);

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

            return redirect()->route('admin.users.index')->with('success', "Data user successfully updated.");

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
            return back()->with('error', "You cannot delete your own account.");
        }

        try {
            DB::beginTransaction();
            if ($user->supplier) {
                $user->supplier()->delete();
            }
            $user->delete();
            DB::commit();
            
            return redirect()->route('admin.users.index')->with('success', "User successfully deleted.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', "Failed to delete user. Make sure there is no tightly related data.");
        }
    }
}
