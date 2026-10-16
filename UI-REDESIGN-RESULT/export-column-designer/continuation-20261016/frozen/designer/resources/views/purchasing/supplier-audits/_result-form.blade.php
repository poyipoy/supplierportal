{{-- Upload / ganti file hasil penilaian (D9). Input: $audit, $replacing (bool). --}}
@can('uploadResult', $audit)
    <form method="POST" action="{{ route('purchasing.supplier-audits.result', $audit) }}" enctype="multipart/form-data" data-async-submit class="tw-grid tw-gap-3" data-result-form="{{ $replacing ? 'replace' : 'publish' }}">
        @csrf
        <x-ui.file-upload name="result_file" id="supplier_audit_result_file_{{ $replacing ? 'replace' : 'publish' }}" :label="__('supplier_audit.fields.result_file')" :helper="__('supplier_audit.upload.accepted')"
            accept=".pdf,.xlsx,.jpg,.jpeg,.png" :max-size-mb="10" :max-files="1" required />
        @if($replacing)
            <x-ui.textarea name="reason" id="supplier_audit_replace_reason" :label="__('supplier_audit.fields.replace_reason')" rows="2" required maxlength="2000" />
        @endif
        <x-ui.button type="submit" :variant="$replacing ? 'outline' : 'primary'" size="sm" class="tw-justify-self-start">
            <x-ui.icon name="upload" size="sm" />
            <span>{{ $replacing ? __('supplier_audit.actions.replace_result') : __('supplier_audit.actions.upload_result') }}</span>
        </x-ui.button>
    </form>
@endcan
