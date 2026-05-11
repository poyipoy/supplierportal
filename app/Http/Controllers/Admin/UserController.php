<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $users = User::with('supplier')->orderBy('created_at', 'desc')->get();
        return view('admin.users.index', compact('users'));
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

            return redirect()->route('admin.users.index')->with('success', "User berhasil ditambahkan.");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', "Terjadi kesalahan: {$e->getMessage()}");
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

            return redirect()->route('admin.users.index')->with('success', "Data user berhasil diperbarui.");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', "Terjadi kesalahan: {$e->getMessage()}");
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', "Anda tidak dapat menghapus akun Anda sendiri.");
        }

        try {
            DB::beginTransaction();
            if ($user->supplier) {
                $user->supplier()->delete();
            }
            $user->delete();
            DB::commit();
            
            return redirect()->route('admin.users.index')->with('success', "User berhasil dihapus.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', "Gagal menghapus user, pastikan tidak ada data yang terkait erat.");
        }
    }
}
