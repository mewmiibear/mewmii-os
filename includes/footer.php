<?php if (!defined('APP_START')) {
    require_once __DIR__ . '/bootstrap.php';
} ?>
</main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php /* V3 Phase 3.2 - the one shared confirmation dialog. Rendered once here rather than
         per call site, so 53 confirmations share one piece of markup and one behaviour.
         role/aria-labelledby are set by the script per tone: alertdialog for destructive
         actions, dialog otherwise. Nothing is bound to any specific action - see
         assets/js/confirm-dialog.js. */ ?>
<div class="modal fade" id="app-confirm-dialog" tabindex="-1" role="dialog"
     aria-labelledby="app-confirm-dialog-title" aria-describedby="app-confirm-dialog-body"
     aria-hidden="true" data-tone="neutral">
    <div class="modal-dialog modal-dialog-centered modal-confirm">
        <div class="modal-content">
            <div class="modal-header">
                <?php /* Deliberately a <div>, not a heading. Bootstrap's examples use one, but the
                         dialog's accessible name comes from aria-labelledby above, so a heading adds
                         nothing for assistive tech here - and this markup renders on all ~94 pages,
                         so an <h2> would inject a heading into every page's outline and corrupt the
                         heading-structure work in Phase 3.6. */ ?>
                <div class="modal-title" id="app-confirm-dialog-title" data-confirm-role="title">Are you sure?</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="app-confirm-dialog-body" data-confirm-role="body"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" data-confirm-role="cancel">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" data-confirm-role="confirm">Confirm</button>
            </div>
        </div>
    </div>
</div>
<script src="/assets/js/confirm-dialog.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/confirm-dialog.js'); ?>"></script>
</body>

</html>