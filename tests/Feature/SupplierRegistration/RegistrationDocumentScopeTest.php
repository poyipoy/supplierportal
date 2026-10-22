<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class RegistrationDocumentScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function payload(): array
    {
        return [
            'company_name' => 'Cycle Vendor', 'address' => 'Jakarta', 'phone' => '0211234567',
            'nib' => '1234567890123', 'npwp' => '01.234.567.8-412.000',
            'pic_name' => 'Contact', 'pic_email' => 'contact@example.test', 'pic_phone' => '0812345678',
            'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder_name' => 'Cycle Vendor',
            'email' => 'cycle@example.test', 'password' => 'Password123!',
            'questionnaire' => SupplierComplianceQuestionnaire::QUESTIONS,
        ];
    }

    private function files(string $prefix, bool $optional = false): array
    {
        $files = [
            'nib_file' => NativeFileFixtures::upload($prefix.'-nib.pdf', 10),
            'npwp_file' => UploadedFile::fake()->image($prefix.'-npwp.png'),
            'sknr_file' => NativeFileFixtures::upload($prefix.'-sknr.pdf', 10),
        ];
        if ($optional) {
            $files['sppkp_file'] = NativeFileFixtures::upload($prefix.'-sppkp.pdf', 10);
        }

        return $files;
    }

    private function newCycle(): array
    {
        $service = app(SupplierRegistrationService::class);
        $old = $service->submitInitialRegistration($this->payload(), $this->files('prior', true));
        $oldDocuments = $old['attempt']->user->supplierMasterDocuments()->get();
        $reviewer = User::factory()->create(['role' => 'admin']);
        $service->rejectRegistration($old['attempt'], $reviewer, 'New application required.');
        $current = $service->submitInitialRegistration($this->payload(), $this->files('current'));
        $this->post(route('supplier.registration.access'), [
            'reference' => $current['reference'], 'access_key' => $current['access_key'],
        ])->assertRedirect(route('supplier.registration.status'));

        return [$old, $current, $oldDocuments, $reviewer];
    }

    public function test_status_lists_only_documents_and_attempts_from_the_current_cycle(): void
    {
        [, $current, $oldDocuments] = $this->newCycle();
        $response = $this->get(route('supplier.registration.status'))->assertOk();

        foreach ($oldDocuments as $document) {
            $response->assertDontSee($document->original_filename, false);
        }
        $response->assertSee('current-nib.pdf', false);
        $this->assertSame([$current['attempt']->id], $response->viewData('attempts')->modelKeys());
    }

    public function test_prior_cycle_documents_are_not_downloadable_but_current_documents_are(): void
    {
        [, $current, $oldDocuments] = $this->newCycle();
        foreach ($oldDocuments as $document) {
            $this->get(route('supplier.registration.document.download', $document))->assertForbidden();
        }
        $documentId = $current['attempt']->submission_snapshot['documents']['NIB']['document_id'];
        $document = SupplierMasterDocument::findOrFail($documentId);
        $this->get(route('supplier.registration.document.download', $document))->assertDownload('current-nib.pdf');
    }

    public function test_reviewer_can_still_download_a_document_from_a_previous_attempt(): void
    {
        [$old, , $oldDocuments, $reviewer] = $this->newCycle();
        $document = $oldDocuments->firstWhere('document_type', SupplierMasterDocument::TYPE_NIB);

        $this->actingAs($reviewer)->get(route('supplier-registrations.document', [
            'attempt' => $old['attempt'], 'document' => $document,
        ]))->assertDownload('prior-nib.pdf');
    }

    public function test_revision_retention_cannot_reintroduce_prior_cycle_documents(): void
    {
        [, $current, $oldDocuments, $reviewer] = $this->newCycle();
        $service = app(SupplierRegistrationService::class);
        $service->requestRevision($current['attempt'], $reviewer, 'Review the profile.');
        $response = $this->get(route('supplier.registration.edit'))->assertOk();
        foreach ($oldDocuments as $document) {
            $response->assertDontSee($document->original_filename, false);
        }
        $response->assertSee('current-nib.pdf', false);

        $resubmitted = $service->resubmitRevision($current['attempt']->user, $this->payload(), []);
        $documents = $resubmitted['attempt']->submission_snapshot['documents'];
        $this->assertArrayNotHasKey('SPPKP', $documents);
        $this->assertSame($current['attempt']->submission_snapshot['documents']['NIB']['document_id'], $documents['NIB']['document_id']);
        $this->assertSame([], array_intersect($oldDocuments->modelKeys(), array_column($documents, 'document_id')));
    }

    public function test_replacement_and_retained_documents_remain_accessible_within_the_same_cycle(): void
    {
        [, $current, , $reviewer] = $this->newCycle();
        $service = app(SupplierRegistrationService::class);
        $service->requestRevision($current['attempt'], $reviewer, 'Replace NIB.');
        $resubmitted = $service->resubmitRevision($current['attempt']->user, $this->payload(), [
            'nib_file' => NativeFileFixtures::upload('replacement-nib.pdf', 10),
        ]);
        $status = $this->get(route('supplier.registration.status'))->assertOk();
        $status->assertSee('current-nib.pdf', false)->assertSee('replacement-nib.pdf', false);
        foreach ([$current, $resubmitted] as $result) {
            $id = $result['attempt']->submission_snapshot['documents']['NIB']['document_id'];
            $this->get(route('supplier.registration.document.download', SupplierMasterDocument::findOrFail($id)))->assertOk();
        }
    }

    public function test_reusing_an_access_row_does_not_reactivate_a_prior_cycle_session(): void
    {
        [$old, $current] = $this->newCycle();
        $access = $current['attempt']->user->registrationAccesses()->sole();

        $this->withSession([
            'registration_access_id' => $access->id,
            'registration_user_id' => $access->user_id,
            'registration_cycle_reference' => $old['reference'],
        ])->get(route('supplier.registration.status'))->assertRedirect(route('supplier.registration.access-form'));
        $this->assertNull(session('registration_access_id'));
    }

    public function test_rejected_cycle_key_cannot_open_the_replacement_application(): void
    {
        [$old, $current] = $this->newCycle();
        $this->post(route('supplier.registration.logout'));
        $this->post(route('supplier.registration.access'), [
            'reference' => $old['reference'], 'access_key' => $old['access_key'],
        ])->assertSessionHas('error', __('registration.feedback.invalid_access'));
        $this->assertNull(session('registration_access_id'));
        $this->post(route('supplier.registration.access'), [
            'reference' => $current['reference'], 'access_key' => $current['access_key'],
        ])->assertRedirect(route('supplier.registration.status'));
    }

    public function test_resubmit_rechecks_the_registration_cycle_inside_the_transaction(): void
    {
        [$old, $current, , $reviewer] = $this->newCycle();
        $service = app(SupplierRegistrationService::class);
        $service->requestRevision($current['attempt'], $reviewer, 'Review the profile.');
        $user = $current['attempt']->user->fresh();
        $before = $user->supplier->getAttributes();
        $data = array_merge($this->payload(), ['company_name' => 'Changed Company']);

        try {
            $service->resubmitRevision($user, $data, [], registrationReference: $old['reference']);
            $this->fail('A previous registration cycle was allowed to resubmit the new application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registration', $exception->errors());
        }

        $this->assertSame($before, $user->supplier->fresh()->getAttributes());
        $this->assertSame(2, $user->registrationAttempts()->count());
    }

    public static function applicantReadActions(): array
    {
        return [['status'], ['edit'], ['document.download']];
    }

    #[DataProvider('applicantReadActions')]
    public function test_access_changed_during_an_applicant_read_is_rejected(string $action): void
    {
        [, $current, , $reviewer] = $this->newCycle();
        $service = app(SupplierRegistrationService::class);
        $service->requestRevision($current['attempt'], $reviewer, 'Review the profile.');
        $user = $current['attempt']->user;
        $documents = $service->currentCycleDocuments($user);
        $this->partialMock(SupplierRegistrationService::class)->shouldReceive('currentCycleDocuments')
            ->once()->andReturnUsing(function () use ($user, $documents) {
                $user->registrationAccesses()->update(['registration_reference' => 'REG-REPLACED-CYCLE']);

                return $documents;
            });

        $parameters = $action === 'document.download' ? [$documents->first()] : [];
        $this->get(route('supplier.registration.'.$action, $parameters))->assertForbidden();
    }
}
