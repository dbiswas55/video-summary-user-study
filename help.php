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
    <div class="print-title">Video Detailed Summary - User Study</div>
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
      method for lecture video chapters relative to a Baseline. As a participant,
      you may watch (or browse) short lecture chapters and then evaluate their
      summaries.
    </p>
    <p>The evaluation is organized into two parts:</p>
    <ul class="help-list">
      <li>
        <strong>Part 1 &mdash; Text Summary Evaluation:</strong>
        A side-by-side comparative evaluation of two anonymized text summaries.
      </li>
      <li>
        <strong>Part 2 &mdash; Visual Object Selection:</strong>
        An evaluation of a visual summary intended to complement the text summary
        and help form a more complete multimodal chapter summary.
      </li>
    </ul>
    <div class="help-note">
      <strong>Note:</strong> The two text summaries are labelled <em>Version A</em>
      and <em>Version B</em>. The method behind each version is intentionally hidden
      so that ratings remain unbiased.
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
      After logging in, the <strong>Dashboard</strong> is the landing page. It
      lists all lecture video chapters available for the courses you have selected
      (the course selection can be updated from the <strong>Profile</strong> page).
      Chapters are grouped first by <strong>course</strong> and then by
      <strong>video</strong>, and each course section can be collapsed or expanded.
      Each chapter row shows:
    </p>
    <ul class="help-list">
      <li>The chapter number and title.</li>
      <li>A progress badge — <em>Not started</em>, <em>In Progress</em>, or
          <em>&#10003; Done</em>.</li>
      <li>An action button — <em>Start Evaluation</em>, <em>Continue</em>, or
          <em>Review Again</em> — depending on the current progress.</li>
    </ul>
    <p>
      Selecting the action button on a chapter row opens the survey page for that
      chapter.
    </p>

    <h3 class="help-subsection-title">2.2 Survey Page Layout</h3>
    <p>The survey page is divided into four main areas stacked vertically:</p>

    <div class="help-areas">
      <div class="help-area-card">
        <div class="help-area-label">Area 1 — Video &amp; Transcript</div>
        <p>
          The lecture video appears at the top. By default, playback is limited to
          the current chapter; a <strong>Single Chapter Only</strong> toggle below
          the player can be turned off to play the whole video. Evaluations should
          be based on the current chapter content. A <strong>transcript panel</strong>
          beside the video allows you to click any line to jump to that moment, and
          on-screen subtitles can be toggled using the <em>CC</em> button on the
          player.
        </p>
        <p>
          A small <strong>segment timeline</strong> under the video shows the
          chapter's range and the current playback position.
        </p>
      </div>

      <div class="help-area-card">
        <div class="help-area-label">Area 2 — Slides</div>
        <p>
          Below the video, a <strong>slide strip</strong> shows thumbnail images of
          the video frames (slides) used in this chapter. Selecting any thumbnail
          opens a larger view, and the arrow keys may be used to step through
          slides.
        </p>
      </div>

      <div class="help-area-card">
        <div class="help-area-label">Area 3 — Summary Comparison</div>
        <p>
          <strong>Version A</strong> and <strong>Version B</strong> of the text
          summary are displayed side by side. Both summaries cover the same chapter.
          The whole section can be <em>collapsed</em> using the arrow next to
          <strong>Summary Comparison</strong>.
        </p>
        <p>
          The <strong>Normal</strong> and <strong>Diff View</strong> tabs at the top
          of this section switch between plain text and a highlighted comparison.
          In Diff View, text unique to Version A and text unique to Version B are
          highlighted in different colors, while shared text is left unmarked. This
          may help when comparing the two summaries.
        </p>
      </div>

      <div class="help-area-card">
        <div class="help-area-label">Area 4 — Survey Questions</div>
        <p>
          The survey questions appear below the summaries and are organized into
          two parts, accessible via the <strong>Part 1</strong> and
          <strong>Part 2</strong> tabs at the top of the question area. Each tab
          also shows the current completion status (e.g., <em>0/5</em>).
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
      It is recommended to read both summaries carefully before answering. Part 1
      contains five questions:
    </p>

    <h3 class="help-subsection-title">Q1 — Background (Familiarity)</h3>
    <p>
      This question asks how familiar you are with the topic of the current video
      chapter. The response helps us understand how background knowledge may
      influence the evaluation.
    </p>

    <h3 class="help-subsection-title">Q2–Q5 — Rating Dimensions</h3>
    <p>
      These four questions ask you to rate <em>both</em> Version A and Version B
      on the dimensions of <strong>Faithfulness</strong>,
      <strong>Completeness</strong>, <strong>Coherence</strong>, and
      <strong>Usefulness</strong> using a 1–10 scale. Each question on the survey
      page includes a short description and scale anchors for reference. An
      optional comment box is provided under each dimension for any additional
      remarks.
    </p>
  </section>

  <!-- ─── Section 4: Part 2 ────────────────────────────────────────────── -->
  <section class="help-section" id="sec-part2">
    <h2 class="help-section-title">
      <span class="help-section-num">4</span>
      Part 2 — Visual Object Selection
    </h2>
    <p>
      Part 2 focuses on evaluating a visual summary composed of
      <strong>four selected visual objects</strong> — cropped images extracted from
      key frames of the lecture video. These are intended to complement the text
      summary and form <strong>a more complete multimodal chapter summary</strong>.
    </p>
    <p>The visual objects are presented in two groups:</p>
    <ul class="help-list">
      <li>
        <strong>Top — Selected Visual Objects (S1, S2, &hellip;):</strong>
        The four objects automatically chosen for the visual summary.
      </li>
      <li>
        <strong>Bottom — Not Selected Visual Objects (U1, U2, &hellip;):</strong>
        Other objects extracted from the same video chapter that were
        <em>not</em> chosen for the visual summary.
      </li>
    </ul>
    <p>
      The <strong>Images per row</strong> slider above the grid may be used to
      resize the thumbnails for easier viewing.
    </p>
    <p>
      Part 2 contains three questions covering the overall quality of the visual
      summary and whether any important image objects should be added to it or removed from it.
      Each question on the survey page indicates which group of objects it refers
      to and what kind of response is expected, so it is recommended to review
      each question before responding.
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
          The <strong>Save &amp; Finish Later</strong> button at the bottom of the
          survey page saves any answered questions and returns to the dashboard.
          Progress is preserved, so the chapter can be resumed later from where it
          was left off. This button becomes active once at least one question has
          been answered.
        </p>
      </div>
      <div class="help-area-card">
        <div class="help-area-label">Submit Ratings</div>
        <p>
          Once all required questions in both Part 1 and Part 2 are answered, the
          <strong>Submit Ratings</strong> button becomes available. Submitting
          finalizes the response for the chapter and marks it as
          <em>&#10003; Done</em> on the dashboard.
        </p>
      </div>
    </div>

    <p>
      A progress indicator at the bottom of the survey page shows how many required
      questions in each part have been answered (for example,
      <em>Part 1: 3/5 required · Part 2: 1/3 required · Total: 4/8</em>), making it
      easy to see what remains before submitting.
    </p>

    <div class="help-note">
      <strong>Required questions:</strong> Five questions in Part 1 and three
      visual-object questions in Part 2 should be completed before the response
      can be submitted. Optional comments do not count toward completion.
    </div>
  </section>

  <!-- ─── Section 6: Tips ──────────────────────────────────────────────── -->
  <section class="help-section" id="sec-faq">
    <h2 class="help-section-title">
      <span class="help-section-num">6</span>
      Tips &amp; Frequently Asked Questions
    </h2>

    <dl class="help-faq">
      <dt>Is it necessary to watch the entire video chapter?</dt>
      <dd>
        Watching the video chapter is generally recommended, since the generated
        summaries are based solely on the content of that chapter. The transcript
        panel and slide thumbnails may also be helpful for navigating the content.
      </dd>

      <dt>Can a rating be changed after it has been selected?</dt>
      <dd>
        Yes. Selecting a different number on the same rating row, at any time
        before submitting, will update the answer. The most recent selection is
        the one that gets saved.
      </dd>

      <dt>Why are the summaries labelled Version A and Version B?</dt>
      <dd>
        The labels are anonymous to avoid bias. Knowing how a summary was generated
        could influence the ratings, which would affect the validity of the study.
      </dd>

      <dt>Can the chapters be completed in any order?</dt>
      <dd>
        Yes. Chapters may be completed in any order from the dashboard, and a
        completed chapter can be reviewed again via the <em>Review Again</em>
        button.
      </dd>

      <dt>Where can the Participant Guide be reopened from the survey page?</dt>
      <dd>
        The <strong>Participant Guide</strong> link in the top-right of each
        survey page reopens this guide in a new tab.
      </dd>

      <dt>What if there is a technical problem or question?</dt>
      <dd>
        The <strong>Contact</strong> link in the navigation bar can be used to
        send a message to the study team, or you may email us directly at
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
