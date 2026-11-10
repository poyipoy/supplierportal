<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseRequisitionStatus;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\Materials\MaterialResolver;
use App\Services\Materials\PurchaseRequisitionItemSynchronizer;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionSubmissionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PurchaseRequisitionItemSynchronizer $itemSynchronizer,
    ) {}

    public function submitDraft(PurchaseRequisition $pr, User $actor): void
    {
        DB::transaction(function () use ($pr, $actor) {
            $this->itemSynchronizer->reprocessForSubmission($pr, $actor->id);
            app(PurchaseRequisitionStateEngine::class)->transition(
                model: $pr,
                target: PurchaseRequisitionStatus::SUBMITTED,
                actor: $actor,
                metadata: [
                    'attributes' => [
                        'pr_number' => $pr->pr_number ?: PurchaseRequisition::generatePrNumber(),
                    ],
                ]
            );
        });

        $this->notifyAdminsOfSubmission($pr, $actor);
    }

    public function create(array $validated, User $actor): PurchaseRequisition
    {
        $pr = DB::transaction(function () use ($validated, $actor) {
            $pr = PurchaseRequisition::create([
                'period_id' => $validated['period_id'],
                'created_by' => $actor->id,
                'pr_number' => $validated['action'] === 'submitted' ? PurchaseRequisition::generatePrNumber() : null,
                'notes' => $validated['notes'] ?? null,
                'port_of_discharge' => $validated['port_of_discharge'] ?? null,
                'incoterm' => $validated['incoterm'] ?? null,
                'status' => $validated['action'],
            ]);

            $this->itemSynchronizer->sync(
                $pr,
                $validated['items'],
                $validated['action'] === 'submitted',
                $actor->id,
            );
            $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));

            return $pr;
        });

        if ($validated['action'] === 'submitted') {
            $this->notifyAdminsOfSubmission($pr, $actor);
        }

        return $pr;
    }

    public function update(PurchaseRequisition $pr, array $validated, Request $request, User $actor): void
    {
        DB::transaction(function () use ($pr, $validated, $request, $actor) {
            $this->itemSynchronizer->sync(
                $pr,
                $validated['items'],
                $validated['action'] === 'submitted',
                $actor->id,
            );

            $prAttributes = [
                'period_id' => $validated['period_id'],
                'pr_number' => ($validated['action'] === 'submitted' && ! $pr->pr_number)
                    ? PurchaseRequisition::generatePrNumber()
                    : $pr->pr_number,
                'notes' => $validated['notes'] ?? null,
                'port_of_discharge' => $validated['port_of_discharge'] ?? null,
                'incoterm' => $validated['incoterm'] ?? null,
            ];
            $targetPrStatus = PurchaseRequisitionStatus::tryFromWithLegacy($validated['action']);
            if ($pr->statusEnum() !== $targetPrStatus) {
                app(PurchaseRequisitionStateEngine::class)->transition(
                    model: $pr,
                    target: $targetPrStatus,
                    actor: $actor,
                    metadata: ['attributes' => $prAttributes]
                );
            } else {
                $pr->update($prAttributes);
            }

            if ($request->boolean('supplier_selection_present') || $request->has('supplier_id') || $request->has('supplier_ids')) {
                $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));
            }
        });

        if ($validated['action'] === 'submitted') {
            $this->notifyAdminsOfSubmission($pr, $actor);
        }
    }

    public function resolveUnlinkedMaterials(PurchaseRequisition $pr): void
    {
        $resolver = app(MaterialResolver::class);
        $materialIndex = $resolver->activeIndex();
        foreach ($pr->items as $item) {
            if ($item->material_master_id === null && ! empty($item->material_name)) {
                $matched = $resolver->resolveExact($item->material_name, $materialIndex);
                if (! $matched) {
                    $stripped = preg_replace('/[\s\-_]+(F|R|H|FLAT|ROUND|HOLLOW)$/i', '', trim($item->material_name));
                    $matched = $resolver->resolveExact($stripped, $materialIndex);
                    if (! $matched) {
                        $nospace = str_replace(' ', '', $stripped);
                        $matched = $resolver->resolveExact($nospace, $materialIndex);
                    }
                }
                if ($matched) {
                    $item->material_master_id = $matched->id;
                    $item->setRelation('materialMaster', $matched);
                }
            }
        }
    }

    public function supplierIdsFromValidated(array $validated): array
    {
        if (! empty($validated['supplier_id'])) {
            return [(int) $validated['supplier_id']];
        }

        return collect($validated['supplier_ids'] ?? [])
            ->filter()
            ->map(fn ($supplierId) => (int) $supplierId)
            ->all();
    }

    public function syncInvitedSuppliers(PurchaseRequisition $pr, array $supplierIds): void
    {
        $syncData = collect($supplierIds)
            ->filter()
            ->unique()
            ->mapWithKeys(fn ($supplierId) => [
                (int) $supplierId => ['invited_at' => now()],
            ])
            ->all();

        $pr->invitedSuppliers()->sync($syncData);
    }

    public function notifyAdminsOfSubmission(PurchaseRequisition $pr, User $actor): void
    {
        $admins = User::where('role', 'admin')->where('is_active', true)->get();

        $this->notifications->send(
            $admins,
            'pr.submitted',
            'pr.submitted:'.$pr->id.':'.($pr->updated_at?->format('YmdHis.u') ?? 'initial'),
            'purchasing.copy.new_purchase_requisition',
            'purchasing.notify.pr_submitted_body',
            route('admin.requisitions.show', $pr, absolute: false),
            'clipboard-plus text-primary',
            [
                'category' => NotificationCategory::QUOTATION,
                'pr_id' => $pr->id,
                'pr_number' => $pr->pr_number,
            ],
            [
                'pr_number' => $pr->pr_number ?? '-',
                'name' => $actor->name,
            ],
        );
    }
}
