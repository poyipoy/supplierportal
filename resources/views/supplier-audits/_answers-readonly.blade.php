{{--
    Jawaban Supplier Audit read-only (Purchasing & supplier) dengan filter cepat dan bagian yang bisa dilipat.
    Input: $sections dari SupplierAudit::answerSections(). Tanpa JS semua bagian tetap terbuka dan terbaca.
--}}
@php
    $rowMatches = static fn ($answer) => [
        'no' => $answer->answer === \App\Models\SupplierAuditAnswer::ANSWER_NO,
        'low' => $answer->answer === \App\Models\SupplierAuditAnswer::ANSWER_YES && $answer->score !== null && $answer->score <= 2,
        'empty' => ! $answer->isComplete(),
    ];
    $filterCounts = ['all' => 0, 'no' => 0, 'low' => 0, 'empty' => 0];
    $sectionFilterCounts = [];
    foreach ($sections as $section) {
        $counts = ['all' => $section['answers']->count(), 'no' => 0, 'low' => 0, 'empty' => 0];
        foreach ($section['answers'] as $answer) {
            foreach ($rowMatches($answer) as $key => $match) {
                $counts[$key] += $match ? 1 : 0;
            }
        }
        $sectionFilterCounts[$section['code']] = $counts;
        foreach ($counts as $key => $value) {
            $filterCounts[$key] += $value;
        }
    }
    $filterLabels = [
        'all' => __('supplier_audit.answer_filters.all'),
        'no' => __('supplier_audit.answer_filters.no'),
        'low' => __('supplier_audit.answer_filters.low'),
        'empty' => __('supplier_audit.answer_filters.empty'),
    ];
    $lastParent = null;
@endphp
<div class="tw-grid tw-gap-3" x-data="{ filter: 'all', sectionCounts: @js($sectionFilterCounts), counts: @js($filterCounts) }" data-supplier-audit-answers>
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2">
        <div class="tw-flex tw-flex-wrap tw-gap-1" role="group" aria-label="{{ __('supplier_audit.answer_filters.label') }}">
            @foreach($filterLabels as $key => $label)
                <button type="button" x-on:click="filter = '{{ $key }}'" :aria-pressed="filter === '{{ $key }}' ? 'true' : 'false'" aria-pressed="{{ $key === 'all' ? 'true' : 'false' }}"
                    class="ui-focus-ring tw-inline-flex tw-min-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-px-2.5 tw-text-ui-xs tw-font-medium"
                    style="transition-property: background-color, border-color, color; transition-duration: 150ms;"
                    :class="filter === '{{ $key }}' ? 'tw-border-primary tw-bg-primary-container tw-text-on-surface' : 'tw-border-outline-variant tw-text-on-surface-variant hover:tw-bg-surface-container'"
                    data-answer-filter="{{ $key }}">
                    <span>{{ $label }}</span>
                    <span class="tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">{{ $filterCounts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <div class="tw-flex tw-gap-1">
            <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$root.querySelectorAll('details[data-answer-section]').forEach((d) => d.open = true)">{{ __('supplier_audit.answer_filters.expand_all') }}</x-ui.button>
            <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$root.querySelectorAll('details[data-answer-section]').forEach((d) => d.open = false)">{{ __('supplier_audit.answer_filters.collapse_all') }}</x-ui.button>
        </div>
    </div>

    <p class="tw-m-0 tw-rounded-ui-sm tw-border tw-border-dashed tw-border-outline-variant tw-p-4 tw-text-center tw-text-ui-sm tw-text-on-surface-variant" x-show="counts[filter] === 0" x-cloak role="status">
        {{ __('supplier_audit.answer_filters.none_match') }}
    </p>

    @foreach($sections as $section)
        @if($section['parent_code'] !== null && $section['parent_code'] !== $lastParent)
            @php
                $siblingCodes = collect($sections)->where('parent_code', $section['parent_code'])->pluck('code')->values();
                $lastParent = $section['parent_code'];
            @endphp
            <h3 class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" style="text-wrap: balance;"
                x-show="filter === 'all' || @js($siblingCodes).some((code) => sectionCounts[code][filter] > 0)">
                {{ $section['parent_code'] }}. {{ $section['parent_title'] }}
            </h3>
        @elseif($section['parent_code'] === null)
            @php $lastParent = $section['code']; @endphp
        @endif

        <details open data-answer-section class="tw-group tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant" x-show="sectionCounts['{{ $section['code'] }}'][filter] > 0">
            <summary class="ui-focus-ring tw-flex tw-cursor-pointer tw-list-none tw-flex-wrap tw-items-center tw-justify-between tw-gap-2 tw-bg-surface-container tw-px-4 tw-py-2.5">
                <span class="tw-flex tw-items-center tw-gap-2">
                    <x-ui.icon name="chevron-right" size="sm" class="tw-shrink-0 tw-text-on-surface-variant tw-transition-transform group-open:tw-rotate-90" />
                    <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface" style="text-wrap: balance;">{{ $section['code'] }}. {{ $section['title'] }}</span>
                </span>
                <span class="tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">
                    {{ __('supplier_audit.labels.yes_count') }} {{ $section['yes'] }} · {{ __('supplier_audit.labels.no_count') }} {{ $section['no'] }} · {{ __('supplier_audit.labels.empty_count') }} {{ $section['empty'] }}
                </span>
            </summary>
            <div class="tw-overflow-x-auto">
                <table class="table align-top tw-m-0 tw-text-ui-sm">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="tw-w-12 tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.number') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.criterion') }}</th>
                            <th scope="col" class="tw-w-28 tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.answer') }}</th>
                            <th scope="col" class="tw-w-20 tw-text-ui-xs tw-font-semibold text-end">{{ __('supplier_audit.fields.score') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($section['answers'] as $answer)
                            @php $matches = $rowMatches($answer); @endphp
                            <tr x-show="filter === 'all' || @js($matches)[filter]" data-answer="{{ $answer->answer ?? 'EMPTY' }}" data-score="{{ $answer->score }}">
                                <td style="font-variant-numeric: tabular-nums;">{{ $answer->criterion_number_snapshot }}</td>
                                <td style="text-wrap: pretty;">{{ $answer->criterion_text_snapshot }}</td>
                                <td>
                                    @if($answer->answer === \App\Models\SupplierAuditAnswer::ANSWER_YES)
                                        <x-ui.status-chip tone="success" size="sm" icon="check">{{ __('supplier_audit.fields.yes') }}</x-ui.status-chip>
                                    @elseif($answer->answer === \App\Models\SupplierAuditAnswer::ANSWER_NO)
                                        <x-ui.status-chip tone="neutral" size="sm" icon="x">{{ __('supplier_audit.fields.no') }}</x-ui.status-chip>
                                    @else
                                        <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('supplier_audit.labels.empty_answer') }}</span>
                                    @endif
                                </td>
                                <td class="text-end tw-font-semibold {{ $matches['low'] ? 'tw-text-error' : '' }}" style="font-variant-numeric: tabular-nums;">{{ $answer->score ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endforeach
</div>
