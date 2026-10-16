@extends('layouts.app')
@section('title', __('ga.labels.master_page'))
@section('page-title', __('navigation.employee_master'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.labels.master')"
        :description="__('ga.review.employee_help')"
        :eyebrow="__('ga.dashboard.operational')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.review.claim_register') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="primary" size="sm" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('ga.actions.add_employee') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw-p-4 tw-rounded-lg tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-sm tw-flex tw-items-center tw-gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    {{-- Filter Card --}}
    <x-ui.card>
        <form method="GET" action="{{ route('ga.employees.index') }}" class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-gap-3">
            <div class="tw-flex-1">
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="{{ __('ga.filters.employee_search') }}"
                    value="{{ request('search') }}"
                >
            </div>
            <div class="tw-w-40">
                <label for="employee-status-filter" class="visually-hidden">{{ __('ga.labels.employee_status') }}</label>
                <select id="employee-status-filter" name="status" class="form-select form-select-sm">
                    <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                    <option value="active" @selected(request('status') === 'active')>{{ __('local_invoice.labels.active') }}</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>{{ __('common.labels_review.inactive') }}</option>
                </select>
            </div>
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" variant="primary" size="sm">
                    <x-ui.icon name="search" size="sm" />
                    <span>{{ __('local_invoice.actions.search') }}</span>
                </x-ui.button>
                @if(request()->hasAny(['search', 'status']))
                    <x-ui.button :href="route('ga.employees.index')" variant="ghost" size="sm">
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Data Table --}}
    <x-ui.data-table
        :title="__('ga.labels.employee_register')"
        :description="__('ga.labels.bank_help')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('ga.labels.employee_name') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.department') }}</th>
                        <th scope="col">{{ __('common.labels_review.bank') }}</th>
                        <th scope="col">{{ __('ga.detail.bank_number') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.account_name') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="text-end tw-whitespace-nowrap">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                        <tr>
                            <td><strong class="tw-text-ui-sm tw-text-on-surface">{{ $emp->name }}</strong></td>
                            <td><span class="tw-font-medium">{{ $emp->department }}</span></td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-bold {{ $emp->isBca() ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface' }}">
                                    {{ $emp->bank_name }}
                                </span>
                            </td>
                            <td><span class="tw-font-mono tw-text-ui-sm">{{ $emp->account_number }}</span></td>
                            <td>{{ $emp->account_holder_name }}</td>
                            <td>
                                <x-ui.status-chip :tone="$emp->is_active ? 'success' : 'neutral'">
                                    {{ __($emp->is_active ? 'ga.employee_status.active' : 'ga.employee_status.inactive') }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end tw-whitespace-nowrap">
                                <div class="tw-inline-flex tw-items-center tw-justify-end tw-gap-1.5">
                                    <button
                                        type="button"
                                        class="ui-data-action ui-data-action--primary ui-motion ui-focus-ring tw-gap-1.5 active:tw-scale-[0.98]"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editEmployeeModal-{{ $emp->id }}"
                                        aria-label="{{ __('ga.review.edit_employee', ['name' => $emp->name]) }}"
                                    >
                                        <x-ui.icon name="pencil" size="sm" class="tw-shrink-0" />
                                        <span>{{ __('common.final_copy.edit') }}</span>
                                    </button>
                                    <form
                                        method="POST"
                                        action="{{ route('ga.employees.toggle-status', $emp) }}"
                                        class="tw-inline"
                                        onsubmit="event.preventDefault(); {{ $emp->is_active ? 'window.AdasiAlert.confirmDanger' : 'window.AdasiAlert.confirm' }}({
                                            title: @js(__($emp->is_active ? 'ga.closure.deactivate_title' : 'ga.closure.activate_title')),
                                            text: @js(__($emp->is_active ? 'ga.closure.deactivate_help' : 'ga.closure.activate_help', ['name' => $emp->name])),
                                            confirmText: @js(__($emp->is_active ? 'ga.closure.deactivate_confirm' : 'ga.closure.activate_confirm')),
                                            cancelText: @js(__('common.actions.cancel'))
                                        }).then(r => { if (r.isConfirmed) this.submit(); });"
                                    >
                                        @csrf
                                        <button
                                            type="submit"
                                            class="ui-data-action {{ $emp->is_active ? 'ui-data-action--danger' : 'ui-data-action--success' }} ui-motion ui-focus-ring tw-gap-1.5 active:tw-scale-[0.98]"
                                            aria-label="{{ __($emp->is_active ? 'ga.review.deactivate_employee' : 'ga.review.activate_employee', ['name' => $emp->name]) }}"
                                        >
                                            <x-ui.icon name="{{ $emp->is_active ? 'user-x' : 'user-check' }}" size="sm" class="tw-shrink-0" />
                                            <span>{{ __($emp->is_active ? 'ga.employee_action.deactivate' : 'ga.employee_action.activate') }}</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        {{-- Edit Modal --}}
                        <div class="modal fade" id="editEmployeeModal-{{ $emp->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form method="POST" action="{{ route('ga.employees.update', $emp) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('ga.actions.edit_employee') }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                        </div>
                                        <div class="modal-body tw-space-y-3 tw-text-ui-xs">
                                            <div>
                                                <label class="form-label tw-font-semibold">{{ __('common.fields.full_name') }}</label>
                                                <input type="text" name="name" class="form-control form-control-sm" value="{{ $emp->name }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">{{ __('local_invoice.labels.department') }}</label>
                                                <input type="text" name="department" class="form-control form-control-sm" value="{{ $emp->department }}" required>
                                            </div>
                                            <div>
                                                <x-ui.searchable-select
                                                    name="bank_name"
                                                    id="bank_name_edit_{{ $emp->id }}"
                                                    :label="__('ga.review.bank')"
                                                    :placeholder="__('ga.review.choose_bank')"
                                                    :search-placeholder="__('ga.review.bank_search')"
                                                    :options="\App\Support\BankList::options(old('bank_name', $emp->bank_name))"
                                                    :value="old('bank_name', $emp->bank_name)"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">{{ __('ga.detail.bank_number') }}</label>
                                                <input type="text" name="account_number" class="form-control form-control-sm" value="{{ $emp->account_number }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">{{ __('ga.review.account_holder') }}</label>
                                                <input type="text" name="account_holder_name" class="form-control form-control-sm" value="{{ $emp->account_holder_name }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">{{ __('ga.labels.employee_status') }}</label>
                                                <select name="is_active" class="form-select form-select-sm">
                                                    <option value="1" @selected($emp->is_active)>{{ __('ga.labels.active_claims') }}</option>
                                                    <option value="0" @selected(! $emp->is_active)>{{ __('common.labels_review.inactive') }}</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer tw-gap-2">
                                            <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                                                <span>{{ __('local_invoice.actions.cancel') }}</span>
                                            </x-ui.button>
                                            <x-ui.button type="submit" variant="primary" size="sm">
                                                <span>{{ __('local_invoice.actions.save_changes') }}</span>
                                            </x-ui.button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant">
                                {{ __('ga.empty.employees') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($employees->hasPages())
            <x-slot:pagination>
                {{ $employees->onEachSide(1)->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>

{{-- Add Employee Modal --}}
<div class="modal fade" id="addEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('ga.employees.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('ga.actions.add_employee_full') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                </div>
                <div class="modal-body tw-space-y-3 tw-text-ui-xs">
                    <div>
                        <label class="form-label tw-font-semibold">{{ __('common.fields.full_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required placeholder="{{ __('ga.review.name_example') }}">
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">{{ __('local_invoice.labels.department') }} <span class="text-danger">*</span></label>
                        <input type="text" name="department" class="form-control form-control-sm" required placeholder="{{ __('ga.review.department_example') }}">
                    </div>
                    <div>
                        <x-ui.searchable-select
                            name="bank_name"
                            id="bank_name_add"
                            :label="__('ga.review.bank')"
                            :placeholder="__('ga.review.choose_bank')"
                            :search-placeholder="__('ga.review.bank_search')"
                            :options="\App\Support\BankList::options(old('bank_name'))"
                            :value="old('bank_name')"
                            required
                        />
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">{{ __('ga.detail.bank_number') }} <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control form-control-sm" required placeholder="{{ __('ga.detail.bank_number') }}">
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">{{ __('ga.review.account_holder') }}<span class="text-danger">*</span></label>
                        <input type="text" name="account_holder_name" class="form-control form-control-sm" required placeholder="{{ __('local_invoice.labels.account_book') }}">
                    </div>
                </div>
                <div class="modal-footer tw-gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                        <span>{{ __('local_invoice.actions.cancel') }}</span>
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="sm">
                        <x-ui.icon name="plus" size="sm" />
                        <span>{{ __('ga.actions.save_employee') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
