# Voffload for Cloudflare Stream

WordPress plugin that uploads existing Media Library video attachments to Cloudflare Stream and serves ready videos through the Cloudflare Stream player.

This is an independent project. It is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc. “Cloudflare” and “Cloudflare Stream” are trademarks of Cloudflare, Inc., used here only to describe compatibility.

## Requirements

- WordPress 6.5 or newer
- Tested up to WordPress 7.0
- PHP 8.1 or newer
- A Cloudflare account with an active Stream subscription
- A scoped Cloudflare API token with Account → Stream → Edit or Account → Stream → Write permission

## Features

- Lists local WordPress video attachments in `Media → Cloudflare Stream`.
- Uploads selected videos to Cloudflare Stream by URL-copy first.
- Falls back to server-side direct upload for files under 200 MB when URL-copy fails.
- Stores Stream UID/status metadata on the WordPress attachment.
- Non-destructively syncs existing Cloudflare Stream videos back to matching WordPress video attachments when metadata or a unique exact title/filename match identifies the local attachment.
- Deletes selected Cloudflare Stream videos from the plugin dashboard, individually or in bulk, while keeping the original WordPress media attachments.
- Self-managing WP-Cron status polling while videos are processing.
- Optional automatic frontend replacement for Gutenberg video blocks, WordPress `[video]` shortcodes, local `<video>` HTML, and direct local video-file links when the matching Stream video is ready.
- Manual shortcode: `[voffloadcfs_video id="123"]` or `[voffloadcfs_video uid="VIDEO_UID"]`.
- Non-destructive Cloudflare connection test.
- Structured dashboard with workflow overview, setup status pills, action descriptions, and clearer destructive-action styling.
- Setup checklist in the WordPress dashboard collapses after a successful connection test and can be reopened with one click.
- Dashboard footer displays the current plugin version, copyright attribution, and GPLv2-or-later license notice.
- Does not delete local files; remote Cloudflare Stream deletion is available only through explicit delete actions with confirmation.

## Cloudflare setup

The plugin needs three Cloudflare values. In WordPress, go to `Media → Cloudflare Stream → Settings`. The same checklist is shown there.

### 1. Account ID

Use the Cloudflare Account ID for the account where Stream is enabled. You can find it in the Cloudflare dashboard URL and account overview.

### 2. API token

Create a scoped API token. Do **not** use the Global API Key.

Recommended token setup:

1. Open Cloudflare Dashboard → Manage Account → API Tokens.
2. Create a custom token.
3. Add the account-level permission `Account → Stream → Edit`. In some Cloudflare screens the equivalent permission is shown as `Account → Stream → Write`. Upload, sync/reconnect, status refresh, and remote deletion use this scoped Stream permission.
4. Scope Account Resources to the single Cloudflare account that owns the Stream library.
5. Do not add DNS, Zone, Workers, Cache Purge, or Global API Key permissions. They are not needed.
6. Copy the token immediately after creation. Cloudflare shows the token secret only once.

For better security, store the API token outside the database by adding this to `wp-config.php`:

```php
define( 'VOFFLOADCFS_API_TOKEN', 'xxx' );
```

When this constant is set, the plugin uses it and does not write the token to the WordPress options table.

### 3. Cloudflare Stream Customer Code

The Customer Code is the `CODE` part in:

```text
customer-CODE.cloudflarestream.com
```

To find it, open Cloudflare Dashboard → Stream → Videos, open any uploaded video, and copy the embed/iframe code. You may paste the bare code, the full customer subdomain, the iframe URL, or the full iframe snippet into the plugin setting; the plugin extracts and stores only the code.

## Usage

1. Install and activate the plugin.
2. Go to `Media → Cloudflare Stream`.
3. Enter Account ID, API token, and Customer Code.
4. Run `Test Cloudflare connection`. If the test succeeds, the setup checklist collapses automatically and can be reopened by clicking the checklist heading.
5. Select videos and click `Upload selected`.
6. Wait until Cloudflare marks the videos ready. The plugin can then replace matching local video output automatically.
7. To remove a remote Stream copy later, use `Delete from Cloudflare` for one video row or select multiple rows and use the bulk delete button. The local WordPress media attachment remains.

If videos already exist in Cloudflare Stream because you used an earlier unpublished build, click `Sync existing Cloudflare Stream videos` or select rows and click `Reconnect selected from Cloudflare`. As a final fallback, paste a Stream UID and attach it to one selected WordPress video. Reconnection is non-destructive and uses stored metadata first, then exact unique title/filename matches for the current site.

## Dashboard notes

The dashboard intentionally separates remote Cloudflare actions from local WordPress media management:

- Upload creates a remote Stream copy and keeps the local WordPress attachment.
- Refresh reads Cloudflare processing status and now attempts safe reconnect when the local UID is missing.
- Reconnect links existing Stream videos back to local attachments when there is a safe metadata or exact unique filename/title match.
- Delete from Cloudflare removes only the remote Stream asset and clears local Stream metadata. It does not delete the WordPress media attachment.
- Clear local Stream metadata removes the local mapping only. It does not contact Cloudflare.

## Shortcode

By WordPress attachment ID:

```text
[voffloadcfs_video id="123"]
```

By Cloudflare Stream UID:

```text
[voffloadcfs_video uid="VIDEO_UID"]
```

Optional attributes:

- `title` — accessible iframe title.
- `aspect` — aspect ratio as `width/height`, for example `aspect="16/9"`.

## Development

Run a PHP syntax check:

```bash
find voffload-cloudflare-stream -name '*.php' -print0 | xargs -0 -n1 php -l
```

Build an installable ZIP:

```bash
bash bin/build-zip.sh
```

The build script creates `dist/voffload-cloudflare-stream.zip`.

## WordPress.org release notes

The WordPress.org plugin directory uses `readme.txt` in SVN `trunk` to find the `Stable tag`; that stable tag must point to a matching folder under `/tags/`. Keep the version in `voffload-cloudflare-stream.php`, `readme.txt`, and the SVN tag folder synchronized.

## License

GPL-2.0-or-later. See `LICENSE`.

## Suggested GitHub topics

Recommended repository topics: `wordpress-plugin`, `wordpress`, `cloudflare`, `cloudflare-stream`, `video`, `video-hosting`, `streaming`, `media-library`, `media-offload`.
