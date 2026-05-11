<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';

requireLogin();

$pageTitle = 'Participant Guide — User Study';
$pageStyles = ['assets/css/help.css'];

include __DIR__ . '/app/includes/header.php';
?>

<div class="help-page">

  <!-- ─── Print header (visible only in print) ─────────────────────────── -->
  <div class="print-header print-only">
    <div class="print-title">VideoPoints User Study</div>
    <div class="print-subtitle">Participant Guide</div>
  </div>

  <div class="help-layout">

    <!-- ─── Sidebar (screen only) ──────────────────────────────────────── -->
    <aside class="help-sidebar no-print">
      <div class="help-sidebar-inner">
        <div class="help-sidebar-heading">
          <span class="help-sidebar-title">Participant Guide</span>
          <button class="help-print-btn" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Save as PDF
          </button>
        </div>
        <nav class="help-nav" aria-label="Guide sections">
          <a href="#sec-purpose"  class="help-nav-link">1 &middot; Overview</a>
          <a href="#sec-overview" class="help-nav-link">2 &middot; Website Overview</a>
          <a href="#sec-part1"    class="help-nav-link">3 &middot; Part 1 — Text Evaluation</a>
          <a href="#sec-part2"    class="help-nav-link">4 &middot; Part 2 — Visual Objects</a>
          <a href="#sec-submit"   class="help-nav-link">5 &middot; Saving &amp; Submitting</a>
          <a href="#sec-faq"      class="help-nav-link">6 &middot; Tips &amp; FAQ</a>
        </nav>
      </div>
    </aside>

    <!-- ─── Main content ────────────────────────────────────────────────── -->
    <div class="help-main">

  <!-- ─── Section 1: Purpose ───────────────────────────────────────────── -->
  <section class="help-section" id="sec-purpose">
    <h2 class="help-section-title">
      <span class="help-section-num">1</span>
      Overview of the User Study
    </h2>
    <p>
      This study evaluates the performance of a proposed multimodal summarization
      method for lecture video chapters relative to a Baseline. You will be asked
      to watch (or browse) short lecture chapters and then evaluate their summaries.
    </p>
    <p>Each participant will complete the evaluation in two parts:</p>
    <ul class="help-list">
      <li>
        <strong>Part 1 &mdash; Text Summary:</strong>
        A side-by-side comparative evaluation of two anonymized text summaries.
      </li>
      <li>
        <strong>Part 2 &mdash; Visual Summary:</strong>
        Evaluation of the quality of a visual summary designed to complement the
        text summary and help form a more complete multimodal chapter summary.
      </li>
    </ul>
    <div class="help-note">
      <strong>Note:</strong> The two text summaries are labelled <em>Version A</em>
      and <em>Version B</em>. The method behind each version is intentionally hidden
      so that your ratings are unbiased.
    </div>
  </section>

  <!-- ─── Section 2: Website overview ─────────────────────────────────── -->
  <section class="help-section" id="sec-overview">
    <h2 class="help-section-title">
      <span class="help-section-num">2</span>
      Website Overview
    </h2>

    <h3 class="help-subsection-title">2.1 Dashboard</h3>
    <p>
      After logging in, you land on the <strong>Dashboard</strong>. The dashboard
      lists all lecture video chapters available to the selected courses (you can 
      change the selected courses by going to the <strong>Profile</strong> page). Each
      chapter card shows:
    </p>
    <ul class="help-list">
      <li>The chapter number and title.</li>
      <li>A progress badge — <em>Not Started</em>, <em>In Progress</em>, or
          <em>Completed</em>.</li>
    </ul>
    <p>
      Click <strong>Start</strong> (or <strong>Continue</strong>) on a chapter card
      to open the survey page for that chapter.
    </p>

    <h3 class="help-subsection-title">2.2 Survey Page Layout</h3>
    <p>The survey page is divided into three main areas stacked vertically:</p>

    <div class="help-areas">
      <div class="help-area-card">
        <div class="help-area-label">Area 1 — Video &amp; Transcript</div>
        <p>
          The lecture video is shown at the top. Playback is restricted to the
          current chapter by default. You can toggle a button to play whole video.
          But, your evaluation on summaries should be based on the video chapter. 
          A <strong>transcript panel</strong> beside the
          video lets you click any line to jump to that moment. You can toggle
          on-screen subtitles using the <em>CC</em> button on the player.
        </p>
        <p>
          A <strong>slide strip</strong> below the player shows thumbnail images of
          the slides used in this chapter. Click any thumbnail to enlarge it.
        </p>
      </div>

      <div class="help-area-card">
        <div class="help-area-label">Area 2 — Text Summaries</div>
        <p>
          Then you will find <strong>Summary Version A</strong> and
          <strong>Summary Version B</strong> displayed side by side. Both summaries
          cover the same chapter.
        </p>
        <p>
          Use the <strong>Diff View</strong> toggle (top-right of the summary panel)
          to highlight text that differs between the two versions. This can help you
          compare them more efficiently.
        </p>
      </div>

      <div class="help-area-card">
        <div class="help-area-label">Area 3 — Survey Questions</div>
        <p>
          The survey questions appear below the summaries and are split into two
          parts accessible via the <strong>Part 1</strong> and
          <strong>Part 2</strong> tabs at the top of the question area.
        </p>
      </div>
    </div>
  </section>

  <!-- ─── Section 3: Part 1 ────────────────────────────────────────────── -->
  <section class="help-section" id="sec-part1">
    <h2 class="help-section-title">
      <span class="help-section-num">3</span>
      Part 1 — Text Summary Evaluation
    </h2>
    <p>
      Read both summaries carefully before answering. Part 1 contains five questions:
    </p>

    <h3 class="help-subsection-title">Q1 — Background (Familiarity)</h3>
    <p>
      Select how familiar you are with the topic of this video chapter. This helps
      us understand how background knowledge influences evaluation.
    </p>

    <h3 class="help-subsection-title">Q2–Q5 — Rating Dimensions</h3>
    <p>
      Rate <em>both</em> Version A and Version B on four dimensions —
      <strong>Faithfulness</strong>, <strong>Completeness</strong>,
      <strong>Coherence</strong>, and <strong>Usefulness</strong> — using a
      1–10 scale. Each question on the survey page includes a short description
      and scale anchors to guide your rating. An optional comment box is also
      available under each dimension.
    </p>
  </section>

  <!-- ─── Section 4: Part 2 ────────────────────────────────────────────── -->
  <section class="help-section" id="sec-part2">
    <h2 class="help-section-title">
      <span class="help-section-num">4</span>
      Part 2 — Visual Object Selection
    </h2>
    <p>
      In Part 2, you evaluate a visual summary made up of <em>visual objects</em> —
      cropped images extracted from key frames of the lecture video. These are
      intended to complement the text summary to form a more complete chapter summary.
    </p>
    <p>The visual objects are shown in two groups:</p>
    <ul class="help-list">
      <li>
        <strong>Top — Selected Visual Objects (S1, S2, &hellip;):</strong>
        These objects were automatically chosen for the visual summary.
      </li>
      <li>
        <strong>Bottom — Unselected Visual Objects (U1, U2, &hellip;):</strong>
        These objects were extracted from the same video chapter but were
        <em>not</em> selected for the visual summary.
      </li>
    </ul>
    <p>
      Use the <strong>Objects per row</strong> slider above the grid to resize the images.
    </p>
    <p>
      Part 2 contains three questions. Please read each question on the survey page
      carefully — the wording tells you exactly which group of objects to look at
      and what action to take.
    </p>
  </section>

  <!-- ─── Section 5: Saving and Submitting ─────────────────────────────── -->
  <section class="help-section" id="sec-submit">
    <h2 class="help-section-title">
      <span class="help-section-num">5</span>
      Saving Your Progress and Submitting
    </h2>

    <div class="help-areas">
      <div class="help-area-card">
        <div class="help-area-label">Save &amp; Finish Later</div>
        <p>
          Click <strong>Save &amp; Finish Later</strong> at any time to save your
          current answers and return to the dashboard. Your progress is preserved
          and you can continue from where you left off.
        </p>
      </div>
      <div class="help-area-card">
        <div class="help-area-label">Submit Ratings</div>
        <p>
          Once all required questions in both Part 1 and Part 2 are answered, the
          <strong>Submit Ratings</strong> button becomes available. Submitting
          finalises your response for this chapter and marks it as
          <em>Completed</em> on the dashboard.
        </p>
      </div>
    </div>

    <p>
      The progress bar at the bottom of the survey page shows how many required
      questions in each part are answered, so you can see at a glance what remains
      before you can submit.
    </p>

    <div class="help-note">
      <strong>Required questions:</strong> Five questions in Part 1 and three visual-object 
      questions in Part 2 must be completed before you can submit. Optional
      comments do not count toward completion.
    </div>
  </section>

  <!-- ─── Section 6: Tips ──────────────────────────────────────────────── -->
  <section class="help-section" id="sec-faq">
    <h2 class="help-section-title">
      <span class="help-section-num">6</span>
      Tips &amp; Frequently Asked Questions
    </h2>

    <dl class="help-faq">
      <dt>Do I need to watch the entire video?</dt>
      <dd>
        Generally, it is recommended to watch the video chapter to evaluate the summaries as
        the generated summaries are only based on the video chapter content. 
      </dd>

      <dt>Can I change a rating after clicking it?</dt>
      <dd>
        Yes. Click a different number on the same rating row at any time before
        you submit. Your most recent selection is saved.
      </dd>

      <dt>Why are the summaries labelled Version A and Version B?</dt>
      <dd>
        The labels are anonymous to avoid bias. Knowing how a summary was generated
        could influence your ratings, which would affect the validity of the study.
      </dd>

      <dt>Can I complete the chapters in any order?</dt>
      <dd>
        Yes. You may complete the chapters in any order from your dashboard.
      </dd>

      <dt>What if I have a technical problem or question?</dt>
      <dd>
        Use the <strong>Contact</strong> link in the navigation bar to send a
        message to the study team, or email us directly at
        <a href="mailto:dbiswas@cougarnet.uh.edu">dbiswas@cougarnet.uh.edu</a> or
        <a href="mailto:jsubhlok@central.uh.edu">jsubhlok@central.uh.edu</a>.
        We will respond as soon as possible.
      </dd>
    </dl>
  </section>

    </div><!-- .help-main -->
  </div><!-- .help-layout -->
</div><!-- .help-page -->

<script>
(function () {
  var links = document.querySelectorAll('.help-nav-link');
  if (!links.length || !('IntersectionObserver' in window)) return;
  var ids = Array.from(links).map(function (l) { return l.getAttribute('href').slice(1); });
  var current = ids[0];
  function setActive(id) {
    if (id === current) return;
    current = id;
    links.forEach(function (l) {
      l.classList.toggle('is-active', l.getAttribute('href') === '#' + id);
    });
  }
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) setActive(e.target.id); });
  }, { rootMargin: '-10% 0px -75% 0px', threshold: 0 });
  ids.forEach(function (id) { var el = document.getElementById(id); if (el) observer.observe(el); });
  if (links[0]) links[0].classList.add('is-active');
}());
</script>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
