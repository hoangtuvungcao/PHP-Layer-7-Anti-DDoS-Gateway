(() => {
    const root = document.getElementById('challenge-root');
    if (!root) {
        return;
    }

    const parseJsonAttr = (value, fallback) => {
        if (!value) {
            return fallback;
        }

        try {
            return JSON.parse(value);
        } catch (err) {
            console.warn('Failed to parse dataset JSON', err);
            return fallback;
        }
    };

    const bundle = parseJsonAttr(root.dataset.bundle, null);
    const endpoint = root.dataset.endpoint || '/challenge/verify.php';
    const redirectUrl = root.dataset.redirect || '/';
    const maxAttempts = 3;

    const statusEl = document.getElementById('challenge-status');
    const progressEl = document.getElementById('challenge-progress');
    const errorEl = document.getElementById('challenge-error');

    const updateStatus = (message) => {
        if (statusEl) {
            statusEl.textContent = message;
        }
    };

    const updateProgress = (percent) => {
        if (progressEl) {
            progressEl.style.width = `${Math.min(100, Math.max(0, percent))}%`;
        }
    };

    const showError = (message) => {
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
        updateStatus('Không thể xác minh trình duyệt. Vui lòng thử lại.');
    };

    const canvasFingerprint = () => {
        try {
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            if (!context) {
                return null;
            }

            context.textBaseline = 'top';
            context.font = "14px 'Arial'";
            context.textBaseline = 'alphabetic';
            context.fillStyle = '#f60';
            context.fillRect(125, 1, 62, 20);
            context.fillStyle = '#069';
            context.fillText('security-challenge', 2, 15);
            context.fillStyle = 'rgba(102, 204, 0, 0.7)';
            context.fillText('security-challenge', 4, 17);

            const data = canvas.toDataURL();
            return data;
        } catch (err) {
            return null;
        }
    };

    const audioFingerprint = async () => {
        if (typeof window.OfflineAudioContext === 'undefined' && typeof window.webkitOfflineAudioContext === 'undefined') {
            return null;
        }

        try {
            const context = new (window.OfflineAudioContext || window.webkitOfflineAudioContext)(1, 44100, 44100);
            const oscillator = context.createOscillator();
            oscillator.type = 'triangle';
            oscillator.frequency.setValueAtTime(1000, context.currentTime);

            const compressor = context.createDynamicsCompressor();
            compressor.threshold.setValueAtTime(-50, context.currentTime);
            compressor.knee.setValueAtTime(40, context.currentTime);
            compressor.ratio.setValueAtTime(12, context.currentTime);
            compressor.attack.setValueAtTime(0, context.currentTime);
            compressor.release.setValueAtTime(0.25, context.currentTime);

            oscillator.connect(compressor);
            compressor.connect(context.destination);

            oscillator.start(0);
            const buffer = await context.startRendering();
            oscillator.stop(0);

            const channelData = buffer.getChannelData(0);
            let hash = 0;
            for (let i = 0; i < channelData.length; i++) {
                hash += Math.abs(channelData[i]);
            }

            return hash.toString();
        } catch (err) {
            return null;
        }
    };

    const webglFingerprint = () => {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) {
                return null;
            }

            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            return {
                vendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : gl.getParameter(gl.VENDOR),
                renderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER),
                shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
                version: gl.getParameter(gl.VERSION),
            };
        } catch (err) {
            return null;
        }
    };

    const detectDebugger = () => {
        const threshold = 200;
        const start = performance.now();
        const probe = Function('');
        probe();
        const delta = performance.now() - start;

        let detected = delta > threshold;
        if (!detected) {
            const toString = Function.prototype.toString;
            detected = toString.call(console.log).indexOf('[native code]') === -1;
        }

        return {
            detected,
            delta,
        };
    };

    const collectNavigatorData = () => ({
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        language: navigator.language,
        languages: navigator.languages,
        hardwareConcurrency: navigator.hardwareConcurrency || null,
        deviceMemory: navigator.deviceMemory || null,
        maxTouchPoints: navigator.maxTouchPoints || 0,
        doNotTrack: navigator.doNotTrack || null,
        plugins: Array.from(navigator.plugins || []).map((p) => `${p.name}::${p.description}`),
        connection: navigator.connection ? {
            effectiveType: navigator.connection.effectiveType,
            downlink: navigator.connection.downlink,
            rtt: navigator.connection.rtt,
            saveData: navigator.connection.saveData,
        } : null,
        webdriver: navigator.webdriver || false,
        permissions: (navigator.permissions && navigator.permissions.query) ? 'available' : 'unavailable',
    });

    const collectScreenData = () => ({
        width: screen.width,
        height: screen.height,
        availWidth: screen.availWidth,
        availHeight: screen.availHeight,
        colorDepth: screen.colorDepth,
        pixelDepth: screen.pixelDepth,
        outerDiff: {
            width: window.outerWidth - window.innerWidth,
            height: window.outerHeight - window.innerHeight,
        },
    });

    const measureTimings = async () => {
        const samples = [];
        for (let i = 0; i < 5; i++) {
            const start = performance.now();
            await new Promise((resolve) => requestAnimationFrame(resolve));
            const delta = performance.now() - start;
            samples.push(delta);
        }

        const sum = samples.reduce((acc, val) => acc + val, 0);
        const avg = samples.length ? (sum / samples.length) : 0;

        return {
            samples,
            average: avg,
            timestamp: Date.now(),
        };
    };

    const computeScore = (fingerprint, metrics) => {
        let score = 0;
        if (metrics && metrics.debugger && !metrics.debugger.detected) {
            score += 15;
        }
        if (navigator.cookieEnabled) {
            score += 5;
        }
        if (fingerprint.navigator && fingerprint.navigator.plugins && fingerprint.navigator.plugins.length > 0) {
            score += 10;
        }
        if (fingerprint.navigator && fingerprint.navigator.webdriver) {
            score -= 30;
        }
        if (fingerprint.navigator && fingerprint.navigator.userAgent && fingerprint.navigator.userAgent.length < 60) {
            score -= 10;
        }
        if (metrics && metrics.timing && metrics.timing.average > 16) {
            score -= 5;
        }
        return score;
    };

    const collectFingerprint = async () => {
        updateStatus('Đang thu thập thông tin trình duyệt...');
        updateProgress(10);

        const navigatorData = collectNavigatorData();
        updateProgress(25);

        const screenData = collectScreenData();
        updateProgress(40);

        const canvasData = canvasFingerprint();
        updateProgress(55);

        const webglData = webglFingerprint();
        updateProgress(70);

        const audioData = await audioFingerprint();
        updateProgress(85);

        const timing = await measureTimings();
        updateProgress(95);

        const debuggerInfo = detectDebugger();

        const fingerprint = {
            navigator: navigatorData,
            screen: screenData,
            canvas: canvasData,
            webgl: webglData,
            audio: audioData,
        };

        const metrics = {
            timing,
            debugger: debuggerInfo,
        };

        const score = computeScore(fingerprint, metrics);

        return {
            fingerprint,
            metrics,
            score,
        };
    };

    const submitChallenge = async () => {
        if (!bundle) {
            showError('Thiếu dữ liệu challenge từ server.');
            return;
        }

        let attempt = 0;
        while (attempt < maxAttempts) {
            attempt += 1;

            try {
                const payload = await collectFingerprint();
                updateStatus('Đang xác minh với máy chủ...');
                updateProgress(98);
                
                //await new Promise((resolve) => setTimeout(resolve, 1000));
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bundle,
                        fingerprint: payload.fingerprint,
                        metrics: payload.metrics,
                        score: payload.score,
                        timestamp: Date.now(),
                    }),
                });

                if (!response.ok) {
                    throw new Error(`Máy chủ trả về ${response.status}`);
                }

                const contentType = response.headers.get('content-type') || '';
                let result = null;

                if (contentType.includes('application/json')) {
                    result = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error(text.substring(0, 120));
                }
                if (result && result.success) {
                    updateStatus('Xác minh thành công...');
                    //await new Promise((resolve) => setTimeout(resolve, 1000));
                    updateProgress(100);
                    const target = result.redirect || redirectUrl;
                    setTimeout(() => {
                        window.location.href = target;
                    }, 400);
                    return;
                }

                const message = (result && result.error) ? result.error : 'Xác minh thất bại';
                throw new Error(message);
            } catch (err) {
                console.warn('Challenge attempt failed', err);
                if (attempt >= maxAttempts) {
                    showError(err.message || 'Không thể hoàn thành challenge.');
                    return;
                }

                updateStatus(`Thử lại lần ${attempt + 1}/${maxAttempts}...`);
                await new Promise((resolve) => setTimeout(resolve, 300 + Math.random() * 700));
            }
        }
    };

    window.addEventListener('load', () => {
        updateStatus('Bắt đầu xác minh trình duyệt...');
        submitChallenge();
    });
})();
