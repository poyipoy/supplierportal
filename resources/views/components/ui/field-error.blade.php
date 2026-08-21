@props(['messages' => null])

@if($messages)
    <ul role="alert" {{ $attributes->class(['tw-m-0 tw-grid tw-list-none tw-gap-1 tw-p-0 tw-text-ui-xs tw-font-medium tw-text-error']) }}>
        @foreach((array) $messages as $message)
            <li class="tw-flex tw-items-start tw-gap-1.5">
                <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" />
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
