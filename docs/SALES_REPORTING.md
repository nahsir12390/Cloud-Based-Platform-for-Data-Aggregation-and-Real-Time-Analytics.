# Sales search, reports and exports

## Find transactions

Open **Sales transactions** to search by full/partial transaction reference, product name or SKU. Combine search with branch, product, date range, sale status and data source filters. Sort by sale time or recorded total, in either direction. Pagination preserves the selected filters.

Click a transaction reference to open its detail page. It shows the full reference, original sale time, recording time, actor, source, saved unit price/total, and linked sale/return stock movements. Owners can authorize a full return from this page. Staff can view only transactions from their assigned branch.

## Reports

Open **Reports** to see:

- Completed revenue, excluding returned sales.
- The count of matching transactions, including whichever statuses were selected.
- Units from matching completed sales.
- The original value of matching returned sales, displayed separately.
- Branch performance and the top ten products ranked by completed revenue.

All summaries use the same selected filters. A returned-only filter therefore shows returned value but zero completed revenue and no completed-sale rankings. Summary totals cover all matching records, not just a visible page of transactions.

Dates use Africa/Lagos and filter by the **original sale time** (`sold_at`), not import time or return time. Both selected days are included in full; the upper bound is the start of the day after the end date. Either date can be used independently. Blank dates mean all recorded sales.

These are sales-value reports. They do not calculate profit, tax, payment settlement, or cash refunds by refund date. Returning a sale removes it from completed revenue for its original sale period. Historical product names follow the current catalog, while each sale's recorded quantities and prices remain preserved.

## CSV exports

**Export matching sales** uses the same search, branch, product, date, status, source and sort settings as the transaction list. Reports exports use those settings too. The dashboard export explicitly selects completed sales to agree with its revenue metrics.

Exports include all matching pages and stream records in chunks. Columns remain: reference, branch, SKU, quantity, unit price in NGN, total in NGN, status and sale timestamp. Formula-like text cells receive an apostrophe prefix to reduce spreadsheet formula execution risk.

An export is a live read rather than an immutable accounting snapshot. If transactions or statuses change during a large export, refresh/re-export to obtain an updated view. Production-scale reporting, immutable snapshots and performance testing remain future work.

## Access and validation

Staff queries, aggregates, transaction details and exports are restricted to the assigned branch. Supplying another branch's ID is rejected. Reports do not grant staff access to global business figures. Invalid dates, reversed date ranges, unknown statuses, sources and sort modes are rejected before querying/exporting.
