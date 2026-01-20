(function () {
  let video, canvas, ctx, statusEl, resultEl, msgEl;
  let stream = null, detector = null, useJsQr = false, raf = null;
  let scanning = true;

  const settings = (window.drupalSettings && window.drupalSettings.qr_scanner_simple) || { stopAfterFirst: false };
  const jwtToken = localStorage.getItem('jwt_token'); // Retrieve token from localStorage
  const loginUrl = window.drupalSettings?.qr_scanner_simple?.login_url || '/user/login'; // Fallback login URL

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
    console.log("📢 STATUS:", msg);
  }

  async function setupDetector() {
    try {
      if ('BarcodeDetector' in window) {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        if (formats && formats.includes('qr_code')) {
          detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          useJsQr = false;
          return;
        }
      }
    } catch (e) {
      console.error("❌ Detector setup error:", e);
    }
    await loadJsQr();
    useJsQr = true;
  }

  function loadJsQr() {
    return new Promise((resolve, reject) => {
      if (window.jsQR) return resolve();
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js';
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Failed to load jsQR'));
      document.head.appendChild(s);
    });
  }

  function pickConstraints() {
    return { video: { facingMode: { ideal: 'environment' } }, audio: false };
  }

  async function openCamera() {
    try {
      stream = await navigator.mediaDevices.getUserMedia(pickConstraints());
      video.srcObject = stream;
      await video.play();
      canvas.width = video.videoWidth || 640;
      canvas.height = video.videoHeight || 480;
      setStatus('Scanning…');
      loop();
    } catch (e) {
      console.error("❌ Camera error:", e);
      setStatus('Camera error. Use HTTPS and allow camera permission.');
    }
  }

  function drawFrame() {
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  }

async function handleFound(text) {
  if (!text || !scanning) return;
  scanning = false;
  console.log("🔍 QR Found:", text);

  resultEl.value = text;
  try {
    const response = await fetch(Drupal.url('qr-scanner-simple/submit'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`
      },
      body: 'qr_value=' + encodeURIComponent(text)
    });

    const data = await response.json();
    console.log("✅ Response from Drupal:", data);

    if (msgEl) {
      if (data.status === 'success') {
        msgEl.textContent = data.message || '✅ Scan successful!';
        msgEl.style.color = 'green';
        if (settings.stopAfterFirst) stop();
      } else if (data.status === 'error' && data.login_url) {
        msgEl.textContent = data.message || '⚠️ Authorization required.';
        msgEl.style.color = 'red';
        localStorage.removeItem('jwt_token'); // Clear invalid token
        window.location.href = data.login_url;
      } else {
        msgEl.textContent = data.message || '⚠️ Scan failed or already in use.';
        msgEl.style.color = 'red';
      }
    }

    if (!settings.stopAfterFirst || data.status !== 'success') {
      scanning = true;
    }
  } catch (error) {
    console.error("❌ Fetch error:", error);
    if (msgEl) {
      msgEl.textContent = '⚠️ Error communicating with the server.';
      msgEl.style.color = 'red';
    }
    scanning = true;
  }
}

  async function loop() {
    drawFrame();
    try {
      if (detector && !useJsQr) {
        const codes = await detector.detect(canvas);
        if (codes && codes.length && codes[0].rawValue) {
          handleFound(codes[0].rawValue);
        }
      } else if (window.jsQR) {
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (code && code.data) {
          handleFound(code.data);
        }
      }
    } catch (e) {
      console.error("❌ Loop error:", e);
    }
    if (scanning) raf = requestAnimationFrame(loop);
  }

  function stop() {
    if (raf) cancelAnimationFrame(raf);
    raf = null;

    if (stream) {
      stream.getTracks().forEach(t => {
        try {
          t.stop();
          console.log("🛑 Track stopped:", t.kind);
        } catch (e) {
          console.error("❌ Error stopping track:", e);
        }
      });
      stream = null;
    }

    if (video) {
      video.pause();
      video.srcObject = null;
      video.removeAttribute('src');
      video.load();
      video.style.display = 'none';
    }
    if (canvas) canvas.style.display = 'none';
    setStatus('Stopped.');
  }

  function init() {
    video = document.getElementById('qrs-video');
    canvas = document.getElementById('qrs-canvas');
    statusEl = document.getElementById('qrs-status');
    resultEl = document.getElementById('qrs-result');
    msgEl = document.getElementById('scan-message');
    ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!jwtToken) {
      setStatus('⚠️ No JWT token found. Redirecting to login...');
      window.location.href = loginUrl;
      return;
    }

    setupDetector().then(openCamera).catch(error => {
      console.error("❌ Init error:", error);
      setStatus('Failed to initialize scanner.');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();