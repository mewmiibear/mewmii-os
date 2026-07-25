<?php

/**
 * Shared reporting helpers (UI/UX Phase 5F.1) - kept separate from any one report so
 * modules/reports/sales.php and modules/reports/inventory.php can both build a date-window
 * WHERE fragment off the exact same period options, instead of each hand-rolling its own
 * switch statement. Pure string composition - no query execution, no business logic.
 */

const REPORT_PERIOD_OPTIONS = ['all', 'today', '7days', '30days', '90days'];

const REPORT_PERIOD_LABELS = [
    'all' => 'All Time',
    'today' => 'Today',
    '7days' => 'Last 7 Days',
    '30days' => 'Last 30 Days',
    '90days' => 'Last 90 Days',
];

/**
 * Whitelist a raw ?period= value against REPORT_PERIOD_OPTIONS, same "invalid input silently
 * becomes the default" convention used by every filter in this app. $default lets a report
 * pick its own fallback (modules/reports/sales.php uses '30days'; a report where "all time"
 * is the more useful default can pass 'all').
 */
function report_period_from_request(?string $rawPeriod, string $default): string
{
    return in_array($rawPeriod, REPORT_PERIOD_OPTIONS, true) ? $rawPeriod : $default;
}

/**
 * The AND-prefixed SQL date-range fragment for one period value, relocated verbatim from
 * modules/reports/sales.php's own inline switch (UI/UX Phase 5F.1 extraction - identical
 * date ranges, identical DATE_SUB()/CURDATE() expressions, just reusable). $dateColumn is the
 * fully-qualified column to filter on (e.g. 'o.order_date') so a second report can point it
 * at its own aliased table without this function needing to know the query shape around it.
 */
function report_period_date_condition(string $period, string $dateColumn): string
{
    switch ($period) {
        case 'today':
            return " AND {$dateColumn} = CURDATE()";
        case '7days':
            return " AND {$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
        case '30days':
            return " AND {$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
        case '90days':
            return " AND {$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL 89 DAY)";
        case 'all':
        default:
            return '';
    }
}
