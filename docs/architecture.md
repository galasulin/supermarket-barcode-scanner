# Architecture & design notes

## The problem that shaped the design

The store needed to be catalogued from **several phones at the same time**, so
the data could not live in any single device's `localStorage` (the first
version did exactly that - and the second phone couldn't see the first phone's
scans). We needed one shared source of truth.

## Why not a third-party backend?

Tried and rejected:

- **kvdb.io** - works, CORS-friendly, but requires a paid plan / email
  verification for writes.
- **jsonblob.com** - anonymous and free, but **no CORS headers**, so the
  browser can't call it.
- **Netlify / external host** - another account and moving parts; the owner
  preferred to keep everything on their own server.

## Why not a plain PHP file on the server?

The first server-side version was a stand-alone `barcodes.php` that read/wrote a
JSON file. It worked for a few minutes, then started returning:

```
403 - The requested URL was rejected
```

The host runs a WAF (Wordfence + an Imperva-style edge). A custom PHP file that
does `fopen`/`fwrite` looks exactly like a **web shell**, so it gets blocked /
quarantined. Meanwhile `index.html` and `*.json` in the same folder served fine.

## The solution: ride WordPress's REST API

`GET /wp-json/` proved the REST API was open **and passed the WAF** (even a POST
to an unknown route returned a normal WordPress `404`, not a firewall `403`).

So storage moved into a ~40-line plugin that:

- registers `GET/POST /wp-json/sa/v1/list`
- stores the merged barcode set in a single `wp_options` row (**no disk
  writes** → nothing for the malware scanner to flag)
- is **same-origin** with the `/scan/` page → no CORS needed at all

## Merge semantics (why parallel scanning is safe)

The client always `POST`s its **full local set**; the server does an additive
union with what's already stored and returns the merged result:

```php
foreach ($incoming as $k => $v) { $data[(string)$k] = $v; }
update_option($key, $data, false);
```

Consequences:

- Two phones scanning different aisles never clobber each other.
- A dropped write self-heals: the phone tracks which barcodes the server has
  confirmed and keeps retrying the rest on every poll.
- Deletion is impossible through the normal path - clearing everything requires
  `?mode=replace&admin=<key>`, and the admin key exists only on the server.

## Front-end sync loop

- Each phone keeps two sets in `localStorage`: everything it has scanned, and
  the subset the server has **confirmed** (`sa_synced_v2`).
- On save → debounced `POST` of only the **unconfirmed** barcodes, in batches of
  150. Each successful batch is marked confirmed, then the next one goes.
- Every 5s → `GET` and merge (pick up other devices' scans). Anything the
  server returns counts as confirmed, and any leftover unconfirmed barcodes are
  pushed again, so a failed write retries itself.
- The status line says "synced" only when nothing is waiting. Otherwise it
  shows how many barcodes are still on the phone only.
- `localStorage` is only an **offline cache**; the server is the source of truth.

### Why batches: the WAF has a body-size limit

The first version POSTed the whole list on every scan. That worked in testing,
then failed in the real store: the host's Imperva WAF rejects request bodies
over roughly **9 KB** (an 8.9 KB POST passed, a 9.8 KB one got
`403 The requested URL was rejected`). One barcode is about 18 bytes of JSON,
so every push failed once a phone passed ~500 scans.

Worse, the 5s `GET` poll kept succeeding and repainted the status as "synced",
hiding the failed pushes. One employee scanned 1,878 products and only the
first few hundred reached the server; the rest were recovered from the phone's
CSV export.

Two fixes came out of it: send small deltas instead of the full set, and never
report "synced" from a read alone.

### Excel and barcodes

Opening a plain CSV in Excel turns `1070906108635` into `1.07091E+12` and
`038000232183` into `38000232183`, which collapses distinct barcodes into one
value. The export now writes each barcode as `="1070906108635"` so Excel keeps
it as text. The import button still reads any 6+ digit run, so old and new
exports both re-import cleanly.

## Identification

`build_lookup.py` reduces a scraped catalog (thousands of products) to a slim
`{ barcode: name }` map, kept to a few hundred KB by dropping non-numeric /
short internal codes. The page fetches it once and caches it, so every scan is
matched instantly with zero network round-trips.
