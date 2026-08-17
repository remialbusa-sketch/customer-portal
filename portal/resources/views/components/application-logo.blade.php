@php($logo = asset('images/brand/mcbio-logo.png'))
<img src="{{ $logo }}" alt="MC BioTechnical Solutions Inc." {{ $attributes->merge(['class' => $attributes->get('class', 'h-10 w-auto')]) }}>
