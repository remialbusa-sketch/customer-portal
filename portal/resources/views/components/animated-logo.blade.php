{{-- =============================================================
     Animated logo — strip-reveal + shine sweep, ported from the
     MC BioTechnical Solutions animated-logo reference.
     - Plays once on mount (28 vertical strips fly in, then the
       static base takes over and a soft shine loops).
     - Honors prefers-reduced-motion (static logo immediately).
     - Safety timeout forces the static logo if WAAPI stalls.
     Usage: <x-animated-logo class="w-[146px]" />
     ============================================================= --}}
@props(['src' => null, 'alt' => 'MC BioTechnical Solutions Inc.'])

@php
    $logoSrc = $src ?? asset('images/brand/mcbio-logo.png');
@endphp

<div
    {{ $attributes->merge(['class' => 'relative overflow-hidden']) }}
    x-data="mcAnimatedLogo(@js($logoSrc))"
    x-init="play()"
    style="aspect-ratio: 1024 / 252;"
    role="img"
    aria-label="{{ $alt }}"
>
    <img src="{{ $logoSrc }}" alt="" aria-hidden="true"
         x-ref="base"
         class="absolute inset-0 w-full h-full object-fill opacity-0">
    <div x-ref="strips" class="absolute inset-0" aria-hidden="true"></div>
    <div x-ref="shine" class="absolute inset-0 opacity-0 pointer-events-none mc-logo-shine" aria-hidden="true"
         style="mask-image: url('{{ $logoSrc }}'); -webkit-mask-image: url('{{ $logoSrc }}'); mask-size: 100% 100%; -webkit-mask-size: 100% 100%; mask-repeat: no-repeat; -webkit-mask-repeat: no-repeat;"></div>
</div>

@once
    @push('scripts')
        <script>
            // Animated-logo factory (single global; every instance
            // calls play() on mount). Pure WAAPI, no dependencies.
            window.mcAnimatedLogo = function (src) {
                return {
                    src,
                    play() {
                        const base = this.$refs.base;
                        const box = this.$refs.strips;
                        const shine = this.$refs.shine;
                        const done = () => {
                            base.style.opacity = 1;
                            box.innerHTML = '';
                            shine.style.opacity = 1;
                        };
                        // Motion-safe + no-WAAPI fallback: static logo.
                        if (!window.matchMedia
                            || window.matchMedia('(prefers-reduced-motion: reduce)').matches
                            || typeof Element.prototype.animate !== 'function') {
                            done();
                            return;
                        }
                        // Safety net: never leave the logo invisible.
                        const failsafe = setTimeout(done, 4000);
                        try {
                            const N = 28;
                            const anims = [];
                            for (let i = 0; i < N; i++) {
                                const s = document.createElement('div');
                                s.className = 'mc-logo-strip';
                                s.style.backgroundImage = 'url("' + src + '")';
                                const l = Math.max(0, (i / N) * 100 - 0.08);
                                const r = Math.max(0, 100 - ((i + 1) / N) * 100 - 0.08);
                                s.style.clipPath = 'inset(0 ' + r + '% 0 ' + l + '%)';
                                box.appendChild(s);
                                const dir = i % 2 ? 1 : -1;
                                const d = 110 + Math.random() * 220;
                                const rot = Math.random() * 16 - 8;
                                anims.push(s.animate([
                                    { transform: 'translateY(' + (dir * d) + 'px) rotate(' + rot + 'deg) scale(.7)', opacity: 0, filter: 'blur(10px)' },
                                    { opacity: 1, filter: 'blur(2px)', offset: 0.35 },
                                    { transform: 'none', opacity: 1, filter: 'blur(0)' },
                                ], { duration: 1000, delay: i * 38 + Math.random() * 120, easing: 'cubic-bezier(.2,1.25,.35,1)', fill: 'both' }).finished);
                            }
                            Promise.all(anims).then(() => {
                                clearTimeout(failsafe);
                                done();
                            }).catch(() => {
                                clearTimeout(failsafe);
                                done();
                            });
                        } catch (e) {
                            clearTimeout(failsafe);
                            done();
                        }
                    },
                };
            };
        </script>
    @endpush
@endonce
