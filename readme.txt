=== Voffload for Cloudflare Stream ===
Contributors: geralddrissner
Tags: video, video hosting, media offload, video player, streaming
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload your WordPress video attachments to Cloudflare Stream and serve them through the fast Cloudflare Stream player from the Media Library.

== Description ==

**Voffload for Cloudflare Stream** moves the heavy lifting of video hosting and playback off your own server and onto Cloudflare Stream. You pick existing video attachments in your WordPress Media Library, the plugin uploads them to Cloudflare Stream, and your visitors then watch them through Cloudflare's adaptive‑bitrate player instead of downloading large files from your origin.

This is useful if self‑hosted videos are slowing your site down, eating bandwidth, or stressing your host. Cloudflare Stream handles transcoding, multiple quality levels, and global delivery for you.

*This plugin is an independent project. It is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc. "Cloudflare" and "Cloudflare Stream" are trademarks of Cloudflare, Inc., used here only to describe compatibility.*

= What it does =

* Lists the video attachments already in your Media Library and shows their Cloudflare Stream status (Not uploaded / Processing / Ready / Error).
* Uploads selected videos to Cloudflare Stream. It first asks Cloudflare to fetch the public file URL ("URL copy"). If Cloudflare cannot reach that URL (for example because of hotlink protection, a firewall rule, or Automatic Platform Optimization), it automatically falls back to a direct upload from your server for files under 200 MB.
* Stores each video's Cloudflare Stream UID and processing status on the attachment.
* Can non-destructively sync existing Cloudflare Stream videos back to matching WordPress video attachments when metadata or a unique exact title/filename match identifies the local attachment.
* Can delete selected Cloudflare Stream videos from the plugin dashboard, individually or in bulk, while keeping the original WordPress media attachments.
* Tracks "processing" videos with a background check (WP‑Cron) that runs every 10 minutes — but only while at least one video is still processing. When everything is ready, the background check stops on its own, so the plugin does not make unnecessary calls.
* Automatically replaces your local video output on the frontend with the Cloudflare Stream player once the matching Stream video is ready (this is optional and can be turned off — see "Automatic replacement" below).
* Provides a shortcode for manual placement of the player (see the "Shortcode" section below for the exact tag and options).
* Includes a non‑destructive **Connection test** that checks your token, account access, and playback settings without uploading anything.
* Uses a structured dashboard with setup status, workflow descriptions, action guidance, and a small version/copyright/license footer.
* Never deletes your local video files. Deleting a Stream copy from Cloudflare only clears the plugin's local Stream metadata; removing local files is intentionally left to you.

= Do I need a paid Cloudflare account? =

You need a Cloudflare account and an active **Cloudflare Stream** subscription. A few clarifications, because this is a common point of confusion:

* Cloudflare Stream is a separate, usage‑based product. As of 2026 it is billed at roughly **$5 per 1,000 minutes of video stored** per month and **$1 per 1,000 minutes delivered**. Encoding and ingest are free, and there are no per‑GB egress fees.
* You do **not** need a Cloudflare Pro plan specifically, and Stream does **not** require the Workers Paid plan. A **free Cloudflare account works**, as long as you have added a Stream subscription to it.
* If you are on a **Pro or Business** plan, Cloudflare includes a small monthly allowance (around 100 minutes of storage and 10,000 minutes of delivery) on top.

Always confirm current pricing on Cloudflare's own pricing page, as it can change.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin** and upload the plugin ZIP, then click **Activate**. (Or install it directly from the WordPress.org plugin directory.)
2. Go to **Media → Cloudflare Stream**.
3. Enter your **Account ID**, **API Token**, and **Customer Code** (see "Getting your Cloudflare details" below).
4. Click **Test Cloudflare connection** to confirm everything is configured correctly.
5. Select one or more videos and click **Upload selected**.
6. Wait for status to reach **Ready** (refresh manually, or let the background check update it), then your videos are served from Cloudflare Stream.

If you already uploaded videos with an earlier unpublished build, use **Sync existing Cloudflare Stream videos** or select affected rows and click **Reconnect selected from Cloudflare**. As a final fallback, paste a Stream UID and attach it to one selected WordPress video. The sync lists videos in your Cloudflare Stream account and imports only those that still match an existing WordPress video attachment for the current site, using stored metadata first and then exact unique title/filename matches. It does not delete, upload, or overwrite videos.

== Dashboard guide ==

The plugin dashboard is available under **Media → Cloudflare Stream**.

* The top overview explains the three-step workflow: configure Cloudflare, upload or reconnect videos, then serve and maintain the Stream copies.
* The setup status pills show whether the Account ID, API token, and Customer Code are present before you run actions that need them.
* The **Video Library** table lists WordPress video attachments and shows their local file link, Stream status, Stream UID, and shortcode.
* **Upload selected** creates a Cloudflare Stream copy for selected local WordPress videos. The WordPress attachment remains in the Media Library.
* **Refresh selected status** reads the current processing state from Cloudflare. If no local UID is saved, the plugin first attempts a safe reconnect.
* **Reconnect selected from Cloudflare** finds existing Stream videos for selected local attachments by stored metadata first and then by exact unique title/filename matches.
* **Delete from Cloudflare** deletes the remote Stream asset after confirmation and clears only local Stream metadata. It does not delete the WordPress attachment.
* The manual UID fallback is for rare cases where automatic matching is unsafe because Cloudflare metadata is missing or multiple possible matches exist.

== Compatibility ==

* Requires WordPress 6.5 or newer.
* Tested up to WordPress 7.0.
* Requires PHP 8.1 or newer. PHP 8.3 or newer is recommended for current WordPress installations.
* Tested with modern PHP 8.x environments, including PHP 8.5.

== Getting your Cloudflare details ==

You need three pieces of information from your Cloudflare account.

**1. Account ID**
Log in to the Cloudflare dashboard at https://dash.cloudflare.com/ and open the **Stream** product (or any account page). Your Account ID is shown in the URL and on the account/overview pages. It is a 32‑character hexadecimal string.

**2. API Token (with Stream permission)**
Create a scoped token so the plugin can upload and check status. Do **not** use the Global API Key.

1. In the Cloudflare dashboard, go to **Manage Account → API Tokens** or **My Profile → API Tokens** and choose **Create Token → Create Custom Token**. Direct link: https://dash.cloudflare.com/profile/api-tokens
2. Under **Permissions**, add **Account → Stream → Edit**. Some Cloudflare screens use the newer label **Account → Stream → Write**; that is the equivalent write permission. Uploads, status updates, sync/reconnect, and remote deletion require Edit/Write. Read-only tokens can verify access but cannot upload or delete videos.
3. Under **Account Resources**, scope the token to the specific Cloudflare account that owns the Stream library.
4. Do not add unnecessary permissions. This plugin does **not** need DNS, Zone, Workers, Cache Purge, or Global API Key permissions.
5. (Recommended) Set an expiration and, if your server has a static IP, restrict the token to that IP.
6. Click **Continue to summary → Create Token**. **Copy the token immediately** — Cloudflare shows it only once and you cannot retrieve it later.

For better security you can define the token in your `wp-config.php` instead of saving it in the database:

`define( 'VOFFLOADCFS_API_TOKEN', 'xxx' );`

When this constant is set, the plugin uses it and never stores the token in the WordPress database (so it stays out of database backups).

**3. Customer Code**
This is the code in your Stream playback URLs, of the form `customer-CODE.cloudflarestream.com`. The plugin needs it to build the official Cloudflare Stream iframe URL. To find it, open **Cloudflare Dashboard → Stream → Videos**, open any uploaded video, and copy the embed/iframe code. The iframe `src` contains `customer-CODE.cloudflarestream.com`. You may paste the bare code, the full customer subdomain, the iframe URL, or the full iframe snippet; the plugin extracts and stores only the code.

== Automatic replacement (serve from Cloudflare Stream when available) ==

In **Media → Cloudflare Stream → Settings** you control when ready videos are automatically served from Cloudflare instead of from your server. There are independent checkboxes so you can enable exactly what you want:

* **Automatically replace Gutenberg video blocks** — replaces the core/video block on the frontend when the linked attachment has a Stream UID.
* **Automatically replace WordPress [video] shortcodes** — replaces the built‑in `[video]` shortcode output.
* **Automatically replace local video HTML and video‑file links** — also catches classic‑editor `<video>` tags, video blocks that have no attachment ID, and direct links to local video files.

Automatic replacement happens only for videos that already have a saved Cloudflare Stream UID and are marked ready. While Cloudflare is still processing a video, the local WordPress video output remains in place. If you turn every automatic option off, nothing on your frontend changes until you place a shortcode yourself.

== Shortcode ==

Use the shortcode to place a Cloudflare Stream player manually, for example inside a post, page, or widget.

By WordPress attachment ID (the ID shown in the Media → Cloudflare Stream table):

`[voffloadcfs_video id="123"]`

By Cloudflare Stream UID directly:

`[voffloadcfs_video uid="VIDEO_UID"]`

Optional attributes:

* `title` — accessible title for the player iframe.
* `aspect` — aspect ratio as `width/height` (default `16/9`). Example: `aspect="4/3"`.

Example with options:

`[voffloadcfs_video id="123" title="My talk" aspect="16/9"]`

The shortcode requires the **Customer Code** setting to be filled in, because that is what builds the official Cloudflare Stream iframe URL. If a logged‑in editor views a shortcode that cannot render (missing UID, wrong ID, missing Customer Code), the plugin shows a short admin‑only notice explaining why; normal visitors simply see nothing.

== External services ==

This plugin connects to **Cloudflare Stream**, a third‑party service operated by Cloudflare, Inc., because that is the service it exists to integrate with.

What is sent, and when:

* **When you upload a video:** the plugin sends the video (or the public URL of the video so Cloudflare can fetch it), the attachment title, the attachment ID, and your site URL to the Cloudflare API. This happens only when you choose to upload a video.
* **When you check status, run the connection test, reconnect selected videos, or sync existing Stream videos:** the plugin sends your Account ID and API token (as an authorization header) to the Cloudflare API to read video status, verify the token, list Stream video metadata, search Stream videos by name for a selected local attachment, or verify a manually entered Stream UID. The connection test, reconnect, and sync do not upload any video.
* **When you delete a Stream video from the plugin dashboard:** the plugin sends your Account ID, API token, and the selected Cloudflare Stream video UID to the Cloudflare API. Cloudflare deletes the remote Stream video and its copies. The original WordPress media attachment is not deleted.
* **On the frontend:** when a video is served from Stream, the visitor's browser loads the Cloudflare Stream player iframe from `customer-CODE.cloudflarestream.com`, so the visitor's browser communicates directly with Cloudflare.

Cloudflare API endpoint: https://api.cloudflare.com/
Cloudflare terms of service: https://www.cloudflare.com/terms/
Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/

No data is sent anywhere else, and the plugin does not send analytics about your site to the plugin author.

== Frequently Asked Questions ==

= Does this delete my original video files? =
No. The plugin never deletes local files. When a Stream video is ready, the matching local video output on the frontend is replaced by the Cloudflare Stream player (if you leave automatic replacement enabled), but the original files stay on your server until you remove them yourself. If you use **Delete from Cloudflare**, only the remote Cloudflare Stream copy is deleted and the local Stream metadata is cleared.

= What happens to my Cloudflare videos if I uninstall the plugin? =
Uninstalling removes the plugin's WordPress settings and the local Stream metadata it stored on your attachments. It does **not** delete any videos from Cloudflare. To remove videos from Cloudflare before uninstalling, use **Delete from Cloudflare** in the plugin dashboard or remove them in the Cloudflare Stream dashboard/API.

= Why did an upload fail with a "not reachable" or copy error? =
Cloudflare's "URL copy" needs to fetch your public media URL. If hotlink protection, a firewall/WAF rule, Cloudflare Automatic Platform Optimization, or login‑protected media blocks that fetch, the copy fails. For files under 200 MB the plugin then uploads directly from your server automatically. For files 200 MB or larger, either make the media URL publicly reachable so URL copy can be used, or upload that file to Cloudflare with a tus client (the resumable protocol used for large uploads).

= Does it work with caching plugins like WP Rocket and with Cloudflare APO? =
Yes. The frontend output is a standard iframe, so it caches well. If you use Automatic Platform Optimization, direct uploads from the server are used as the fallback when Cloudflare cannot fetch your media URL.

= Do I need to keep the browser open while videos process? =
No. After upload, Cloudflare processes the video on its side. The plugin checks status in the background every 10 minutes while videos are still processing, and stops checking once they are all ready. You can also refresh status manually at any time.

= Can I store the API token outside the database? =
Yes. Add `define( 'VOFFLOADCFS_API_TOKEN', 'xxx' );` to `wp-config.php` and the plugin will use that and never write the token to the database.

= Can I reconnect videos that already exist in Cloudflare Stream? =
Yes, if those videos can be matched safely. Use **Sync existing Cloudflare Stream videos** in Media → Cloudflare Stream, or select rows and click **Reconnect selected from Cloudflare**. As a final fallback, paste a Stream UID and attach it to one selected WordPress video. The plugin first uses the WordPress attachment ID stored in Stream metadata, then falls back to exact unique title/filename matches. Ambiguous matches are skipped to avoid linking the wrong video.

== Screenshots ==

1. The Cloudflare Stream manager under Media, showing the workflow overview, setup status, video library, status badges, and bulk actions.
2. The settings panel with Account ID, API token, Customer Code, full wp-config.php token line, allowed origins, collapsible setup help, and automatic‑replacement options.
3. The non‑destructive connection test with red/amber/green diagnostics.

== Changelog ==

= 1.0.0 =
* First public release prepared for the WordPress.org directory.
* Renamed to "Voffload for Cloudflare Stream" and updated the WordPress.org slug/text domain accordingly.
* Removed the explicit translation loader because WordPress.org loads plugin translations automatically on supported WordPress versions.
* Added uninstall cleanup that removes plugin options, transients, and local Stream metadata (Cloudflare videos are never touched).
* Added a "Settings" link on the Plugins screen.
* Hardened admin request handling with wp_unslash before sanitizing all input.
* Documented external service usage (Cloudflare Stream) and account/pricing requirements.
* Set the WordPress compatibility to 7.0 and the minimum PHP version to 8.1 for the public release.
* Restricted Cloudflare Stream management to administrators by default, because uploads can incur Cloudflare usage costs.
* Made the Customer Code field accept a full iframe/embed snippet and extract the code automatically.
* Kept local WordPress video output in place until the Cloudflare Stream video is marked ready.
* Carries forward the 0.2.x functionality: URL-copy with direct-upload fallback under 200 MB, self-managing status cron, connection test, Cloudflare-to-WordPress metadata sync/reconnect, remote Stream deletion, polished dashboard help/styling, collapsible setup help, shortcode playback, and automatic frontend replacement for blocks, [video] shortcodes, local <video> HTML, and local video-file links.

== Upgrade Notice ==

= 1.0.0 =
First public release. If you used an unpublished pre-release build, re-enter the settings after installing this public build.
