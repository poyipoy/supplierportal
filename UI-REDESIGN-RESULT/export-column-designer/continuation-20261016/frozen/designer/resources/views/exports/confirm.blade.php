@extends('layouts.app')

@section('title', __('exports.confirmation.title').' - ADASI Portal')
@section('page-title', __('exports.confirmation.title'))

@section('content')
<x-ui.page-header :title="__('exports.confirmation.title')" :description="$label" />
<section class="tw-mt-4 tw-border tw-border-outline tw-bg-surface tw-p-5">
    <p>{{ __('exports.confirmation.help') }}</p>
    <form method="POST" action="{{ $action }}" data-async-export
          data-export-source-singular="{{ __('js.export.record') }}"
          data-export-source-plural="{{ __('js.export.records') }}"
          data-export-row-label="{{ __('js.export.rows') }}"
          data-export-row-explanation="{{ __('exports.confirmation.rows_help') }}">
        @csrf
        @foreach($fields as $field)
            <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
        @endforeach
        <x-ui.button type="submit" variant="primary">{{ __('exports.confirmation.submit') }}</x-ui.button>
        <x-ui.button :href="route('exports.index')" variant="outline">{{ __('exports.confirmation.history') }}</x-ui.button>
    </form>
</section>
@endsection
