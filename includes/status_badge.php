<?php

/**
 * Shared status badge renderer (V3 Phase 2.2).
 *
 * Before this file, ten helpers across includes/orders.php, shipments.php, ship_my_box.php,
 * supplier_orders.php and catalog.php each built their own <span class="badge bg-..."> and
 * each picked its own Bootstrap colour. The same business status therefore rendered
 * differently depending on which page you were looking at - `shipped` appeared as dark,
 * success, and primary in four different places, and `received` (a success step) shared
 * amber with `waiting_stock` (a blocked one).
 *
 * All ten now call status_badge() with a token from the shared five-value scale defined in
 * assets/css/components.css. See docs/V3_DESIGN_SYSTEM.md section 2.8.
 *
 * Display only. No status enum value, column, query, or workflow is affected by anything in
 * this file - it decides colour and nothing else.
 *
 * NOTE ON CROSS-DOMAIN STATUS NAMES: the rule is "one meaning per token, and a given enum
 * value in a given column always renders the same". It is NOT "the same word always renders
 * the same across unrelated tables". mewmii_orders.order_status = 'pending' means "not
 * started, nothing to do yet" (neutral), while ship_requests.status = 'pending' means
 * "customer submitted, staff must review" (warning) - ship_request_next_action() returns
 * 'Review Request' for it. Those are different business states that happen to share a word;
 * flattening them to one colour would delete an action signal from the Ship My Box queue.
 */

/**
 * The five semantic tokens, plus `outline` for a category rather than a state.
 *
 *   neutral  not started / inactive       "nothing to do yet"
 *   info     in progress, no action now   "moving, leave it"
 *   warning  blocked, action required     "I need to do something"
 *   success  complete / healthy           "done"
 *   danger   failed / cancelled / error   "something went wrong"
 *   outline  a category, not a state      product lifecycle, order source
 *
 * Brand pink is deliberately absent: it is reserved for actions and active states.
 */
const STATUS_BADGE_TOKENS = ['neutral', 'info', 'warning', 'success', 'danger', 'outline'];

/**
 * Render one status badge. $label is the human-facing text - never a raw enum value; every
 * caller passes its existing *_label() helper's output, which is unchanged by this phase.
 *
 * An unrecognised token falls back to `neutral` rather than emitting a broken class, matching
 * the `?? 'secondary'` default every one of these helpers already had.
 */
function status_badge(string $label, string $token): string
{
    if (!in_array($token, STATUS_BADGE_TOKENS, true)) {
        $token = 'neutral';
    }

    return '<span class="badge badge-status badge-status--' . $token . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}
