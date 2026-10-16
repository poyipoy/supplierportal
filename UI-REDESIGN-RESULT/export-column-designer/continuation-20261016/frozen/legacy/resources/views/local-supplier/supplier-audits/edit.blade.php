@extends('layouts.app')
@section('title', __('supplier_audit.title').' · '.$audit->period_label)
@section('page-title', __('supplier_audit.title'))

@section('content')
@php
    $initialAnswers = $audit->answers->mapWithKeys(fn ($answer) => [
        (string) $answer->supplier_audit_criterion_id => ['answer' => $answer->answer, 'score' => $answer->score],
    ]);
    $navSections = $steps->map(fn ($step) => [
        'code' => $step['code'],
        'title' => $step['title'],
        'ids' => $step['sections']->flatMap(fn ($section) => $section['answers']->pluck('supplier_audit_criterion_id'))->map(fn ($id) => (string) $id)->values(),
    ])->values();
    $scoreScale = collect(range(1, 5))->mapWithKeys(fn ($score) => [$score => __('supplier_audit.score_scale.'.$score)]);
    $labels = [
        'progress' => __('supplier_audit.labels.progress', ['filled' => ':filled', 'total' => ':total']),
        'sectionPicker' => __('supplier_audit.form.section_picker', ['current' => ':current', 'total' => ':total']),
        'sectionStates' => [
            'done' => __('supplier_audit.form.section_done'),
            'partial' => __('supplier_audit.form.section_partial'),
            'empty' => __('supplier_audit.form.section_empty'),
            'error' => __('supplier_audit.form.section_error'),
        ],
        'idle' => __('supplier_audit.autosave.idle'),
        'saving' => __('supplier_audit.autosave.saving'),
        'saved' => __('supplier_audit.autosave.saved', ['time' => ':time']),
        'failed' => __('supplier_audit.autosave.failed'),
        'expired' => __('supplier_audit.autosave.session_expired'),
        'incompleteOne' => trans_choice('supplier_audit.form.incomplete', 1, ['count' => ':count']),
        'incompleteMany' => trans_choice('supplier_audit.form.incomplete', 2, ['count' => ':count']),
        'confirmTitle' => __('supplier_audit.confirm.submit_title'),
        'confirmText' => __('supplier_audit.form.submit_summary', ['yes' => ':yes', 'no' => ':no']),
        'confirmYes' => __('supplier_audit.confirm.submit_confirm'),
        'cancel' => __('supplier_audit.actions.close'),
    ];
    $config = [
        'answers' => $initialAnswers,
        'sections' => $navSections,
        'labels' => $labels,
        'autosaveUrl' => route('local-supplier.supplier-audits.autosave', $audit),
        'savedLabel' => $audit->status !== \App\Models\SupplierAudit::STATUS_ASSIGNED && $audit->updated_at ? $regionalFormatter->businessTime($audit->updated_at, 'H:i') : '',
    ];
@endphp

<div class="tw-grid tw-gap-5 tw-pb-28" x-data="supplierAuditForm(@js($config))" data-ignore-dirty="true" data-supplier-audit-form>
    <x-ui.page-header :title="__('supplier_audit.title').' · '.$audit->period_label" :description="$audit->template?->title" :eyebrow="__('supplier_audit.eyebrow')">
        <x-slot:status>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip>
            @if($audit->isLate())
                <x-ui.status-chip tone="error" icon="clock">{{ __('supplier_audit.labels.late') }}</x-ui.status-chip>
            @endif
        </x-slot:status>
        <x-slot:meta>
            <span style="font-variant-numeric: tabular-nums;">
                {{ __('supplier_audit.fields.due_date') }}:
                <strong class="tw-text-on-surface">{{ $audit->due_date ? $regionalFormatter->date($audit->due_date) : __('supplier_audit.fields.no_deadline') }}</strong>
                @if($audit->dueRelativeLabel())
                    <span class="{{ $audit->isLate() ? 'tw-font-semibold tw-text-error' : '' }}">({{ $audit->dueRelativeLabel() }})</span>
                @endif
            </span>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.supplier-audits.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_audit.actions.back_to_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoiceBlocked)
        <x-ui.alert tone="warning" :title="__('supplier_audit.invoice_block.banner_title')" data-supplier-audit-invoice-block>{{ __('supplier_audit.invoice_block.edit_notice') }}</x-ui.alert>
    @endif
    @if($audit->status === \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED && $audit->revision_note)
        <x-ui.alert tone="warning" :title="__('supplier_audit.fields.revision_note')">{{ $audit->revision_note }}</x-ui.alert>
    @endif

    {{-- HP/tablet: pemilih bagian sticky menggantikan navigasi kiri (U5). --}}
    <div class="tw-sticky tw-top-14 tw-z-10 tw-flex tw-items-center tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-py-1.5 lg:tw-hidden">
        <button type="button" class="ui-focus-ring tw-flex tw-min-h-11 tw-min-w-0 tw-flex-1 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-text-start tw-text-ui-sm tw-font-medium tw-text-on-surface"
            x-on:click="$dispatch('open-ui-dialog', 'supplier-audit-sections')" aria-haspopup="dialog">
            <span class="tw-min-w-0 tw-flex-1 tw-truncate" x-text="sectionPickerLabel"></span>
            <x-ui.icon name="chevron-down" size="sm" class="tw-shrink-0" />
        </button>
        <span class="tw-shrink-0 tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" x-text="`${filled}/${total}`">{{ $progress['filled'] }}/{{ $progress['total'] }}</span>
    </div>

    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-[17rem_minmax(0,1fr)]">
        {{-- Navigasi bagian (desktop): panel setinggi ruang antara navbar dan action bar; daftar bergulir sendiri. --}}
        <aside class="tw-hidden tw-min-w-0 lg:tw-block">
            <nav class="tw-sticky tw-top-20 tw-flex tw-flex-col tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface"
                style="max-height: calc(100dvh - 11rem);" aria-labelledby="audit-nav-title" data-section-nav>
                <div class="tw-grid tw-shrink-0 tw-gap-2 tw-border-b tw-border-outline-variant tw-px-3 tw-py-3">
                    <div class="tw-flex tw-items-baseline tw-justify-between tw-gap-2">
                        <h2 id="audit-nav-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.form.sections') }}</h2>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" x-text="`${filled}/${total}`">{{ $progress['filled'] }}/{{ $progress['total'] }}</span>
                    </div>
                    <div class="tw-h-1.5 tw-overflow-hidden tw-rounded-ui-full tw-bg-surface-container" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['filled'] }}" :aria-valuenow="filled" aria-label="{{ __('supplier_audit.fields.progress') }}">
                        <div class="tw-h-full tw-bg-primary" style="width: {{ $progress['total'] ? round($progress['filled'] / $progress['total'] * 100) : 0 }}%; transition: width 200ms ease-out;" :style="`width: ${percent}%; transition: width 200ms ease-out;`"></div>
                    </div>
                </div>

                <ol class="ui-scrollable-list tw-m-0 tw-flex tw-min-h-0 tw-flex-1 tw-list-none tw-flex-col tw-gap-px tw-overflow-y-auto tw-overscroll-contain tw-p-1.5" data-section-nav-list>
                    @foreach($navSections as $section)
                        <li class="tw-min-w-0">
                            <a href="#bagian-{{ str_replace('.', '-', $section['code']) }}" x-on:click.prevent="goToSection(@js($section['code']))" data-nav-code="{{ $section['code'] }}"
                                title="{{ $section['code'] }}. {{ $section['title'] }}"
                                class="ui-focus-ring tw-flex tw-min-h-9 tw-min-w-0 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-2 tw-py-1.5 tw-text-ui-xs tw-no-underline"
                                style="transition-property: background-color, color; transition-duration: 150ms;"
                                :class="activeSection === @js($section['code']) ? 'tw-bg-primary-container tw-font-semibold tw-text-on-primary-container' : 'tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface'"
                                :aria-current="activeSection === @js($section['code']) ? 'location' : null">
                                <span class="tw-inline-flex tw-h-[1.125rem] tw-w-[1.125rem] tw-shrink-0 tw-items-center tw-justify-center tw-rounded-full tw-border"
                                    :class="{
                                        'tw-border-success tw-bg-success tw-text-success-foreground': sectionState(sections[{{ $loop->index }}]) === 'done',
                                        'tw-border-error tw-bg-error-container tw-text-error': sectionState(sections[{{ $loop->index }}]) === 'error',
                                        'tw-border-primary tw-text-primary': sectionState(sections[{{ $loop->index }}]) === 'partial',
                                        'tw-border-outline-strong': sectionState(sections[{{ $loop->index }}]) === 'empty',
                                    }" aria-hidden="true">
                                    <span x-show="sectionState(sections[{{ $loop->index }}]) === 'done'" x-cloak class="tw-inline-flex"><x-ui.icon name="check" size="xs" /></span>
                                    <span x-show="sectionState(sections[{{ $loop->index }}]) === 'error'" x-cloak class="tw-text-[11px] tw-font-bold tw-leading-none">!</span>
                                    <span x-show="sectionState(sections[{{ $loop->index }}]) === 'partial'" x-cloak class="tw-h-1.5 tw-w-1.5 tw-rounded-full tw-bg-primary"></span>
                                </span>
                                <span class="tw-w-5 tw-shrink-0 tw-text-end" style="font-variant-numeric: tabular-nums;">{{ $section['code'] }}</span>
                                <span class="tw-min-w-0 tw-flex-1 tw-truncate">{{ $section['title'] }}</span>
                                <span class="tw-shrink-0 tw-text-[11px]" style="font-variant-numeric: tabular-nums;"
                                    :class="sectionState(sections[{{ $loop->index }}]) === 'done' ? 'tw-text-success' : ''"
                                    x-text="`${sectionFilled(sections[{{ $loop->index }}])}/{{ count($section['ids']) }}`">0/{{ count($section['ids']) }}</span>
                                <span class="tw-sr-only" x-text="sectionStateLabel(sections[{{ $loop->index }}])"></span>
                            </a>
                        </li>
                    @endforeach
                </ol>

                <div class="tw-shrink-0 tw-border-t tw-border-outline-variant tw-p-2">
                    <x-ui.button type="button" variant="outline" size="sm" class="tw-w-full tw-justify-center" x-on:click="jumpToUnanswered()" x-bind:disabled="filled === total">
                        <x-ui.icon name="arrow-down-to-line" size="sm" />
                        <span x-text="filled === total ? @js(__('supplier_audit.form.all_complete')) : @js(__('supplier_audit.form.jump_unanswered'))">{{ __('supplier_audit.form.jump_unanswered') }}</span>
                    </x-ui.button>
                </div>
            </nav>
        </aside>

        <div class="tw-grid tw-min-w-0 tw-content-start tw-gap-5">
            {{-- Panduan Score (U4) & pintasan (U9) --}}
            <div class="tw-grid tw-items-start tw-gap-2 md:tw-grid-cols-2">
                <details open class="tw-group tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface" data-score-guide
                    x-data x-init="$el.open = localStorage.getItem('supplierAuditScoreGuide') !== 'closed'"
                    x-on:toggle="localStorage.setItem('supplierAuditScoreGuide', $el.open ? 'open' : 'closed')">
                    <summary class="ui-focus-ring tw-flex tw-min-h-10 tw-cursor-pointer tw-list-none tw-items-center tw-gap-2 tw-px-3 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                        <x-ui.icon name="chevron-right" size="sm" class="tw-transition-transform group-open:tw-rotate-90" />
                        {{ __('supplier_audit.score_scale.title') }}
                    </summary>
                    <div class="tw-px-3 tw-pb-3">
                        <p class="tw-m-0 tw-mb-2 tw-text-ui-xs tw-text-on-surface-variant">{{ __('supplier_audit.score_scale.intro') }}</p>
                        <dl class="tw-m-0 tw-grid tw-grid-cols-[1.5rem_1fr] tw-gap-x-2 tw-gap-y-1 tw-text-ui-xs">
                            @foreach($scoreScale as $score => $meaning)
                                <dt class="tw-font-semibold tw-text-on-surface" style="font-variant-numeric: tabular-nums;">{{ $score }}</dt>
                                <dd class="tw-m-0 tw-text-on-surface-variant">{{ $meaning }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </details>
                <details class="tw-group tw-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface md:tw-block">
                    <summary class="ui-focus-ring tw-flex tw-min-h-10 tw-cursor-pointer tw-list-none tw-items-center tw-gap-2 tw-px-3 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                        <x-ui.icon name="chevron-right" size="sm" class="tw-transition-transform group-open:tw-rotate-90" />
                        {{ __('supplier_audit.form.shortcuts_title') }}
                    </summary>
                    <p class="tw-m-0 tw-px-3 tw-pb-3 tw-text-ui-xs tw-text-on-surface-variant">{{ __('supplier_audit.form.shortcuts') }}</p>
                </details>
            </div>

            <form method="POST" action="{{ route('local-supplier.supplier-audits.update', $audit) }}" data-async-submit x-ref="form" novalidate
                x-on:adasi:form-errors="onFormErrors($event)" x-on:adasi:form-settled="submitting = false" class="tw-grid tw-min-w-0 tw-gap-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="action" value="submit">

                @foreach($steps as $step)
                    <section id="bagian-{{ str_replace('.', '-', $step['code']) }}" data-section-code="{{ $step['code'] }}" class="tw-grid tw-scroll-mt-28 tw-gap-3"
                        aria-labelledby="audit-heading-{{ str_replace('.', '-', $step['code']) }}">
                        <h2 id="audit-heading-{{ str_replace('.', '-', $step['code']) }}" tabindex="-1" class="tw-m-0 tw-text-ui-lg tw-font-semibold tw-text-on-surface focus:tw-outline-none" style="text-wrap: balance;">
                            {{ $step['code'] }}. {{ $step['title'] }}
                        </h2>

                        @foreach($step['sections'] as $section)
                            <div class="tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface">
                                @if($section['parent_code'] !== null)
                                    <h3 class="tw-m-0 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                                        {{ $section['code'] }}. {{ $section['title'] }}
                                    </h3>
                                @endif
                                <ol class="tw-m-0 tw-list-none tw-divide-y tw-divide-outline-variant tw-p-0">
                                    @foreach($section['answers'] as $answer)
                                        @php
                                            $cid = (string) $answer->supplier_audit_criterion_id;
                                            $ref = __('supplier_audit.fields.criterion_ref', ['section' => $section['code'], 'number' => $answer->criterion_number_snapshot]);
                                        @endphp
                                        {{-- Garis kiri hanya menandai baris yang sedang fokus (state nyata). --}}
                                        <li tabindex="0" data-criterion-row="{{ $cid }}" x-on:keydown="onRowKeydown($event, '{{ $cid }}')"
                                            aria-labelledby="crit-{{ $cid }}-text"
                                            class="tw-grid tw-scroll-mt-28 tw-gap-3 tw-border-s-2 tw-border-transparent tw-px-4 tw-py-3 tw-outline-none focus-within:tw-border-primary focus-within:tw-bg-surface-container lg:tw-grid-cols-[minmax(0,1fr)_auto] lg:tw-items-start"
                                            style="transition-property: background-color, border-color; transition-duration: 150ms;"
                                            :class="rowFlagged('{{ $cid }}') ? 'tw-bg-error-container' : ''">
                                            <p id="crit-{{ $cid }}-text" class="tw-m-0 tw-flex tw-gap-2 tw-text-ui-sm tw-text-on-surface" style="text-wrap: pretty;">
                                                <span class="tw-w-6 tw-shrink-0 tw-font-semibold" style="font-variant-numeric: tabular-nums;">{{ $answer->criterion_number_snapshot }}.</span>
                                                <span>{{ $answer->criterion_text_snapshot }}</span>
                                            </p>

                                            <div class="tw-grid tw-gap-2 sm:tw-flex sm:tw-flex-wrap sm:tw-items-center lg:tw-justify-end">
                                                {{-- Nilai kosong dikirim lebih dulu; radio terpilih menimpanya. --}}
                                                <input type="hidden" name="answers[{{ $cid }}][answer]" value="">
                                                <div class="btn-group tw-w-full sm:tw-w-auto" role="radiogroup" aria-labelledby="crit-{{ $cid }}-text">
                                                    <input type="radio" class="btn-check" name="answers[{{ $cid }}][answer]" id="a{{ $cid }}-yes" value="YES" autocomplete="off"
                                                        @checked($answer->answer === 'YES') :checked="answers['{{ $cid }}'].answer === 'YES'" x-on:change="setAnswer('{{ $cid }}', 'YES')">
                                                    <label class="btn btn-outline-success tw-min-h-11 tw-flex-1 tw-px-4 sm:tw-min-h-9 sm:tw-flex-none" for="a{{ $cid }}-yes">{{ __('supplier_audit.fields.yes') }}</label>
                                                    <input type="radio" class="btn-check" name="answers[{{ $cid }}][answer]" id="a{{ $cid }}-no" value="NO" autocomplete="off"
                                                        @checked($answer->answer === 'NO') :checked="answers['{{ $cid }}'].answer === 'NO'" x-on:change="setAnswer('{{ $cid }}', 'NO')">
                                                    <label class="btn btn-outline-secondary tw-min-h-11 tw-flex-1 tw-px-4 sm:tw-min-h-9 sm:tw-flex-none" for="a{{ $cid }}-no">{{ __('supplier_audit.fields.no') }}</label>
                                                </div>

                                                <input type="hidden" name="answers[{{ $cid }}][score]" value="">
                                                {{-- U3: Score hanya muncul setelah Ya. --}}
                                                <div class="btn-group tw-w-full sm:tw-w-auto" role="radiogroup" aria-label="{{ __('supplier_audit.fields.score') }} — {{ $ref }}"
                                                    x-show="answers['{{ $cid }}'].answer === 'YES'"
                                                    x-transition:enter="tw-transition tw-duration-150 tw-ease-out" x-transition:enter-start="tw-opacity-0" x-transition:enter-end="tw-opacity-100"
                                                    @if($answer->answer !== 'YES') style="display: none;" @endif>
                                                    @foreach($scoreScale as $score => $meaning)
                                                        <input type="radio" class="btn-check" name="answers[{{ $cid }}][score]" id="s{{ $cid }}-{{ $score }}" value="{{ $score }}" autocomplete="off"
                                                            @checked($answer->score === $score) @disabled($answer->answer !== 'YES')
                                                            :checked="Number(answers['{{ $cid }}'].score) === {{ $score }}"
                                                            :disabled="answers['{{ $cid }}'].answer !== 'YES'"
                                                            x-on:change="setScore('{{ $cid }}', {{ $score }})">
                                                        <label class="btn btn-outline-primary tw-min-h-11 tw-flex-1 sm:tw-min-h-9 sm:tw-min-w-9 sm:tw-flex-none" for="s{{ $cid }}-{{ $score }}" title="{{ $score }} — {{ $meaning }}" style="font-variant-numeric: tabular-nums;">
                                                            <span aria-hidden="true">{{ $score }}</span>
                                                            <span class="tw-sr-only">{{ __('supplier_audit.labels.score_option', ['score' => $score]) }}: {{ $meaning }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>

                                            <p id="crit-{{ $cid }}-error" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error lg:tw-col-span-2" role="alert"
                                                x-show="rowFlagged('{{ $cid }}')" x-cloak
                                                x-text="rowError('{{ $cid }}') || (answers['{{ $cid }}'].answer === 'YES' ? @js(__('supplier_audit.errors.score_required', ['criterion' => $ref])) : @js(__('supplier_audit.errors.answer_required', ['criterion' => $ref])))"></p>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </form>
        </div>
    </div>

    <x-ui.action-bar>
        <div class="tw-flex tw-w-full tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
            <div class="tw-flex tw-min-w-0 tw-items-center tw-gap-2 tw-text-ui-xs" role="status" aria-live="polite" data-autosave-status>
                <span class="tw-truncate" :class="saveState === 'failed' || saveState === 'expired' ? 'tw-font-medium tw-text-error' : 'tw-text-on-surface-variant'" x-text="saveStatusText">{{ $config['savedLabel'] ? __('supplier_audit.autosave.saved', ['time' => $config['savedLabel']]) : __('supplier_audit.autosave.idle') }}</span>
                <x-ui.button type="button" variant="ghost" size="sm" x-show="saveState === 'failed'" x-cloak x-on:click="retryNow()">{{ __('supplier_audit.autosave.retry') }}</x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" x-show="saveState === 'expired'" x-cloak x-on:click="window.location.reload()">{{ __('supplier_audit.autosave.reload') }}</x-ui.button>
            </div>
            <div class="tw-flex tw-items-center tw-gap-2">
                <span class="tw-hidden tw-text-ui-xs tw-text-on-surface-variant sm:tw-inline" style="font-variant-numeric: tabular-nums;" x-text="progressLabel">{{ __('supplier_audit.labels.progress', $progress) }}</span>
                <x-ui.button type="button" variant="outline" class="lg:tw-hidden" x-on:click="jumpToUnanswered()" x-bind:disabled="filled === total" :label="__('supplier_audit.form.jump_unanswered')" :icon-only="true">
                    <x-ui.icon name="arrow-down-to-line" size="sm" />
                </x-ui.button>
                <x-ui.button type="button" variant="primary" x-on:click="submit()" x-bind:disabled="submitting || saveState === 'expired'" data-supplier-audit-submit>
                    <x-ui.icon name="send" size="sm" />
                    <span>{{ __('supplier_audit.actions.submit') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.action-bar>

    {{-- Daftar bagian untuk layar kecil --}}
    <x-ui.dialog name="supplier-audit-sections" :title="__('supplier_audit.form.sections')" max-width="md">
        <ol class="tw-m-0 tw-grid tw-grid-cols-[minmax(0,1fr)] tw-list-none tw-gap-1 tw-p-0">
            @foreach($navSections as $section)
                <li class="tw-min-w-0">
                    <button type="button" x-on:click="goToSection(@js($section['code']))"
                        :aria-current="activeSection === @js($section['code']) ? 'location' : null"
                        :class="activeSection === @js($section['code']) ? 'tw-border-primary tw-bg-primary-container tw-font-semibold' : 'tw-border-outline-variant hover:tw-bg-surface-container'"
                        class="ui-focus-ring tw-flex tw-min-h-11 tw-w-full tw-items-center tw-gap-3 tw-rounded-ui-sm tw-border tw-px-3 tw-py-2 tw-text-start tw-text-ui-sm">
                        <span class="tw-min-w-0 tw-flex-1">{{ $section['code'] }}. {{ $section['title'] }}</span>
                        <span class="tw-shrink-0 tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" x-text="`${sectionFilled(sections[{{ $loop->index }}])}/{{ count($section['ids']) }} · ${sectionStateLabel(sections[{{ $loop->index }}])}`"></span>
                    </button>
                </li>
            @endforeach
        </ol>
    </x-ui.dialog>
</div>
@endsection
