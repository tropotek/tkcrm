{{-- The logo, in the two colour variants the Minton skin switches between: the tk mark (the favicon) beside the site name, and the mark alone where the sidebar is narrow. --}}
@foreach (['dark', 'light'] as $variant)
    <a href="{{ url('/') }}" class="logo logo-{{ $variant }} text-center">
        <span class="logo-sm">
            <img src="/favicon.svg" alt="{{ config('app.name') }}" class="logo-mark">
        </span>
        <span class="logo-lg">
            <img src="/favicon.svg" alt="" class="logo-mark me-1">
            <span class="logo-lg-text-{{ $variant }}">{{ config('app.name') }}</span>
        </span>
    </a>
@endforeach
