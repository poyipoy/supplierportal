@extends(($fixturePublic ?? false) ? 'density-qa-layout' : 'layouts.app')
@section('title', 'Density component measurement fixture')
@section('content')
<div class="tw-grid tw-gap-4" id="density-fixture">
    <x-ui.page-header title="Density measurement fixture" description="Synthetic labels and shared production components only." />
    <div class="tw-flex tw-flex-wrap tw-gap-2" aria-label="Temporary fixture density">
        @foreach(config('user_preferences.densities') as $level)
            <x-ui.button type="button" size="sm" variant="outline" :id="'fixture-'.$level" data-fixture-density="{{ $level }}">{{ ucfirst($level) }}</x-ui.button>
        @endforeach
    </div>
    <x-ui.metric-card id="fixture-metric" label="Items" value="24" icon="package" />
    <x-ui.card id="fixture-card" title="Information panel"><p class="tw-m-0">Representative non-sensitive information.</p></x-ui.card>
    <x-ui.toolbar id="fixture-toolbar">
        <x-slot:search><label for="fixture-search">Search</label><input id="fixture-search" class="form-control form-control-sm" value="Example" readonly></x-slot:search>
        <x-slot:actions><x-ui.button size="sm" variant="outline">Filter</x-ui.button></x-slot:actions>
    </x-ui.toolbar>
    <x-ui.form-section id="fixture-form" title="Form section"><x-ui.input name="fixture_field" label="Reference" value="Example" readonly /></x-ui.form-section>
    @foreach(['text', 'action'] as $kind)
        <x-ui.data-table :id="'fixture-'.$kind" :title="ucfirst($kind).' rows'">
            <table class="table" id="fixture-{{ $kind }}-table"><thead><tr><th>Item</th><th>State</th></tr></thead><tbody>
            @for($i = 1; $i <= 25; $i++)
                <tr><td>Item {{ str_pad((string) $i, 2, '0', STR_PAD_LEFT) }}</td><td>@if($kind === 'action')<x-ui.button type="button" size="sm" variant="outline">View</x-ui.button>@else Available @endif</td></tr>
            @endfor
            </tbody></table>
        </x-ui.data-table>
    @endforeach
    <x-ui.data-table id="fixture-explicit" density="compact" title="Explicit compact compatibility"><table class="table"><tbody><tr><td>Example</td><td>Available</td></tr></tbody></table></x-ui.data-table>
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-fixture-density]').forEach(button => button.addEventListener('click', () => window.AdasiPreferences.previewDensity(button.dataset.fixtureDensity)));
</script>
@endpush
