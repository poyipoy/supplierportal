<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(NotificationPreferenceService::class)->eventsFor($user) !== [];
    }

    public function rules(NotificationPreferenceService $notifications): array
    {
        $events = $notifications->eventsFor($this->user());
        $eventKeys = array_keys($events);
        $rules = [
            'notification_preferences' => ['present', 'array:'.implode(',', $eventKeys)],
            'notification_delivery' => ['sometimes', 'array', 'array:'.implode(',', $eventKeys)],
        ];

        foreach ($events as $key => $event) {
            $rules['notification_preferences.'.$key] = ['sometimes', 'required', 'boolean'];
            $rules['notification_delivery.'.$key] = ['sometimes', 'required', 'string', 'in:normal,silent'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, ['_token', '_method', 'notification_preferences', 'notification_delivery'], true)) {
                    $validator->errors()->add($key, 'This field is not supported by notification preferences.');
                }
            }
        });
    }
}
