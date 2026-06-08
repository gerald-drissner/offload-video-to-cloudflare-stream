# Offload Video to Cloudflare Stream

WordPress plugin that uploads existing Media Library video attachments to Cloudflare Stream and serves ready videos through the Cloudflare Stream player.

This is an independent project. It is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc. “Cloudflare” and “Cloudflare Stream” are trademarks of Cloudflare, Inc., used here only to describe compatibility.

## Requirements

- WordPress 6.5 or newer
- Tested up to WordPress 7.0
- PHP 8.1 or newer
- A Cloudflare account with an active Stream subscription
- A scoped Cloudflare API token with Account → Stream → Edit permission

## Features

- Lists local WordPress video attachments in `Media → Cloudflare Stream`.
- Uploads selected videos to Cloudflare Stream by URL-copy first.
- Falls back to server-side direct upload for files under 200 MB when URL-copy fails.
- Stores Stream UID/status metadata on the WordPress attachment.
- Self-managing WP-Cron status polling while videos are processing.
- Optional automatic frontend replacement for Gutenberg video blocks, WordPress `[video]` shortcodes, local `<video>` HTML, and direct local video-file links when the matching Stream video is ready.
- Manual shortcode: `[gd_cloudflare_stream id="123"]` or `[gd_cloudflare_stream uid="VIDEO_UID"]`.
- Non-destructive Cloudflare connection test.
- Does not delete local files or Cloudflare videos.

## Secure token storage

You can store the Cloudflare API token outside the database by adding this to `wp-config.php`:

```php
define( 'GD_CFS_API_TOKEN', 'your-token-here' );
```

When this constant is set, the plugin uses it and does not write the token to the WordPress options table.

## Development

Run a PHP syntax check:

```bash
find offload-video-to-cloudflare-stream -name '*.php' -print0 | xargs -0 -n1 php -l
```

Build an installable ZIP:

```bash
bash bin/build-zip.sh
```

The build script creates `dist/offload-video-to-cloudflare-stream.zip`.

## WordPress.org release notes

The WordPress.org plugin directory uses `readme.txt` in SVN `trunk` to find the `Stable tag`; that stable tag must point to a matching folder under `/tags/`. Keep the version in `offload-video-to-cloudflare-stream.php`, `readme.txt`, and the SVN tag folder synchronized.

## License

GPL-2.0-or-later. See `LICENSE`.
