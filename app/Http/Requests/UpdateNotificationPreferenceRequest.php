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
        $rules = [
            'notification_preferences' => ['present', 'array:'.implode(',', array_keys($events))],
        ];

        foreach ($events as $key => $event) {
            $channels = array_filter($event['channels'], fn (array $channel): bool => $channel['configurable']);
            $rules['notification_preferences.'.$key] = ['sometimes', 'array:'.implode(',', array_keys($channels))];
            foreach ($channels as $channel => $metadata) {
                $rules['notification_preferences.'.$key.'.'.$channel] = ['sometimes', 'required', 'boolean'];
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, ['_token', '_method', 'notification_preferences'], true)) {
                    $validator->errors()->add($key, 'This field is not supported by notification preferences.');
                }
            }
        });
    }
}
