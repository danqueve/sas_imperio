<?php
// views/layout_footer.php — cierra main y body
?>
</main><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<div id="toast-container"></div>

<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<?php if (!empty($page_scripts))
    echo $page_scripts; ?>
</body>

</html>