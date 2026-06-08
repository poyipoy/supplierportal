<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Support\PurchasingNavigation;
use App\Support\ConversationPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * List all conversations owned by the logged-in purchasing user.
     */
    public function index()
    {
        $conversations = Conversation::with(['conversable', 'supplierUser', 'latestMessage.sender'])
            ->withMax('messages', 'created_at')
            ->where('purchasing_user_id', auth()->id())
            ->orderByDesc(DB::raw('COALESCE(messages_max_created_at, conversations.updated_at, conversations.created_at)'))
            ->paginate(25);

        return view('purchasing.conversations.index', compact('conversations'));
    }

    /**
     * Tampilkan halaman chat.
     */
    public function show($id)
    {
        $conversation = Conversation::with(['conversable', 'supplierUser', 'purchasingUser', 'messages.sender'])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        // Mark all unread messages as read
        $conversation->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->load(['messages.sender', 'messages.attachments', 'latestMessage.sender']);

        $chatContext = ConversationPresenter::context($conversation, auth()->user());
        $quickActions = ConversationPresenter::quickActions($conversation, auth()->user());
        $messageTemplates = ConversationPresenter::templates($conversation, auth()->user());

        return view('conversations.show', compact('conversation', 'chatContext', 'quickActions', 'messageTemplates'));
    }

    /**
     * Mulai conversation baru atau redirect ke yang already exists.
     * Konteks: PR (Purchase Requisition).
     */
    public function startFromPr($pr_id, $supplier_id)
    {
        $pr = PurchaseRequisition::findOrFail($pr_id);

        // The supplier must have a quotation for this PR.
        $hasQuotation = Quotation::where('pr_id', $pr_id)
            ->where('supplier_id', $supplier_id)
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted'])
            ->exists();

        if (!$hasQuotation) {
            return back()->with('error', 'This supplier does not have a quotation for this requisition yet.');
        }

        // Search conversation yang already exists
        $conversation = Conversation::where('conversable_type', PurchaseRequisition::class)
            ->where('conversable_id', $pr_id)
            ->where('purchasing_user_id', auth()->id())
            ->where('supplier_user_id', $supplier_id)
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'conversable_type' => PurchaseRequisition::class,
                'conversable_id' => $pr_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $supplier_id,
            ]);
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
            ]);
        }

        $showParameters = [$conversation->id];
        if (PurchasingNavigation::isSafeUrl(request()->input('return_url'))) {
            $showParameters['return_url'] = request()->input('return_url');
        }

        return redirect()->route('purchasing.conversations.show', $showParameters);
    }

    /**
     * Mulai conversation baru atau redirect ke yang already exists.
     * Konteks: PO (Purchase Order).
     */
    public function startFromPo($po_id)
    {
        $po = PurchaseOrder::findOrFail($po_id);
        $supplier_id = $po->supplier_id;

        // Search conversation yang already exists
        $conversation = Conversation::where('conversable_type', PurchaseOrder::class)
            ->where('conversable_id', $po_id)
            ->where('purchasing_user_id', auth()->id())
            ->where('supplier_user_id', $supplier_id)
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'conversable_type' => PurchaseOrder::class,
                'conversable_id' => $po_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $supplier_id,
            ]);
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
            ]);
        }

        $showParameters = [$conversation->id];
        if (PurchasingNavigation::isSafeUrl(request()->input('return_url'))) {
            $showParameters['return_url'] = request()->input('return_url');
        }

        return redirect()->route('purchasing.conversations.show', $showParameters);
    }
}
