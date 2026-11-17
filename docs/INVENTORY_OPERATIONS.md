# Product and inventory operations

## Edit a product

Owners can open **Product catalog → Edit product** to change the name, category, price and reorder level. SKUs remain fixed because imports and API senders use them as product identifiers.

Prices are validated as positive decimal amounts with at most two decimal places and converted directly to integer kobo. An edit affects new sales and queued transactions processed after the edit. Previously recorded sale quantities, unit prices and totals stay unchanged. Historical lists display the current product name through the existing product relation.

Product edits produce `product.updated` audit records. Staff can use catalog products in sales but cannot edit them.

## Find stock

The Inventory screen supports product-name/SKU search, branch filtering, stock-level filtering and pagination. Search is case-insensitive. The low-stock filter includes zero balances, since those are also at or below their reorder threshold. The out-of-stock filter matches zero balances specifically. Branch staff can only search balances at their assigned branch; supplying another branch's ID is rejected.

## Adjust a balance

Owners can use **Inventory → Adjust stock** on a branch/product balance. Enter the **total available stock remaining**, select a reason and explain the correction. The application records the difference as a signed ledger movement.

- Physical stock count and record correction can increase or decrease stock.
- Damage and expiry must decrease stock.
- Counts cannot be negative. Unchanged counts do not create an operation.
- The form includes the balance that was visible when opened. If a sale, receipt, return, transfer or other adjustment changes it before submission, the adjustment is rejected. Refresh and check the latest balance before entering the count again.

Each accepted adjustment stores its previous balance, resulting balance, signed change, reason, explanation, actor and unique operation reference. The adjustment page shows its history. Staff cannot submit or access adjustment forms.

## Transfer between branches

Owners can open **Stock transfers** to record a product moving from one branch to another. Select different sending/receiving branches, a product, quantity and dispatch reference or reason.

Transfers are recorded as completed immediately: source stock decreases and destination stock increases in the same database transaction. The service locks both balances in a consistent order. It rejects insufficient source stock, invalid quantities, missing branch/product balances, and receiving balances above the supported limit.

This version records completed physical movements. It does not implement requests, dispatch approval, stock-in-transit, recipient acceptance, partial receipts or transfer cancellation. Only record a transfer once the actual movement is complete. To correct a wrong completed transfer, an owner can record a new transfer in the opposite direction and explain the original reference in its note.

The ledger contains a negative `transfer_out` and a positive `transfer_in`, both linked to the same operation. Audit entries are visible at both branches. Staff can read transfers involving their assigned branch, including incoming transfers, but cannot create transfers or read transfers between unrelated branches.

## Retry behavior and history

Transfer and adjustment forms carry UUID operation references. Replaying the same reference with the same details returns the existing operation without changing stock again. Reusing a reference with different details is rejected. Replaying an old adjustment does not overwrite movements recorded after it.

Both balance updates, operation records, ledger entries and audit entries are transactional. Operation history is retained and records cannot be deleted through these screens. Transfers do not affect sales revenue.

## Verification and remaining work

Feature tests cover conservation of total stock, ledger links, rollback, duplicates, stale counts, invalid adjustments, branch permissions, catalog prices and filtering. Browser checks exercise actual forms and responsive screens. Concurrency/load verification on the chosen production database remains outstanding; the SQLite development checks do not establish production throughput.

Stock receipt entry still uses the original receipt workflow and does not yet have an operation-level idempotency key. Multi-product transfers and formal request/dispatch/receipt workflows remain future work.
