# Ars Nova Attribution

Answers one question: **was the mailer worth printing?**

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
