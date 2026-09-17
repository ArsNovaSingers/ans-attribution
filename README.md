# Ars Nova Attribution

Answers one question: **was the mailer (or the radio spot) worth paying for?**

Three independent counters, so no single dependency can lose the answer:

| Counter | Source | Survives |
|---|---|---|
| **Scans** | Redirection plugin hit count on `/go/<slug>` | ad-blockers, privacy browsers, no-JS |
| **Visits → sales** | GA4 `utm_content` | — |
| **Redemptions** | WooCommerce coupon `usage_count` | cross-device, GA4 retention |
| **Attributed orders** | `_ans_campaign` order meta | everything above; permanent and queryable |

## How it works

1. The printed QR encodes a short link (`/go/rs`), which 302s to the concert page
   carrying UTM tags plus `ansref=<COUPON>`. The short link is re-pointable **after**
   printing, and its hit counter is the scan count.
2. `ansref` is captured into a 60-day cookie. Patrons here scan in October and buy in
   November — a session-scoped capture would lose most of the effect.
3. The coupon is applied **automatically**. Nobody has to find a coupon box.
4. It is **refused on a package cart** (see below).
5. The finished order is stamped `_ans_campaign` / `_ans_ref` / `_ans_landed`.

## The no-stacking rule

Kimberly Brody, 2026-09-09: the code "must work ONLY for these concerts and not on a
package."

Restricting the coupon to a concert's products does **not** satisfy that. A cart holding
Rivers & Streams plus four other concerts earns the 20% Season Package *and* would still
take 10% off the Rivers lines on top. `ans_attr_cart_has_package()` is the guard, and it
calls **ans-season-packages' own tier functions** rather than copying the thresholds — a
second copy would drift the first time tiers change, and the failure would be silent.

## Reading results

    GET ans-ops/v1/attribution/report?campaign=rivers-streams-mailer-2026

Registered in `ans-ops/v1`, so the Ars Nova WordPress MCP connector reaches it via
`ans_rest_call` with no connector rebuild.

## Adding the next mailer

Add an entry to `ans_attr_campaigns()` (or filter it), create the coupon restricted to
that concert's products, and add the `/go/<slug>` redirect. One code per piece — a
season-wide code cannot tell you which card worked.

## Radio campaigns (v1.4.0)

An announcer says the URL, so it cannot carry the `?m=1` cache-buster a printed QR does.
A campaign can therefore list **spoken `aliases`** (`/cpr`, `/npr`, `/kvod`). An alias is a
plain 302 to `short_path?qr_query`; it records nothing, so a copy cached at Kinsta's edge
loses nothing. The counted hop is always the second one, which always carries the param.

Per-campaign `utm_source` / `utm_medium` replace the old hard-coded `qr` / `print`.

A radio campaign runs all season but promotes a different concert each flight. Re-point it
without a release:

    POST ans-ops/v1/attribution/campaign/cpr-kvod-2627
    { "destination": "/this-season/darkness-and-light/", "utm_content": "darkness-and-light" }

Only `destination` and `utm_content` can change at runtime; every change is kept in a
history list. The visitor's `utm_content` is stored in the cookie and stamped on the order
as `_ans_content`, and the report breaks attributed orders down by it
(`orders_by_content`).

`CPRKVOD` is a ref, not a coupon. Noncommercial underwriting rules keep discounts off
the air, so there is deliberately no WooCommerce coupon behind it.
