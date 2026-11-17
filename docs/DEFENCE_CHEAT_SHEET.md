> Deployment update (17 November 2026): the default Render configuration uses temporary SQLite for a school demo. See [SQLite quickstart](RENDER_SQLITE_QUICKSTART.md). Latest SQLite suite: 122 tests, 743 assertions. Historical verification statements below describe their original checks.

# BranchFlow: Defence Revision Sheet

**Study aid for the checked build, 13 November 2026.** Read the complete guide first. These are short explanations to practise, not invented research findings or a substitute for understanding the code.

## 1. The project in one sentence

BranchFlow combines sales and inventory records from several branches into one Laravel application so a business owner can see performance and control stock, while branch staff see only their permitted data.

## 2. The problem and solution

**Problem:** Separate branch records make it difficult to calculate total sales, compare branches, identify low stock and trace changes.

**Solution:** A central database receives sales through web entry, CSV uploads and an authenticated API, then supplies reports and an automatically refreshing dashboard.

**Example:** Sales from Wuse, Garki and Kubwa contribute to one owner's overview. A Wuse staff account cannot view Garki's sales.

## 3. Technologies to remember

| Technology | Simple explanation |
| --- | --- |
| PHP | The language running backend logic |
| Laravel | The web framework organising the application and providing common web tools |
| Blade | Builds the HTML pages from templates |
| Livewire | Requests updated information and refreshes parts of the screen |
| Custom CSS | Gives the application its navy, white and teal appearance |
| SQLite | The database used locally and in tests |
| Composer | Installs the locked PHP packages |
| PHPUnit | Runs automated behaviour tests |
| Database queue | Stores pending work until a worker processes it |
| Playwright/Chromium | Tools used to verify browser workflows |

Inspected versions: PHP 8.4.23, Laravel 13.42.0, Livewire 4.4.8, PHPUnit 12.5.43. The project declares PHP 8.3 or later, subject to dependency compatibility.

Do not say the current backend is FastAPI, the frontend is React, or the queue uses Redis. Those are not the implemented stack. Node/Vite/Tailwind scaffold files do not mean the current interface needs an npm build.

## 4. Explain the design pattern

**MVC** means Model, View and Controller:

- Model: represents a database record, such as a sale.
- View: presents a page, such as the sales table.
- Controller: receives the request and coordinates the response.

The project also uses services for business rules, middleware for access checks, and jobs for background processing.

**Code example to open:** `routes/web.php` → the sale controller action → `app/Services/SaleService.php` → `Sale`/`Stock` models → a sale test.

## 5. Explain a web sale

1. The signed-in user submits product, branch and quantity.
2. The server checks account/branch permission and validates input.
3. The sales service checks the locked stock balance and catalog price.
4. Sale, stock deduction, movement and audit record commit together.
5. The dashboard reflects the committed result on a later poll.

A web sale runs in the request. It is not placed on the background queue.

## 6. Explain an API sale

1. A branch program sends JSON with its source API key.
2. The application authenticates the source and validates the request.
3. The event and database queue job are committed together.
4. The worker runs the job using the normal sales service.
5. The event becomes completed, rejected or failed.

**202 means accepted, not completed.** The sender checks the status endpoint. Stopping the worker leaves accepted work pending until processing resumes.

A seeded API example is a fixture. To demonstrate actual network ingestion, send a genuine HTTP request.

## 7. Explain the important calculations

| Calculation | Rule |
| --- | --- |
| Sale total | Recorded unit price × quantity |
| Revenue | Sum of matching completed sale totals |
| Units sold | Sum of matching completed sale quantities |
| Returned value | Original value of matching returned sales, shown separately |
| Low stock | Current quantity ≤ reorder level |
| Transfer | Source decreases by the same number that destination increases |
| Adjustment | New counted balance − previous recorded balance |

Money is stored in kobo. ₦1,250.99 is stored as 125099; two units produce 250198 kobo, displayed as ₦2,501.98. A future price edit does not rewrite that saved sale.

Reports use the original sale date, not the refund date. They are not profit, tax or payment-settlement reports.

## 8. Five technical ideas to explain simply

**Transaction:** Changes succeed together or roll back together. A failed transfer must not reduce one branch without increasing the other.

**Idempotency:** Repeating the same referenced request does not apply its effect twice. A new reference for the same real-world sale can still create another sale; the sender must reuse the original reference on retries.

**Queue:** A waiting line for background work. It buffers work but does not provide unlimited capacity.

**Authentication versus authorisation:** Authentication checks who the user is. Authorisation checks which branch/actions they may access.

**Polling:** The browser asks for updated data periodically. Here the dashboard uses approximately ten-second polling, rather than WebSocket push updates.

## 9. Main records in the database

- Users and branches: who can work where.
- Products and stocks: what is sold and how much each branch has.
- Sales: saved transactions with original prices and references.
- Stock movements: why quantities changed.
- Stock operations: referenced adjustments/transfers.
- Audit logs: actions and actors.
- Imports: CSV processing results and errors.
- API sources and ingestion events: machine credentials and incoming transaction outcomes.
- Jobs/failed jobs: pending or exhausted background work.

A current balance answers “How many are left?” A ledger answers “Why is that the balance?”

## 10. Owner and staff

**Owner:** Manages branches, products, stock receipts, adjustments, transfers, staff, full returns and source keys; sees all branch data.

**Staff:** Records sales/imports and reads permitted analytics, inventory and reports for the assigned branch. Can read transfers involving that branch and change their own password.

Deactivation preserves history but blocks access. Password/access changes invalidate old sessions and remembered logins. Reactivation requires fresh authentication.

## 11. Claims to make accurately

| If asked… | Accurate answer |
| --- | --- |
| Is it truly instantaneous? | No. It is near-real-time polling, plus queue processing delay. |
| Is it already hosted in the cloud? | No. The checked build is local; cloud deployment remains outstanding. |
| How many users can it handle? | No measured capacity figure has been established. Load testing is still needed. |
| Does it support many companies? | No. It currently supports one business with multiple branches. |
| Does it make bank/card payments? | No. It records sales/inventory; it does not process payments. |
| Does a return execute a refund? | No. It restores stock and changes sales-reporting status. |
| Is it AI prediction? | No. These are descriptive SQL-based summaries and alerts. |
| Is it connected to a real POS vendor? | Not automatically. A sender must implement the API contract. |
| How many tests passed? | 103 at this documented build; rerun the suite before the defence. |
| Was a production pilot conducted? | Not established by this implementation. Do not invent one. |

The title describes the project's direction. Do not turn intended cloud deployment or scalability into claims of work that has not been completed.

## 12. Short demonstration order

1. Explain the problem and technology stack.
2. Show the dashboard and its filters.
3. Record a sale and inspect stock/transaction details.
4. Demonstrate an insufficient-stock rejection.
5. Show staff branch restrictions in another browser session.
6. Filter a report and export the same results.
7. Submit an actual API sale with the worker stopped, then restart it and show completion.
8. Resend that reference/payload to show replay protection.
9. Show a transfer or adjustment and its history.
10. Run tests and state the remaining work honestly.

Keep passwords, application keys and full API keys off the projected screen. Use prepared sample data and know which branch/product has available stock.

## 13. Commands to understand

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan branchflow:owner
php artisan db:seed --class=DemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work ingestion --tries=3 --timeout=120
php artisan test
```

- Composer installs packages.
- Key generation is for a new installation, not a routine restart.
- Migrations create/update tables.
- The owner command creates the initial administrator.
- Seeding adds labelled demonstration data.
- The web server and worker run in separate terminals.
- Tests check the implemented cases.

Never use `migrate:fresh` on records you intend to preserve.

## 14. What to improve next

Cloud deployment; production database/concurrency/load testing; monitoring and restore verification; WebSocket updates if justified; multi-product receipts/transfers; partial returns; proper dispatch/receipt approval stages; stock-receipt replay protection; email recovery and MFA.

## 15. Practise this closing explanation

“The implemented application demonstrates branch-scoped data collection, transactional stock handling and descriptive sales analytics. Its background queue separates accepted external work from processing, while reference checks protect retries. It is locally verified with automated and browser tests. The next work is operational deployment and measured evaluation, rather than claiming that framework choice alone proves scalability.”
