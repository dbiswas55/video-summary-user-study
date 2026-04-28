// ── Data injected from PHP ─────────────────────────────────────────────────────
const VIEWER_DATA = window.SURVEY_VIEWER_DATA || {};
const SLIDES = VIEWER_DATA.slides || [];
const TRANSCRIPT = VIEWER_DATA.transcript || '';
const SUMMARY_A = VIEWER_DATA.summaryA || '';
const SUMMARY_B = VIEWER_DATA.summaryB || '';
const SEG_START = VIEWER_DATA.segStart || 0;
const SEG_END = VIEWER_DATA.segEnd || 0;
const PREV_FAMILIARITY = VIEWER_DATA.prevFamiliarity || '';
const PREV_RATINGS = VIEWER_DATA.prevRatings || {};
const PREV_COMMENTS = VIEWER_DATA.prevComments || {};
const TIME_LABEL = VIEWER_DATA.timeLabel || '';

// ── Transcript ────────────────────────────────────────────────────────────────
document.getElementById('transcript-body').textContent = TRANSCRIPT || '(no transcript)';

function syncTranscriptHeight() {
  const vid  = document.getElementById('chapter-video');
  const body = document.getElementById('transcript-body');
  if (!vid || !body) return;
  const h = vid.getBoundingClientRect().height;
  if (h > 60) body.style.height = h + 'px';
}

let transcriptVisible = true;
function toggleTranscript() {
  const body = document.getElementById('transcript-body');
  const btn  = document.getElementById('toggle-btn');
  transcriptVisible = !transcriptVisible;
  body.style.display = transcriptVisible ? '' : 'none';
  btn.innerHTML = transcriptVisible ? '&#8250;' : '&#8249;';
  btn.title     = transcriptVisible ? 'Hide transcript' : 'Show transcript';
}

// ── Video player ──────────────────────────────────────────────────────────────
const video    = document.getElementById('chapter-video');
const playhead = document.getElementById('seg-playhead');
const rangeEl  = document.getElementById('seg-range');
const timeEl   = document.getElementById('seg-current-time');
let segmentRestricted = true;

function fmtTime(s) {
  const m = Math.floor(s / 60), sec = Math.floor(s % 60);
  return String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
}

if (video) {
  video.addEventListener('loadedmetadata', () => {
    const dur = video.duration;
    rangeEl.style.left  = (SEG_START / dur * 100).toFixed(2) + '%';
    rangeEl.style.width = ((SEG_END - SEG_START) / dur * 100).toFixed(2) + '%';
    video.currentTime = SEG_START;
    updatePlayhead();
  });
  video.addEventListener('timeupdate', () => {
    updatePlayhead();
    if (!segmentRestricted) return;
    if (!video.paused && video.currentTime >= SEG_END) {
      video.pause(); video.currentTime = SEG_END;
    } else if (!video.paused && video.currentTime < SEG_START) {
      video.currentTime = SEG_START;
    }
  });
  video.addEventListener('seeked', () => {
    if (!segmentRestricted) return;
    if (video.currentTime < SEG_START) video.currentTime = SEG_START;
    if (video.currentTime > SEG_END)   video.currentTime = SEG_END;
  });
  video.addEventListener('play',  () => { document.getElementById('seg-play-btn').textContent = '⏸ Pause'; document.getElementById('seg-play-btn').classList.add('pausing'); });
  video.addEventListener('pause', () => { document.getElementById('seg-play-btn').textContent = '▶ Play Segment'; document.getElementById('seg-play-btn').classList.remove('pausing'); });
  video.addEventListener('loadedmetadata', () => setTimeout(syncTranscriptHeight, 50));
}
window.addEventListener('resize', syncTranscriptHeight);
setTimeout(syncTranscriptHeight, 400);

function updatePlayhead() {
  if (!video) return;
  const dur = video.duration || 1;
  playhead.style.left = (video.currentTime / dur * 100).toFixed(2) + '%';
  timeEl.textContent  = fmtTime(video.currentTime);
}

function toggleRestrict() {
  segmentRestricted = !segmentRestricted;
  const btn  = document.getElementById('seg-restrict-btn');
  const hint = document.getElementById('seg-hint');
  if (segmentRestricted) {
    btn.classList.add('active'); btn.textContent = '■ Segment only';
    hint.textContent = 'Restricted to ' + TIME_LABEL;
    if (video && (video.currentTime < SEG_START || video.currentTime > SEG_END)) {
      video.pause(); video.currentTime = SEG_START;
    }
  } else {
    btn.classList.remove('active'); btn.textContent = '□ Free play';
    hint.textContent = 'Full video unlocked';
  }
}

function jumpToSegment() {
  if (!video) return;
  if (!video.paused) { video.pause(); }
  else { video.currentTime = SEG_START; video.play().catch(() => {}); }
}

document.getElementById('seg-timeline').addEventListener('click', (e) => {
  if (!video) return;
  const rect = e.currentTarget.getBoundingClientRect();
  video.currentTime = (e.clientX - rect.left) / rect.width * (video.duration || 1);
});

// ── Slides ────────────────────────────────────────────────────────────────────
const stripEl = document.getElementById('slide-strip');
if (SLIDES.length > 0) {
  SLIDES.forEach((src, i) => {
    const img = document.createElement('img');
    img.src   = src;
    img.alt   = 'Slide ' + (i + 1);
    img.title = 'Slide ' + (i + 1) + ' — click to zoom';
    img.addEventListener('click', () => openLightbox(i));
    stripEl.appendChild(img);
  });
  document.getElementById('slide-footer').textContent =
    SLIDES.length + ' slide' + (SLIDES.length !== 1 ? 's' : '') +
    '  ·  scroll horizontally to browse  ·  click to zoom';
} else {
  document.getElementById('slide-section').innerHTML =
    '<p style="color:#6e6e73;padding:16px 20px;">No slide images found.</p>';
}

// ── Lightbox ──────────────────────────────────────────────────────────────────
let lbIndex = 0;
function openLightbox(i) { lbIndex = i; _lbRender(); document.getElementById('lightbox').classList.add('open'); }
function _lbRender() {
  document.getElementById('lightbox-img').src = SLIDES[lbIndex];
  document.getElementById('lightbox-img').alt = 'Slide ' + (lbIndex + 1);
  document.getElementById('lb-counter').textContent = (lbIndex + 1) + ' / ' + SLIDES.length;
  document.getElementById('lb-prev').disabled = (lbIndex === 0);
  document.getElementById('lb-next').disabled = (lbIndex === SLIDES.length - 1);
}
function lbStep(d) { lbIndex = Math.max(0, Math.min(SLIDES.length - 1, lbIndex + d)); _lbRender(); }
function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); document.getElementById('lightbox-img').src = ''; }
function lbBackdropClick(e) { if (e.target === document.getElementById('lightbox')) closeLightbox(); }
document.addEventListener('keydown', e => {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'Escape') closeLightbox();
  if (e.key === 'ArrowLeft')  lbStep(-1);
  if (e.key === 'ArrowRight') lbStep(1);
});

// ── Summary rendering ─────────────────────────────────────────────────────────
marked.setOptions({ breaks: true, gfm: true });
function renderNormal() {
  document.getElementById('summary-a').innerHTML = marked.parse(SUMMARY_A || '*(no content)*');
  document.getElementById('summary-b').innerHTML = marked.parse(SUMMARY_B || '*(no content)*');
}
function renderDiff() {
  const changes = Diff.diffWords(SUMMARY_A || '', SUMMARY_B || '');
  let mdA = '', mdB = '';
  changes.forEach(c => {
    if (!c.added)   mdA += c.removed ? '<mark class="diff-a">' + c.value + '</mark>' : c.value;
    if (!c.removed) mdB += c.added   ? '<mark class="diff-b">' + c.value + '</mark>' : c.value;
  });
  document.getElementById('summary-a').innerHTML = marked.parse(mdA || '*(no content)*');
  document.getElementById('summary-b').innerHTML = marked.parse(mdB || '*(no content)*');
}
function setView(mode) {
  document.getElementById('tab-normal').classList.toggle('active', mode === 'normal');
  document.getElementById('tab-diff').classList.toggle('active',   mode === 'diff');
  document.getElementById('diff-legend').hidden = mode !== 'diff';
  if (mode === 'diff') renderDiff(); else renderNormal();
}
renderNormal();

// ── Questionnaire ─────────────────────────────────────────────────────────────
const DIMS = ['faithfulness','completeness','coherence','usefulness'];
const ratings = {};
let familiaritySelected = false;

// Build 1–10 scale buttons for each dimension × version
DIMS.forEach(dim => {
  ['a','b'].forEach(ver => {
    const container = document.getElementById(dim + '-' + ver + '-btns');
    for (let i = 1; i <= 10; i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'scale-btn';
      btn.textContent = i;
      btn.addEventListener('click', () => selectRating(dim, ver, i, btn));
      container.appendChild(btn);
    }
  });
});

function selectRating(dim, ver, value, clickedBtn) {
  const key = dim + '-' + ver;
  ratings[key] = value;
  // Update button highlight
  const container = document.getElementById(dim + '-' + ver + '-btns');
  container.querySelectorAll('.scale-btn').forEach(b => {
    b.classList.remove('selected-a', 'selected-b');
    if (parseInt(b.textContent) === value) {
      b.classList.add(ver === 'a' ? 'selected-a' : 'selected-b');
    }
  });
  // Update display label
  const display = document.getElementById(dim + '-' + ver + '-display');
  display.textContent = value + '/10';
  display.className = 'rating-display has-' + ver;
  // Update hidden input
  document.getElementById(dim + '-' + ver + '-val').value = value;
  updateProgress();
}

function selectFamiliarity(btn) {
  document.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('familiarity-val').value = btn.dataset.v;
  familiaritySelected = true;
  updateProgress();
}

function updateProgress() {
  const answered = Object.keys(ratings).length + (familiaritySelected ? 1 : 0);
  document.getElementById('survey-progress').textContent =
    answered + ' of 9 questions answered';
  document.getElementById('save-later-btn').disabled = (answered === 0);
}

// ── Pre-fill from previous submission ────────────────────────────────────────
if (PREV_FAMILIARITY) {
  const btn = document.querySelector('.choice-btn[data-v="' + PREV_FAMILIARITY + '"]');
  if (btn) selectFamiliarity(btn);
}
DIMS.forEach(dim => {
  ['a', 'b'].forEach(ver => {
    const prevVal = (PREV_RATINGS[dim] || {})[ver.toUpperCase()];
    if (prevVal) {
      const container = document.getElementById(dim + '-' + ver + '-btns');
      const btn = [...container.querySelectorAll('.scale-btn')].find(b => parseInt(b.textContent) === prevVal);
      if (btn) selectRating(dim, ver, prevVal, btn);
    }
  });
  const prevText = PREV_COMMENTS[dim] || '';
  if (prevText) {
    const ta = document.querySelector('textarea[name="comment[' + dim + ']"]');
    if (ta) ta.value = prevText;
  }
});

function saveLater() {
  document.getElementById('form-action').value = 'save_later';
  document.getElementById('qs-form').submit();
}

document.getElementById('qs-form').addEventListener('submit', function(e) {
  if (document.getElementById('form-action').value === 'save_later') return;
  const missing = [];
  if (!familiaritySelected) missing.push('Q1 (Familiarity)');
  DIMS.forEach((d, i) => {
    if (!ratings[d + '-a']) missing.push('Q' + (i+2) + ' Version A');
    if (!ratings[d + '-b']) missing.push('Q' + (i+2) + ' Version B');
  });
  if (missing.length) {
    e.preventDefault();
    const prog = document.getElementById('survey-progress');
    prog.textContent = 'Please complete: ' + missing.slice(0,3).join(', ') + (missing.length > 3 ? '…' : '');
    prog.style.color = '#b91c1c';
    prog.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});
