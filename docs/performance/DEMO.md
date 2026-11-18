# Requirement 3 — Performance demonstration guide (Phase 4)

Every optimization can be shown **before** and **after**, live, with a
measurement on screen. All numbers below were measured on 2026-11-14 on this
project's Docker stack and **run a second time the same day to check that
they repeat** (raw output in `repro-2026-11-14/`). They come from the
development server (`php artisan serve`, 8 workers, Windows bind mount, ~300 ms
of framework time per request), not a production web server.

**What to claim, and what not to:**

| Topic | Show | Do not claim |
|---|---|---|
| Indexes | the plan (scan of the whole table vs index scan of 15 rows) and the database time per request | a big end-to-end speed-up under concurrent load: on this dev server it is within run-to-run noise |
| N+1 | the query count (5 vs 70 / 221) and the identical `data_sha256` | — |
| Redis | how many SQL statements reach MySQL (0 per cache hit vs 3 without the cache) | faster responses |
| Replica | `X-Read-Source` (cache misses and bypasses on the replica), the GTID freshness check, the fallback, and the measured replication lag | any performance gain: it was never load-tested |

Commands run from the project root in **Git Bash**. In Windows PowerShell 5.1,
three patterns are different:

| Git Bash | PowerShell |
|---|---|
| `X=value docker compose up -d app` | `$env:X='value'; docker compose up -d app; Remove-Item Env:X` (remove it, or it stays set for every later command in that window) |
| `docker run … run - < tests/load/orders.js` | `Get-Content tests/load/orders.js -Raw \| docker run --rm -i … run -` |
| the `mysqldump … \| mysql` copy and SQL with \` quotes (replica setup) | run those in Git Bash: PowerShell re-encodes piped data and uses \` as its escape character |

---

## 0. Preparation (once, before the demo)

```bash
docker compose up -d                                   # normal stack
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan perf:seed          # +2,000 customers, +5,000 products, +200,000 orders (~500,000 items), ~30 s
docker compose exec mysql mysql -uroot E-commerce -e "ANALYZE TABLE orders, order_items, products, users"
```

`perf:seed` uses a fixed random seed: on a fresh `migrate:fresh --seed` it
always produces 200,000 orders and 499,587 order items. Run it on an idle stack
(nobody registering or ordering at the same time): it assigns row ids itself.

**Exact numbers need this exact dataset.** Every order or product added later
(a test checkout, the replica demo's product) changes the newest page of
orders, so the N+1 counts in section 2 shift (for example 67 / 219 instead of
70 / 221 after one extra 1-item order); the formula still holds exactly. Re-run
the three commands above right before the demo.

For the database-load measurements (sections 1 and 3), pause the scheduler so
its every-minute `warehouse:reconcile` does not add unrelated statements, and
start it again afterwards:

```bash
docker compose stop scheduler    # before measuring
docker compose start scheduler   # afterwards
```

> **Running the PHP test suite wipes the `E-commerce` database** (tests run on
> it). Re-run the three commands above before a demo.

For the load tests, raise the API rate limit (normally 60/min per user), and
put it back afterwards:

```bash
API_RATE_LIMIT_PER_MINUTE=100000 docker compose up -d app   # before k6
docker compose up -d app                                    # afterwards (back to 60)
```

---

## 1. Indexes on the order lists

**What:** `GET /api/v1/orders` sorts by `created_at DESC, id DESC`. Without an
index MySQL reads and sorts all 200,000 orders for the admin list.
Migration: `Modules/Orders/database/migrations/2026_11_14_000000_add_order_listing_indexes.php`.

| Step | Command |
|---|---|
| Before (no indexes) | `docker compose exec app php artisan migrate:rollback --step=1` |
| Show the plan + time | `docker compose exec app php artisan perf:explain` |
| After (indexes) | `docker compose exec app php artisan migrate` |
| Show the plan + time | `docker compose exec app php artisan perf:explain` |
| Database time per API request (each state) | see "API before / after" below |
| Load test (each state, optional) | `docker run --rm -i --network e-commerce_default -e ROLE=admin grafana/k6:0.54.0 run - < tests/load/orders.js` |

`perf:explain` shows the SQL the API sends, MySQL's plan (EXPLAIN), the real
execution (EXPLAIN ANALYZE) and a median time.

**Read the right row count.** `rows:` in the EXPLAIN line is the optimizer's
*estimate* and changes after every reseed or `ANALYZE` (199,517 in the first
run, 199,364 in the second). The exact figure is in the EXPLAIN ANALYZE line:
`Table scan on orders ... (actual ... rows=200000)`, i.e. every order in the
table. After the index: `Index scan ... (actual ... rows=15)`, exactly the page
size.

**Measured, query level** (`indexes/before/`, `indexes/after/`, second run in `repro-2026-11-14/`):

| | Before | After |
|---|---|---|
| Admin orders, page 1 — plan | `type: ALL`, scan of every row (actual 200,000; estimate ~199,400–199,500), `Using filesort` | `orders_created_at_id_index`, 15 rows, `Backward index scan` |
| Admin orders, page 1 — query time (median of 5) | 72.38 ms / second run 60.83 ms | 0.42 ms / second run 0.41–0.54 ms |
| Customer orders, page 1 — plan | `orders_user_id_foreign`, `Using filesort` | `orders_user_id_created_at_id_index`, `Backward index scan` |
| Customer orders, page 1 — query time | 1.59 ms / second run 1.09 ms | 0.48 ms / second run 0.55 ms |

### API before / after: database time per request

Measured inside MySQL (`performance_schema`, statistics only, no data touched)
over 60 sequential `GET /api/v1/orders` requests as admin. Run it in each
state, with the scheduler paused (section 0). Get an admin token first
(`TOKEN=...`, from `POST /api/v1/auth/login`):

```bash
docker compose exec mysql mysql -uroot -e "TRUNCATE performance_schema.events_statements_summary_global_by_event_name"
for i in $(seq 1 60); do curl -s -o /dev/null -w "%{time_total}\n" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" http://localhost:8000/api/v1/orders; done | sort -n | sed -n 30p   # HTTP p50, seconds
docker compose exec mysql mysql -uroot -e "SELECT COUNT_STAR/60 AS statements_per_request, ROUND(SUM_TIMER_WAIT/1e9/60,2) AS db_ms_per_request FROM performance_schema.events_statements_summary_global_by_event_name WHERE EVENT_NAME='statement/com/Execute'"
```

(Laravel uses server-side prepared statements: they are counted under
`statement/com/Execute`, not in the per-query digest table.)

| 60 sequential admin-list requests | DB time per request | HTTP p50 / p95 |
|---|---|---|
| Without indexes (2 runs) | **78.5 / 76.4 ms** | 342 / 380–386 ms |
| With indexes (3 runs) | **17.7 / 17.7 / 18.4 ms** | 284–286 / 314–355 ms |

Both states run the same 5 statements per request. The ~58 ms saved per request
end to end matches the ~60 ms saved in the database; the remaining ~17 ms is
the pagination `COUNT(*)` over 200,000 rows, which no index removes.

**Under concurrent load the gain is not reproducible on this setup.** The first
k6 run (10 users, 30 s) gave admin p50 619 -> 366 ms; the second gave 394 /
386 ms before and 346 / 393 ms after, overlapping (`repro-2026-11-14/k6-*.txt`).
The ~60 ms of database time per request is the same size as the run-to-run
noise of the dev server's 8 workers, so do not present the k6 numbers as the
proof. The customer list (~130 orders per customer) shows no measurable
difference either.

---

## 2. N+1 queries (eager vs lazy loading)

**What:** an isolated demonstration endpoint, admin only, not used by the store:

```
GET /api/v1/perf/orders?per_page=15        (try per_page=50 too)
```

It lists orders with their customer, items and each item's product. The
response's `meta` block shows `implementation`, `queries` (real SQL count for
this request), `db_ms` and `data_sha256` (identical for A and B → same data).
The `Server-Timing` header repeats `db;dur=…;desc="queries=N"`.

File: `Modules/Orders/app/Http/Controllers/PerfOrdersController.php`

**To switch from A (eager, active by default) to B (lazy, N+1):**

1. In the block marked `===== IMPLEMENTATION A: Eager Loading — ACTIVE`,
   put `// ` in front of its **3 code lines**:
   ```php
   // $orders = Order::query()->with(['user:id,name', 'items.product:id,name'])
   //     ->latest()->orderByDesc('id')->paginate($perPage);
   // $implementation = 'A: eager loading';
   ```
2. In the block marked `===== IMPLEMENTATION B: Lazy Loading (N+1) — COMMENTED OUT`,
   remove `// ` from its **3 code lines**:
   ```php
   $orders = Order::query()
       ->latest()->orderByDesc('id')->paginate($perPage);
   $implementation = 'B: lazy loading (N+1)';
   ```
3. **Restart the app** — OPcache does not re-read PHP files:
   `docker compose restart app`  (~5 s)
4. Call the endpoint again (Postman, or the browser's DevTools with an admin token).

To go back, do the reverse and restart again. Exactly one of the two blocks may
be active.

**Measured** on the freshly seeded dataset (`n-plus-1/A-eager.txt`,
`n-plus-1/B-lazy.txt`). Second run, switching the comments exactly as above: A
gave 5 queries every time; B gave 67 / 219, because one extra 1-item order had
been placed since the seed (35 and 117 items on the page). Without that order
the pages hold 38 and 119 items, i.e. exactly 70 / 221 (see section 0):

| | A: eager | B: lazy (N+1) |
|---|---|---|
| Queries, 15 orders | **5** | **70** (= 2 + 2×15 + 38 items) |
| Queries, 50 orders | **5** | **221** (= 2 + 2×50 + 119 items) |
| DB time, 15 orders | ~23–35 ms | ~56–65 ms |
| DB time, 50 orders | ~27–30 ms | ~115–118 ms |
| `data_sha256` | same as B | same as A |

A always needs 5 queries (count, orders, customers, items, products). B needs
one more query per order for the customer, one per order for the items and
one per item for the product, so it grows with the page size. The normal API
(`/api/v1/orders`) is unchanged and does not return items in its list.

---

## 3. Redis catalog cache

**Before/after, per request (no restart):** send `X-Cache-Bypass: 1` (honored
outside production). Response headers show `X-Cache: BYPASS` vs `MISS`/`HIT`
and `Server-Timing: catalog;dur=…` (database or Redis time) and
`stock;dur=…` (live Warehouse read, never cached).

- In the web UI: admin → **System** → **Compare HIT vs bypass**; or open the
  demo panel (`http://localhost:8000/?demo=1`) and watch the requests.
- Whole-app switch: `PRODUCT_CACHE_ENABLED=false docker compose up -d app`
  (responses say `X-Cache: OFF`), then `docker compose up -d app` to restore.
- Load test, same URLs in both modes:
  ```bash
  docker run --rm -i --network e-commerce_default -e MODE=bypass grafana/k6:0.54.0 run - < tests/load/catalog.js
  docker run --rm -i --network e-commerce_default -e MODE=cached grafana/k6:0.54.0 run - < tests/load/catalog.js
  ```

### What to show: database load

Count the SQL statements that actually reach MySQL with its general log
(temporary, switched off again at the end). Pause the scheduler first
(section 0), warm the cache with one request per page, then:

```bash
docker compose exec mysql mysql -uroot -e "SET GLOBAL log_output='TABLE'; TRUNCATE mysql.general_log; SET GLOBAL general_log=ON"
#   ... send the same catalog requests, once normally and once with X-Cache-Bypass: 1 ...
docker compose exec mysql mysql -uroot -e "SET GLOBAL general_log=OFF; SELECT SUM(argument LIKE '%from \`products\`%' OR argument LIKE '%from \`categories\`%') AS catalog_statements, COUNT(*) AS all_statements FROM mysql.general_log WHERE command_type IN ('Query','Execute')"
docker compose exec mysql mysql -uroot -e "TRUNCATE mysql.general_log; SET GLOBAL log_output='FILE'"
```

**Measured** (80 requests = 2 passes over the 40 pages of `tests/load/catalog.js`, two runs each):

| Request type | Catalog SQL statements per request | All SQL statements per request (incl. JWT user and permission checks) |
|---|---|---|
| `X-Cache: HIT` | **0** | 5 |
| `X-Cache: MISS` (refill) | 3 | 8 |
| `X-Cache: BYPASS` | **3** | 8 |

Steady state with the cache: 80 requests -> **0** catalog statements; without
it: **240**. An entry lives 300 s (`PRODUCT_CACHE_TTL`) from the moment it was
filled; the next request then refills it once (seen in the second run: 40 MISS
-> 120 statements, then HIT again). A product change (`POST /api/v1/products`)
invalidates every entry at once.

### Latency: no gain on this setup

k6, 10 users, 30 s (`baseline/k6-catalog-*.txt`):

| | Bypass (database) | Cached (Redis) |
|---|---|---|
| p50 / p95 / p99 | 379 ms / 722 ms / 942 ms | 421 ms / 768 ms / 1.06 s |
| Throughput / errors | 23.5 req/s / 0 % | 20.9 req/s / 0 % |

**Honest result: the cache did not make the API faster here.** The catalog
query itself takes ~1 ms (5,000 products, indexed by category), while each
request spends ~300 ms in the framework and ~5–20 ms reading live stock from
the Warehouse (never cached). Redis only replaces the 1 ms part; the
difference between the two runs is within run-to-run noise. The cache's value
shows in reduced database load (the table above) and in `Server-Timing:
catalog` (e.g. 0.8 ms HIT vs 3.3 ms BYPASS), not in end-to-end latency on this
setup.

### Edit a product and see it at once (invalidation)

`PUT /api/v1/products/{id}` (admin) saves the product, and every save flushes
the whole catalog cache (`Product::booted` → `CatalogCache::flush()`, a version
bump: old keys are simply never read again).

1. As a customer, read `GET /api/v1/products/1` twice → `X-Cache: HIT`; note its `ETag`.
2. As admin: `PUT /api/v1/products/1` with `{"name": "Wireless Headphones (edited)", "price": 89.99, "category_id": 1}` → 200.
3. As the customer, the same read with `If-None-Match: <old ETag>` → **200, not
   304**, `X-Cache: MISS`, the new name, a new `ETag`.
4. Put the original values back with the same PUT.

Measured live on 2026-11-14 (product 1): customer PUT → 403; admin PUT → 200;
the next customer read → 200, `X-Cache: MISS`, "Wireless Headphones (edited)",
new ETag; restore → 200. Postman: *Requirement 3 → Invalidation: edit the
product*. Test: `CatalogCacheTest::test_an_api_edit_is_visible_at_once_everywhere`
(v1 and v2, details and lists).

---

## 4. ETag / 304

Product endpoints send an `ETag` that covers the whole response, live stock
included. Repeat a request with `If-None-Match: <that ETag>` → **304** with no
body. Change a product or its stock → new ETag, **200**. In the demo panel a
revalidated request shows as `304*`.

---

## 5. MySQL read replica (cache misses and bypasses)

**What:** an optional second MySQL server (`mysql-replica`, Compose profile
`replica`) that copies the primary through GTID replication and is read-only.
Laravel uses it when `DB_READ_HOST` is set.

**Rules** (all in `Modules/Products/app/Services/CatalogCache.php`, connections in `config/database.php`):

| Request | Reads from | `X-Cache` | `X-Read-Source` |
|---|---|---|---|
| Catalog, cached entry | Redis, no SQL | `HIT` | (absent) |
| Catalog, no entry | replica, then stored in Redis for 300 s | `MISS` | `replica` |
| Catalog with `X-Cache-Bypass: 1`, "in stock only" list, cache switched off | replica | `BYPASS` / `SKIP` / `OFF` | `replica` |
| Same, but the replica has not applied the latest catalog write yet | primary | as above | `primary` |
| Same, but the replica is unreachable or its query fails | primary + a `Read replica unavailable` warning in the log | as above | `primary-fallback` |
| Every write, sign-in, JWT and permission lookups, orders, the scheduler | primary (the default `mysql` connection) | — | — |

- **No stale data from lag.** After a product or category write, the next replica
  read first takes the primary's GTID set (`@@gtid_executed`, kept in Redis as
  `catalog:gtid`) and asks the replica `GTID_SUBSET(that set, @@gtid_executed)`:
  only a replica that already contains the write answers. That also covers the
  writer reading right after the write, so no per-user "read-your-writes" timer is needed.
- **No writes on the replica.** `mysql_replica` opens every session `READ ONLY`
  (and the server is `super_read_only`); a write there fails.
- **A missing product stays a 404**: only connection or query failures fall back.
- Without `DB_READ_HOST` everything reads the primary (`X-Read-Source: primary`).

This is a routing and consistency demonstration, not a throughput claim: the
replica was not load-tested.

### One-time setup (fresh replica)

```bash
docker compose --profile replica up -d mysql-replica
docker compose exec mysql mysql -uroot -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED BY 'repl-password'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';"
docker compose exec -T mysql mysqldump -uroot --single-transaction --set-gtid-purged=ON --databases E-commerce | docker compose exec -T mysql-replica mysql -uroot
docker compose exec mysql-replica mysql -uroot -e "CHANGE REPLICATION SOURCE TO SOURCE_HOST='mysql', SOURCE_USER='repl', SOURCE_PASSWORD='repl-password', SOURCE_AUTO_POSITION=1, GET_SOURCE_PUBLIC_KEY=1; START REPLICA; SET PERSIST super_read_only=ON;"
docker compose exec mysql-replica mysql -uroot -e "SHOW REPLICA STATUS\G"   # Replica_IO_Running: Yes, Replica_SQL_Running: Yes
```

Afterwards the replica resumes by itself when its container restarts. If you
later `migrate:fresh` the primary, the replica follows (DDL replicates). Seen
in the second run: after ~6 hours stopped (`Seconds_Behind_Source: 21572`,
several `migrate:fresh` and reseeds on the primary) it replayed everything by
itself in about 3 minutes and ended with identical row counts and every
primary GTID applied.

### Measuring the replication lag

MySQL records, for the last transaction it applied, when it was committed on
the primary and when the replica finished applying it:

```bash
docker compose exec mysql-replica mysql -uroot -e "SELECT ROUND(TIMESTAMPDIFF(MICROSECOND, LAST_APPLIED_TRANSACTION_ORIGINAL_COMMIT_TIMESTAMP, LAST_APPLIED_TRANSACTION_END_APPLY_TIMESTAMP)/1000,1) AS lag_ms FROM performance_schema.replication_applier_status_by_worker"
```

**Measured** (natural lag, nothing paused): 15 single-row writes -> mostly
**8–13 ms**, two at 104 and 126 ms; one 50,000-row transaction -> **1,941 ms**.
A replica can therefore be a few milliseconds to a few seconds behind, which is
why only catalog reads use it, and only after the GTID check.

While the apply thread is stopped (demo step 1) `Seconds_Behind_Source` shows
**NULL**, not a number. Show the lag there with the GTID sets in
`SHOW REPLICA STATUS\G`: `Retrieved_Gtid_Set` (received, e.g. `...:334-5943`)
ends after `Executed_Gtid_Set` (applied, e.g. `...:1-5942`) by the transactions
still waiting.

### Before / after

| State | Command |
|---|---|
| Before: no replica, all reads on one server | `docker compose up -d app` (DB_READ_HOST empty) |
| After: catalog misses and bypasses read the replica | `DB_READ_HOST=mysql-replica docker compose up -d app` |

PowerShell: `$env:DB_READ_HOST='mysql-replica'; docker compose up -d app; Remove-Item Env:DB_READ_HOST`.

### What to show

1. **Where each read went:** the `X-Cache` and `X-Read-Source` headers of any catalog
   request (Postman, or the browser's network tab).
2. **Proof inside MySQL:** count the statements each server ran (statistics only,
   nothing changes). Pause the scheduler first (`docker compose stop scheduler`),
   then run before and after each request:
   ```bash
   for s in mysql mysql-replica; do docker compose exec -T $s mysql -uroot -N -e "SELECT '$s', COUNT_STAR FROM performance_schema.events_statements_summary_global_by_event_name WHERE EVENT_NAME='statement/com/Execute'"; done
   ```
3. **Fallback:** `docker compose --profile replica stop mysql-replica`, send a bypass
   request (`primary-fallback`, still 200), then `docker compose --profile replica start mysql-replica`
   (replication resumes by itself; check with `SHOW REPLICA STATUS\G`).

**Measured on 2026-11-17** (admin, scheduler paused, Δ = statements on each server during the request):

| Request | Status | `X-Cache` | `X-Read-Source` | Primary Δ | Replica Δ |
|---|---|---|---|---|---|
| `GET /api/v1/products?page=9`, first | 200 | MISS | replica | 4 | 4 |
| same, again | 200 | HIT | — | 3 | **0** |
| same + `X-Cache-Bypass: 1` | 200 | BYPASS | replica | 3 | 4 |
| `GET /api/v2/products?in_stock=1` | 200 | SKIP | replica | 3 | 5 |
| `GET /api/v1/products/999999` | 404 | — | — | 3 | 2 |
| `PUT /api/v1/products/1` (price change) | 200 | — | — | 8 | **0** |
| `GET /api/v1/products/1` right after the PUT | 200, new price | MISS | replica | | |
| bypass request, replica container stopped | 200 (3.2 s first, then ~0.46 s) | BYPASS | primary-fallback | | |
| sign-in, `GET /api/v1/orders` ×2 | 200 | — | — | | **0** |

The primary's 3 statements on every request are the JWT user lookup and the
permission checks. The first MISS's 4th primary statement is the one-time
GTID read; on the replica a MISS is the GTID check + products + count + categories.
The first request after the replica stopped waited for the DNS lookup of the
stopped container; later ones fail over at once.

The lag demonstration (`STOP REPLICA SQL_THREAD`, write, read) has not been run
with this design yet. Expected: while the replica lags, catalog reads show
`X-Read-Source: primary` with the new data; after `START REPLICA SQL_THREAD`
they show `replica` again. The earlier runs in `replica/read-your-writes.txt` and
`repro-2026-11-14/replica.txt` used the previous design (per-user read-your-writes window).

To stop: `docker compose up -d app` (back to one server), then
`docker compose --profile replica stop mysql-replica` (data kept).

---

## 6. Requirement 2 proof: 20 orders at the same instant (k6)

`tests/load/race.js`: 20 different customers (`perf-customer-1..20`, from
`perf:seed`) each send one order for product 13 (the seed gives it **1 unit**)
at the same millisecond, over real HTTP, through Laravel and the real
Warehouse. Expected: exactly **one 201 and nineteen 409**, stock 1 → 0, never
negative. The script then cancels the order it created, as admin, so the
Warehouse gets the unit back (stock 0 → 1). Any other outcome fails the run
(exit code 99).

```bash
docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run - < tests/load/race.js
```

No rate-limit change is needed (one order per customer). It deletes and
resets nothing; it leaves, on purpose, one cancelled order and 20 reservation
records (1 cancelled, 19 rejected). Run it **after** the N+1 demo, or reseed
first: the new order becomes the newest order and shifts the N+1 page
(section 0).

---

## 7. Files

| File | Purpose |
|---|---|
| `app/Console/Commands/PerfSeed.php` | `php artisan perf:seed` — large dataset |
| `app/Console/Commands/PerfExplain.php` | `php artisan perf:explain` — EXPLAIN + timings |
| `Modules/Orders/database/migrations/2026_11_14_000000_add_order_listing_indexes.php` | order-list indexes |
| `Modules/Orders/app/Http/Controllers/PerfOrdersController.php` | N+1 demo endpoint (A/B) |
| `Modules/Products/app/Services/CatalogCache.php`, `config/database.php` (`mysql_replica`, `read_connection`) | read replica routing, GTID check, fallback |
| `tests/load/catalog.js`, `tests/load/orders.js`, `tests/load/race.js` | k6 scenarios (approved) |
| `docs/performance/baseline/`, `indexes/`, `n-plus-1/`, `replica/` | raw measurement output |
| `docs/performance/repro-2026-11-14/` | the second run of every measurement (reproducibility check) |
