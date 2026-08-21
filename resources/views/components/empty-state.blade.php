@props([
    'id' => null,
    'testId' => null,
    'icon' => 'clipboard-list',
    'title' => 'No data available',
    'text' => '',
    'actionUrl' => null,
    'actionText' => null,
    'actionIcon' => 'plus-circle'
])
<x-ui.empty-state
    :icon="$icon"
    :title="$title"
    :description="$text"
    :action-url="$actionUrl"
    :action-text="$actionText"
    :action-icon="$actionIcon"
    :id="$id"
    :data-testid="$testId"
>
    {{ $slot }}
</x-ui.empty-state>
