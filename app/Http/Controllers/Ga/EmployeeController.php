<?php

namespace App\Http\Controllers\Ga;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $employees = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('ga.employees.index', compact('employees'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'department' => 'required|string|max:100',
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'account_holder_name' => 'required|string|max:150',
        ]);

        Employee::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', "Karyawan [{$validated['name']}] berhasil ditambahkan ke Employee Master.");
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'department' => 'required|string|max:100',
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'account_holder_name' => 'required|string|max:150',
            'is_active' => 'required|boolean',
        ]);

        $employee->update($validated);

        return back()->with('success', "Data karyawan [{$employee->name}] berhasil diperbarui.");
    }

    public function toggleStatus(Employee $employee)
    {
        $employee->update(['is_active' => ! $employee->is_active]);
        $statusStr = $employee->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return back()->with('success', "Status karyawan [{$employee->name}] berhasil {$statusStr}.");
    }
}
