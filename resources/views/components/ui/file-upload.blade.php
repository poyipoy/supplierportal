@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'multiple' => false,
    'maxFiles' => 5,
    'maxSizeMb' => 5,
    'disabled' => false,
    'accept' => null,
    'existingFiles' => [],
    'existingFileName' => null,
    'existingFileUrl' => null,
    'layout' => 'stacked',
])

@php
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    if (! $message && isset($errors) && $errors->has($validationKey . '.*')) {
        $message = $errors->first($validationKey . '.*');
    }
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $message ? $resolvedId . '-error' : null;

    $normalizedExistingFiles = $existingFiles;
    if (empty($normalizedExistingFiles) && !empty($existingFileName)) {
        $normalizedExistingFiles = [
            [
                'id' => null,
                'name' => $existingFileName,
                'size' => '',
                'url' => $existingFileUrl,
            ]
        ];
    }
    $hasExisting = !empty($normalizedExistingFiles);
    $formatText = $accept ? strtoupper(str_replace(['.', ','], ['', ', '], $accept)) . " · Maks. {$maxSizeMb} MB/berkas" : "Maksimal {$maxSizeMb} MB per berkas";
    $inputName = $multiple && !str_ends_with($name, '[]') ? $name . '[]' : $name;
    $isHorizontal = ($layout === 'horizontal');
@endphp

<div
    x-data="adasiFileUploadComponent({
        multiple: @js($multiple),
        maxFiles: @js($maxFiles),
        maxSizeMb: @js($maxSizeMb),
        existingFiles: @js($normalizedExistingFiles)
    })"
    {{ $attributes->only('class')->class(['tw-h-full']) }}
>
    {{-- Hidden inputs for retained existing documents (across revisions) --}}
    <template x-for="file in existingFiles" :key="'kept-' + (file.id || file.name)">
        <span x-if="file.id">
            <input type="hidden" name="kept_document_ids[]" :value="file.id">
        </span>
    </template>

    {{-- Balanced Document Card Container --}}
    <div
        class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container/25 tw-p-4 tw-flex tw-flex-col tw-h-full tw-transition-all hover:tw-border-outline"
        :class="{ 'tw-border-error tw-bg-error-container/10': {{ $message ? 'true' : 'false' }} || clientError }"
    >
        {{-- Card Header: Title, Status Badge, and Helper Note --}}
        <div>
            <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
                <label for="{{ $resolvedId }}" class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-cursor-pointer tw-mb-0 tw-leading-tight">
                    {{ $label }}
                </label>

                {{-- Dynamic Status Badge --}}
                <div class="tw-shrink-0">
                    <template x-if="hasFiles">
                        @if($multiple)
                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                <x-ui.icon name="files" size="sm" />
                                <span><span x-text="totalFilesCount"></span>/{{ $maxFiles }} Berkas</span>
                            </span>
                        @else
                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/15 tw-text-success">
                                <x-ui.icon name="check" size="sm" />
                                <span x-text="isExisting ? 'Tersimpan' : 'Siap Diunggah'"></span>
                            </span>
                        @endif
                    </template>
                    <template x-if="!hasFiles">
                        @if($required)
                            <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-error/10 tw-text-error">
                                Wajib
                            </span>
                        @else
                            <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                                Opsional
                            </span>
                        @endif
                    </template>
                </div>
            </div>

            @if($helper)
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1 tw-mb-0 tw-leading-normal">
                    {{ $helper }}
                </p>
            @endif
        </div>

        {{-- Card Body: Centered Expanding Dropzone OR Compact Scrollable File List --}}
        <div class="tw-mt-3 tw-flex-1 tw-flex tw-flex-col tw-justify-start tw-gap-2.5">
            {{-- 1. Full Dropzone (When !hasFiles) - Centered & Expands to Fill Row Height --}}
            <div
                x-show="!hasFiles"
                @click="$refs.fileInput.click()"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop($event)"
                tabindex="0"
                @keydown.enter.prevent="$refs.fileInput.click()"
                @keydown.space.prevent="$refs.fileInput.click()"
                class="group tw-relative tw-flex tw-flex-col tw-items-center tw-justify-center tw-text-center tw-rounded-ui-sm tw-border tw-border-dashed tw-border-outline-variant tw-bg-surface tw-p-4 tw-flex-1 tw-min-h-[110px] tw-transition-all tw-cursor-pointer hover:tw-border-primary hover:tw-bg-primary/5 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-primary focus:tw-border-primary"
                :class="{
                    'tw-border-primary tw-bg-primary-container/20': isDragging,
                    'tw-border-error tw-bg-error-container/20': {{ $message ? 'true' : 'false' }} || clientError
                }"
                role="button"
                aria-label="Upload {{ $label ?: 'berkas' }}"
            >
                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-mb-1.5 group-hover:tw-bg-primary group-hover:tw-text-primary-foreground tw-transition-colors">
                    <x-ui.icon name="upload-cloud" size="sm" />
                </div>

                <div class="tw-text-ui-xs tw-font-semibold tw-text-primary group-hover:tw-underline">
                    Pilih berkas <span class="tw-text-on-surface-variant tw-font-normal">atau seret ke sini</span>
                </div>
                <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                    {{ $formatText }} @if($multiple) · Maks. {{ $maxFiles }} berkas @endif
                </div>
            </div>

            {{-- 2. File List (When hasFiles) - Max height 144px + Scrollbar + Add/Limit Control --}}
            <template x-if="hasFiles">
                <div class="tw-flex-1 tw-flex tw-flex-col tw-justify-between tw-gap-2">
                    {{-- Scrollable File Items Container --}}
                    <div
                        class="tw-max-h-36 tw-overflow-y-auto tw-space-y-1.5 tw-pr-1 ui-scrollable-list"
                        @dragover.prevent="if (canAddMore) isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="if (canAddMore) handleDrop($event)"
                    >
                        {{-- 2.1 Existing Saved Files --}}
                        <template x-for="(file, idx) in existingFiles" :key="'exist-' + idx">
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-rounded-ui-sm tw-border tw-border-success/30 tw-bg-surface tw-px-2.5 tw-py-2 tw-transition-all hover:tw-border-success/50">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0 tw-flex-1">
                                    <div class="tw-w-7 tw-h-7 tw-rounded-full tw-bg-success/10 tw-text-success tw-flex tw-items-center tw-justify-center tw-shrink-0">
                                        <x-ui.icon name="file-check" size="sm" />
                                    </div>
                                    <div class="tw-min-w-0 tw-flex-1">
                                        <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-truncate" x-text="file.name" :title="file.name"></div>
                                        <div class="tw-text-[10px] tw-text-on-surface-variant tw-flex tw-items-center tw-gap-1.5 tw-mt-0.5">
                                            <template x-if="file.size">
                                                <span class="tw-font-mono" x-text="file.size"></span>
                                            </template>
                                            <template x-if="file.size">
                                                <span>·</span>
                                            </template>
                                            <span class="tw-text-success tw-font-medium tw-inline-flex tw-items-center tw-gap-0.5">
                                                <x-ui.icon name="check" size="sm" />
                                                <span>Tersimpan</span>
                                            </span>
                                            <template x-if="file.url">
                                                <a :href="file.url" target="_blank" class="tw-text-primary tw-underline tw-font-semibold tw-ms-1" @click.stop>
                                                    Lihat
                                                </a>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-transparent tw-bg-error-container/30 tw-text-error tw-p-1 tw-text-[10px] tw-font-semibold hover:tw-bg-error-container hover:tw-text-on-error-container"
                                    @click.stop="removeExistingFile(idx)"
                                    title="Hapus berkas tersimpan"
                                    aria-label="Hapus berkas tersimpan"
                                >
                                    <x-ui.icon name="trash-2" size="sm" />
                                </button>
                            </div>
                        </template>

                        {{-- 2.2 Newly Staged Files (Ready to upload) --}}
                        <template x-for="(file, idx) in stagedFiles" :key="'staged-' + idx">
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-rounded-ui-sm tw-border tw-border-primary/30 tw-bg-surface tw-px-2.5 tw-py-2 tw-transition-all hover:tw-border-primary/50">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0 tw-flex-1">
                                    <div class="tw-w-7 tw-h-7 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                                        <x-ui.icon name="file-up" size="sm" />
                                    </div>
                                    <div class="tw-min-w-0 tw-flex-1">
                                        <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-truncate" x-text="file.name" :title="file.name"></div>
                                        <div class="tw-text-[10px] tw-text-on-surface-variant tw-flex tw-items-center tw-gap-1.5 tw-mt-0.5">
                                            <span class="tw-font-mono" x-text="formatBytes(file.size)"></span>
                                            <span>·</span>
                                            <span class="tw-text-primary tw-font-medium tw-inline-flex tw-items-center tw-gap-0.5">
                                                <x-ui.icon name="upload-cloud" size="sm" />
                                                <span>Siap diunggah</span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-transparent tw-bg-error-container/30 tw-text-error tw-p-1 tw-text-[10px] tw-font-semibold hover:tw-bg-error-container hover:tw-text-on-error-container"
                                    @click.stop="removeStagedFile(idx)"
                                    title="Batalkan berkas"
                                    aria-label="Batalkan berkas"
                                >
                                    <x-ui.icon name="trash-2" size="sm" />
                                </button>
                            </div>
                        </template>
                    </div>

                    {{-- Secondary Action / Limit Notice --}}
                    <div>
                        {{-- Can add more files --}}
                        <div
                            x-show="canAddMore"
                            @click="$refs.fileInput.click()"
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop.prevent="handleDrop($event)"
                            tabindex="0"
                            @keydown.enter.prevent="$refs.fileInput.click()"
                            class="tw-flex tw-items-center tw-justify-center tw-gap-1.5 tw-py-1.5 tw-px-3 tw-rounded-ui-sm tw-border tw-border-dashed tw-border-outline-variant hover:tw-border-primary hover:tw-bg-primary/5 tw-text-ui-xs tw-font-semibold tw-text-primary tw-cursor-pointer tw-transition-colors"
                            :class="{ 'tw-border-primary tw-bg-primary/10': isDragging }"
                        >
                            <x-ui.icon name="plus" size="sm" />
                            <span>Tambah Berkas (<span x-text="totalFilesCount"></span>/{{ $maxFiles }})</span>
                        </div>

                        {{-- Max files limit reached notice --}}
                        <div
                            x-show="!canAddMore"
                            class="tw-text-center tw-py-1.5 tw-px-2.5 tw-rounded-ui-sm tw-bg-surface-container-high/60 tw-border tw-border-outline-variant/60 tw-text-[11px] tw-font-medium tw-text-on-surface-variant"
                        >
                            <x-ui.icon name="check-circle" size="sm" class="tw-inline tw-text-success tw-me-1" />
                            <span>Batas maksimal {{ $maxFiles }} berkas tercapai</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Real Hidden Native File Input --}}
        <input
            x-ref="fileInput"
            id="{{ $resolvedId }}"
            name="{{ $inputName }}"
            type="file"
            class="tw-sr-only"
            @required($required && !$hasExisting)
            @disabled($disabled)
            @if($multiple) multiple @endif
            @if($accept) accept="{{ $accept }}" @endif
            @if($helper || $message) aria-describedby="{{ collect([$helperId, $errorId])->filter()->implode(' ') }}" @endif
            @if($message) aria-invalid="true" @endif
            {{ $attributes->except(['class', 'accept', 'id', 'name', 'type', 'layout']) }}
        >

        {{-- Error Alert / Message --}}
        <p x-show="clientError" x-text="clientError" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error tw-mt-2.5" role="alert"></p>
        @if($message)
            <p id="{{ $errorId }}" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error tw-mt-2.5" role="alert">
                {{ $message }}
            </p>
        @endif
    </div>
</div>
