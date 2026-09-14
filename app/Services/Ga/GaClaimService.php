<?php

namespace App\Services\Ga;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GaClaimService
{
    /**
     * Submit a new claim by GA.
     */
    public function submitClaim(User $actor, array $data, array $files): GaClaim
    {
        if (! $actor->isGa() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only GA or Admin can submit GA claims.');
        }

        $employee = Employee::where('id', $data['employee_id'] ?? null)->where('is_active', true)->first();
        if (! $employee) {
            throw new InvalidArgumentException('A valid active employee must be selected from Employee Master.');
        }

        if (empty($files['supporting'])) {
            throw new InvalidArgumentException('At least one supporting document is mandatory.');
        }

        $rules = ['file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,doc,docx', 'max:10240'];
        Validator::make($files, ['supporting' => array_merge(['required'], $rules)])->validate();

        $written = [];

        try {
            return DB::transaction(function () use ($actor, $employee, $data, $files, &$written) {
                $year = now()->year;
                DB::table('local_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
                $seq = DB::table('local_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
                $num = $seq->last_number + 1;
                DB::table('local_invoice_sequences')->where('year', $year)->update(['last_number' => $num]);
                $suffix = $year.'-'.str_pad((string) $num, 5, '0', STR_PAD_LEFT);

                $claimNumber = 'CLM-'.$suffix;
                $receiptNumber = 'TT-GA-'.$suffix;

                $claim = GaClaim::create([
                    'claim_number' => $claimNumber,
                    'employee_id' => $employee->id,
                    'claim_type' => $data['claim_type'],
                    'claim_date' => $data['claim_date'],
                    'amount' => (float) $data['amount'],
                    'description' => $data['description'] ?? null,
                    'status' => GaClaim::STATUS_SUBMITTED,
                    'revision_number' => 1,
                    'submitted_by' => $actor->id,
                    'submitted_at' => now(),
                ]);

                // Store supporting document(s)
                $file = $files['supporting'];
                $path = 'ga-claims/'.$claim->id.'/revisions/1/'.$file->hashName();
                $written[] = $path;

                $stream = fopen($file->getPathname(), 'r');
                if ($stream === false) {
                    throw new RuntimeException('Unable to read uploaded file.');
                }
                try {
                    if (! Storage::disk('private')->put($path, $stream)) {
                        throw new RuntimeException('Unable to store GA document.');
                    }
                } finally {
                    fclose($stream);
                }

                $claim->documents()->create([
                    'revision_number' => 1,
                    'document_type' => 'supporting',
                    'file_path' => $path,
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $actor->id,
                ]);

                // Generate TT-GA Receipt
                $claim->receipt()->create([
                    'receipt_number' => $receiptNumber,
                    'issued_at' => now(),
                ]);

                // Record history
                $claim->statusHistories()->create([
                    'from_status' => null,
                    'to_status' => GaClaim::STATUS_SUBMITTED,
                    'actor_id' => $actor->id,
                    'event' => 'submitted',
                    'notes' => 'GA Claim submitted on behalf of '.$employee->name,
                    'created_at' => now(),
                ]);

                return $claim->fresh();
            });
        } catch (Throwable $e) {
            foreach ($written as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Perform GA Basic Verification.
     */
    public function basicVerify(GaClaim $claim, User $gaActor, ?string $notes = null): GaClaim
    {
        if (! $gaActor->isGa() && ! $gaActor->isAdmin()) {
            throw new InvalidArgumentException('Only GA or Admin can perform basic verification.');
        }

        return DB::transaction(function () use ($claim, $gaActor, $notes) {
            /** @var GaClaim $clm */
            $clm = GaClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();

            if ($clm->status !== GaClaim::STATUS_SUBMITTED) {
                throw new RuntimeException("Cannot perform basic verification: claim is in [{$clm->status}] status.");
            }

            $clm->update([
                'status' => GaClaim::STATUS_BASIC_VERIFIED,
                'basic_verified_by' => $gaActor->id,
                'basic_verified_at' => now(),
            ]);

            $clm->statusHistories()->create([
                'from_status' => GaClaim::STATUS_SUBMITTED,
                'to_status' => GaClaim::STATUS_BASIC_VERIFIED,
                'actor_id' => $gaActor->id,
                'event' => 'basic_verified',
                'notes' => $notes ?: 'GA basic information verified.',
                'created_at' => now(),
            ]);

            return $clm->fresh();
        });
    }
}
