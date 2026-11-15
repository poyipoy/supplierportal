<?php

namespace App\Http\Requests\Erp;

use App\Models\ErpDeltaRow;
use App\Models\User;
use App\Services\Erp\CanonicalRowHasher;
use App\Services\FileSecurity\FileInspectionService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeltaSyncImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSupplier()) {
            if ($this->filled('supplier_id')) {
                $targetId = $this->resolveSupplierIdInput($this->input('supplier_id'), $user);
                if ($targetId === null || $targetId !== (int) $user->id) {
                    return false;
                }
            }

            return $user->can('sync', [ErpDeltaRow::class, $user]);
        }

        return $user->can('sync', [ErpDeltaRow::class, null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rawJsonRows = $this->extractRawJsonRows();

        $rejectFormula = function (string $attribute, mixed $value, Closure $fail) use ($rawJsonRows): void {
            $rawValue = $this->lookupRawValue($rawJsonRows, $attribute) ?? $value;

            if (! is_scalar($rawValue)) {
                $fail(__('validation.string', ['attribute' => $attribute]));

                return;
            }

            $text = (string) $rawValue;
            if (preg_match('/^(?:[\s\v\0\x{FEFF}]*[\t\r=+\-@])/u', $text) === 1) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };

        $nonBlankText = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_replace('/^\s+|\s+$/u', '', $value) === '') {
                $fail(__('validation.filled', ['attribute' => $attribute]));
            }
        };

        $nonNegativeDecimal = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                $fail(__('validation.numeric', ['attribute' => $attribute]));

                return;
            }

            try {
                CanonicalRowHasher::normalizeDecimal($value, 4);
            } catch (Throwable) {
                $fail(__('validation.gte.numeric', ['attribute' => $attribute, 'value' => 0]));
            }
        };

        $validPdfUpload = function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $realPath = $value->getRealPath();
            if ($realPath === false || ! is_file($realPath)) {
                $fail(__('file_security.errors.read'));

                return;
            }

            if (! class_exists(\finfo::class)) {
                $fail(__('file_security.errors.unsupported'));

                return;
            }

            try {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
            } catch (Throwable) {
                $fail(__('file_security.errors.read'));

                return;
            }

            if (! is_string($mime) || strtolower($mime) !== 'application/pdf') {
                $fail(__('file_security.errors.type'));

                return;
            }

            $handle = fopen($realPath, 'rb');
            if ($handle === false) {
                $fail(__('file_security.errors.read'));

                return;
            }

            try {
                $head = fread($handle, 512);
                if (! is_string($head) || ! str_starts_with($head, '%PDF-') || preg_match('/<\?(?:php|=)|<!doctype\s+html|<script\b/i', $head) === 1) {
                    $fail(__('file_security.errors.pdf'));

                    return;
                }
            } finally {
                fclose($handle);
            }

            try {
                FileInspectionService::assertPdfEnvelope($realPath);
            } catch (ValidationException) {
                $fail(__('file_security.errors.pdf'));
            }
        };

        $maxPdfKilobytes = (int) config('native_file_security.profiles.po_pdf.max_kb', 20480);

        return [
            'supplier_id' => $this->user()?->isSupplier()
                ? ['nullable']
                : ['required', 'integer', 'exists:users,id'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['required', 'array'],
            'rows.*.doc_no' => ['required', 'string', 'max:100', $nonBlankText, $rejectFormula],
            'rows.*.item_code' => ['required', 'string', 'max:100', $nonBlankText, $rejectFormula],
            'rows.*.qty' => ['required', $rejectFormula, $nonNegativeDecimal],
            'rows.*.price' => ['required', $rejectFormula, $nonNegativeDecimal],
            'rows.*.status' => ['required', 'string', 'max:64', $nonBlankText, $rejectFormula],
            'rows.*.row_hash' => ['nullable', 'string', 'max:64'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:'.$maxPdfKilobytes, $validPdfUpload],
        ];
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function extractRawJsonRows(): ?array
    {
        $content = $this->getContent();
        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            return null;
        }

        return $decoded['rows'];
    }

    /**
     * @param  array<int|string, mixed>|null  $rawJsonRows
     */
    private function lookupRawValue(?array $rawJsonRows, string $attribute): mixed
    {
        if ($rawJsonRows === null) {
            return null;
        }

        if (preg_match('/^rows\.(\d+)\.([a-z_]+)$/', $attribute, $matches) !== 1) {
            return null;
        }

        $index = (int) $matches[1];
        $field = $matches[2];

        return $rawJsonRows[$index][$field] ?? null;
    }

    private function resolveSupplierIdInput(mixed $raw, User $actor): ?int
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return (int) $raw;
        }

        if (is_string($raw) && $raw !== '' && $raw === (string) $actor->getRouteKey()) {
            return (int) $actor->id;
        }

        return null;
    }
}
