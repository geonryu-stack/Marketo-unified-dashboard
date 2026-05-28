<?php
// pages/layout_footer.php
?>
</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<?php if (!empty($scripts)): ?>
  <?php foreach ($scripts as $src): ?>
    <script src="<?= APP_URL ?>/assets/js/<?= htmlspecialchars($src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
