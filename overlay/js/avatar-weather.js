/**
 * Canvas weather layer for the Avatar PNG (overlay + dashboard preview).
 * Sit the canvas on top of the character image; particles stay in that box.
 */
(function (root) {
    'use strict';

    var ALLOWED = { winter: 1, spring: 1, summer: 1, autumn: 1, rain: 1 };

    var COUNTS = {
        winter: [8, 12, 16, 22, 28, 36, 44, 54, 64, 76],
        spring: [6, 10, 14, 18, 24, 30, 38, 46, 56, 66],
        summer: [6, 9, 13, 17, 22, 28, 34, 42, 50, 60],
        autumn: [6, 10, 14, 18, 24, 30, 38, 46, 56, 66],
        rain: [20, 30, 40, 52, 64, 78, 92, 108, 124, 140]
    };

    function clamp(n, lo, hi) {
        n = Number(n);
        if (!isFinite(n)) return lo;
        return Math.max(lo, Math.min(hi, n));
    }

    function rnd(a, b) {
        return a + Math.random() * (b - a);
    }

    function pick(arr) {
        return arr[(Math.random() * arr.length) | 0];
    }

    function SpecterAvatarWeather(canvas) {
        if (!canvas || !canvas.getContext) {
            throw new Error('SpecterAvatarWeather needs a canvas');
        }
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d', { alpha: true });
        this.kind = 'off';
        this.intensity = 5;
        this.particles = [];
        this.running = false;
        this.raf = 0;
        this.last = 0;
        this.w = 0;
        this.h = 0;
        this.dpr = 1;
        this._loop = this._loop.bind(this);
        this._ro = null;
        var parent = canvas.parentElement;
        if (parent && typeof ResizeObserver !== 'undefined') {
            this._ro = new ResizeObserver(function () {
                this.resize();
            }.bind(this));
            this._ro.observe(parent);
        }
        this.resize();
    }

    SpecterAvatarWeather.prototype.resize = function () {
        var canvas = this.canvas;
        var parent = canvas.parentElement;
        var cssW = Math.max(1, (parent && parent.clientWidth) || canvas.clientWidth || 1);
        var cssH = Math.max(1, (parent && parent.clientHeight) || canvas.clientHeight || 1);
        var dpr = Math.min(2, window.devicePixelRatio || 1);
        if (this.w === cssW && this.h === cssH && this.dpr === dpr) {
            return;
        }
        this.w = cssW;
        this.h = cssH;
        this.dpr = dpr;
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
        canvas.style.width = cssW + 'px';
        canvas.style.height = cssH + 'px';
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        if (this.kind !== 'off') {
            this._rebuild();
        }
    };

    SpecterAvatarWeather.prototype.set = function (opts) {
        opts = opts || {};
        var kind = String(opts.kind || 'off');
        if (!ALLOWED[kind]) {
            kind = 'off';
        }
        var intensity = clamp(opts.intensity == null ? this.intensity : opts.intensity, 1, 10) | 0;
        var kindChanged = kind !== this.kind;
        var intChanged = intensity !== this.intensity;
        this.kind = kind;
        this.intensity = intensity;
        if (kind === 'off') {
            this._stop();
            this._clear();
            return;
        }
        if (kindChanged || intChanged || this.particles.length === 0) {
            this._rebuild();
        }
        this._start();
    };

    SpecterAvatarWeather.prototype.destroy = function () {
        this._stop();
        if (this._ro) {
            this._ro.disconnect();
            this._ro = null;
        }
        this.particles = [];
        this._clear();
    };

    SpecterAvatarWeather.prototype._clear = function () {
        this.ctx.clearRect(0, 0, this.w, this.h);
    };

    SpecterAvatarWeather.prototype._count = function () {
        var table = COUNTS[this.kind];
        if (!table) {
            return 0;
        }
        return table[clamp(this.intensity, 1, 10) - 1];
    };

    SpecterAvatarWeather.prototype._rebuild = function () {
        var n = this._count();
        var next = [];
        var i;
        for (i = 0; i < n; i++) {
            next.push(this._spawn(true));
        }
        this.particles = next;
    };

    SpecterAvatarWeather.prototype._spawn = function (anywhere) {
        var w = this.w;
        var h = this.h;
        var kind = this.kind;
        var y = anywhere ? rnd(-h * 0.15, h) : rnd(-18, -4);
        if (kind === 'winter') {
            return {
                x: rnd(0, w),
                y: y,
                r: rnd(1.4, 3.6),
                vy: rnd(12, 28),
                amp: rnd(6, 16),
                freq: rnd(0.6, 1.4),
                phase: rnd(0, Math.PI * 2),
                rot: rnd(0, Math.PI * 2),
                vr: rnd(-1.2, 1.2),
                alpha: rnd(0.55, 1)
            };
        }
        if (kind === 'spring') {
            return {
                x: rnd(0, w),
                y: y,
                r: rnd(3, 6),
                vy: rnd(10, 22),
                amp: rnd(10, 24),
                freq: rnd(0.5, 1.2),
                phase: rnd(0, Math.PI * 2),
                rot: rnd(0, Math.PI * 2),
                vr: rnd(-2, 2),
                color: pick(['#ffb7c5', '#ff9eb5', '#ffd1dc', '#f8a4c8']),
                alpha: rnd(0.7, 1)
            };
        }
        if (kind === 'summer') {
            return {
                x: rnd(0, w),
                y: anywhere ? rnd(0, h) : rnd(0, h),
                r: rnd(1.2, 2.4),
                vx: rnd(-12, 12),
                vy: rnd(-10, 10),
                phase: rnd(0, Math.PI * 2),
                freq: rnd(1.5, 3.2),
                color: pick(['#ffe566', '#b8ff80', '#ffcc00', '#9dff7a']),
                alpha: rnd(0.4, 0.9)
            };
        }
        if (kind === 'autumn') {
            return {
                x: rnd(0, w),
                y: y,
                r: rnd(4, 7.5),
                vy: rnd(14, 28),
                amp: rnd(12, 28),
                freq: rnd(0.7, 1.6),
                phase: rnd(0, Math.PI * 2),
                rot: rnd(0, Math.PI * 2),
                vr: rnd(-3, 3),
                color: pick(['#e67e22', '#d35400', '#c0392b', '#e74c3c', '#f39c12']),
                alpha: rnd(0.75, 1)
            };
        }
        return {
            x: rnd(0, w),
            y: y,
            len: rnd(8, 16),
            vy: rnd(280, 420),
            vx: rnd(-40, -18),
            lineW: rnd(1, 1.8),
            alpha: rnd(0.35, 0.8)
        };
    };

    SpecterAvatarWeather.prototype._recycle = function (p) {
        var fresh = this._spawn(false);
        var k;
        for (k in fresh) {
            if (Object.prototype.hasOwnProperty.call(fresh, k)) {
                p[k] = fresh[k];
            }
        }
    };

    SpecterAvatarWeather.prototype._start = function () {
        if (this.running) {
            return;
        }
        this.running = true;
        this.last = 0;
        this.raf = requestAnimationFrame(this._loop);
    };

    SpecterAvatarWeather.prototype._stop = function () {
        this.running = false;
        if (this.raf) {
            cancelAnimationFrame(this.raf);
            this.raf = 0;
        }
    };

    SpecterAvatarWeather.prototype._loop = function (ts) {
        if (!this.running) {
            return;
        }
        if (!this.last) {
            this.last = ts;
        }
        var dt = Math.min(0.05, (ts - this.last) / 1000);
        this.last = ts;
        this._tick(dt);
        this.raf = requestAnimationFrame(this._loop);
    };

    SpecterAvatarWeather.prototype._tick = function (dt) {
        var ctx = this.ctx;
        var w = this.w;
        var h = this.h;
        var kind = this.kind;
        var list = this.particles;
        var i;
        var p;
        var x;
        ctx.clearRect(0, 0, w, h);
        if (kind === 'off' || !list.length) {
            return;
        }

        if (kind === 'rain') {
            ctx.strokeStyle = 'rgba(190, 220, 255, 0.85)';
            ctx.lineCap = 'round';
            for (i = 0; i < list.length; i++) {
                p = list[i];
                p.x += p.vx * dt;
                p.y += p.vy * dt;
                if (p.y > h + 20 || p.x < -20) {
                    this._recycle(p);
                }
                ctx.globalAlpha = p.alpha;
                ctx.lineWidth = p.lineW;
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(p.x + p.vx * 0.03, p.y + p.len);
                ctx.stroke();
            }
            ctx.globalAlpha = 1;
            return;
        }

        if (kind === 'summer') {
            ctx.save();
            ctx.globalCompositeOperation = 'lighter';
            for (i = 0; i < list.length; i++) {
                p = list[i];
                p.phase += p.freq * dt;
                p.x += p.vx * dt + Math.sin(p.phase) * 18 * dt;
                p.y += p.vy * dt + Math.cos(p.phase * 0.8) * 14 * dt;
                if (p.x < -8) p.x = w + 8;
                if (p.x > w + 8) p.x = -8;
                if (p.y < -8) p.y = h + 8;
                if (p.y > h + 8) p.y = -8;
                var glow = 0.45 + 0.55 * (0.5 + 0.5 * Math.sin(p.phase * 2));
                ctx.globalAlpha = p.alpha * glow;
                ctx.fillStyle = p.color;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r * (0.8 + glow * 0.5), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
            return;
        }

        for (i = 0; i < list.length; i++) {
            p = list[i];
            p.phase += p.freq * dt;
            p.rot += p.vr * dt;
            p.y += p.vy * dt;
            x = p.x + Math.sin(p.phase) * p.amp;
            if (p.y > h + 16) {
                this._recycle(p);
            }
            ctx.save();
            ctx.translate(x, p.y);
            ctx.rotate(p.rot);
            ctx.globalAlpha = p.alpha;
            if (kind === 'winter') {
                ctx.fillStyle = '#ffffff';
                ctx.beginPath();
                ctx.arc(0, 0, p.r, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalAlpha = p.alpha * 0.35;
                ctx.beginPath();
                ctx.arc(0, 0, p.r * 1.8, 0, Math.PI * 2);
                ctx.fill();
            } else if (kind === 'spring') {
                ctx.fillStyle = p.color;
                ctx.beginPath();
                if (ctx.ellipse) {
                    ctx.ellipse(0, 0, p.r, p.r * 0.55, 0, 0, Math.PI * 2);
                } else {
                    ctx.arc(0, 0, p.r, 0, Math.PI * 2);
                }
                ctx.fill();
            } else {
                ctx.fillStyle = p.color;
                ctx.beginPath();
                if (ctx.ellipse) {
                    ctx.ellipse(0, 0, p.r, p.r * 0.45, 0.4, 0, Math.PI * 2);
                } else {
                    ctx.arc(0, 0, p.r, 0, Math.PI * 2);
                }
                ctx.fill();
            }
            ctx.restore();
        }
        ctx.globalAlpha = 1;
    };

    root.SpecterAvatarWeather = SpecterAvatarWeather;
})(typeof window !== 'undefined' ? window : this);
