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
    @if($id) id="{{ $id }}" @endif
    @if($testId) data-testid="{{ $testId }}" @endif
    {{ $attributes }}
>
    {{ $slot }}
</x-ui.empty-state>
