</main>

<footer class="site-footer">
  <div class="footer-inner">
    <span>© Dipayan Biswas — VideoPoints, University of Houston</span>
    <span>
      <span class="footer-meta">Contact Email:</span>
      <a class="footer-meta" href="mailto:thevideopoints@gmail.com">thevideopoints@gmail.com</a>
    </span>
  </div>
</footer>

<?php foreach ((array)($pageScripts ?? []) as $script): ?>
<script src="<?= assetUrl($script) ?>"></script>
<?php endforeach; ?>

</body>
</html>