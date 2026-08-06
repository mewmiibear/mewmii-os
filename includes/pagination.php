<?php

/**
 * Shared pagination component (V3 Phase 3.3/3.4).
 *
 * Seventeen list pages each carried their own copy of the same ~16 lines: a URL closure, a
 * range calculation, and the Showing/Prev/Next markup. They were verified byte-equivalent in
 * behaviour before extraction - all 17 built their link with
 * `http_build_query(array_merge($_GET, ['page' => $targetPage]))` and computed the range with
 * the identical expression - so this changes nothing about which page an operator lands on,
 * how many rows they see, or what the links point at. It is markup consolidation only.
 *
 * Emits exactly the markup those pages emitted, so a structural snapshot diff across the
 * conversion is empty.
 *
 * $basePath   the page's own path, e.g. '/modules/orders/index.php'
 * $noun       singular ('order'); the plural is $noun . 's' unless $pluralNoun is given
 *
 * Deliberately NOT included: page-number links. §2.13 specifies them for <= 7 pages, but no
 * page renders them today, and adding them here would change what every list shows in the
 * same commit that consolidates the markup. That is a separate, visible change.
 */
function render_pagination(
    string $basePath,
    int $page,
    int $totalPages,
    int $totalCount,
    int $perPage,
    string $noun,
    ?string $pluralNoun = null
): void {
    $plural = $pluralNoun ?? ($noun . 's');
    $rangeStart = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeEnd = min($totalCount, $page * $perPage);

    $pageUrl = static function (int $targetPage) use ($basePath): string {
        return $basePath . '?' . http_build_query(array_merge($_GET, ['page' => $targetPage]));
    };
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <p class="text-muted small mb-0">
            <?php if ($totalCount > 0): ?>
                Showing <?php echo (int) $rangeStart; ?>&ndash;<?php echo (int) $rangeEnd; ?> of <?php echo (int) $totalCount; ?> <?php echo app_escape($totalCount === 1 ? $noun : $plural); ?>
            <?php else: ?>
                0 <?php echo app_escape($plural); ?>
            <?php endif; ?>
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(max(1, $page - 1))); ?>">&laquo; Prev</a>
                <span class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo app_escape($pageUrl(min($totalPages, $page + 1))); ?>">Next &raquo;</a>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
