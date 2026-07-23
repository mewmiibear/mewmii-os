<?php

/**
 * Shared CSV-reading helper for every import tool (Customers/Suppliers/Customer Orders/
 * Supplier Orders/Inventory Opening Balance) - CSV only for v1, Excel later. Every import
 * page uses the same all-or-nothing shape: read rows here, validate every row into a
 * $rows/$errors pair, and only ever call the real insert function(s) if $errors is empty.
 *
 * Returns ['header' => string[], 'rows' => array[]] where each row is a header=>value map
 * (so callers never depend on column order, only column names). Throws if the file can't
 * be read/parsed at all - callers show that as a single top-level error, before any
 * per-row validation runs.
 */
function csv_import_read_rows(string $tmpPath): array
{
    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('The CSV file is empty.');
    }
    $header = array_map(static fn ($col) => strtolower(trim((string) $col)), $header);

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        // Skip fully blank trailing lines (a common CSV export artifact) rather than
        // treating them as a real, all-empty data row that would just fail validation.
        if ($line === [null] || $line === ['']) {
            continue;
        }

        $row = [];
        foreach ($header as $index => $columnName) {
            $row[$columnName] = trim((string) ($line[$index] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows];
}
