'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
const DATA = window.EDITOR_DATA || {};

let currentSlideIndex = 0;
let slideStates       = {}; // { slideName: { bboxes: [{id, original_filename, bbox_xyxy, confidence}] } }
let selectionState    = []; // [{filename, url, selected}]
let dirty             = false;
let bboxIdCounter     = 0;
let dragState         = null; // null | {type, ...}
let deleteMode        = false;

// ── DOM refs ──────────────────────────────────────────────────────────────────
let slideListEl, canvasWrap, slideImg, slideNameEl, bboxCountEl;
let saveBboxBtn, saveStatusEl, cropGridEl, selSaveBtn, selStatusEl;

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  slideListEl  = document.getElementById('eobj-slide-list');
  canvasWrap   = document.getElementById('eobj-canvas-wrap');
  slideImg     = document.getElementById('eobj-slide-img');
  slideNameEl  = document.getElementById('eobj-slide-name');
  bboxCountEl  = document.getElementById('eobj-bbox-count');
  saveBboxBtn  = document.getElementById('eobj-save-btn');
  saveStatusEl = document.getElementById('eobj-save-status');
  cropGridEl   = document.getElementById('eobj-crop-grid');
  selSaveBtn   = document.getElementById('eobj-sel-save-btn');
  selStatusEl  = document.getElementById('eobj-sel-status');

  if (!canvasWrap) return; // no editor on page (empty state)

  // Build slideStates from DATA
  (DATA.slides || []).forEach(slide => {
    slideStates[slide.name] = {
      bboxes: (slide.detections || []).map(det => ({
        id:                ++bboxIdCounter,
        original_filename: det.original_filename || null,
        bbox_xyxy:         det.bbox_xyxy.slice(),
        confidence:        det.confidence ?? null,
      })),
    };
  });

  selectionState = (DATA.allCrops || []).map(c => ({ ...c }));

  renderSlideList();
  if (DATA.slides && DATA.slides.length > 0) {
    const initial = Number.isInteger(DATA.initialSlideIndex)
      ? DATA.initialSlideIndex
      : parseInt(DATA.initialSlideIndex || '0', 10);
    const safeInitial = Number.isFinite(initial)
      ? Math.max(0, Math.min(DATA.slides.length - 1, initial))
      : 0;
    loadSlide(safeInitial);
  }
  renderSelectionPanel();

  saveBboxBtn.addEventListener('click', saveBboxes);
  selSaveBtn.addEventListener('click',  saveSelection);

  // Canvas interaction
  canvasWrap.addEventListener('mousedown', onCanvasMouseDown);
  document.addEventListener('mousemove',   onDocMouseMove);
  document.addEventListener('mouseup',     onDocMouseUp);

  // Window resize → re-scale bbox divs
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(renderBboxes, 150);
  });

  // Warn on unsaved bbox changes
  window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function currentSlide() {
  return DATA.slides[currentSlideIndex];
}

function spaceW() {
  const slide = currentSlide() || {};
  return slide.coordW || slide.inferenceW || 1280;
}

function spaceH() {
  const slide = currentSlide() || {};
  return slide.coordH || slide.inferenceH || 720;
}

function getRenderMetrics() {
  const rect = slideImg.getBoundingClientRect();
  const renderW = rect.width;
  const renderH = rect.height;
  return {
    renderW,
    renderH,
    scaleX: renderW > 0 ? renderW / spaceW() : 1,
    scaleY: renderH > 0 ? renderH / spaceH() : 1,
  };
}

async function parseJsonResponse(res) {
  const text = await res.text();
  if (!text.trim()) {
    if (res.ok) {
      return { ok: true, _emptyResponse: true };
    }
    throw new Error('Empty server response');
  }

  try {
    return JSON.parse(text);
  } catch (err) {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 240);
    throw new Error((res.ok ? 'Invalid JSON response' : ('HTTP ' + res.status)) + ': ' + snippet);
  }
}

function submitClassicSave(payload) {
  submitClassicSaveWithReturnSlide(payload, currentSlideIndex);
}

function submitClassicSaveWithReturnSlide(payload, slideIndex) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = DATA.saveUrl;
  form.style.display = 'none';

  const payloadInput = document.createElement('input');
  payloadInput.type = 'hidden';
  payloadInput.name = 'payload';
  payloadInput.value = JSON.stringify(payload);
  form.appendChild(payloadInput);

  const modeInput = document.createElement('input');
  modeInput.type = 'hidden';
  modeInput.name = 'response_mode';
  modeInput.value = 'redirect';
  form.appendChild(modeInput);

  const returnInput = document.createElement('input');
  returnInput.type = 'hidden';
  returnInput.name = 'return_to';
  returnInput.value = buildReturnUrlWithSlide(slideIndex);
  form.appendChild(returnInput);

  document.body.appendChild(form);
  form.submit();
}

function buildReturnUrlWithCurrentSlide() {
  return buildReturnUrlWithSlide(currentSlideIndex);
}

function buildReturnUrlWithSlide(slideIndex) {
  let base = DATA.returnUrl || window.location.href;
  try {
    const url = new URL(base, window.location.origin);
    const safeIndex = Number.isFinite(slideIndex)
      ? Math.max(0, Math.floor(slideIndex))
      : 0;
    url.searchParams.set('slide', String(safeIndex));
    return url.pathname + (url.search ? url.search : '');
  } catch (err) {
    const sep = base.includes('?') ? '&' : '?';
    const safeIndex = Number.isFinite(slideIndex)
      ? Math.max(0, Math.floor(slideIndex))
      : 0;
    return base + sep + 'slide=' + encodeURIComponent(String(safeIndex));
  }
}

function setDeleteMode(enabled) {
  deleteMode = !!enabled;
  slideListEl.classList.toggle('delete-mode', deleteMode);
  const toggleBtn = document.getElementById('eobj-delete-mode-toggle');
  if (toggleBtn) {
    toggleBtn.classList.toggle('active', deleteMode);
    toggleBtn.textContent = deleteMode ? 'Done Deleting' : 'Delete Slides';
  }
}

function requestDeleteSlide(index) {
  const slide = DATA.slides[index];
  if (!slide) return;

  const ok = window.confirm(
    'Delete slide "' + slide.name + '"?\n\n' +
    'This will remove the slide image, its visual object crops, and related data from metadata.json and detection_data.json.'
  );
  if (!ok) return;

  const nextIndex = DATA.slides.length > 1
    ? Math.max(0, Math.min(DATA.slides.length - 2, index))
    : 0;

  submitClassicSaveWithReturnSlide(
    { action: 'delete_slide', vid: DATA.vid, chapter: DATA.chapter, slide_name: slide.name },
    nextIndex
  );
}

function clamp(val, lo, hi) {
  return Math.max(lo, Math.min(hi, val));
}

function getBboxById(bboxId) {
  const state = slideStates[currentSlide().name];
  return state ? state.bboxes.find(b => b.id === bboxId) : null;
}

function markDirty() {
  dirty = true;
  saveBboxBtn.classList.add('dirty');
}

function setStatus(el, msg, type) {
  el.textContent = msg;
  el.className   = 'eobj-status eobj-status-' + type;
  if (type === 'success') {
    setTimeout(() => { el.textContent = ''; el.className = 'eobj-status'; }, 3000);
  }
}

// ── Slide list ────────────────────────────────────────────────────────────────
function renderSlideList() {
  // Wrap in scrollable inner div
  slideListEl.innerHTML =
    '<div class="eobj-slide-list-header">' +
      '<span>Slides <span class="eobj-slide-list-count">(' + DATA.slides.length + ')</span></span>' +
      '<button type="button" class="eobj-delete-mode-toggle" id="eobj-delete-mode-toggle">Delete Slides</button>' +
    '</div>' +
    '<div class="eobj-slide-list-inner" id="eobj-slide-list-inner"></div>';

  const toggleBtn = document.getElementById('eobj-delete-mode-toggle');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => setDeleteMode(!deleteMode));
  }

  const inner = document.getElementById('eobj-slide-list-inner');

  DATA.slides.forEach((slide, index) => {
    const btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'eobj-slide-item' + (index === currentSlideIndex ? ' active' : '');
    btn.dataset.index = index;

    const img    = document.createElement('img');
    img.src      = slide.url;
    img.alt      = slide.name;
    img.loading  = 'lazy';

    const nameEl = document.createElement('span');
    nameEl.className = 'eobj-slide-item-name';
    nameEl.textContent = slide.name;

    const countEl = document.createElement('span');
    countEl.className = 'eobj-slide-item-count';
    countEl.id        = 'slide-count-' + index;
    updateSlideCountEl(countEl, index);

    const delEl = document.createElement('span');
    delEl.className = 'eobj-slide-delete';
    delEl.title = 'Delete this slide';
    delEl.textContent = 'Delete';

    btn.appendChild(img);
    btn.appendChild(nameEl);
    btn.appendChild(countEl);
    btn.appendChild(delEl);
    btn.addEventListener('click', e => {
      if (e.target && e.target.closest('.eobj-slide-delete')) {
        e.preventDefault();
        e.stopPropagation();
        requestDeleteSlide(index);
        return;
      }
      loadSlide(index);
    });
    inner.appendChild(btn);
  });

  setDeleteMode(deleteMode);
}

function updateSlideCountEl(el, index) {
  const slide = DATA.slides[index];
  if (!slide || !el) return;
  const n = (slideStates[slide.name] || { bboxes: [] }).bboxes.length;
  el.textContent = n + ' bbox' + (n !== 1 ? 'es' : '');
}

function refreshSlideCount(index) {
  const el = document.getElementById('slide-count-' + index);
  if (el) updateSlideCountEl(el, index);
}

// ── Load slide ────────────────────────────────────────────────────────────────
function loadSlide(index) {
  currentSlideIndex = index;
  const slide = DATA.slides[index];

  // Active state in list
  slideListEl.querySelectorAll('.eobj-slide-item').forEach((item, i) => {
    item.classList.toggle('active', i === index);
  });

  slideNameEl.textContent = slide.name;

  // Load image then render bboxes
  if (slideImg.src !== slide.url) {
    slideImg.onload  = () => window.requestAnimationFrame(renderBboxes);
    slideImg.onerror = () => { slideImg.alt = 'Image failed to load'; };
    slideImg.src     = slide.url;
  } else {
    window.requestAnimationFrame(renderBboxes);
  }

  updateBboxCount();
}

function updateBboxCount() {
  const slide = currentSlide();
  if (!slide) return;
  const n = (slideStates[slide.name] || { bboxes: [] }).bboxes.length;
  bboxCountEl.textContent = n + ' bounding box' + (n !== 1 ? 'es' : '');
}

// ── Render bboxes ─────────────────────────────────────────────────────────────
function renderBboxes() {
  canvasWrap.querySelectorAll('.eobj-bbox').forEach(el => el.remove());

  const slide = currentSlide();
  if (!slide) return;
  const state = slideStates[slide.name];
  if (!state) return;

  const metrics = getRenderMetrics();
  if (metrics.renderW <= 0 || metrics.renderH <= 0) return;
  state.bboxes.forEach(bbox => createBboxElement(bbox, metrics));
  updateBboxCount();
}

function createBboxElement(bbox, metrics) {
  if (metrics === undefined) metrics = getRenderMetrics();
  const [x1, y1, x2, y2] = bbox.bbox_xyxy;

  const div = document.createElement('div');
  div.className         = 'eobj-bbox';
  div.dataset.bboxId    = bbox.id;
  div.style.left        = (x1 * metrics.scaleX) + 'px';
  div.style.top         = (y1 * metrics.scaleY) + 'px';
  div.style.width       = ((x2 - x1) * metrics.scaleX) + 'px';
  div.style.height      = ((y2 - y1) * metrics.scaleY) + 'px';

  // Label
  const labelEl = document.createElement('div');
  labelEl.className = 'eobj-bbox-label';
  labelEl.textContent = bbox.original_filename
    ? bbox.original_filename.replace(/^.+_(\d+)\.jpg$/, 'obj $1')
    : 'new';
  div.appendChild(labelEl);

  // Delete button
  const del = document.createElement('button');
  del.type        = 'button';
  del.className   = 'eobj-bbox-delete';
  del.title       = 'Delete box';
  del.textContent = '×';
  del.addEventListener('mousedown', e => e.stopPropagation());
  del.addEventListener('click',     e => { e.stopPropagation(); deleteBbox(bbox.id); });
  div.appendChild(del);

  // 8 resize handles
  ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(dir => {
    const h = document.createElement('div');
    h.className      = 'eobj-bbox-handle';
    h.dataset.dir    = dir;
    h.dataset.bboxId = bbox.id;
    div.appendChild(h);
  });

  canvasWrap.appendChild(div);
}

function deleteBbox(bboxId) {
  const slide = currentSlide();
  const state = slideStates[slide.name];
  state.bboxes = state.bboxes.filter(b => b.id !== bboxId);
  canvasWrap.querySelector('.eobj-bbox[data-bbox-id="' + bboxId + '"]')?.remove();
  refreshSlideCount(currentSlideIndex);
  updateBboxCount();
  markDirty();
}

// ── Mouse → inference coords ──────────────────────────────────────────────────
function imgRelativePos(e) {
  const rect  = slideImg.getBoundingClientRect();
  const metrics = getRenderMetrics();
  return {
    ix: clamp((e.clientX - rect.left) / metrics.scaleX, 0, spaceW()),
    iy: clamp((e.clientY - rect.top)  / metrics.scaleY, 0, spaceH()),
  };
}

// ── Canvas mousedown ──────────────────────────────────────────────────────────
function onCanvasMouseDown(e) {
  if (e.button !== 0) return;

  // Priority 1: resize handle
  const handle = e.target.closest('.eobj-bbox-handle');
  if (handle) {
    e.preventDefault();
    const bboxId = parseInt(handle.dataset.bboxId);
    const bbox   = getBboxById(bboxId);
    if (!bbox) return;
    dragState = {
      type:      'resize',
      dir:       handle.dataset.dir,
      bboxId,
      startPos:  imgRelativePos(e),
      startBbox: bbox.bbox_xyxy.slice(),
    };
    return;
  }

  // Priority 2: bbox body (move)
  const bboxEl = e.target.closest('.eobj-bbox');
  if (bboxEl && !e.target.closest('.eobj-bbox-delete') && !e.target.closest('.eobj-bbox-handle')) {
    e.preventDefault();
    const bboxId = parseInt(bboxEl.dataset.bboxId);
    const bbox   = getBboxById(bboxId);
    if (!bbox) return;
    dragState = {
      type:      'move',
      bboxId,
      startPos:  imgRelativePos(e),
      startBbox: bbox.bbox_xyxy.slice(),
    };
    return;
  }

  // Priority 3: draw new box on image/wrap background
  if (e.target === slideImg || e.target === canvasWrap) {
    e.preventDefault();
    const pos    = imgRelativePos(e);
    const rubber = document.createElement('div');
    rubber.id        = 'eobj-rubber';
    rubber.className = 'eobj-rubber';
    const metrics    = getRenderMetrics();
    rubber.style.left   = (pos.ix * metrics.scaleX) + 'px';
    rubber.style.top    = (pos.iy * metrics.scaleY) + 'px';
    rubber.style.width  = '0px';
    rubber.style.height = '0px';
    canvasWrap.appendChild(rubber);
    dragState = { type: 'draw', startPos: pos, rubber };
  }
}

// ── Document mousemove ────────────────────────────────────────────────────────
function onDocMouseMove(e) {
  if (!dragState) return;
  e.preventDefault();

  const pos     = imgRelativePos(e);
  const metrics = getRenderMetrics();

  if (dragState.type === 'move') {
    const bbox = getBboxById(dragState.bboxId);
    if (!bbox) return;
    const [ox1, oy1, ox2, oy2] = dragState.startBbox;
    const w  = ox2 - ox1;
    const h  = oy2 - oy1;
    const dx = pos.ix - dragState.startPos.ix;
    const dy = pos.iy - dragState.startPos.iy;
    const nx1 = clamp(ox1 + dx, 0, spaceW() - w);
    const ny1 = clamp(oy1 + dy, 0, spaceH() - h);
    bbox.bbox_xyxy = [Math.round(nx1), Math.round(ny1), Math.round(nx1 + w), Math.round(ny1 + h)];

    const el = canvasWrap.querySelector('.eobj-bbox[data-bbox-id="' + dragState.bboxId + '"]');
    if (el) {
      el.style.left = (bbox.bbox_xyxy[0] * metrics.scaleX) + 'px';
      el.style.top  = (bbox.bbox_xyxy[1] * metrics.scaleY) + 'px';
    }
  }

  else if (dragState.type === 'resize') {
    const bbox = getBboxById(dragState.bboxId);
    if (!bbox) return;
    const [ox1, oy1, ox2, oy2] = dragState.startBbox;
    const dx  = pos.ix - dragState.startPos.ix;
    const dy  = pos.iy - dragState.startPos.iy;
    const MIN = 10;
    const dir = dragState.dir;
    let nx1 = ox1, ny1 = oy1, nx2 = ox2, ny2 = oy2;

    if (dir.includes('w')) nx1 = clamp(ox1 + dx, 0, ox2 - MIN);
    if (dir.includes('e')) nx2 = clamp(ox2 + dx, ox1 + MIN, spaceW());
    if (dir.includes('n')) ny1 = clamp(oy1 + dy, 0, oy2 - MIN);
    if (dir.includes('s')) ny2 = clamp(oy2 + dy, oy1 + MIN, spaceH());

    bbox.bbox_xyxy = [Math.round(nx1), Math.round(ny1), Math.round(nx2), Math.round(ny2)];

    const el = canvasWrap.querySelector('.eobj-bbox[data-bbox-id="' + dragState.bboxId + '"]');
    if (el) {
      el.style.left   = (bbox.bbox_xyxy[0] * metrics.scaleX) + 'px';
      el.style.top    = (bbox.bbox_xyxy[1] * metrics.scaleY) + 'px';
      el.style.width  = ((bbox.bbox_xyxy[2] - bbox.bbox_xyxy[0]) * metrics.scaleX) + 'px';
      el.style.height = ((bbox.bbox_xyxy[3] - bbox.bbox_xyxy[1]) * metrics.scaleY) + 'px';
    }
  }

  else if (dragState.type === 'draw') {
    const sx = dragState.startPos.ix;
    const sy = dragState.startPos.iy;
    const rx = Math.min(sx, pos.ix);
    const ry = Math.min(sy, pos.iy);
    const rw = Math.abs(pos.ix - sx);
    const rh = Math.abs(pos.iy - sy);
    dragState.rubber.style.left   = (rx * metrics.scaleX) + 'px';
    dragState.rubber.style.top    = (ry * metrics.scaleY) + 'px';
    dragState.rubber.style.width  = (rw * metrics.scaleX) + 'px';
    dragState.rubber.style.height = (rh * metrics.scaleY) + 'px';
  }
}

// ── Document mouseup ──────────────────────────────────────────────────────────
function onDocMouseUp(e) {
  if (!dragState) return;
  const MIN = 10;

  if (dragState.type === 'draw') {
    const pos = imgRelativePos(e);
    const sx  = dragState.startPos.ix;
    const sy  = dragState.startPos.iy;
    dragState.rubber.remove();

    if (Math.abs(pos.ix - sx) > MIN && Math.abs(pos.iy - sy) > MIN) {
      const x1 = Math.round(Math.min(sx, pos.ix));
      const y1 = Math.round(Math.min(sy, pos.iy));
      const x2 = Math.round(Math.max(sx, pos.ix));
      const y2 = Math.round(Math.max(sy, pos.iy));

      const newBbox = {
        id:                ++bboxIdCounter,
        original_filename: null,
        bbox_xyxy:         [x1, y1, x2, y2],
        confidence:        null,
      };

      const slide = currentSlide();
      slideStates[slide.name].bboxes.push(newBbox);
      createBboxElement(newBbox, getRenderMetrics());
      refreshSlideCount(currentSlideIndex);
      updateBboxCount();
      markDirty();
    }
  } else if (dragState.type === 'move' || dragState.type === 'resize') {
    markDirty();
  }

  dragState = null;
}

// ── Save bboxes ───────────────────────────────────────────────────────────────
async function saveBboxes() {
  setStatus(saveStatusEl, 'Saving…', 'pending');
  saveBboxBtn.disabled = true;

  // Collect all slides' current bbox states
  const detections = {};
  const slideMeta = {};
  (DATA.slides || []).forEach(slide => {
    detections[slide.name] = (slideStates[slide.name] || { bboxes: [] }).bboxes.map(b => ({
      original_filename: b.original_filename,
      bbox_xyxy:         b.bbox_xyxy.slice(),
      confidence:        b.confidence,
    }));
    slideMeta[slide.name] = {
      coordW: slide.coordW || slide.inferenceW || 1280,
      coordH: slide.coordH || slide.inferenceH || 720,
    };
  });

  dirty = false;
  saveBboxBtn.classList.remove('dirty');
  submitClassicSave({ action: 'save_bboxes', vid: DATA.vid, chapter: DATA.chapter, detections, slideMeta });
}

// ── Selection panel ───────────────────────────────────────────────────────────
function renderSelectionPanel() {
  cropGridEl.innerHTML = '';

  if (!selectionState.length) {
    cropGridEl.innerHTML = '<p class="eobj-empty">No visual objects found for this chapter.</p>';
    return;
  }

  selectionState.forEach((crop, index) => {
    const card = document.createElement('div');
    card.className = 'eobj-crop-card';

    const imgWrap = document.createElement('div');
    imgWrap.className = 'eobj-crop-img-wrap';
    const img = document.createElement('img');
    img.src     = crop.url;
    img.alt     = crop.filename;
    img.loading = 'lazy';
    imgWrap.appendChild(img);

    const footer  = document.createElement('div');
    footer.className = 'eobj-crop-footer';

    const nameEl  = document.createElement('span');
    nameEl.className   = 'eobj-crop-name';
    nameEl.textContent = crop.filename;
    nameEl.title       = crop.filename;

    const toggle  = document.createElement('button');
    toggle.type      = 'button';
    toggle.className = 'eobj-sel-toggle ' + (crop.selected ? 'selected' : 'unselected');
    toggle.textContent = crop.selected ? '✓ Selected' : '✗ Unselected';
    toggle.addEventListener('click', () => {
      selectionState[index].selected = !selectionState[index].selected;
      toggle.className   = 'eobj-sel-toggle ' + (selectionState[index].selected ? 'selected' : 'unselected');
      toggle.textContent = selectionState[index].selected ? '✓ Selected' : '✗ Unselected';
    });

    footer.appendChild(nameEl);
    footer.appendChild(toggle);
    card.appendChild(imgWrap);
    card.appendChild(footer);
    cropGridEl.appendChild(card);
  });
}

// ── Save selection ────────────────────────────────────────────────────────────
async function saveSelection() {
  setStatus(selStatusEl, 'Saving…', 'pending');
  selSaveBtn.disabled = true;

  const selected   = selectionState.filter(c =>  c.selected).map(c => c.filename);
  const unselected = selectionState.filter(c => !c.selected).map(c => c.filename);

  submitClassicSave({ action: 'save_selection', vid: DATA.vid, chapter: DATA.chapter, selected, unselected });
}
