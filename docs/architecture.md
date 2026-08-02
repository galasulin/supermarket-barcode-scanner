# Architecture & design notes

## The problem that shaped the design

The store needed to be catalogued from **several phones at the same time**, so
the data could not live in any single device's `localStorage` (the first
version did exactly that — and the second phone couldn't see the first phone's
scans). We needed one shared source of truth.

## Why not a third-party backend?

Tried and rejected:

- **kvdb.io** — works, CORS-friendly, but requires a paid plan / email
  verification for writes.
- **jsonblob.com** — anonymous and free, but **no CORS headers**, so the
  browser can't call it.
- **Netlify / external host** — another account and moving parts; the owner
  preferred to keep everything on their own server.

## Why not a plain PHP file on the server?

The first server-side version was a stand-alone `barcodes.php` that read/wrote a
JSON file. It worked for a few minutes, then started returning:

```
403 — The requested URL was rejected
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
- A dropped write self-heals: the phone still holds the value locally and
  re-sends it on the next scan.
- Deletion is impossible through the normal path — clearing everything requires
  `?mode=replace&admin=<key>`, and the admin key exists only on the server.

## Front-end sync loop

- On save → debounced `POST` (push local set, merge server response back).
- Every 5s → `GET` and merge (pick up other devices' scans).
- On load → pull, then push, so a fresh device both receives and contributes.
- `localStorage` is only an **offline cache**; the server is the source of truth.

## Identification

`build_lookup.py` reduces a scraped catalog (thousands of products) to a slim
`{ barcode: name }` map, kept to a few hundred KB by dropping non-numeric /
short internal codes. The page fetches it once and caches it, so every scan is
matched instantly with zero network round-trips.
