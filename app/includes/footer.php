</main>

<footer class="site-footer">
  <div class="footer-inner">
    <span>&copy; <?= date('Y') ?> Dipayan Biswas, Department of Computer Science, University of Houston</span>
    <span>
      <a class="footer-meta" href="https://videopoints.org/" target="_blank" rel="noopener">VideoPoints</a>
      <span class="footer-sep">·</span>
      <a class="footer-meta" href="mailto:thevideopoints@gmail.com">thevideopoints@gmail.com</a>
    </span>
  </div>
</footer>

<?php foreach ((array)($pageScripts ?? []) as $script): ?>
<script src="<?= assetUrl($script) ?>"></script>
<?php endforeach; ?>

</body>
</html>
