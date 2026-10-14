<?php

namespace App\Http\Controllers\Ga;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($request, $validated): void {
            $employee = Employee::create(array_merge($validated, ['is_active' => true]));
            app(LocalFinanceAuditService::class)->record($employee, 'employee_created', $request->user(), null, $employee->toArray());
        });

        return back()->with('success', __('ga.feedback.employee_added', ['name' => $validated['name']]));
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

        DB::transaction(function () use ($employee, $validated, $request): void {
            $locked = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked->bankChangeActor = $request->user();
            $locked->update($validated);
        });

        return back()->with('success', __('ga.feedback.employee_updated', ['name' => $employee->name]));
    }

    public function toggleStatus(Employee $employee)
    {
        $employee->update(['is_active' => ! $employee->is_active]);

        return back()->with('success', __($employee->is_active ? 'ga.feedback.employee_activated' : 'ga.feedback.employee_deactivated', ['name' => $employee->name]));
    }
}
