<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreConversationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::messageRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function messageRules(): array
    {
        return [
            'body' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx|max:10240',
        ];
    }

    public static function validateMessage(Request $request): string
    {
        $validated = $request->validate(self::messageRules());

        $body = trim((string) ($validated['body'] ?? ''));
        $hasAttachments = $request->hasFile('attachments');

        if ($body === '' && ! $hasAttachments) {
            throw ValidationException::withMessages([
                'body' => __('notifications.conversation.empty_message'),
            ]);
        }

        return $body;
    }

    /**
     * @return array{action: string, note: string}
     */
    public static function validateQuickAction(Request $request): array
    {
        $validated = $request->validate([
            'action' => 'required|string|in:request_price_revision,request_validity_extension,request_delivery_confirmation,accept_quotation,reject_quotation',
            'note' => 'nullable|string|max:1000',
        ]);

        $note = trim((string) ($validated['note'] ?? ''));

        return [
            'action' => (string) $validated['action'],
            'note' => $note,
        ];
    }
}
