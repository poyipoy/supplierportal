<x-app-layout>
    <x-slot name="header">
        <h2 class="tw:font-semibold tw:text-xl tw:text-on-surface tw:leading-tight">
            {{ __('common.labels_review.dashboard') }}
        </h2>
    </x-slot>

    <div class="tw:py-12">
        <div class="tw:max-w-7xl tw:mx-auto tw:sm:px-6 tw:lg:px-8">
            <div class="tw:bg-surface tw:overflow-hidden tw:shadow-ui-1 tw:sm:rounded-lg">
                <div class="tw:p-6 tw:text-on-surface">
                    You're logged in!
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
