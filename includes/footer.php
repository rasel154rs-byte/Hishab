</main>

<footer class="footer">
  <div class="container">
    <p class="text-muted mb-0">© <?= date('Y') ?> <?= htmlspecialchars($t['app_name']) ?></p>
  </div>
</footer>

<script>
  window.APP_URL = '<?= htmlspecialchars(dirname($_SERVER['PHP_SELF'])) ?>';
  window.APP_LANG = '<?= htmlspecialchars($lang) ?>';
</script>
<script src="assets/js/app.js"></script>
</body>
</html>