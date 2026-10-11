# Subscription Box Toolkit

WordPress plugin for subscriptionboxes.co.nz and subscriptionboxaustralia.com. Installed in `wp-content/plugins/sbnz-plans/` (folder name kept from the original plans plugin).

Guide and settings on each site: **wp-admin → SB Toolkit**.

| File | What |
|---|---|
| `sbnz-plans.php` | Loader, version |
| `includes/settings.php` | Defaults per site + `sbt_get()` |
| `includes/admin.php` | SB Toolkit admin page |
| `includes/plans.php` | `[sbnz_plans]`, `[sbnz_swap_plans]`, `[sbnz_marketplace_plans]`, plan checkout handler |
| `includes/listings.php` | Vendor self-serve listings, approval, lifecycle, marketplace perks, menu order |
| `includes/categories.php` | `[sb_categories]` |
| `includes/carousels.php` | `[sb_products]`, `[sb_posts]` |
| `includes/tweaks.php` | Currency per Product price guard, Products list tidy |
| `includes/updater.php` | Updates from GitHub releases |

## Releasing an update

1. Change the code, bump `Version:` and `SBT_VERSION` in `sbnz-plans.php` (must match), write `RELEASE_NOTES.md`.
2. Commit and push to `main`.
3. The Release workflow builds `sb-toolkit.zip` and publishes release `vX.Y.Z` (skipped if that version already exists). Sites pick it up within ~12 hours, or straight away with SB Toolkit → Updates → Save & check.
