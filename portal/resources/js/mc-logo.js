/**
 * <mc-logo> — MC BioTechnical Solutions animated logo + loading screen.
 * Ported from the brand reference ("MC BioTechnical Solutions – Logo &
 * Loader"), with four adaptations for the portal:
 *
 *   1. The logo art comes from the element's `src` attribute (a plain
 *      asset URL) instead of an inlined base64 data URI. Same file,
 *      ~99 KB less JavaScript to download and parse.
 *   2. prefers-reduced-motion is read live per frame rather than once
 *      at load, and the shadow stylesheet also stops the glint sweep
 *      and the completion burst under it.
 *   3. A `nohelix` logo parks its animation frame loop once the intro
 *      has finished and the last particle has died, so a small header
 *      logo does not burn a 60 fps loop for the rest of the session.
 *   4. A failed image load degrades to a plain, fully visible logo
 *      instead of stalling on an invisible one.
 *
 * Markup
 *   <mc-logo></mc-logo>                           animated logo, plays once
 *   <mc-logo mode="loader"></mc-logo>             progress 0→1, call finish()
 *   <mc-logo mode="loader" fullscreen></mc-logo>  fullscreen loading screen
 *
 * Attributes
 *   mode=logo|loader · fullscreen · nohelix · theme=light|dark · keep
 *   src · progress=0..1
 *
 * Methods
 *   finish() · hide() · replay()
 *
 * Events
 *   done · hiding · hidden
 */
const MC_LOGO_DEFAULT_SRC = '/images/brand/mcbio-logo.png';

/**
 * Alpha-column index of the logo art, cached per src. The particle
 * burst samples opaque columns only, so it never spawns in the gaps
 * between letters. Resolved lazily and shared by every instance.
 */
const inkCache = new Map();
const inkPending = new Map();

function loadInk(src, cb) {
    if (inkCache.has(src)) {
        cb(inkCache.get(src));
        return;
    }
    if (inkPending.has(src)) {
        inkPending.get(src).push(cb);
        return;
    }

    const waiting = [cb];
    inkPending.set(src, waiting);

    const settle = (value) => {
        inkCache.set(src, value);
        inkPending.delete(src);
        waiting.splice(0).forEach((fn) => fn(value));
    };

    const im = new Image();
    im.onload = function () {
        try {
            const gw = 256;
            const gh = Math.round(gw * im.naturalHeight / im.naturalWidth);
            const c = document.createElement('canvas');
            c.width = gw;
            c.height = gh;
            const x = c.getContext('2d');
            x.drawImage(im, 0, 0, gw, gh);
            const d = x.getImageData(0, 0, gw, gh).data;
            const cols = [];
            for (let i = 0; i < gw; i++) {
                cols[i] = [];
                for (let j = 0; j < gh; j++) {
                    if (d[(j * gw + i) * 4 + 3] > 110) cols[i].push(j);
                }
            }
            settle({ gw: gw, gh: gh, cols: cols });
        } catch (e) {
            settle(null);
        }
    };
    // Cross-origin art would taint the canvas and throw on
    // getImageData — handled above — but a 404 lands here.
    im.onerror = function () { settle(null); };
    im.src = src;
}

function easeOutCubic(e) {
    return e < .5 ? 4 * e * e * e : 1 - Math.pow(-2 * e + 2, 3) / 2;
}

function prefersReducedMotion() {
    return typeof matchMedia === 'function'
        && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isDarkMode(el) {
    const t = el.getAttribute('theme') || document.documentElement.dataset.theme;
    return t === 'dark' || (t !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
}

/**
 * Shadow stylesheet. Built per element because `--img` embeds that
 * instance's src. Identical rules to the brand reference, plus the
 * reduced-motion guard for the glint sweep.
 */
function shadowCss(src) {
    return ':host{display:block;position:relative;width:100%;max-width:760px;--bg:#f7f7f4;--mut:#8a919a;--ac:#2f6fe4;--img:url(' + src + ')}' +
        ':host(.d){--bg:#0b0d11;--mut:#8593a3;--ac:#8fb4ff}' +
        ':host([fullscreen]){position:fixed;inset:0;z-index:2147483000;max-width:none;width:auto;display:flex;align-items:center;justify-content:center;background:var(--bg);transition:opacity .7s ease,visibility .7s}' +
        ':host(.out){opacity:0;visibility:hidden}' +
        '.box{position:relative;width:100%}:host([fullscreen]) .box{width:min(760px,84vw)}' +
        '.logo{position:relative;width:100%;aspect-ratio:1024/252}.logo>div{position:absolute;inset:0}' +
        '.ghost{background:var(--img) center/100% 100% no-repeat;filter:grayscale(1);opacity:.09}:host(.d) .ghost{opacity:.16;filter:grayscale(1) brightness(1.6)}' +
        '.base{background:var(--img) center/100% 100% no-repeat;-webkit-mask-image:linear-gradient(90deg,#000 46%,transparent 54%);mask-image:linear-gradient(90deg,#000 46%,transparent 54%);-webkit-mask-size:400% 100%;mask-size:400% 100%;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:-9999px 0;mask-position:-9999px 0}' +
        '.m{-webkit-mask:var(--img) center/100% 100% no-repeat;mask:var(--img) center/100% 100% no-repeat}' +
        '.glint{background:linear-gradient(105deg,transparent 44%,rgba(255,255,255,.95) 50%,transparent 56%) no-repeat;background-size:280% 100%;background-position:120% 0;opacity:0}.glint.on{opacity:1;animation:g 1.6s .1s cubic-bezier(.5,0,.2,1) forwards}@keyframes g{to{background-position:-20% 0}}' +
        '.pulse{opacity:0;transition:opacity 1s;filter:drop-shadow(0 0 5px #19e07a)}:host(.live) .pulse{opacity:1}' +
        '.pulse .m{position:absolute;inset:0;clip-path:inset(54% 0 24% 51%);background:linear-gradient(90deg,transparent,#8dffc0 42%,#12e070 50%,#8dffc0 58%,transparent) no-repeat;background-size:24% 100%;animation:p 5.5s linear infinite}@keyframes p{0%{background-position:-5% 0}42%,100%{background-position:105% 0}}' +
        '.hx{height:64px}:host([nohelix]) .hx{display:none}' +
        '.cap{display:none;text-align:center;color:var(--mut);font:11px/1 system-ui,sans-serif;letter-spacing:.24em;text-transform:uppercase;margin-top:6px}:host([mode=loader]) .cap{display:block}' +
        '.n{font-size:30px;font-weight:200;letter-spacing:.02em;color:var(--ac);font-variant-numeric:tabular-nums}.u{font-size:13px;color:var(--ac);margin-right:14px}' +
        'canvas{position:absolute;left:-60px;top:-60px;width:calc(100% + 120px);height:calc(100% + 120px);pointer-events:none}' +
        '@media(prefers-reduced-motion:reduce){.pulse .m{animation:none}.glint.on{animation:none}}';
}

class MCLogo extends HTMLElement {
    static get observedAttributes() {
        return ['progress'];
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (name !== 'progress' || newValue == null) return;
        this.progress = newValue;
        if (this._built) this._wake();
    }

    set progress(v) {
        this._tp = Math.max(0, Math.min(1, +v));
    }

    get progress() {
        return this.dp;
    }

    connectedCallback() {
        const root = this.shadowRoot || this.attachShadow({ mode: 'open' });

        // Re-attached (a wire:navigate swap, a Livewire morph, or the
        // same node moved): the shadow tree survives, so just make sure
        // we can still be woken and resume the frame loop.
        //
        // The observer MUST be re-established here. disconnectedCallback
        // disposes it, and a parked element with no observer can never
        // wake — it would sit at the stylesheet's hidden mask position
        // (-9999px) and render as a blank box. That is exactly what
        // happens to a logo inside a wire:loading overlay: it mounts
        // display:none, parks, then gets morphed when the action fires.
        this._observe();
        if (root.querySelector('.box')) {
            this._wake();
            return;
        }

        const me = this;
        root.innerHTML = '<style>' + shadowCss(this._src()) + '</style>'
            + '<div class="box">'
            + '<div class="logo">'
            + '<div class="ghost"></div>'
            + '<div class="base"></div>'
            + '<div class="m glint"></div>'
            + '<div class="pulse"><div class="m"></div></div>'
            + '</div>'
            + '<div class="hx"></div>'
            + '<div class="cap"><span class="n">0</span><span class="u">%</span><span class="l">Loading</span></div>'
            + '<canvas></canvas>'
            + '</div>';

        const q = (sel) => root.querySelector(sel);
        this.lg = q('.logo');
        this.bs = q('.base');
        this.gl = q('.glint');
        this.hxEl = q('.hx');
        this.nEl = q('.n');
        this.lEl = q('.l');
        this.cv = q('canvas');
        this.cx = this.cv.getContext('2d');

        this._built = true;
        this._degraded = false;
        this.P = [];
        this.dp = 0;
        this._auto = 0;
        this._fin = false;
        this._done = false;
        this._idle = false;
        this._t0 = performance.now();
        if (this._tp === undefined) this._tp = null;

        // A rebuilt shadow tree has to re-apply the finished state,
        // otherwise a re-attached logo loses its glint and glow.
        if (this._wasDone) {
            this.gl.classList.add('on');
            this.classList.add('live');
        }

        loadInk(this._src(), function (data) { me.ink = data; });

        this._wake();
    }

    /**
     * (Re)arm the layout observer. Called on EVERY connect, including
     * the re-attach path — an element that measures zero (a
     * wire:loading overlay, a collapsed panel) parks its frame loop, and
     * this observer is the only thing that can wake it again once it is
     * actually laid out.
     */
    _observe() {
        if (typeof ResizeObserver !== 'function') return;
        if (this._ro) return;
        this._ro = new ResizeObserver(() => this._wake());
        this._ro.observe(this);
    }

    disconnectedCallback() {
        this._sleep();
        if (this._ro) {
            this._ro.disconnect();
            this._ro = null;
        }
    }

    _src() {
        return this.getAttribute('src') || MC_LOGO_DEFAULT_SRC;
    }

    /** Start the animation frame loop (no-op if already running). */
    _wake() {
        if (this.raf) return;
        this._idle = false;
        this._pt = 0;
        const frame = (t) => {
            this._tick(t);
            this.raf = this._idle ? 0 : requestAnimationFrame(frame);
        };
        this.raf = requestAnimationFrame(frame);
    }

    /** Stop the animation frame loop. */
    _sleep() {
        if (!this.raf) return;
        cancelAnimationFrame(this.raf);
        this.raf = 0;
    }

    /** Mark the run complete and run the glint + completion burst. */
    finish() {
        this._fin = true;
        if (this._tp != null) this._tp = 1;
        this._wake();
    }

    replay() {
        this._t0 = performance.now();
        this._done = false;
        this._wasDone = false;
        this.P = [];
        if (this.gl) this.gl.classList.remove('on');
        this.classList.remove('live');
        if (this.gl) void this.gl.offsetWidth;
        this.dp = 0;
        this._auto = 0;
        this._fin = false;
        this._tp = null;
        this._wake();
    }

    /** Fade out, then remove unless `keep` is set. */
    hide() {
        if (this._h) return;
        this._h = 1;
        const me = this;
        this.dispatchEvent(new CustomEvent('hiding'));
        this.classList.add('out');
        setTimeout(function () {
            me.dispatchEvent(new CustomEvent('hidden'));
            if (! me.hasAttribute('keep')) me.remove();
        }, 750);
    }

    /** Spawn one particle at logo-relative coordinates. */
    _spawn(x, y) {
        const dark = this.classList.contains('d');
        const palette = dark ? ['143,180,255', '130,245,175'] : ['47,111,228', '18,169,90'];
        const r = Math.random();
        this.P.push({
            x: x, y: y,
            vx: (Math.random() - .5) * 10,
            vy: -(7 + Math.random() * 16),
            R: (3 + Math.random() * 4.5) * (this._sc || 1),
            t: 0,
            life: 1.8 + Math.random() * 1.6,
            c: palette[Math.random() * 2 | 0],
            ph: Math.random() * 6,
            rot: Math.random() * 6.3,
            sp: (Math.random() - .5) * 1.2,
            k: r < .4 ? 0 : r < .75 ? 1 : 2,
        });
    }

    /**
     * Art could not be read: paint the plain logo and stop. Without
     * this the base layer would sit at opacity 0 forever, because only
     * _tick() reveals it.
     */
    _degradeToStatic() {
        this._degraded = true;
        this.bs.style.opacity = '1';
        this.bs.style.webkitMaskPosition = '0 0';
        this.bs.style.maskPosition = '0 0';
        this.bs.style.filter = 'none';
        this.gl.classList.add('on');
        this.classList.add('live');
        this._done = true;
        this._wasDone = true;
        // Park the frame loop rather than calling _sleep(): we are
        // still inside the current tick, and the loop re-arms itself
        // from _idle after this returns.
        this._idle = true;
    }

    _tick(now) {
        const dt = Math.min(.05, (now - (this._pt || now)) / 1000);
        this._pt = now;

        const rm = prefersReducedMotion();
        this.classList.toggle('d', isDarkMode(this));

        if (!this.ink) {
            if (this._degraded) return;
            // Image still loading on the very first frame — wait.
            if (inkCache.has(this._src())) this._degradeToStatic();
            return;
        }

        const isLoader = this.getAttribute('mode') === 'loader';
        const lr = this.lg.getBoundingClientRect();
        const W = lr.width;
        const H = lr.height;
        if (!W) {
            // Not laid out (display:none ancestor). Park the loop; the
            // ResizeObserver in connectedCallback wakes us.
            this._idle = true;
            return;
        }
        this._sc = Math.max(.45, Math.min(1, W / 700));

        let p;
        let tp = 0;
        if (isLoader) {
            if (this._tp >= 1) this._fin = true;
            tp = this._tp;
            if (tp == null) {
                this._auto += ((this._fin ? 1 : .92) - this._auto) * dt * (this._fin ? 5 : .4);
                tp = this._auto;
            }
            this.dp += (tp - this.dp) * Math.min(1, dt * 6);
            p = this.dp;
            if (this._fin && p > .995) p = 1;
        } else {
            p = easeOutCubic(rm ? 1 : Math.max(0, Math.min(1, (now - this._t0) / 2600)));
        }

        // Reveal sweep: the mask window travels right-to-left.
        const ex = W * (-.2 + 1.4 * p);
        const maskPos = (ex - 2 * W) + 'px 0';
        const ik = this.ink;
        this.bs.style.webkitMaskPosition = maskPos;
        this.bs.style.maskPosition = maskPos;
        this.bs.style.opacity = Math.min(1, p * 6);
        this.bs.style.filter = (this.classList.contains('d') ? 'brightness(1.35) saturate(1.1) ' : '')
            + (!isLoader && p < 1 ? 'blur(' + ((1 - p) * 5).toFixed(1) + 'px)' : '')
            || 'none';

        const cr = this.cv.getBoundingClientRect();
        const ox = lr.left - cr.left;
        const oy = lr.top - cr.top;

        if (isLoader) {
            this.nEl.textContent = Math.round(p * 100);
            this.lEl.textContent = p >= 1 ? 'Ready' : 'Loading';
        }

        // Trail particles along the reveal edge while it travels.
        if (!rm && p > 0 && p < 1) {
            const n = Math.round((isLoader ? (tp - this.dp > .004 ? 1 : 0) : 2) * this._sc);
            for (let k = 0; k < n; k++) {
                const ci = Math.max(0, Math.min(ik.gw - 1, Math.round((ex / W + (Math.random() - .5) * .12) * ik.gw)));
                const col = ik.cols[ci];
                if (col.length) this._spawn(ox + ci / ik.gw * W, oy + col[Math.random() * col.length | 0] / ik.gh * H);
            }
        }

        if (p >= 1 && !this._done) {
            this._done = true;
            this._wasDone = true;
            this.gl.classList.add('on');
            this.classList.add('live');
            if (isLoader && !rm) {
                for (let b = 0; b < 26; b++) {
                    const cj = Math.random() * ik.gw | 0;
                    const cc = ik.cols[cj];
                    if (cc.length) this._spawn(ox + cj / ik.gw * W, oy + cc[Math.random() * cc.length | 0] / ik.gh * H);
                }
            }
            this.dispatchEvent(new CustomEvent('done'));
            if (isLoader && this.hasAttribute('fullscreen') && !this.hasAttribute('keep')) {
                setTimeout(() => this.hide(), 1300);
            }
        }

        const dpr = Math.min(2, devicePixelRatio || 1);
        const cw = Math.round(cr.width * dpr);
        const ch = Math.round(cr.height * dpr);
        if (this.cv.width !== cw || this.cv.height !== ch) {
            this.cv.width = cw;
            this.cv.height = ch;
        }
        const cx = this.cx;
        cx.setTransform(dpr, 0, 0, dpr, 0, 0);
        cx.clearRect(0, 0, cr.width, cr.height);
        cx.shadowBlur = 0;

        // Idle helix ribbon (skipped with `nohelix`).
        if (!this.hasAttribute('nohelix')) {
            const hr = this.hxEl.getBoundingClientRect();
            const h0 = hr.left - cr.left;
            const hy = hr.top - cr.top + hr.height / 2;
            const A = hr.height * .36;
            const N = Math.max(20, Math.round(hr.width / 8));
            const t = rm ? 0 : now / 1000;
            const cB = this.classList.contains('d') ? '128,170,255' : '47,111,228';
            const cG = this.classList.contains('d') ? '90,240,160' : '18,169,90';
            for (let i = 0; i < N; i++) {
                const f = (i + .5) / N;
                const x = h0 + f * hr.width;
                const a = f * 13.8 + t * 1.2;
                const sn = Math.sin(a);
                const z = Math.cos(a);
                const lit = p >= 1 ? .4 + .6 * Math.max(0, Math.sin(f * 7 - t * 1.6)) : (f <= p + .01 ? 1 : .14);
                cx.strokeStyle = 'rgba(' + cB + ',' + (.28 * lit * (.25 + .75 * Math.abs(sn))) + ')';
                cx.lineWidth = 1;
                cx.beginPath();
                cx.moveTo(x, hy + A * sn);
                cx.lineTo(x, hy - A * sn);
                cx.stroke();
                cx.fillStyle = 'rgba(' + cB + ',' + (.25 + .7 * lit * (.5 + .5 * z)) + ')';
                cx.beginPath();
                cx.arc(x, hy + A * sn, 1.3 + 1.3 * (.5 + .5 * z), 0, 6.3);
                cx.fill();
                cx.fillStyle = 'rgba(' + cG + ',' + (.25 + .7 * lit * (.5 - .5 * z)) + ')';
                cx.beginPath();
                cx.arc(x, hy - A * sn, 1.3 + 1.3 * (.5 - .5 * z), 0, 6.3);
                cx.fill();
            }
        }

        for (let i = this.P.length - 1; i >= 0; i--) {
            const q = this.P[i];
            q.t += dt;
            if (q.t > q.life) {
                this.P.splice(i, 1);
                continue;
            }
            q.x += (q.vx + Math.sin(q.t * 2 + q.ph) * 7) * dt;
            q.y += q.vy * dt;
            q.rot += q.sp * dt;
            const al = Math.sin(Math.PI * q.t / q.life) * .85;
            const R = q.R * (1 + .08 * Math.sin(q.t * 4 + q.ph));
            const col = 'rgba(' + q.c + ',';
            cx.lineWidth = 1.1;
            cx.strokeStyle = col + al + ')';
            cx.fillStyle = col + al * .14 + ')';
            cx.beginPath();
            if (q.k === 0) {
                cx.arc(q.x, q.y, R, 0, 6.3);
                cx.fill();
                cx.stroke();
                cx.fillStyle = col + al * .7 + ')';
                cx.beginPath();
                cx.arc(q.x + R * .22, q.y - R * .15, R * .3, 0, 6.3);
                cx.fill();
            } else if (q.k === 1) {
                for (let j = 0; j < 6; j++) {
                    const ha = q.rot + j * 1.0472;
                    const hx = q.x + R * Math.cos(ha);
                    const hy2 = q.y + R * Math.sin(ha);
                    if (j) cx.lineTo(hx, hy2); else cx.moveTo(hx, hy2);
                }
                cx.closePath();
                cx.fill();
                cx.stroke();
                cx.beginPath();
                cx.arc(q.x, q.y, R * .5, 0, 6.3);
                cx.stroke();
            } else {
                const ax = q.x + R * 1.5 * Math.cos(q.rot);
                const ay = q.y + R * 1.5 * Math.sin(q.rot);
                const bx = q.x - R * 1.5 * Math.cos(q.rot + .9);
                const by = q.y - R * 1.5 * Math.sin(q.rot + .9);
                cx.moveTo(ax, ay);
                cx.lineTo(q.x, q.y);
                cx.lineTo(bx, by);
                cx.stroke();
                cx.fillStyle = col + al * .85 + ')';
                [[ax, ay, .45], [q.x, q.y, .6], [bx, by, .45]].forEach((o) => {
                    cx.beginPath();
                    cx.arc(o[0], o[1], R * o[2], 0, 6.3);
                    cx.fill();
                });
            }
        }
        cx.globalAlpha = 1;

        // Nothing left to draw: a finished nohelix logo with no live
        // particles is static, so stop burning frames on it.
        this._idle = this._done && this.hasAttribute('nohelix') && this.P.length === 0;
    }
}

if (!customElements.get('mc-logo')) {
    customElements.define('mc-logo', MCLogo);
}

export { MCLogo };
