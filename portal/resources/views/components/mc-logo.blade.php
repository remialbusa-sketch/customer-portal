{{-- =============================================================
     MC BioTechnical Solutions logo — thin wrapper around the
     <mc-logo> custom element (resources/js/mc-logo.js, ported
     from the brand "Logo & Loader" reference).

     Replaces the previous Alpine strip-reveal + shine component.
     The new element brings a reveal sweep, a particle burst along
     the sweep edge, a completion glint, an idle helix ribbon, and
     a real percentage counter in `mode="loader"`.

     Props
       width  CSS width for the logo box      (default 200px)
       alt    accessible name                (default company name)

     Usage
       <x-mc-logo width="146px" />
       <x-mc-logo width="200px" helex />

     `theme="light"` is deliberate: the portal ships a single DaisyUI
     light theme ("mcbio"), so the element must not fall back to the
     operating system's colour scheme or it would render its
     dark-mode treatment on a light page.

     The sibling <img> is a no-JS fallback. `mc-logo:defined ~
     .mc-logo-fallback` (app.css) hides it the moment the custom
     element upgrades, so the brand never disappears when the
     component script is blocked or fails.
     ============================================================= --}}
@props([
    'width' => '200px',
    'alt' => 'MC BioTechnical Solutions Inc.',
    'helix' => false,
])

@php
    $logoAsset = asset('images/brand/mcbio-logo.png');
@endphp

<span {{ $attributes->merge(['class' => 'relative inline-block shrink-0 align-middle']) }}
      style="width: {{ $width }};">
    <mc-logo
        src="{{ $logoAsset }}"
        theme="light"
        @unless ($helix) nohelix @endunless
        role="img"
        aria-label="{{ $alt }}"
        class="block w-full"></mc-logo>
    <img src="{{ $logoAsset }}" alt="{{ $alt }}" aria-hidden="true"
         class="mc-logo-fallback absolute inset-0 h-full w-full object-fill">
</span>
