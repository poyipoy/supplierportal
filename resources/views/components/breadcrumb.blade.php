@props(['items' => []])
@if(count($items) > 0)
<nav aria-label="breadcrumb" class="bg-light py-2 px-3 rounded shadow-sm mb-4">
    <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
        @foreach($items as $label => $url)
            @if($loop->last)
                <li class="breadcrumb-item active fw-bold text-truncate" style="max-width: 250px;" aria-current="page">{{ $label }}</li>
            @else
                <li class="breadcrumb-item"><a href="{{ $url }}" class="text-decoration-none">{{ $label }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
@endif
