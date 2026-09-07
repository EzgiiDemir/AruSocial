# REAL PRODUCT AUDIT — Hardening status

> Updated: 2026-08-26 (P4–P9 product package pass)

## STATUS: REAL_PRODUCT_HARDENING_P4_P9_PARTIAL

### Closed in P4–P9 packages

| Paket | Delivery |
|---|---|
| **P4 Map** | GPS/campus center; full POI seed via `CampusCatalogSeeder`; heatmap from `recentCheckins` (+ density fallback); category-colored pins + nabız legend |
| **P5 Routing** | Local `ROUTING_BASE_URL=https://router.project-osrm.org`; empty → 501 / straight-line honesty; navigate UX already shows provider vs fallback |
| **P6 Social** | Rail/bar labels (Akış/Mesajlar/Kişiler); chat composer ARUCAD palette |
| **P7 Ask tab** | Ask ARUCAD is **6th** bottom tab (Home·Explore·Social·Ask·Activity·Profile) |
| **P8 Staff CRM** | Expanded staff catalog seed (no invented emails); admin Personel grouped by faculty + counts |
| **P9 Soft realtime** | Admin dashboard / pending / applications soft-poll (30–45s); Reverb stays opt-in (`REVERB_ENABLED`) |

### Still open / honest limitations

| Area | Notes |
|---|---|
| Full interactive browser console sweep | Needs human browser session |
| OpenFreeMap glyph/sprite 404s | `THIRD_PARTY_STYLE_BLOCKER` |
| True 59-name ARUCAD roster with emails | Not in repo — titles/roles seeded without inventing emails |
| Public OSRM | Demo/rate-limited; production should self-host |
| Faculty/department dashboard SQL filters | Days filter only |

### Hardening 1–3 (still green)

Check-in geo, XP, session restore, applications/staff/achievements APIs, Flutter apply UIs.
