// ── Data injected from PHP ─────────────────────────────────────────────────────
const VIEWER_DATA = window.SURVEY_VIEWER_DATA || {};
const SLIDES = VIEWER_DATA.slides || [];
const TRANSCRIPT_VTT = VIEWER_DATA.transcriptVtt || '';
const SUMMARY_A = VIEWER_DATA.summaryA || '';
const SUMMARY_B = VIEWER_DATA.summaryB || '';
const SEG_START = VIEWER_DATA.segStart || 0;
const SEG_END = VIEWER_DATA.segEnd || 0;
const PREV_FAMILIARITY = VIEWER_DATA.prevFamiliarity || '';
const PREV_RATINGS = VIEWER_DATA.prevRatings || {};
const PREV_COMMENTS = VIEWER_DATA.prevComments || {};
const PREV_SELECTION_QUALITY_RATING = VIEWER_DATA.prevSelectionQualityRating || '';
const PREV_INCLUDE_IMPORTANT = VIEWER_DATA.prevIncludeImportant || [];
const PREV_EXCLUDE_UNIMPORTANT = VIEWER_DATA.prevExcludeUnimportant || [];
const PREV_INCLUDE_IMPORTANT_NONE = Boolean(VIEWER_DATA.prevIncludeImportantNone);
const PREV_EXCLUDE_UNIMPORTANT_NONE = Boolean(VIEWER_DATA.prevExcludeUnimportantNone);
const DIMENSIONS = VIEWER_DATA.dimensions || [];
const RATING_SCALE = VIEWER_DATA.ratingScale || { min: 1, max: 10 };
const TEXT_QUESTION_TOTAL = VIEWER_DATA.questionTotal || (1 + DIMENSIONS.length);
const VISUAL_QUESTION_TOTAL = 3;
const REQUIRED_QUESTION_TOTAL = TEXT_QUESTION_TOTAL + VISUAL_QUESTION_TOTAL;
const TIME_LABEL = VIEWER_DATA.timeLabel || '';

// ── Transcript ────────────────────────────────────────────────────────────────
const transcriptBody = document.getElementById('transcript-body');
const transcriptCues = parseVtt(TRANSCRIPT_VTT)
  .filter(cue => cue.end >= SEG_START && cue.start <= SEG_END);
let activeTranscriptIndex = -1;

function parseVttTime(value) {
  const time = value.trim().replace(',', '.');
  const parts = time.split(':').map(Number);
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 60 + parts[1];
  return Number(time) || 0;
}

function parseVtt(vtt) {
  return (vtt || '')
    .replace(/\r/g, '')
    .split(/\n{2,}/)
    .map(block => block.split('\n').map(line => line.trim()).filter(Boolean))
    .map(lines => {
      if (!lines.length || /^WEBVTT($|\s)/i.test(lines[0])) return null;
      if (/^(NOTE|STYLE|REGION)($|\s)/i.test(lines[0])) return null;
      const timingIndex = lines.findIndex(line => line.includes('-->'));
      if (timingIndex < 0) return null;
      const [startRaw, endRaw] = lines[timingIndex].split('-->');
      const endToken = (endRaw || '').trim().split(/\s+/)[0];
      const text = lines.slice(timingIndex + 1).join(' ').replace(/<[^>]+>/g, '').trim();
      if (!text) return null;
      return {
        start: parseVttTime(startRaw),
        end: parseVttTime(endToken),
        text
      };
    })
    .filter(Boolean);
}

function renderTranscript() {
  if (!transcriptBody) return;
  transcriptBody.innerHTML = '';
  if (!transcriptCues.length) {
    transcriptBody.textContent = '(no transcript)';
    return;
  }
  transcriptCues.forEach((cue, index) => {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'transcript-cue';
    item.dataset.index = String(index);
    item.innerHTML =
      '<span class="transcript-time">' + fmtTime(cue.start) + '</span>' +
      '<span class="transcript-text"></span>';
    item.querySelector('.transcript-text').textContent = cue.text;
    item.addEventListener('click', () => {
      if (!video) return;
      video.currentTime = Math.max(SEG_START, cue.start);
      syncTranscriptCue();
      refreshNativeCaptions();
      video.play().catch(() => {});
    });
    transcriptBody.appendChild(item);
  });
}

function syncTranscriptHeight() {
  const vid  = document.getElementById('chapter-video');
  const body = transcriptBody;
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
const noticeEl = document.getElementById('seg-notice');
let segmentRestricted = true;
let noticeTimer = null;

function fmtTime(s) {
  const m = Math.floor(s / 60), sec = Math.floor(s % 60);
  return String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
}

function hideNativeCaptionsByDefault() {
  if (!video || !video.textTracks) return;
  Array.from(video.textTracks).forEach(track => {
    track.mode = 'disabled';
  });
}

function refreshNativeCaptions() {
  if (!video || !video.textTracks) return;
  const showingTracks = Array.from(video.textTracks).filter(track => track.mode === 'showing');
  if (!showingTracks.length) return;
  showingTracks.forEach(track => {
    track.mode = 'hidden';
  });
  requestAnimationFrame(() => {
    showingTracks.forEach(track => {
      track.mode = 'showing';
    });
  });
}

function showSegmentNotice() {
  if (!noticeEl) return;
  noticeEl.hidden = false;
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => {
    noticeEl.hidden = true;
  }, 5000);
}

function hideSegmentNotice() {
  if (!noticeEl) return;
  clearTimeout(noticeTimer);
  noticeEl.hidden = true;
}

if (video) {
  hideNativeCaptionsByDefault();
  video.addEventListener('loadedmetadata', () => {
    hideNativeCaptionsByDefault();
    const dur = video.duration;
    rangeEl.style.left  = (SEG_START / dur * 100).toFixed(2) + '%';
    rangeEl.style.width = ((SEG_END - SEG_START) / dur * 100).toFixed(2) + '%';
    video.currentTime = SEG_START;
    updatePlayhead();
  });
  video.addEventListener('timeupdate', () => {
    updatePlayhead();
    syncTranscriptCue();
    if (!segmentRestricted) return;
    if (!video.paused && video.currentTime >= SEG_END) {
      showSegmentNotice();
      video.pause(); video.currentTime = SEG_END;
    } else if (!video.paused && video.currentTime < SEG_START) {
      showSegmentNotice();
      video.currentTime = SEG_START;
    }
  });
  video.addEventListener('seeked', () => {
    refreshNativeCaptions();
    if (!segmentRestricted) return;
    if (video.currentTime < SEG_START) {
      showSegmentNotice();
      video.currentTime = SEG_START;
    }
    if (video.currentTime > SEG_END) {
      showSegmentNotice();
      video.currentTime = SEG_END;
    }
  });
  video.addEventListener('play',  () => { document.getElementById('seg-play-btn').textContent = '⏸ Pause'; document.getElementById('seg-play-btn').classList.add('pausing'); });
  video.addEventListener('pause', () => { document.getElementById('seg-play-btn').textContent = '▶ Play'; document.getElementById('seg-play-btn').classList.remove('pausing'); });
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

function syncTranscriptCue() {
  if (!transcriptBody || !transcriptCues.length || !video) return;
  const current = video.currentTime;
  let nextIndex = transcriptCues.findIndex(cue => current >= cue.start && current < cue.end);
  if (nextIndex < 0 && current >= SEG_END) nextIndex = transcriptCues.length - 1;
  if (nextIndex === activeTranscriptIndex) return;
  const oldActive = transcriptBody.querySelector('.transcript-cue.active');
  if (oldActive) oldActive.classList.remove('active');
  activeTranscriptIndex = nextIndex;
  if (nextIndex < 0) return;
  const active = transcriptBody.querySelector('.transcript-cue[data-index="' + nextIndex + '"]');
  if (!active) return;
  active.classList.add('active');
  active.scrollIntoView({ block: 'nearest' });
}

function toggleRestrict() {
  segmentRestricted = !segmentRestricted;
  const btn  = document.getElementById('seg-restrict-btn');
  const hint = document.getElementById('seg-hint');
  if (segmentRestricted) {
    btn.classList.add('active'); btn.textContent = '■ Single Chapter Only';
    hint.textContent = 'Playback limited to ' + TIME_LABEL;
    if (video && (video.currentTime < SEG_START || video.currentTime > SEG_END)) {
      showSegmentNotice();
      video.pause(); video.currentTime = SEG_START;
      refreshNativeCaptions();
    }
  } else {
    btn.classList.remove('active'); btn.textContent = '□ Full video';
    hint.textContent = 'Full video unlocked';
    hideSegmentNotice();
  }
}

function jumpToSegment() {
  if (!video) return;
  if (!video.paused) { video.pause(); }
  else {
    video.currentTime = SEG_START;
    refreshNativeCaptions();
    video.play().catch(() => {});
  }
}

document.getElementById('seg-timeline').addEventListener('click', (e) => {
  if (!video) return;
  const rect = e.currentTarget.getBoundingClientRect();
  video.currentTime = (e.clientX - rect.left) / rect.width * (video.duration || 1);
  refreshNativeCaptions();
});

renderTranscript();

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
    'Scroll horizontally to browse  ·  Click to zoom';
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

function toggleSummaryComparison() {
  const row = document.getElementById('summaries-row');
  const btn = document.getElementById('summary-collapse-btn');
  const icon = document.getElementById('summary-collapse-icon');
  if (!row || !btn || !icon) return;
  const collapsed = !row.hidden;
  row.hidden = collapsed;
  btn.setAttribute('aria-expanded', String(!collapsed));
  btn.title = collapsed ? 'Expand summary comparison' : 'Collapse summary comparison';
  icon.innerHTML = collapsed ? '&#9656;' : '&#9662;';
}

// ── Questionnaire ─────────────────────────────────────────────────────────────
const DIMS = DIMENSIONS.map(dim => dim.id);
const ratings = {};
let familiaritySelected = false;
let visualRatingSelected = false;
const includeImportant = new Set();
const excludeUnimportant = new Set();
let includeImportantNone = false;
let excludeUnimportantNone = false;

// Build rating scale buttons for each dimension × version
DIMS.forEach(dim => {
  ['a','b'].forEach(ver => {
    const container = document.getElementById(dim + '-' + ver + '-btns');
    if (!container) return;
    for (let i = RATING_SCALE.min; i <= RATING_SCALE.max; i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'scale-btn';
      btn.textContent = i;
      btn.addEventListener('click', () => selectRating(dim, ver, i, btn));
      container.appendChild(btn);
    }
  });
});

const visualRatingContainer = document.getElementById('visual-rating-btns');
if (visualRatingContainer) {
  for (let i = RATING_SCALE.min; i <= RATING_SCALE.max; i++) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'scale-btn';
    btn.textContent = i;
    btn.addEventListener('click', () => selectVisualRating(i));
    visualRatingContainer.appendChild(btn);
  }
}

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
  if (display) {
    display.textContent = value + '/' + RATING_SCALE.max;
    display.className = 'rating-display has-' + ver;
  }
  // Update hidden input
  const input = document.getElementById(dim + '-' + ver + '-val');
  if (input) input.value = value;
  updateProgress();
}

function selectFamiliarity(btn) {
  document.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('familiarity-val').value = btn.dataset.v;
  familiaritySelected = true;
  updateProgress();
}

function selectVisualRating(value) {
  visualRatingSelected = true;
  const container = document.getElementById('visual-rating-btns');
  if (container) {
    container.querySelectorAll('.scale-btn').forEach(b => {
      b.classList.toggle('selected-visual', parseInt(b.textContent) === value);
    });
  }
  const display = document.getElementById('visual-rating-display');
  if (display) {
    display.textContent = value + '/' + RATING_SCALE.max;
    display.className = 'rating-display has-visual';
  }
  const input = document.getElementById('visual-selection-quality-val');
  if (input) input.value = value;
  updateProgress();
}

function syncVisualHiddenInputs(name, values) {
  const form = document.getElementById('qs-form');
  if (!form) return;
  form.querySelectorAll('input[data-visual-hidden="' + name + '"]').forEach(el => el.remove());
  values.forEach(value => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name + '[]';
    input.value = value;
    input.dataset.visualHidden = name;
    form.appendChild(input);
  });
}

function updateVisualChoice(btn) {
  const label = btn.dataset.label || '';
  const target = btn.dataset.target || '';
  if (target === 'include_important_none') {
    setVisualNone('include_important', !includeImportantNone);
    updateProgress();
    return;
  }
  if (target === 'exclude_unimportant_none') {
    setVisualNone('exclude_unimportant', !excludeUnimportantNone);
    updateProgress();
    return;
  }
  if (!label) return;
  if (target === 'include_important') {
    if (includeImportantNone) setVisualNone('include_important', false);
    if (includeImportant.has(label)) includeImportant.delete(label);
    else includeImportant.add(label);
    btn.classList.toggle('selected', includeImportant.has(label));
    syncVisualHiddenInputs('visual_include_important', [...includeImportant]);
  } else if (target === 'exclude_unimportant') {
    if (excludeUnimportantNone) setVisualNone('exclude_unimportant', false);
    if (excludeUnimportant.has(label)) excludeUnimportant.delete(label);
    else excludeUnimportant.add(label);
    btn.classList.toggle('selected', !excludeUnimportant.has(label));
    syncVisualHiddenInputs('visual_exclude_unimportant', [...excludeUnimportant]);
  }
  updateProgress();
}

function setVisualNone(target, enabled) {
  const isInclude = target === 'include_important';
  const hidden = document.getElementById(isInclude ? 'visual-include-important-none-val' : 'visual-exclude-unimportant-none-val');
  const noneBtn = document.querySelector('.visual-choice-btn[data-target="' + target + '_none"]');
  if (isInclude) {
    includeImportantNone = enabled;
    if (enabled) {
      includeImportant.clear();
      document.querySelectorAll('.visual-choice-btn[data-target="include_important"]').forEach(btn => btn.classList.remove('selected'));
      syncVisualHiddenInputs('visual_include_important', []);
    }
  } else {
    excludeUnimportantNone = enabled;
    if (enabled) {
      excludeUnimportant.clear();
      document.querySelectorAll('.visual-choice-btn[data-target="exclude_unimportant"]').forEach(btn => btn.classList.add('selected'));
      syncVisualHiddenInputs('visual_exclude_unimportant', []);
    }
  }
  if (hidden) hidden.value = enabled ? '1' : '0';
  if (noneBtn) noneBtn.classList.toggle('selected', enabled);
}

document.querySelectorAll('.visual-choice-btn').forEach(btn => {
  btn.addEventListener('click', () => updateVisualChoice(btn));
});

const visualColumnsSlider = document.getElementById('visual-columns-slider');
if (visualColumnsSlider) {
  const updateVisualColumns = () => {
    const layout = document.querySelector('.visual-study-layout');
    if (!layout) return;
    layout.style.setProperty('--visual-object-columns', visualColumnsSlider.value);
    const output = document.getElementById('visual-columns-output');
    if (output) output.value = visualColumnsSlider.value;
  };
  visualColumnsSlider.addEventListener('input', updateVisualColumns);
  updateVisualColumns();
}

function updateProgress() {
  const completedRatingQuestions = DIMS.filter(dim => ratings[dim + '-a'] && ratings[dim + '-b']).length;
  const textAnswered = completedRatingQuestions + (familiaritySelected ? 1 : 0);
  const textStarted = textAnswered > 0 || Object.keys(ratings).length > 0;
  const includeImportantAnswered = includeImportantNone || includeImportant.size > 0;
  const excludeUnimportantAnswered = excludeUnimportantNone || excludeUnimportant.size > 0;
  const visualAnswered = (visualRatingSelected ? 1 : 0) +
    (includeImportantAnswered ? 1 : 0) +
    (excludeUnimportantAnswered ? 1 : 0);
  const answered = textAnswered + visualAnswered;
  const progress = document.getElementById('survey-progress');
  progress.textContent =
    'Part 1: ' + textAnswered + '/' + TEXT_QUESTION_TOTAL +
    ' required · Part 2: ' + visualAnswered + '/' + VISUAL_QUESTION_TOTAL +
    ' required · Total: ' + answered + '/' + REQUIRED_QUESTION_TOTAL;
  progress.style.color = '';
  document.getElementById('save-later-btn').disabled =
    (!textStarted && visualAnswered === 0);
  updateStepStatuses(textAnswered, visualAnswered, textStarted);
}

function setStepStatus(id, status, answered, total) {
  const el = document.getElementById(id);
  if (!el) return;
  el.dataset.status = status;
  const label = status === 'completed'
    ? 'Completed'
    : (status === 'in_progress' ? 'In progress' : 'Not started');
  el.textContent = label + ' · ' + answered + '/' + total;
}

function updateStepStatuses(textAnswered, visualAnswered, textStarted) {
  const textStatus = !textStarted
    ? 'not_started'
    : (textAnswered >= TEXT_QUESTION_TOTAL ? 'completed' : 'in_progress');
  const visualStarted = visualAnswered > 0;
  const visualStatus = !visualStarted
    ? 'not_started'
    : (visualAnswered >= VISUAL_QUESTION_TOTAL ? 'completed' : 'in_progress');
  setStepStatus('step-status-text', textStatus, textAnswered, TEXT_QUESTION_TOTAL);
  setStepStatus('step-status-visual', visualStatus, visualAnswered, VISUAL_QUESTION_TOTAL);
}

function showStudyStep(step) {
  const isVisual = step === 'visual';
  document.getElementById('step-panel-text').hidden = isVisual;
  document.getElementById('step-panel-visual').hidden = !isVisual;
  document.getElementById('step-tab-text').classList.toggle('active', !isVisual);
  document.getElementById('step-tab-visual').classList.toggle('active', isVisual);
  document.getElementById('step-tab-text').setAttribute('aria-pressed', String(!isVisual));
  document.getElementById('step-tab-visual').setAttribute('aria-pressed', String(isVisual));
  document.getElementById('step-next-btn').hidden = isVisual;
  document.getElementById('submit-btn').hidden = !isVisual;
  document.getElementById('step-back-btn').hidden = !isVisual;
  document.querySelector('.questions-section').scrollIntoView({ block: 'start' });
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
      if (!container) return;
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

if (PREV_SELECTION_QUALITY_RATING) {
  selectVisualRating(parseInt(PREV_SELECTION_QUALITY_RATING));
}
PREV_INCLUDE_IMPORTANT.forEach(label => {
  const btn = document.querySelector('.visual-choice-btn[data-target="include_important"][data-label="' + label + '"]');
  if (btn) updateVisualChoice(btn);
});
if (PREV_INCLUDE_IMPORTANT_NONE) {
  setVisualNone('include_important', true);
}
PREV_EXCLUDE_UNIMPORTANT.forEach(label => {
  const btn = document.querySelector('.visual-choice-btn[data-target="exclude_unimportant"][data-label="' + label + '"]');
  if (btn) updateVisualChoice(btn);
});
if (PREV_EXCLUDE_UNIMPORTANT_NONE) {
  setVisualNone('exclude_unimportant', true);
}
updateProgress();

function saveLater() {
  document.getElementById('form-action').value = 'save_later';
  document.getElementById('qs-form').submit();
}

document.getElementById('qs-form').addEventListener('submit', function(e) {
  if (document.getElementById('form-action').value === 'save_later') return;
  const missing = [];
  let missingText = false;
  if (!familiaritySelected) missing.push('Q1 (Familiarity)');
  if (!familiaritySelected) missingText = true;
  DIMS.forEach((d, i) => {
    if (!ratings[d + '-a']) {
      missing.push('Q' + (i+2) + ' Version A');
      missingText = true;
    }
    if (!ratings[d + '-b']) {
      missing.push('Q' + (i+2) + ' Version B');
      missingText = true;
    }
  });
  if (!visualRatingSelected) missing.push('Part 2 Q1 (Selection Quality)');
  if (!includeImportantNone && includeImportant.size === 0) missing.push('Part 2 Q2 (Include Important)');
  if (!excludeUnimportantNone && excludeUnimportant.size === 0) missing.push('Part 2 Q3 (Exclude Unimportant)');
  if (missing.length) {
    e.preventDefault();
    const prog = document.getElementById('survey-progress');
    const textMissing = missing.filter(item => !item.startsWith('Part 2'));
    const visualRequiredMissing = missing.filter(item => item.startsWith('Part 2'));
    const parts = [];
    if (textMissing.length) parts.push('Part 1: ' + textMissing.slice(0, 2).join(', ') + (textMissing.length > 2 ? '…' : ''));
    if (visualRequiredMissing.length) parts.push('Part 2: ' + visualRequiredMissing.slice(0, 2).join(', ') + (visualRequiredMissing.length > 2 ? '…' : ''));
    prog.textContent = 'Please complete ' + parts.join(' · ');
    prog.style.color = '#b91c1c';
    showStudyStep(missingText ? 'text' : 'visual');
    prog.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});
