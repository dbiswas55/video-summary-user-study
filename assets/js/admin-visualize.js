const ADMIN_DATA = window.ADMIN_VISUALIZE_DATA || {};
const TRANSCRIPT_VTT = ADMIN_DATA.transcriptVtt || '';

const transcriptBody = document.getElementById('transcript-body');
const transcriptToggleBtn = document.getElementById('toggle-btn');
const video = document.getElementById('chapter-video');
const currentTimeEl = document.getElementById('video-current-time');
const videoId = new URLSearchParams(window.location.search).get('vid') || 'unknown';
const chapterCollapseStorageKeyPrefix = 'admin-visualize-collapse:' + videoId + ':';

let transcriptVisible = true;
let activeTranscriptIndex = -1;
let lightboxSlides = [];
let lightboxIndex = 0;
let activeChapterStopHandler = null;

function fmtTime(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
}

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

const transcriptCues = parseVtt(TRANSCRIPT_VTT);

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
      video.currentTime = cue.start;
      video.play().catch(() => {});
      syncTranscriptCue();
    });
    transcriptBody.appendChild(item);
  });
}

function syncTranscriptCue() {
  if (!transcriptBody || !transcriptCues.length || !video) return;
  const current = video.currentTime;
  const nextIndex = transcriptCues.findIndex(cue => current >= cue.start && current < cue.end);
  if (nextIndex === activeTranscriptIndex) return;

  const prev = transcriptBody.querySelector('.transcript-cue.active');
  if (prev) prev.classList.remove('active');
  activeTranscriptIndex = nextIndex;
  if (nextIndex < 0) return;

  const active = transcriptBody.querySelector('.transcript-cue[data-index="' + nextIndex + '"]');
  if (!active) return;
  active.classList.add('active');
  active.scrollIntoView({ block: 'nearest' });
}

function syncTranscriptHeight() {
  if (!video || !transcriptBody) return;
  const height = video.getBoundingClientRect().height;
  if (height > 60) transcriptBody.style.height = height + 'px';
}

function toggleTranscript() {
  if (!transcriptBody || !transcriptToggleBtn) return;
  transcriptVisible = !transcriptVisible;
  transcriptBody.style.display = transcriptVisible ? '' : 'none';
  transcriptToggleBtn.innerHTML = transcriptVisible ? '&#8250;' : '&#8249;';
  transcriptToggleBtn.title = transcriptVisible ? 'Hide transcript' : 'Show transcript';
}

function renderSlideGallery(stripEl, slides) {
  slides.forEach((src, index) => {
    const img = document.createElement('img');
    img.src = src;
    img.alt = 'Slide ' + (index + 1);
    img.title = 'Slide ' + (index + 1) + ' - click to zoom';
    img.addEventListener('click', () => openLightbox(slides, index));
    stripEl.appendChild(img);
  });
}

function openLightbox(slides, index) {
  lightboxSlides = slides;
  lightboxIndex = index;
  renderLightbox();
  document.getElementById('lightbox').classList.add('open');
}

function renderLightbox() {
  const image = document.getElementById('lightbox-img');
  const counter = document.getElementById('lb-counter');
  const prev = document.getElementById('lb-prev');
  const next = document.getElementById('lb-next');
  image.src = lightboxSlides[lightboxIndex] || '';
  image.alt = 'Slide ' + (lightboxIndex + 1);
  counter.textContent = (lightboxIndex + 1) + ' / ' + lightboxSlides.length;
  prev.disabled = lightboxIndex === 0;
  next.disabled = lightboxIndex === lightboxSlides.length - 1;
}

function stepLightbox(step) {
  lightboxIndex = Math.max(0, Math.min(lightboxSlides.length - 1, lightboxIndex + step));
  renderLightbox();
}

function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.getElementById('lightbox-img').src = '';
}

function jumpToChapter(start, end) {
  if (!video) return;
  if (activeChapterStopHandler) {
    video.removeEventListener('timeupdate', activeChapterStopHandler);
    activeChapterStopHandler = null;
  }

  video.currentTime = start;
  video.play().catch(() => {});

  activeChapterStopHandler = () => {
    if (video.currentTime >= end) {
      video.pause();
      video.currentTime = end;
      video.removeEventListener('timeupdate', activeChapterStopHandler);
      activeChapterStopHandler = null;
    }
  };

  video.addEventListener('timeupdate', activeChapterStopHandler);
}

function applyChapterExpandedState(button, expanded) {
  const panel = document.getElementById(button.getAttribute('aria-controls'));
  const icon = button.querySelector('.admin-chapter-toggle-icon');
  if (!panel || !icon) return;
  button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  panel.hidden = !expanded;
  icon.innerHTML = expanded ? '&#9662;' : '&#9656;';
}

document.querySelectorAll('.chapter-slide-strip').forEach((stripEl) => {
  const slides = JSON.parse(stripEl.dataset.gallery || '[]');
  renderSlideGallery(stripEl, slides);
});

document.querySelectorAll('.admin-chapter-toggle').forEach((button) => {
  const panelId = button.getAttribute('aria-controls');
  const stored = sessionStorage.getItem(chapterCollapseStorageKeyPrefix + panelId);
  if (stored === 'collapsed') {
    applyChapterExpandedState(button, false);
  }

  button.addEventListener('click', () => {
    const expanded = button.getAttribute('aria-expanded') === 'true';
    const nextExpanded = !expanded;
    applyChapterExpandedState(button, nextExpanded);
    sessionStorage.setItem(
      chapterCollapseStorageKeyPrefix + panelId,
      nextExpanded ? 'expanded' : 'collapsed'
    );
  });
});

document.querySelectorAll('.admin-play-chapter-btn').forEach((button) => {
  button.addEventListener('click', () => {
    const start = Number(button.dataset.start || 0);
    const end = Number(button.dataset.end || start);
    jumpToChapter(start, end);
  });
});

if (transcriptToggleBtn) {
  transcriptToggleBtn.addEventListener('click', toggleTranscript);
}

if (video) {
  video.addEventListener('loadedmetadata', syncTranscriptHeight);
  video.addEventListener('timeupdate', () => {
    if (currentTimeEl) currentTimeEl.textContent = fmtTime(video.currentTime);
    syncTranscriptCue();
  });
}

window.addEventListener('resize', syncTranscriptHeight);

document.getElementById('lightbox').addEventListener('click', (event) => {
  if (event.target.id === 'lightbox') closeLightbox();
});
document.getElementById('lb-close').addEventListener('click', closeLightbox);
document.getElementById('lb-prev').addEventListener('click', () => stepLightbox(-1));
document.getElementById('lb-next').addEventListener('click', () => stepLightbox(1));
document.addEventListener('keydown', (event) => {
  const lightbox = document.getElementById('lightbox');
  if (!lightbox.classList.contains('open')) return;
  if (event.key === 'Escape') closeLightbox();
  if (event.key === 'ArrowLeft') stepLightbox(-1);
  if (event.key === 'ArrowRight') stepLightbox(1);
});

renderTranscript();
syncTranscriptHeight();