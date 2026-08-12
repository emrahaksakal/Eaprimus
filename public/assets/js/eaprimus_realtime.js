// public/assets/js/eaprimus_realtime.js
// Eaprimus Real-Time Engine (Live SSE, Sub-Second Typing Indicator, Web Push & 10 Audio Tones)

(function () {
    'use strict';

    window.EaprimusRealtime = {
        isMuted: (function() { try { return localStorage.getItem('eaprimus_muted') === 'true'; } catch(e) { return false; } })(),
        soundChoice: (function() { try { return localStorage.getItem('eaprimus_sound') || 'chime'; } catch(e) { return 'chime'; } })(),
        lastReplyId: 0,
        lastTicketId: 0,
        lastGlobalReplyId: 0,
        lastRatingId: 0,
        activeTicketId: 0,
        streamInterval: null,
        audioUnlocked: false,
        lastTypeSentTime: 0,
        originalTitle: document.title,
        titleFlashInterval: null,
        isFlashing: false,
        previousUnreadCount: 0,
        _pendingReload: false,

        chimeDataUri: 'data:audio/wav;base64,UklGRtAmAABXQVZFZm10EBAAAAABAAEARKwAAESsAAABAAgAZGF0YaggAAAPBgT2jPZ89oL2nvbP9hT3bvfb91r46/iL+Tr69vq9+478Z/1F/if/CgDuANABrgKFA1UEGgXUBYEGHweuByoIlAjrCC4JXAl0CXgJZgk/CQMJsghPCNgHUAe3Bg8GWQWYBMwD+AIcAj0BWgB4/5b+t/3e/Az8QvuE+tH5LfmZ+BX4o/dD9/j2wfaf9pL2mva49ur2MfeM9/r3evgL+az5W/oW+937rPyE/WH+Qf8iAAQB4wG+ApMDYAQjBdoFhAYfB6oHIwiKCN4IHglKCWAJYQlNCSQJ5giVCDAIuAcvB5YG7gU5BXgErQPaAgACIgFCAGH/gv6m/c/8//s5+336zvkt+Zv4Gvir90/3BvfS9rL2qPay9tL2BvdO96v3Gfia+Cv5zPl7+jb7/PvK/KD9fP5b/zoAGQH2Ac8CoQNrBCoF3wWFBh0HpQccCIAI0QgPCTcJSwlKCTQJCQnKCHcIEQiZBw8HdgbOBRkFWQSPA70C5QEIASkAS/9u/pT9wPzz+zD7d/rL+S35nvgg+LT3WvcV9+P2xva+9sr27PYh92v3yfc4+Lr4S/ns+Zv6Vfsa/Oj8vf2X/nT/UQAuAQkC3wKuA3UEMgXjBYcGHAehBxUIdgjECP8IJQk3CTMJGwnvCK4IWgjzB3oH8AZWBq4F+gQ6BHEDoALJAe4AEgA2/1v+g/2y/Oj7J/tx+sj5Lfmh+Cb4vfdm9yP39PbZ9tP24vYF9z33iPfn91f42fhr+Qz6uvp0+zj8Bf3Z/bH+jP9nAEIBGwLuArsDfwQ5BecFiAYaB5wHDQhsCLcI7wgTCSIJHAkCCdQIkgg8CNQHWgfQBjYGjwXbBBwEUwOEAq4B1QD7/yD/SP5z/aT83Pse+2z6xfkt+aT4LPjG93L3MvcF9+326fb69h/3WPel9wT4dvj4+Iv5K/rZ+pP7Vvwi/fT9y/6k/30AVgEsAv0CxwOJBEAF6wWJBhgHlwcFCGEIqgjfCAAJDQkGCeoIugh2CB8Itgc8B7EGFwZwBbwE/gM2A2gClAG8AOT/DP81/mP9lvzS+xb7ZvrD+S75qPgz+M/3fvdB9xf3Aff/9hL3Ofdz98H3IviU+Bf5qvlL+vj6sft0/D79D/7k/rz/kwBqAT0CDAPTA5IERgXvBYoGFgeSB/0HVgicCM8I7gj4CO8I0QifCFoIAgiYBx0Hkgb4BVEFnQTgAxkDTAJ6AaQAzv/3/iP+U/2J/Mf7D/ti+sH5L/ms+Dr42feL91D3KPcU9xX3KvdS94733vc/+LL4NvnJ+Wn6F/vP+5H8Wv0q/v3+0/+oAH0BTgIaA98DmwRMBfIFigYTB4wH9QdLCI4IvwjbCOMI2Ai4CIUIPgjlB3oH/wZzBtkFMgV/BMID/QIxAmABjAC4/+P+Ef5E/X38vfsI+136wPkw+bD4Qfjj95f3X/c69yj3K/dB92z3qff691340PhU+ef5iPo1++37rvx2/UT+Fv/q/70AjwFeAigD6gOjBFIF9QWKBhAHhwfsB0AIgAiuCMgIzgjBCJ8IawgjCMkHXQfhBlUGuwUUBWEEpQPhAhYCRwF0AKL/0P4A/jX9cPy0+wH7Wfq/+TL5tfhI+O33pPdu90v3PPdB91n3hffE9xb4evju+HL5Bvqm+lP7CvzK/JH9Xv4u/wAA0gCiAW4CNQP1A6sEVwX3BYkGDQeBB+MHNAhyCJ4ItQi6CKoIhwhQCAcIrAdAB8MGNwadBfYERASJA8UC/AEuAV0Ajf+8/u/9Jv1k/Kr7+vpW+r75NPm6+FD49/ex9333XfdQ91f3cfef99/3MviX+Az5kPkk+sT6cfsn/Ob8rP14/kb/FgDmALQBfgJCA/8DswRcBfkFiQYKB3oH2gcpCGQIjQijCKUIkwhuCDYI7AeQByIHpQYZBn8F2AQnBGwDqgLiARUBRgB4/6r+3/0Y/Vn8ofv0+lL6vfk2+b/4V/gC+L73jfdv92T3bfeJ97j3+vdO+LP4Kfmu+UL64vqO+0T8Av3H/ZH+Xv8rAPkAxQGNAk8DCQS6BGEF+wWIBgYHdAfRBx0IVgh8CJAIkAh8CFYIHAjRB3MHBQeIBvsFYQW7BAoEUAOPAsgB/QAwAGP/l/7P/Qv9TvyZ++76T/q9+Tn5xPhf+Az4y/ec94D3ePeD96H30fcU+Gr40PhG+cz5X/r/+qv7YPwd/eH9qf51/0AADAHWAZsCWwMTBMEEZQX9BYcGAgdtB8gHEQhICGwIfQh7CGUIPQgCCLYHVwfpBmoG3gVEBZ4E7gM1A3QCrwHlABoAT/+F/r/9/fxD/JH76fpM+r35O/nJ+Gf4F/jY96z3kveM95n3uPfq9y/4hfjs+GP56fl8+hz7x/t8/Dj9+/3C/oz/VQAfAecBqgJnAxwEyARpBf4FhQb+BmYHvgcFCDkIWwhqCGYITwglCOkHmwc8B8wGTQbABScFgQTSAxkDWgKWAc4ABAA8/3T+sP3w/Dj8ifvk+kr6vfk++c/4cPgi+Ob3vPek96D3rvfQ9wT4Sfig+Aj5gPkG+pn6Ofvk+5f8U/0U/tr+ov9qADIB9wG4AnIDJQTOBG0F/wWDBvkGXwe0B/gHKghKCFcIUQg4CAwIzweAByAHsAYxBqQFCgVlBLYD/gJAAn0BtwDw/yj/Y/6h/eT8LvyB+9/6SPq++UL51fh4+C348/fL97b3tPfE9+f3Hfhk+Lz4JPmc+SP6tvpW+wD8s/xt/S3+8f64/34ARAEHAsUCfQMuBNQEcAX/BYEG9AZYB6sH7AccCDkIRAg8CCEI9Ae1B2UHBAeUBhQGhwXtBEkEmgPkAicCZQGgANv/Ff9S/pL92Pwl/Hr72vpG+r75Rfnb+IH4OPgB+Nv3yffI99r3//c1+H741/hA+bj5P/rT+nL7G/zN/If9Rv4I/83/kgBWARYC0gKIAzYE2gRzBQAGfwbvBlAHoAffBw0IKAgwCCcICgjcB5wHSwfpBngG+AVrBdEELQR/A8oCDgJNAYoAx/8D/0H+hP3M/Bv8dPvW+kT6v/lJ+eH4ivhE+A/46/fb99z38PcW+E74mPjy+Fz51flb+u/6jvs3/Oj8oP1e/h//4v+lAGcBJQLfApMDPgTfBHYFAAZ8BuoGSAeWB9MH/gcXCB0IEgj0B8QHggcwB84GXAbcBU4FtQQRBGQDsAL1ATYBdACz//H+Mf52/cD8Evxt+9L6Q/rB+U356PiT+E/4HPj89+338PcG+C74Z/ix+Az5d/nw+Xf6C/up+1L8Av25/Xb+Nv/3/7gAeAE0AuwCnQNFBOQEeAUABnoG5QZAB4wHxgfvBwUICgj9B90HrAdpBxYHswZABsAFMwWaBPYDSgOWAt0BHwFfAJ//3/4i/mn9tfwK/Gf7z/pC+sP5Ufnv+Jz4W/gq+Az4//cE+Bz4RfiA+Mv4J/mS+Qz6k/om+8X7bPwc/dL9jf5M/wsAygCIAUMC+AKmA0wE6QR6Bf8FdgbfBjgHgQe5B+AH9Af3B+gHxgeUB1AH/AaYBiUGpAUXBX4E2wMwA30CxQEIAUoAjP/O/hL+XP2r/AH8YfvL+kH6xPlW+fb4pvhn+Dn4HPgR+Bn4Mvhc+Jj45fhB+a35J/qv+kL74PuH/Db96/2l/mH/HwDdAJgBUQIEA7ADUwTtBHwF/gVzBtkGMAd2B6wH0AfjB+QH0wewB3wHNwfiBn0GCgaJBfwEYwTBAxYDZAKtAfIANQB5/73+A/5P/aD8+ftc+8j6QfrH+Vr5/fiw+HP4R/gs+CT4LfhH+HT4sfj++Fz5yPlC+sr6Xfv6+6H8T/0D/rv+d/8yAO4AqAFeAg8DuQNaBPEEfgX9BXAG0wYnB2wHnwfBB9EH0Ae+B5kHZAceB8gGYwbvBW4F4QRIBKYD/AJMApYB3AAhAGb/rP71/UL9lvzy+1b7xvpB+sn5X/kE+bn4f/hV+D34NvhB+F34i/jJ+Bj5dvnj+V365fp4+xX8uvxo/Rv+0v6M/0YAAAG4AWwCGgPBA2AE9QR/BfwFbAbNBh8HYAeSB7EHwAe9B6kHgwdMBwUHrgZIBtQFUwXGBC4EjQPjAjMCfwHHAA0AVP+c/uf9Nv2M/Or7UvvD+kH6zPlk+Qz5w/iL+GT4TfhI+FX4c/ii+OH4MfmQ+f35ePr/+pL7L/zU/ID9Mv7o/qD/WQARAccBeQIlA8oDZgT5BIAF+wVoBscGFgdVB4QHogevB6oHlAdtBzUH7QaVBi4GugU4BasEFARzA8oCHAJoAbEA+v9C/4z+2f0r/YP84/tN+8H6QfrP+Wr5FPnN+Jf4cvhe+Fv4afiI+Ln4+fhK+an5F/qT+hr7rPtJ/O38mP1J/v7+tf9rACIB1gGFAi8D0gNsBPwEgQX5BWQGwAYNB0oHdweSB50Hlwd/B1YHHQfUBnwGFAagBR4FkQT6A1oDsgIEAlIBnADn/zH/fP7L/R/9evzd+0n7v/pC+tL5b/kc+dj4pPiB+G74bfh9+J740PgR+WP5w/kx+q36NPvG+2L8Bv2w/WD+E//J/30AMgHkAZECOQPaA3EE/wSBBfcFXwa5BgQHPwdpB4MHjAeDB2oHQAcGB7wGYgb7BYYFBAV3BOADQQOaAu0BPAGIANT/IP9t/r79FP1x/Nb7Rfu++kP61fl1+ST54viw+I/4f/iA+JH4tPjm+Cn5e/nc+Uv6x/pO++D7e/we/cj9dv4o/9z/jwBCAfIBnQJDA+EDdgQBBYEF9QVaBrIG+gYzB1sHcwd6B3AHVQcqB+4GowZJBuEFbAXqBF0ExwMoA4IC1gEmAXQAwf8P/17+sf0K/Wn80PtB+736RPrZ+Xv5LPnt+L34nviQ+JL4pfjJ+P34QfmU+fb5Zfrh+mpl6gL4U/uX+tD7V/w3/db9j/5S/wEA0gCCAV0C8QKaA0wE3wR+BfoFcwbaBjgHgQe5B+AH9Af3B+gHxgeUB1AH/AaYBiUGpAUXBX4E2wMwA30CxQEIAUoAjP/O/hL+XP2r/AH8YfvL+kH6xPlW+fb4pvhn+Dn4HPgR+Bn4Mvhc+Jj45fhB+a35J/qv+kL74PuH/Db96/2l/mH/HwDdAJgBUQIEA7ADUwTtBHwF/gVzBtkGMAd2B6wH0AfjB+QH0wewB3wHNwfiBn0GCgaJBfwEYwTBAxYDZAKtAfIANQB5/73+A/5P/aD8+ftc+8j6QfrH+Vr5/fiw+HP4R/gs+CT4LfhH+HT4sfj++Fz5yPlC+sr6Xfv6+6H8T/0D/rv+d/8yAO4AqAFeAg8DuQNaBPEEfgX9BXAG0wYnB2wHnwfBB9EH0Ae+B5kHZAceB8gGYwbvBW4F4QRIBKYD/AJMApYB3AAhAGb/rP71/UL9lvzy+1b7xvpB+sn5X/kE+bn4f/hV+D34NvhB+F34i/jJ+Bj5dvnj+V365fp4+xX8uvxo/Rv+0v6M/0YAAAG4AWwCGgPBA2AE9QR/BfwFbAbNBh8HYAeSB7EHwAe9B6kHgwdMBwUHrgZIBtQFUwXGBC4EjQPjAjMCfwHHAA0AVP+c/uf9Nv2M/Or7UvvD+kH6zPlk+Qz5w/iL+GT4TfhI+FX4c/ii+OH4MfmQ+f35ePr/+pL7L/zU/ID9Mv7o/qD/WQARAccBeQIlA8oDZgT5BIAF+wVoBscGFgdVB4QHogevB6oHlAdtBzUH7QaVBi4GugU4BasEFARzA8oCHAJoAbEA+v9C/4z+2f0r/YP84/tN+8H6QfrP+Wr5FPnN+Jf4cvhe+Fv4afiI+Ln4+fhK+an5F/qT+hr7rPtJ/O38mP1J/v7+tf9rACIB1gGFAi8D0gNsBPwEgQX5BWQGwAYNB0oHdweSB50Hlwd/B1YHHQfUBnwGFAagBR4FkQT6A1oDsgIEAlIBnADn/zH/fP7L/R/9evzd+0n7v/pC+tL5b/kc+dj4pPiB+G74bfh9+J740PgR+WP5w/kx+q36NPvG+2L8Bv2w/WD+E//J/30AMgHkAZECOQPaA3EE/wSBBfcFXwa5BgQHPwdpB4MHjAeDB2oHQAcGB7wGYgb7BYYFBAV3BOADQQOaAu0BPAGIANT/IP9t/r79FP1x/Nb7Rfu++kP61fl1+ST54viw+I/4f/iA+JH4tPjm+Cn5e/nc+Uv6x/pO++D7e/we/cj9dv4o/9z/jwBCAfIBnQJDA+EDdgQBBYEF9QVaBrIG+gYzB1sHcwd6B3AHVQcqB+4GowZJBuEFbAXqBF0ExwMoA4IC1gEmAXQAwf8P/17+sf0K/Wn80PtB+736RPrZ+Xv5LPnt+L34nviQ+JL4pfjJ+P34QfmU+fb5Zfrh+mj7+fuU/Df93/2N/j3/7/+hAFIBAAKpAkwD6AN7BAQFgQXyBVYGqwbxBicHTgdjB2gHXQdABxQH1waLBjEGyAVSBdAERASuAw8DagK/AREBYACv//7+UP6l/f/8YfzK+z77vPpG+tz5gfk1+ff4yvit+KD4pfi5+N/4FPlZ+a35D/p++vr6gfsT/K38Tv32/aL+Uv8BALIAYQENArQCVQPvA38EBgWBBe8FUAajBucGGwdAB1MHVwdJBywH/gbABnMGGAavBTkFtwQrBJUD9wJTAqkB/ABMAJ3/7v5B/pn99fxZ/MX7O/u7+kf64PmH+T35AvnX+Lz4sfi3+M349Pgq+XD5xfko+pf6E/ub+yz8xfxm/Q3+uP5m/xQAwwBwARoCvwJeA/UDhAQIBYAF7AVLBpwG3QYQBzIHRAdFBzYHFwfoBqkGWwb/BZYFIAWeBBIEfAPfAjwCkwHnADkAjP/e/jT+jf3s/FL8wPs4+7r6Sfrl+Y75RvkN+eT4y/jC+Mr44fgJ+UH5iPnd+UD6sPos+7P7RPzd/H39I/7N/nn/JgDTAH8BJwLKAmYD+wOHBAkFfwXBUYGlAbTBgMHJAc0BzMHIwcCB9IGkgZEBucFfQUHBYUE+QNkA8gCJQJ+AdMAJgB7/8/+Jv4B/eL8Svy7+zX7uvpC+un5lflP+Rj58fja+NP43Pj1+B75V/mf+fX5WfrJ+kX7zPtc/PX8lP05/uL+jf84AOQAjQEzAtQCbwMBBIsECgV+BeYFQAaMBskG9wYVByQHIgcQB+4GvAZ7BiwGzwVkBe4EbAThA0wDsQIPAmkBvwAUAGr/wP4Z/nb92fxE/Lb7M/u6+k367vmc+Vj5I/n++On45Pju+An5NPlt+bb5Dfpx+uL6Xvvl+3X8DP2r/U/+9v6g/0kA8wCbAT8C3gJ2AwcEjgQLBX0F4gU6BoQGvwbrBgcHFAcQB/0G2QamBmUGFQa3BUwF1QRUBMgDNQOaAvkBVAGrAAIAWf+x/gz+a/3R/D38svsx+7r6UPry+aP5Yfkv+Qz5+Pj1+AH5HflJ+YP5zfkl+on6+vp2+/37jPwk/cH9ZP4K/7P/WwADAakBSwLoAn4DDASRBAwFfAXeBTQGfAa1Bt8G+QYEB/4G6QbFBpEGTgb9BS8FrwQyBIwDAwN4AqoBCQF+AMr/CP9a/oj9xfwa/HH72PpD+sD5Vfnx+KP4XPgy+CD4EPgF+C/4WPh6+KX41/gM+WT5wvk0+q/6QPvb+4H8Pv3f/ZH+Tv8BAMUAhAE9AhQDxwNhBO8EfAX9BXAG1gYiB28HmQe9B74HswefB2sHNAfbBmMG3wVOBe0EWgTHAycDfAKqAR8BZgCs//f+Q/54/b38C/xh+8/6Q/rB+VH57viY+Fz4LvgR+AL4Avgn+FT4g/i4+Or4P/mn+Q/6efrx+nf7APyr/FX9B/6k/kX/8P9+ACsB1QFhAg8DowNEBOAEegX/BXAG1AYhB28HmAe7B7sHsAeZB2UHKwfZBl0G0AVLBckEJASNA+MCKwJmAZ0A4/8P/03+dP2y/AD8W/vF+j36wPlA+db4g/g/+=',

        init: function (ticketId, maxReplyId, maxTicketId, maxGlobalReplyId, maxRatingId) {
            const self = this;
            this.activeTicketId = ticketId || 0;
            this.lastReplyId = maxReplyId || 0;
            this.lastTicketId = maxTicketId || 0;
            this.lastGlobalReplyId = maxGlobalReplyId || 0;
            this.lastRatingId = maxRatingId || 0;
            this.updateNavbarAudioIcon();
            this.setupAudioUnlockers();
            this.registerPWA();
            this.startLiveStream();

            // Activity listeners to stop tab title flashing
            const stopFlashOnActivity = function () {
                self.stopTitleFlash();
            };
            ['click', 'focus', 'keydown', 'mousemove', 'touchstart'].forEach(evt => {
                window.addEventListener(evt, stopFlashOnActivity, { passive: true });
            });
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    self.stopTitleFlash();
                }
            });
        },

        startTitleFlash: function (msg) {
            const self = this;
            if (self.titleFlashInterval) return;
            self.isFlashing = true;
            let showMsg = true;
            self.titleFlashInterval = setInterval(function () {
                document.title = showMsg ? msg : self.originalTitle;
                showMsg = !showMsg;
            }, 1000);
        },

        stopTitleFlash: function () {
            const self = this;
            if (self.titleFlashInterval) {
                clearInterval(self.titleFlashInterval);
                self.titleFlashInterval = null;
            }
            self.isFlashing = false;
            document.title = self.originalTitle;
        },

        isFirstPoll: true,

        setupAudioUnlockers: function () {
            const self = this;
            // Her kullanıcı etkileşiminde AudioContext'i başlat ve unlocked tut
            const unlock = function () {
                try {
                    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                    if (AudioContextClass) {
                        if (!self.audioCtx) {
                            self.audioCtx = new AudioContextClass();
                        }
                        if (self.audioCtx.state === 'suspended') {
                            self.audioCtx.resume().then(function () {
                                self.audioUnlocked = true;
                            }).catch(function () {});
                        } else {
                            self.audioUnlocked = true;
                        }
                    }
                } catch (e) {}
            };

            // once: false - her tıklamada context canlı tutsun
            ['click', 'touchstart', 'keydown', 'mousedown'].forEach(evt => {
                document.addEventListener(evt, unlock, { passive: true });
            });
        },

        registerPWA: function () {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js').catch(function (err) {});
            }
        },

        toggleMute: function () {
            this.isMuted = !this.isMuted;
            localStorage.setItem('eaprimus_muted', this.isMuted);
            this.updateNavbarAudioIcon();
            
            if (!this.isMuted) {
                this.playChime();
            }

            const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
            const msg = this.isMuted 
                ? (isTr ? 'Sesler Sessize Alındı' : 'Notification Sounds Muted') 
                : (isTr ? 'Bildirim Sesleri Açıldı' : 'Notification Sounds Enabled');
            if (window.toastr) {
                toastr.info(msg);
            }
        },

        updateNavbarAudioIcon: function () {
            const icon = document.getElementById('nav-volume-icon');
            if (icon) {
                const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                if (this.isMuted) {
                    icon.className = 'fas fa-volume-mute text-danger';
                    icon.title = isTr ? 'Sesler Kapalı (Tıkla ve Aç)' : 'Sounds Off (Click to Enable)';
                } else {
                    icon.className = 'fas fa-volume-up text-success';
                    icon.title = isTr ? 'Sesler Açık (Tıkla ve Sessize Al)' : 'Sounds On (Click to Mute)';
                }
            }
        },

        // More reliable sound method using HTMLAudioElement (works in async callbacks)
        playChimeNow: function () {
            if (this.isMuted) return;
            // WAV Data URI yöntemi (en güvenilir - user gesture gerekmez eğer unlock edilmişse)
            try {
                const audio = new Audio(this.chimeDataUri);
                audio.volume = 0.7;
                const p = audio.play();
                if (p && p.catch) {
                    p.catch(function () {});
                }
            } catch (e) {}
            // AudioContext ile de çal (paylaşımlı unlocked context ile)
            this.playChime();
        },

        playChime: function () {
            if (this.isMuted) return;

            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) return;

                // Yeni context oluşturmak yerine paylaşımlı unlocked context'i kullan
                if (!this.audioCtx) {
                    this.audioCtx = new AudioContextClass();
                }
                const ctx = this.audioCtx;
                const self = this;

                const doPlay = function () {
                    const now = ctx.currentTime;
                        // Use selected sound pattern
                        switch(self.soundChoice) {
                            case 'pop':
                                const osc1 = ctx.createOscillator(); const g1 = ctx.createGain();
                                osc1.type = 'sine'; osc1.frequency.setValueAtTime(800, now);
                                osc1.frequency.exponentialRampToValueAtTime(400, now + 0.08);
                                g1.gain.setValueAtTime(0.4, now); g1.gain.exponentialRampToValueAtTime(0.01, now + 0.08);
                                osc1.connect(g1); g1.connect(ctx.destination); osc1.start(now); osc1.stop(now + 0.08);
                                break;
                            case 'crystal':
                                [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'triangle'; osc.frequency.value = freq;
                                    gain.gain.setValueAtTime(0.25, now + idx * 0.06);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.06 + 0.3);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.06); osc.stop(now + idx * 0.06 + 0.3);
                                });
                                break;
                            case 'radar':
                                [880, 1760].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.value = freq;
                                    gain.gain.setValueAtTime(0.3, now + idx * 0.1);
                                    gain.gain.exponentialRampToValueAtTime(0.01, now + idx * 0.1 + 0.15);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.1); osc.stop(now + idx * 0.1 + 0.15);
                                });
                                break;
                            case 'marimba':
                                [261.63, 329.63, 392.00, 523.25].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.06);
                                    gain.gain.setValueAtTime(0.35, now + idx * 0.06);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.06 + 0.3);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.06); osc.stop(now + idx * 0.06 + 0.3);
                                });
                                break;
                            case 'bell':
                                const bOsc = ctx.createOscillator(); const bGain = ctx.createGain();
                                bOsc.type = 'sine'; bOsc.frequency.setValueAtTime(987.77, now);
                                bGain.gain.setValueAtTime(0.4, now); bGain.gain.exponentialRampToValueAtTime(0.001, now + 0.8);
                                bOsc.connect(bGain); bGain.connect(ctx.destination); bOsc.start(now); bOsc.stop(now + 0.8);
                                break;
                            case 'echo':
                                [440, 554.37, 659.25, 880].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.1);
                                    gain.gain.setValueAtTime(0.25 / (idx + 1), now + idx * 0.1);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.1 + 0.6);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.1); osc.stop(now + idx * 0.1 + 0.6);
                                });
                                break;
                            case 'harp':
                                [329.63, 392.00, 493.88, 587.33, 659.25, 783.99].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'triangle'; osc.frequency.setValueAtTime(freq, now + idx * 0.045);
                                    gain.gain.setValueAtTime(0.25, now + idx * 0.045);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.045 + 0.4);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.045); osc.stop(now + idx * 0.045 + 0.4);
                                });
                                break;
                            case 'breeze':
                                [587.33, 659.25, 783.99, 880, 1046.50].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.08);
                                    gain.gain.setValueAtTime(0.2, now + idx * 0.08);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.08 + 0.5);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.08); osc.stop(now + idx * 0.08 + 0.5);
                                });
                                break;
                            case 'cosmic':
                            case 'synth':
                                [440, 554.37, 659.25, 880].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sawtooth'; osc.frequency.setValueAtTime(freq, now + idx * 0.07);
                                    gain.gain.setValueAtTime(0.18, now + idx * 0.07);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.07 + 0.45);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.07); osc.stop(now + idx * 0.07 + 0.45);
                                });
                                break;
                            case 'cyber':
                                [300, 600, 1200].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sawtooth'; osc.frequency.setValueAtTime(freq, now + idx * 0.05);
                                    gain.gain.setValueAtTime(0.2, now + idx * 0.05);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.05 + 0.2);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.05); osc.stop(now + idx * 0.05 + 0.2);
                                });
                                break;
                            case 'glass':
                                [1567.98, 2093.00, 2637.02].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.06);
                                    gain.gain.setValueAtTime(0.2, now + idx * 0.06);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.06 + 0.4);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.06); osc.stop(now + idx * 0.06 + 0.4);
                                });
                                break;
                            case 'fanfare':
                                [349.23, 440.00, 523.25, 698.46].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'triangle'; osc.frequency.setValueAtTime(freq, now + idx * 0.09);
                                    gain.gain.setValueAtTime(0.3, now + idx * 0.09);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.09 + 0.45);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.09); osc.stop(now + idx * 0.09 + 0.45);
                                });
                                break;
                            case 'drop':
                                const dropOsc = ctx.createOscillator(); const dropGain = ctx.createGain();
                                dropOsc.type = 'sine';
                                dropOsc.frequency.setValueAtTime(400, now);
                                dropOsc.frequency.exponentialRampToValueAtTime(1200, now + 0.12);
                                dropGain.gain.setValueAtTime(0.4, now);
                                dropGain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
                                dropOsc.connect(dropGain); dropGain.connect(ctx.destination);
                                dropOsc.start(now); dropOsc.stop(now + 0.15);
                                break;
                            case 'flute':
                                [440, 523.25, 659.25].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.12);
                                    gain.gain.setValueAtTime(0.05, now + idx * 0.12);
                                    gain.gain.linearRampToValueAtTime(0.25, now + idx * 0.12 + 0.04);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.4);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.12); osc.stop(now + idx * 0.12 + 0.4);
                                });
                                break;
                            case 'arcade':
                                [150, 300, 600, 1200].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'square'; osc.frequency.setValueAtTime(freq, now + idx * 0.04);
                                    gain.gain.setValueAtTime(0.15, now + idx * 0.04);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.04 + 0.08);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.04); osc.stop(now + idx * 0.04 + 0.08);
                                });
                                break;
                            case 'lotus':
                                [220, 277.18, 329.63, 440].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now);
                                    gain.gain.setValueAtTime(0.2, now);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + 1.2);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now); osc.stop(now + 1.2);
                                });
                                break;
                            case 'pulsar':
                                [110, 220, 440].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.setValueAtTime(freq, now + idx * 0.12);
                                    gain.gain.setValueAtTime(0.3, now + idx * 0.12);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.5);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.12); osc.stop(now + idx * 0.12 + 0.5);
                                });
                                break;
                            case 'electro':
                                const elOsc = ctx.createOscillator(); const elGain = ctx.createGain();
                                elOsc.type = 'sawtooth';
                                elOsc.frequency.setValueAtTime(1500, now);
                                elOsc.frequency.exponentialRampToValueAtTime(200, now + 0.15);
                                elGain.gain.setValueAtTime(0.3, now);
                                elGain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
                                elOsc.connect(elGain); elGain.connect(ctx.destination);
                                elOsc.start(now); elOsc.stop(now + 0.15);
                                break;
                            case 'symphony':
                                [261.63, 329.63, 392.00, 523.25, 659.25].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'triangle'; osc.frequency.setValueAtTime(freq, now + idx * 0.06);
                                    gain.gain.setValueAtTime(0.2, now + idx * 0.06);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.06 + 0.6);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.06); osc.stop(now + idx * 0.06 + 0.6);
                                });
                                break;
                            default: // chime / default
                                [523.25, 659.25].forEach((freq, idx) => {
                                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                                    osc.type = 'sine'; osc.frequency.value = freq;
                                    gain.gain.setValueAtTime(0.35, now + idx * 0.12);
                                    gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.45);
                                    osc.connect(gain); gain.connect(ctx.destination);
                                    osc.start(now + idx * 0.12); osc.stop(now + idx * 0.12 + 0.45);
                                });
                                break;
                        }
                };

                // Context suspended ise resume et, sonra çal
                if (ctx.state === 'suspended') {
                    ctx.resume().then(function () {
                        self.audioUnlocked = true;
                        doPlay();
                    }).catch(function () {});
                } else {
                    doPlay();
                }
            } catch (e) {}
        },

        sendDesktopNotification: function (title, body, url) {
            const baseUrl = (window.EaprimusBaseUrl || '/').replace(/\/+$/, '');
            const targetUrl = url || window.location.href;

            // Always display screen bottom-right Toastr notification card inside browser
            if (window.toastr) {
                const prevPos = (toastr.options && toastr.options.positionClass) ? toastr.options.positionClass : 'toast-top-right';
                toastr.options = Object.assign({}, toastr.options || {}, {
                    positionClass: 'toast-bottom-right',
                    timeOut: 7000,
                    extendedTimeOut: 3000,
                    closeButton: true,
                    progressBar: true
                });
                const toastInstance = toastr.info(body || title, title);
                if (toastInstance && targetUrl) {
                    toastInstance.css('cursor', 'pointer').on('click', function () {
                        window.location.href = targetUrl;
                    });
                }
            }

            if (!('Notification' in window)) {
                return;
            }

            const fireOsNotif = function () {
                if (Notification.permission !== 'granted') return;
                const iconUrl = baseUrl ? baseUrl + '/public/favicon.png' : '/public/favicon.png';
                const options = {
                    body: body || '',
                    icon: iconUrl,
                    badge: iconUrl,
                    data: { url: targetUrl },
                    dir: 'auto',
                    renotify: true,
                    tag: 'eaprimus-notif-' + Date.now()
                };

                // 1. Direct browser OS Notification
                try {
                    const notif = new Notification(title, options);
                    notif.onclick = function (e) {
                        e.preventDefault();
                        window.focus();
                        if (targetUrl) window.location.href = targetUrl;
                        notif.close();
                    };
                    return;
                } catch (e) {
                    console.warn("EaprimusRealtime: Direct OS Notification failed, attempting ServiceWorker:", e);
                }

                // 2. Service Worker OS Notification fallback
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.ready.then(function (registration) {
                        return registration.showNotification(title, options);
                    }).catch(function (err) {
                        console.error("EaprimusRealtime: ServiceWorker showNotification error:", err);
                    });
                }
            };

            if (Notification.permission === 'granted') {
                fireOsNotif();
            } else if (Notification.permission === 'default') {
                try {
                    Notification.requestPermission().then(function (perm) {
                        if (perm === 'granted') fireOsNotif();
                    }).catch(function () {});
                } catch (e) {}
            }
        },

        broadcastTyping: function () {
            if (this.activeTicketId <= 0) return;
            const now = Date.now();
            if (now - this.lastTypeSentTime < 200) return; // 200ms throttle
            this.lastTypeSentTime = now;
            fetch((window.EaprimusBaseUrl || '/') + 'api/v1/live_stream.php?action=type&ticket_id=' + this.activeTicketId, {
                credentials: 'same-origin'
            }).catch(function () {});
        },

        stopTyping: function () {
            if (this.activeTicketId <= 0) return;
            fetch((window.EaprimusBaseUrl || '/') + 'api/v1/live_stream.php?action=type&stop=1&ticket_id=' + this.activeTicketId, {
                credentials: 'same-origin'
            }).catch(function () {});
        },

        startLiveStream: function () {
            const self = this;
            const poll = function () {
                let url = (window.EaprimusBaseUrl || '/') + 'api/v1/live_stream.php?ticket_id=' + self.activeTicketId + '&last_reply_id=' + self.lastReplyId + '&last_ticket_id=' + self.lastTicketId + '&last_global_reply_id=' + self.lastGlobalReplyId + '&last_rating_id=' + self.lastRatingId + '&_t=' + new Date().getTime();
                fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(r => r.text())
                    .then(text => {
                        const match = text.match(/data:\s*({.*})/);
                        if (!match) return;
                        const data = JSON.parse(match[1]);

                        if (data.latest_ticket_id && data.latest_ticket_id > 0 && self.lastTicketId === 0) {
                            self.lastTicketId = data.latest_ticket_id;
                        }
                        if (data.latest_global_reply_id && data.latest_global_reply_id > 0 && self.lastGlobalReplyId === 0) {
                            self.lastGlobalReplyId = data.latest_global_reply_id;
                        }
                        if (data.latest_rating_id && data.latest_rating_id > 0 && self.lastRatingId === 0) {
                            self.lastRatingId = data.latest_rating_id;
                        }

                        // 1. Update unread ticket badge & nav item container & dropdown items
                        const ticketItem = document.getElementById('nav-ticket-item');
                        const badge = document.getElementById('unread-tickets-badge');
                        const tabBadge = document.getElementById('support-system-tab-badge');
                        const headerCount = document.getElementById('nav-ticket-header-count');
                        const dropdownItems = document.getElementById('nav-ticket-dropdown-items');
                        const btn = document.getElementById('nav-ticket-btn');
                        const icon = document.getElementById('nav-ticket-icon-element');

                        const prevUnread = self.previousUnreadCount;

                        if (data.unread_tickets !== undefined) {
                            const unreadCount = parseInt(data.unread_tickets || 0);

                            if (badge) badge.innerText = unreadCount;
                            if (headerCount) headerCount.innerText = unreadCount;

                            // Sound + flash when unread count INCREASES (works regardless of who created the ticket)
                            if (!self.isFirstPoll && unreadCount > prevUnread) {
                                const flashMsg = (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1) ? '\uD83D\uDD14 Yeni Bir Bildirim!' : '\uD83D\uDD14 New Notification!';
                                self.startTitleFlash(flashMsg);
                                // Play sound if not muted (and no new_ticket event which plays its own)
                                if (!data.new_ticket_created && !data.new_global_reply) {
                                    self.playChime();
                                    if (window.toastr) {
                                        const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                                        toastr.warning((isTr ? 'Okunmamış bilet sayısı: ' : 'Unread tickets count: ') + unreadCount, isTr ? 'BEKLEYEN BİLET' : 'PENDING TICKET');
                                    }
                                }
                                const currentPath = window.location.pathname + window.location.search;
                                const isTicketListPage = currentPath.includes('biletler') || currentPath.includes('anasayfa') || currentPath === '/' || currentPath.endsWith('public/');
                                // Bilet listesi sayfasındaysa her zaman yenile (new_ticket_created kendi reload'unu yapar ama bu da garanti)
                                if (isTicketListPage && !self._pendingReload) {
                                    self._pendingReload = true;
                                    setTimeout(function() { window.location.reload(); }, 1500);
                                }
                            }

                            self.previousUnreadCount = unreadCount;
                            self.isFirstPoll = false;

                            if (unreadCount === 0) {
                                self.stopTitleFlash();
                            }

                            if (unreadCount > 0) {
                                if (badge) badge.classList.remove('d-none');
                                if (btn) btn.classList.add('ticket-active-glow');
                                if (icon) {
                                    icon.classList.remove('normal-ticket-icon');
                                    icon.classList.add('pulse-ticket-icon');
                                }

                                // Dynamically build dropdown items HTML
                                if (dropdownItems && data.unread_list && data.unread_list.length > 0) {
                                    let html = '';
                                    data.unread_list.forEach(function (item) {
                                        const linkUrl = item.url ? item.url : ('bilet-detay/' + item.id);
                                        html += '<a href="' + linkUrl + '" class="dropdown-item py-3">' +
                                            '<div class="d-flex align-items-center justify-content-between">' +
                                                '<div style="max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' +
                                                    '<strong class="small font-weight-bold text-dark">#' + item.ticket_no + '</strong><br>' +
                                                    '<span class="small text-muted" style="font-size: 11px;">' + item.title + '</span>' +
                                                '</div>' +
                                                '<span class="badge ' + item.status_class + ' ml-2" style="font-size: 10px; padding: 4px 6px;">' + item.status_text + '</span>' +
                                            '</div>' +
                                        '</a>' +
                                        '<div class="dropdown-divider" style="margin: 0;"></div>';
                                    });
                                    dropdownItems.innerHTML = html;
                                }
                            } else {
                                if (badge) badge.classList.add('d-none');
                                if (tabBadge) tabBadge.classList.add('d-none');
                                if (btn) btn.classList.remove('ticket-active-glow');
                                if (icon) {
                                    icon.classList.remove('pulse-ticket-icon');
                                    icon.classList.add('normal-ticket-icon');
                                }
                                if (dropdownItems) {
                                    const emptyMsg = (window.EAPRIMUS_LANG_NO_NOTIFICATIONS || 'Yeni veya okunmamış bilet bildirimi yok');
                                    dropdownItems.innerHTML = '<div class="p-3 text-center text-muted small">' + emptyMsg + '</div>';
                                }
                            }
                        }

                        // Update Support System tab badge using the unread tickets count
                        if (data.unread_tickets !== undefined && tabBadge) {
                            if (data.unread_tickets > 0) {
                                tabBadge.classList.remove('d-none');
                                tabBadge.innerText = data.unread_tickets;
                            } else {
                                tabBadge.classList.add('d-none');
                            }
                        }

                        // 2. Global New Ticket Created Notification
                        if (data.new_ticket_created) {
                            const nt = data.new_ticket_created;
                            self.lastTicketId = parseInt(nt.id);

                            // Play sound using Audio element (more reliable than AudioContext in async)
                            self.playChimeNow();

                            // Flash title
                            const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                            self.startTitleFlash(isTr ? '🔔 YENİ BİLET!' : '🔔 NEW TICKET!');

                            // Desktop notification
                            self.sendDesktopNotification(
                                (isTr ? 'Yeni Bilet Geldi: #' : 'New Ticket Received: #') + nt.ticket_no,
                                nt.title,
                                'bilet-detay/' + nt.id
                            );

                            // Show prominent toastr (stays 8 seconds)
                            if (window.toastr) {
                                const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                                toastr.options = { timeOut: 8000, extendedTimeOut: 4000, positionClass: 'toast-top-right', closeButton: true };
                                toastr.success(
                                    '<strong>#' + nt.ticket_no + '</strong> - ' + nt.title + '<br><small>' + (isTr ? 'Sayfayı yenilemek için tıklayın' : 'Click to refresh page') + '</small>',
                                    isTr ? '🔔 YENİ DESTEK TALEBİ' : '🔔 NEW SUPPORT TICKET',
                                    { onclick: function() { window.location.reload(); } }
                                );
                            }

                            // Bilet listesi sayfasındaysa yenile (1500ms - ses ve toast'un çıkmasını bekle)
                            const currentPath = window.location.pathname + window.location.search;
                            if (currentPath.includes('biletler') || currentPath.includes('anasayfa') || currentPath === '/' || currentPath.endsWith('public/')) {
                                if (!self._pendingReload) {
                                    self._pendingReload = true;
                                    setTimeout(function() { window.location.reload(); }, 1500);
                                }
                            }
                        }

                        // 3. Global New Reply Notification
                        if (data.new_global_reply) {
                            const gr = data.new_global_reply;
                            self.lastGlobalReplyId = parseInt(gr.id);
                            
                            // Prevent double chime if user is already inside this ticket (item 5 handles active ticket sound)
                            if (parseInt(gr.ticket_id) !== parseInt(self.activeTicketId)) {
                                self.playChime();
                                const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));

                                self.sendDesktopNotification(
                                    (isTr ? 'Yeni Yanıt: ' : 'New Reply: ') + gr.author_name + ' (#' + gr.ticket_no + ')',
                                    gr.message.replace(/<[^>]*>?/gm, '').substring(0, 80),
                                    'bilet-detay/' + gr.ticket_id
                                );

                                if (window.toastr) {
                                    toastr.info((isTr ? 'Yeni Yanıt: #' : 'New Reply: #') + gr.ticket_no + ' - ' + gr.author_name, isTr ? 'MÜŞTERİ YANITI' : 'CUSTOMER REPLY');
                                }
                            }
                        }

                        // 3.5 Global New Ticket Rating Notification
                        if (data.new_ticket_rating) {
                            const tr = data.new_ticket_rating;
                            self.lastRatingId = parseInt(tr.id);
                            
                            if (parseInt(tr.ticket_id) !== parseInt(self.activeTicketId)) {
                                self.playChime();
                                const countStars = parseInt(tr.rating) || 5;
                                const starsStr = '★'.repeat(countStars) + '☆'.repeat(Math.max(0, 5 - countStars));
                                const isTr = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                                
                                self.sendDesktopNotification(
                                    (isTr ? 'Bilet Puanlandı (' : 'Ticket Rated (') + countStars + '/5 ★): #' + tr.ticket_no,
                                    (tr.customer_name || (isTr ? 'Müşteri' : 'Customer')) + ': ' + (tr.comment ? tr.comment : (isTr ? 'Bilet değerlendirildi.' : 'Ticket evaluated.')),
                                    'bilet-detay/' + tr.ticket_id
                                );

                                if (window.toastr) {
                                    toastr.success((isTr ? 'Bilet Puanlandı: #' : 'Ticket Rated: #') + tr.ticket_no + ' (' + countStars + '/5 ' + starsStr + ') - ' + (tr.customer_name || (isTr ? 'Müşteri' : 'Customer')), isTr ? 'MÜŞTERİ DEĞERLENDİRMESİ' : 'CUSTOMER EVALUATION');
                                }
                            }
                        }

                        // 4. Typing indicator handling inside active ticket
                        const typingBox = document.getElementById('live-typing-indicator');
                        if (typingBox) {
                            if (data.typing_user && data.typing_user.trim() !== '') {
                                const msgTpl = (window.EAPRIMUS_LANG_OTHERS_TYPING || 'yanıt yazıyor...');
                                typingBox.querySelector('span').innerText = data.typing_user + ' ' + msgTpl;
                                typingBox.classList.remove('d-none');
                            } else {
                                typingBox.classList.add('d-none');
                            }
                        }

                        // 5. New live replies inside ticket detail
                        if (data.new_replies && data.new_replies.length > 0) {
                            let hasOtherReply = false;
                            let shouldReloadPage = false;
                            data.new_replies.forEach(function (reply) {
                                let replyId = parseInt(reply.id);
                                if (replyId > self.lastReplyId) {
                                    self.lastReplyId = replyId;
                                    if (window.appendLiveReply) {
                                        window.appendLiveReply(reply);
                                    } else if (!reply.is_me) {
                                        shouldReloadPage = true;
                                    }
                                    if (!reply.is_me) {
                                        hasOtherReply = true;
                                        const isTrReply = (document.documentElement.lang === 'tr' || (window.EAPRIMUS_LANG_NO_NOTIFICATIONS && window.EAPRIMUS_LANG_NO_NOTIFICATIONS.indexOf('Yeni') !== -1));
                                        self.sendDesktopNotification(
                                            (isTrReply ? 'Yeni Yanıt: ' : 'New Reply: ') + reply.author_name,
                                            reply.message.replace(/<[^>]*>?/gm, '').substring(0, 80),
                                            'bilet-detay/' + self.activeTicketId
                                        );
                                    }
                                }
                            });

                            if (hasOtherReply) {
                                self.playChime();
                            }
                            if (shouldReloadPage) {
                                setTimeout(function() { location.reload(); }, 500);
                            }
                        }
                    })
                    .catch(function () {});
            };

            poll();
            // Ultra-responsive 600ms polling inside ticket detail for sub-second live typing responsiveness
            const pollIntervalMs = (self.activeTicketId > 0) ? 600 : 3000;
            this.streamInterval = setInterval(poll, pollIntervalMs);
        }
    };
})();
