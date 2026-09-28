@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[13px] font-semibold text-base-content']) }}>
    {{ $value ?? $slot }}
</label>
