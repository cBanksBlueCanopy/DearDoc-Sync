# DearDoc Sync

Contributors: Mahoney Marketing LLC
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later

Automatically imports new posts from a DearDoc blog into WordPress.

## How it works

On each scheduled run the plugin:

1. Asks DearDoc for its post total (one small request).
2. Stops if the total hasn't grown since the last complete sync.
3. Otherwise fetches all posts (100 per page) and imports every published
   post whose DearDoc id isn't on the site yet, oldest first, 3 per run with
   a 2-second pause between posts. Posts older than the cutoff date are
   skipped.
4. For each post: keeps only the article body, copies images into the Media
   Library (converted to WebP, at most 1600px wide), sets the featured image,
   author, categories, publish date and the Rank Math title, description and
   focus keywords, and stores the DearDoc id in post meta (_deardoc_id).
5. Saves the new total, logs the result with the run time and emails an
   alert on failure.

Posts are matched by DearDoc id, never by slug or count alone, because
DearDoc reuses slugs. A synced post that is permanently deleted is
remembered and not imported again. A DearDoc post that fails 3 times is
skipped permanently (logged), so one broken post can't make every run fail;
"Retry skipped posts" on the settings screen clears that list.

## Setup

1. Upload the deardoc-sync folder to wp-content/plugins and activate it.
2. Go to Settings → DearDoc Sync.
3. Enter the Blog ID, choose the author (client-branded, not an agency
   account), categories, status (draft or publish) and the cutoff date, then
   Save. Leave Automatic sync off for now.
4. Click "1. Preview linking existing posts", check the list, then
   "2. Link existing posts". This tags posts that were imported earlier so
   they are never imported again.
5. Click "Preview sync" and check that only the expected new posts are
   listed.
6. Click "Sync now", check the new posts on the site, then turn on
   Automatic sync and Save.

## Cron

WP-Cron only runs when the site receives a request that reaches WordPress.
Behind a full-page CDN cache this can be irregular. For a reliable schedule,
add `define( 'DISABLE_WP_CRON', true );` to wp-config.php and run WordPress's
cron from the server every 5–15 minutes, for example:

    wp cron event run --due-now

Or run the sync directly from a server cron job:

    wp deardoc sync

## WP-CLI

    wp deardoc link --dry-run   Preview linking existing posts
    wp deardoc link             Link existing posts
    wp deardoc sync --dry-run   Preview new posts
    wp deardoc sync             Import new posts (skips if the total is unchanged)
    wp deardoc sync --force     Import new posts even if the total is unchanged

## Filters

dds_max_posts_per_run   Posts imported per run (default 3).
dds_pause_seconds       Pause between posts in a run (default 2).
dds_convert_to_webp     Convert PNG/JPEG images to WebP before import (default true).
dds_max_image_width     Maximum width of imported images in pixels (default 1600).
dds_webp_quality        WebP quality, 1–100 (default 82).
dds_image_hosts         Hosts images may be downloaded from
                        (default firebasestorage.googleapis.com, storage.googleapis.com).

## Changelog

### 1.1.0

- A post that fails 3 times is skipped permanently instead of failing every run.
- Smaller runs: 3 posts per run with a pause between posts (was 10).
- Images are converted to WebP and resized before WordPress makes its copies.
- Run time is logged. New "DearDoc posts skipped" status and "Retry skipped posts" action.

### 1.0.0

- First release.
