@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block w-full h-9 rounded-lg border-base-300 bg-base-200/50 px-3 text-[13px] text-base-content placeholder:text-base-content/40 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:ring-offset-0 focus:bg-base-100 shadow-sm transition']) }}>
