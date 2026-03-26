/**
 * CBZ-Viewer — Reader JavaScript
 *
 * Features:
 *   - 3 reading modes: RTL (manga), LTR (western), Webtoon (vertical scroll)
 *   - Double-page mode with optional cover offset
 *   - Swipe (touch) and pointer drag navigation
 *   - Pinch-to-zoom + button zoom + double-tap reset
 *   - Pan when zoomed in
 *   - Keyboard shortcuts
 *   - Page slider + numeric input
 *   - Auto-save / resume progress per volume (localStorage)
 *   - Preload next + previous page
 *   - Prev/Next volume navigation
 *   - Fullscreen API
 *   - Settings panel (mode, double-page, zoom default, theme)
 *   - Auto-hide chrome after idle
 */
'use strict';

// ============================================================
// BOOT
// ============================================================
const CFG = window.READER_CONFIG;
if (!CFG || CFG.error || CFG.total === 0) {
    // Error or empty archive — nothing to initialise
    document.getElementById('pageLoader')?.classList.remove('active');
    // Restore chrome so user can navigate back
    document.getElementById('readerToolbar')?.classList.remove('hidden-chrome');
    document.getElementById('readerBottombar')?.classList.remove('hidden-chrome');
    throw new Error('Reader init aborted: ' + (CFG?.error || 'no pages'));
}

// ============================================================
// STATE
// ============================================================
const state = {
    page:       0,          // current 0-indexed page
    total:      CFG.total,
    mode:       'rtl',      // 'rtl' | 'ltr' | 'webtoon'
    doublePage: false,
    coverOffset: false,     // first page rendered alone in double mode
    zoom:       1,          // 1 = fit default
    zoomMode:   'fit-page', // 'fit-width' | 'fit-height' | 'fit-page' | 'custom'
    panX:       0,
    panY:       0,
    chromeVisible: true,
    chromeTimer:   null,
};

// ============================================================
// DOM REFS
// ============================================================
const $  = id => document.getElementById(id);
const toolbar     = $('readerToolbar');
const bottombar   = $('readerBottombar');
const viewport    = $('readerViewport');
const pageStage   = $('pageStage');
const pageCont    = $('pageContainer');
const imgA        = $('pageImgA');
const imgB        = $('pageImgB');
const webtoonSt   = $('webtoonStage');
const loader      = $('pageLoader');
const tapLeft     = $('tapLeft');
const tapRight    = $('tapRight');
const btnPrev     = $('btnPrevPage');
const btnNext     = $('btnNextPage');
const btnPrevVol  = $('btnPrevVol');
const btnNextVol  = $('btnNextVol');
const btnFS       = $('btnFullscreen');
const btnSettings = $('btnSettings');
const sliderEl    = $('pageSlider');
const progressFill = $('progressFill');
const pageInput   = $('pageInput');
const pageTotal   = $('pageTotal');
const settingsOverlay = $('settingsOverlay');
const settingsClose   = $('settingsClose');
const zoomLabel   = $('zoomLabel');
const zoomIn      = $('zoomIn');
const zoomOut     = $('zoomOut');

// ============================================================
// LOCAL STORAGE HELPERS
// ============================================================
const LS_PREFIX = 'cbzv_progress_';
const LS_SETTINGS = 'cbzv_settings';

function saveProgress() {
    try {
        localStorage.setItem(LS_PREFIX + CFG.file, JSON.stringify({
            page: state.page,
            total: state.total,
            ts: Date.now(),
        }));
    } catch {}
}

function loadProgress() {
    try {
        const raw = localStorage.getItem(LS_PREFIX + CFG.file);
        if (!raw) return null;
        return JSON.parse(raw);
    } catch { return null; }
}

function saveSettings() {
    try {
        localStorage.setItem(LS_SETTINGS, JSON.stringify({
            mode:       state.mode,
            doublePage: state.doublePage,
            coverOffset: state.coverOffset,
            zoomMode:   state.zoomMode,
            zoom:       state.zoom,
            lightTheme: document.body.classList.contains('light-theme'),
        }));
    } catch {}
}

function loadSettings() {
    try {
        const raw = localStorage.getItem(LS_SETTINGS);
        if (!raw) return;
        const s = JSON.parse(raw);
        if (s.mode)       state.mode       = s.mode;
        if (s.doublePage !== undefined) state.doublePage  = s.doublePage;
        if (s.coverOffset !== undefined) state.coverOffset = s.coverOffset;
        if (s.zoomMode)   state.zoomMode   = s.zoomMode;
        if (s.zoom)       state.zoom       = s.zoom;
        if (s.lightTheme) document.body.classList.add('light-theme');
    } catch {}
}

// ============================================================
// URL HELPERS
// ============================================================
function pageUrl(n) {
    return `${CFG.pageApiUrl}?file=${encodeURIComponent(CFG.file)}&page=${n}`;
}

// ============================================================
// NAVIGATION
// ============================================================
function goTo(n, skipSave = false) {
    n = Math.max(0, Math.min(state.total - 1, n));
    state.page = n;
    renderPage();
    if (!skipSave) saveProgress();
    updateUI();
    preload();
}

function nextPage() {
    const step = (state.doublePage && state.mode !== 'webtoon') ? 2 : 1;
    if (state.page + step >= state.total && CFG.nextFileUrl) {
        if (confirm('Dernier page atteinte. Passer au tome suivant ?'))
            window.location.href = CFG.nextFileUrl;
        return;
    }
    goTo(state.page + step);
}

function prevPage() {
    const step = (state.doublePage && state.mode !== 'webtoon') ? 2 : 1;
    if (state.page - step < 0 && CFG.prevFileUrl) {
        if (confirm('Première page. Passer au tome précédent ?'))
            window.location.href = CFG.prevFileUrl;
        return;
    }
    goTo(state.page - step);
}

// "forward" = next in reading direction
function forward()  { if (state.mode === 'ltr') nextPage(); else prevPage(); }
function backward() { if (state.mode === 'ltr') prevPage(); else nextPage(); }

// ============================================================
// RENDERING
// ============================================================
function renderPage() {
    if (state.mode === 'webtoon') return; // webtoon handles its own scroll

    const p = state.page;
    showLoader(true);
    resetTransform();

    // Double page logic
    const showDouble = state.doublePage
        && !(state.coverOffset && p === 0)
        && p + 1 < state.total;

    imgA.src = pageUrl(p);
    imgA.classList.remove('hidden');

    if (showDouble) {
        imgB.src = pageUrl(p + 1);
        imgB.classList.remove('hidden');
        // RTL: B on left, A on right; LTR: A on left, B on right
        if (state.mode === 'rtl') {
            pageCont.style.flexDirection = 'row-reverse';
        } else {
            pageCont.style.flexDirection = 'row';
        }
    } else {
        imgB.classList.add('hidden');
        imgB.src = '';
        pageCont.style.flexDirection = 'row';
    }

    // Image load tracking
    let loaded = 0;
    const needed = showDouble ? 2 : 1;
    const onLoad = () => { loaded++; if (loaded >= needed) showLoader(false); };
    imgA.onload = onLoad;
    imgA.onerror = onLoad;
    if (showDouble) {
        imgB.onload = onLoad;
        imgB.onerror = onLoad;
    }
}

function renderWebtoon() {
    webtoonSt.innerHTML = '';
    for (let i = 0; i < state.total; i++) {
        const img = document.createElement('img');
        img.dataset.page = i;
        img.alt = `Page ${i + 1}`;
        img.loading = (i < 3) ? 'eager' : 'lazy';
        img.decoding = 'async';
        img.src = pageUrl(i);
        webtoonSt.appendChild(img);
    }
}

// ============================================================
// MODE SWITCH
// ============================================================
function setMode(newMode) {
    const oldMode = state.mode;
    state.mode = newMode;

    if (newMode === 'webtoon') {
        pageStage.classList.add('hidden');
        webtoonSt.classList.remove('hidden');
        tapLeft.classList.add('hidden');
        tapRight.classList.add('hidden');
        renderWebtoon();
        scrollWebtoonToPage(state.page);
    } else {
        webtoonSt.classList.add('hidden');
        pageStage.classList.remove('hidden');
        tapLeft.classList.remove('hidden');
        tapRight.classList.remove('hidden');
        if (oldMode === 'webtoon') {
            // detect page from webtoon scroll position
            state.page = getWebtoonCurrentPage();
        }
        renderPage();
    }
    updateTapZones();
    saveSettings();
}

function scrollWebtoonToPage(n) {
    const imgs = webtoonSt.querySelectorAll('img');
    if (imgs[n]) {
        webtoonSt.scrollTop = imgs[n].offsetTop;
    }
}

function getWebtoonCurrentPage() {
    const imgs = webtoonSt.querySelectorAll('img');
    let best = 0;
    const mid = webtoonSt.scrollTop + webtoonSt.clientHeight / 2;
    imgs.forEach((img, i) => {
        if (img.offsetTop <= mid) best = i;
    });
    return best;
}

// Webtoon scroll → update page indicator
function onWebtoonScroll() {
    const p = getWebtoonCurrentPage();
    if (p !== state.page) {
        state.page = p;
        saveProgress();
        updateUI();
    }
}

// ============================================================
// ZOOM & PAN
// ============================================================
function applyTransform() {
    pageCont.style.transform = `translate(${state.panX}px, ${state.panY}px) scale(${state.zoom})`;
    zoomLabel.textContent = Math.round(state.zoom * 100) + '%';
}

function resetTransform() {
    state.panX = 0;
    state.panY = 0;
    // apply zoom mode
    if (state.zoomMode === 'fit-page') {
        state.zoom = 1;
    } else if (typeof state.zoomMode === 'number') {
        state.zoom = state.zoomMode;
    }
    // 'fit-width' / 'fit-height' are handled by CSS; zoom stays 1
    if (state.zoomMode === 'fit-width' || state.zoomMode === 'fit-height') {
        state.zoom = 1;
    }
    applyTransform();
}

function clampPan() {
    if (state.zoom <= 1) { state.panX = 0; state.panY = 0; return; }
    const vw = viewport.clientWidth;
    const vh = viewport.clientHeight;
    const cw = pageCont.offsetWidth  * state.zoom;
    const ch = pageCont.offsetHeight * state.zoom;
    const mx = Math.max(0, (cw - vw) / 2);
    const my = Math.max(0, (ch - vh) / 2);
    state.panX = Math.max(-mx, Math.min(mx, state.panX));
    state.panY = Math.max(-my, Math.min(my, state.panY));
}

function zoomBy(delta, cx, cy) {
    const prev = state.zoom;
    state.zoom = Math.max(0.5, Math.min(5, state.zoom + delta));
    if (cx !== undefined && cy !== undefined) {
        // Zoom towards pointer position
        const rect = viewport.getBoundingClientRect();
        const ox = cx - rect.left - rect.width  / 2;
        const oy = cy - rect.top  - rect.height / 2;
        state.panX -= ox * (state.zoom / prev - 1);
        state.panY -= oy * (state.zoom / prev - 1);
    }
    clampPan();
    applyTransform();
    saveSettings();
}

function setZoomMode(mode) {
    state.zoomMode = mode;
    state.zoom = (typeof mode === 'number') ? mode : 1;
    state.panX = 0;
    state.panY = 0;
    applyTransform();
    saveSettings();
    updateZoomPresets();
}

function updateZoomPresets() {
    document.querySelectorAll('.zoom-preset').forEach(btn => {
        const v = btn.dataset.zoom;
        const numV = parseFloat(v);
        const match = (state.zoomMode === v) ||
            (state.zoomMode === numV && !isNaN(numV));
        btn.classList.toggle('active', match);
    });
}

// ============================================================
// CHROME AUTO-HIDE
// ============================================================
function showChrome() {
    state.chromeVisible = true;
    toolbar.classList.remove('hidden-chrome');
    bottombar.classList.remove('hidden-chrome');
    resetChromeTimer();
}

function hideChrome() {
    state.chromeVisible = false;
    toolbar.classList.add('hidden-chrome');
    bottombar.classList.add('hidden-chrome');
}

function toggleChrome() {
    if (state.chromeVisible) hideChrome(); else showChrome();
}

function resetChromeTimer() {
    clearTimeout(state.chromeTimer);
    state.chromeTimer = setTimeout(() => {
        // Only auto-hide when no settings panel is open
        if (settingsOverlay.classList.contains('hidden')) hideChrome();
    }, 4000);
}

// ============================================================
// LOADER
// ============================================================
function showLoader(on) {
    loader.classList.toggle('active', on);
}

// ============================================================
// UI UPDATE
// ============================================================
function updateUI() {
    const p = state.page;
    // Slider / progress bar
    const pct = state.total > 1 ? p / (state.total - 1) * 100 : 0;
    progressFill.style.width = pct + '%';
    sliderEl.value = p;

    // Page indicator
    pageInput.value = p + 1;
    pageTotal.textContent = state.total;

    // Prev/Next page buttons
    btnPrev.disabled = (p <= 0 && !CFG.prevFileUrl);
    btnNext.disabled = (p >= state.total - 1 && !CFG.nextFileUrl);

    // Prev/Next volume
    if (btnPrevVol) btnPrevVol.disabled = !CFG.prevFileUrl;
    if (btnNextVol) btnNextVol.disabled = !CFG.nextFileUrl;
}

function updateTapZones() {
    // In LTR: left tap = prev, right tap = next
    // In RTL: left tap = next (forward), right tap = prev (back)
    // In webtoon: tap zones hidden
    const hidden = state.mode === 'webtoon';
    tapLeft.classList.toggle('hidden', hidden);
    tapRight.classList.toggle('hidden', hidden);
}

function syncSettingsUI() {
    // Mode
    document.querySelectorAll('[name="readingMode"]').forEach(r => {
        r.checked = (r.value === state.mode);
    });
    // Double page
    $('doublePage').checked   = state.doublePage;
    $('coverOffset').checked  = state.coverOffset;
    // Theme
    $('lightTheme').checked   = document.body.classList.contains('light-theme');
    // Zoom
    updateZoomPresets();
    zoomLabel.textContent = Math.round(state.zoom * 100) + '%';
}

// ============================================================
// PRELOAD
// ============================================================
function preload() {
    if (state.mode === 'webtoon') return;
    const preloads = [state.page + 1, state.page - 1, state.page + 2];
    preloads.forEach(n => {
        if (n >= 0 && n < state.total) {
            const img = new Image();
            img.src = pageUrl(n);
        }
    });
}

// ============================================================
// TOUCH / POINTER EVENTS (swipe + pinch + pan)
// ============================================================
let pointers   = new Map(); // active pointer events
let gesture    = null;      // { type: 'swipe'|'pinch'|'pan', ... }

viewport.addEventListener('pointerdown', onPointerDown);
viewport.addEventListener('pointermove', onPointerMove);
viewport.addEventListener('pointerup',   onPointerEnd);
viewport.addEventListener('pointercancel', onPointerEnd);

function onPointerDown(e) {
    if (state.mode === 'webtoon') return;
    viewport.setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (pointers.size === 1) {
        gesture = { type: 'pending', startX: e.clientX, startY: e.clientY, panX0: state.panX, panY0: state.panY };
    } else if (pointers.size === 2) {
        const pts = [...pointers.values()];
        const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        gesture = { type: 'pinch', dist0: dist, zoom0: state.zoom, cx: (pts[0].x + pts[1].x) / 2, cy: (pts[0].y + pts[1].y) / 2 };
    }
    showChrome();
}

function onPointerMove(e) {
    if (state.mode === 'webtoon') return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (pointers.size === 2 && gesture?.type === 'pinch') {
        const pts = [...pointers.values()];
        const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        state.zoom = Math.max(0.5, Math.min(5, gesture.zoom0 * (dist / gesture.dist0)));
        clampPan();
        applyTransform();
        return;
    }

    if (pointers.size === 1 && gesture) {
        const dx = e.clientX - gesture.startX;
        const dy = e.clientY - gesture.startY;

        if (gesture.type === 'pending' && Math.hypot(dx, dy) > 8) {
            gesture.type = state.zoom > 1.05 ? 'pan' : 'swipe';
        }
        if (gesture.type === 'pan') {
            state.panX = gesture.panX0 + dx;
            state.panY = gesture.panY0 + dy;
            clampPan();
            applyTransform();
            pageCont.classList.add('is-panning');
        }
    }
}

function onPointerEnd(e) {
    if (state.mode === 'webtoon') {
        pointers.delete(e.pointerId);
        return;
    }

    if (gesture?.type === 'swipe' && pointers.size === 1) {
        const dx = e.clientX - gesture.startX;
        const dy = e.clientY - gesture.startY;
        const threshold = 50;
        if (Math.abs(dx) > threshold && Math.abs(dx) > Math.abs(dy)) {
            if (dx < 0) forward(); else backward();
        } else if (Math.abs(dy) > threshold && Math.abs(dy) > Math.abs(dx)) {
            // Vertical swipe: treat as next/prev regardless of mode
            if (dy < 0) nextPage(); else prevPage();
        }
    } else if (gesture?.type === 'pinch') {
        saveSettings();
    }

    pointers.delete(e.pointerId);
    if (pointers.size === 0) {
        pageCont.classList.remove('is-panning');
        gesture = null;
    }
}

// Double-tap to reset zoom
let lastTap = 0;
viewport.addEventListener('click', e => {
    if (state.mode === 'webtoon') return;
    const now = Date.now();
    if (now - lastTap < 300) {
        // Double tap
        if (state.zoom > 1.05) {
            state.zoom = 1; state.panX = 0; state.panY = 0; applyTransform();
        } else {
            zoomBy(0.5, e.clientX, e.clientY);
        }
    }
    lastTap = now;
});

// Mouse wheel zoom
viewport.addEventListener('wheel', e => {
    if (state.mode === 'webtoon') return;
    e.preventDefault();
    const delta = -e.deltaY * 0.001;
    zoomBy(delta, e.clientX, e.clientY);
}, { passive: false });

// ============================================================
// PROGRESS BAR CLICK
// ============================================================
const progressTrack = document.querySelector('.progress-bar-track');
progressTrack.addEventListener('click', e => {
    const rect = progressTrack.getBoundingClientRect();
    const ratio = (e.clientX - rect.left) / rect.width;
    goTo(Math.round(ratio * (state.total - 1)));
});

// ============================================================
// TAP ZONES
// ============================================================
tapLeft.addEventListener('click',  () => { if (state.mode === 'ltr') prevPage(); else nextPage(); });
tapRight.addEventListener('click', () => { if (state.mode === 'ltr') nextPage(); else prevPage(); });

// ============================================================
// BUTTONS
// ============================================================
btnPrev.addEventListener('click', () => prevPage());
btnNext.addEventListener('click', () => nextPage());

if (btnPrevVol) btnPrevVol.addEventListener('click', () => {
    if (CFG.prevFileUrl) window.location.href = CFG.prevFileUrl;
});
if (btnNextVol) btnNextVol.addEventListener('click', () => {
    if (CFG.nextFileUrl) window.location.href = CFG.nextFileUrl;
});

btnFS.addEventListener('click', toggleFullscreen);
btnSettings.addEventListener('click', openSettings);
settingsClose.addEventListener('click', closeSettings);
settingsOverlay.addEventListener('click', e => { if (e.target === settingsOverlay) closeSettings(); });

// Page input
pageInput.addEventListener('change', () => {
    const n = parseInt(pageInput.value, 10) - 1;
    if (!isNaN(n)) goTo(n);
});
pageInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { pageInput.blur(); }
});

// Zoom buttons
zoomIn.addEventListener('click',  () => zoomBy(0.2));
zoomOut.addEventListener('click', () => zoomBy(-0.2));

// Zoom presets
document.querySelectorAll('.zoom-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const v = btn.dataset.zoom;
        const num = parseFloat(v);
        setZoomMode(isNaN(num) ? v : num);
    });
});

// Reading mode radios
document.querySelectorAll('[name="readingMode"]').forEach(r => {
    r.addEventListener('change', () => {
        if (r.checked) setMode(r.value);
    });
});

// Double page toggle
$('doublePage').addEventListener('change', e => {
    state.doublePage = e.target.checked;
    $('coverOffsetRow').style.display = state.doublePage ? '' : 'none';
    renderPage();
    saveSettings();
});

$('coverOffset').addEventListener('change', e => {
    state.coverOffset = e.target.checked;
    renderPage();
    saveSettings();
});

// Light theme toggle
$('lightTheme').addEventListener('change', e => {
    document.body.classList.toggle('light-theme', e.target.checked);
    saveSettings();
});

// Clear progress button
$('btnClearProgress').addEventListener('click', () => {
    localStorage.removeItem(LS_PREFIX + CFG.file);
    alert('Progression effacée.');
});

// ============================================================
// SETTINGS PANEL
// ============================================================
function openSettings() {
    showChrome();
    clearTimeout(state.chromeTimer); // prevent auto-hide while panel is open
    syncSettingsUI();
    settingsOverlay.classList.remove('hidden');
    // Ensure double-page cover offset row visibility
    $('coverOffsetRow').style.display = state.doublePage ? '' : 'none';
    $('doublePagSection').style.display = state.mode === 'webtoon' ? 'none' : '';
}

function closeSettings() {
    settingsOverlay.classList.add('hidden');
    resetChromeTimer();
}

// ============================================================
// FULLSCREEN
// ============================================================
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen().catch(() => {});
    }
}
document.addEventListener('fullscreenchange', () => {
    const isFs = !!document.fullscreenElement;
    btnFS.title = isFs ? 'Quitter plein écran (F)' : 'Plein écran (F)';
    btnFS.textContent = isFs ? '⊠' : '⛶';
});

// ============================================================
// KEYBOARD
// ============================================================
document.addEventListener('keydown', e => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
    switch (e.key) {
        case 'ArrowRight': case 'ArrowDown': e.preventDefault(); forward(); break;
        case 'ArrowLeft':  case 'ArrowUp':   e.preventDefault(); backward(); break;
        case 'f': case 'F': toggleFullscreen(); break;
        case 's': case 'S':
            settingsOverlay.classList.contains('hidden') ? openSettings() : closeSettings();
            break;
        case '+': case '=': zoomBy(0.2); break;
        case '-': case '_': zoomBy(-0.2); break;
        case '0': state.zoom = 1; state.panX = 0; state.panY = 0; applyTransform(); break;
        case 'Escape':
            if (!settingsOverlay.classList.contains('hidden')) { closeSettings(); break; }
            if (document.fullscreenElement) { document.exitFullscreen(); }
            break;
    }
});

// Pointer movement anywhere shows chrome
document.addEventListener('pointermove', () => { showChrome(); }, { passive: true });

// ============================================================
// WEBTOON: track page on scroll
// ============================================================
let webtoonScrollTimer = null;
webtoonSt.addEventListener('scroll', () => {
    clearTimeout(webtoonScrollTimer);
    webtoonScrollTimer = setTimeout(onWebtoonScroll, 200);
}, { passive: true });

// ============================================================
// INIT
// ============================================================
(function init() {
    loadSettings();
    applyTransform();
    updateUI();
    syncSettingsUI();

    // Determine start page
    let startPage = 0;
    if (CFG.startPage !== null && CFG.startPage !== undefined) {
        startPage = parseInt(CFG.startPage, 10) || 0;
    } else {
        const saved = loadProgress();
        if (saved?.page > 0) {
            const resume = confirm(`Reprendre la lecture à la page ${saved.page + 1} / ${saved.total} ?`);
            if (resume) startPage = saved.page;
        }
    }
    startPage = Math.max(0, Math.min(state.total - 1, startPage));
    state.page = startPage;

    // Set up mode UI
    setMode(state.mode); // renders pages or webtoon
    if (state.mode !== 'webtoon') {
        renderPage();
    }

    updateUI();
    preload();

    // Double-page cover offset row visibility
    $('coverOffsetRow').style.display = state.doublePage ? '' : 'none';
    $('doublePagSection').style.display = state.mode === 'webtoon' ? 'none' : '';

    // Auto-hide chrome after initial display
    resetChromeTimer();
})();
