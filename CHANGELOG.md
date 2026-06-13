# Changelog

## 1.0.0

- First public release prepared for the WordPress.org directory.
- Upload existing WordPress video attachments to Cloudflare Stream.
- URL-copy first, with direct-upload fallback for files under 200 MB.
- Self-managing status polling via WP-Cron.
- Automatic frontend replacement for local videos that have a Stream UID.
- Manual `[voffloadcfs_video]` shortcode.
- Non-destructive connection test.
- Polished dashboard with workflow overview, setup status pills, clearer action descriptions, and stronger destructive-action styling.
- Setup checklist collapses after a successful connection test and can be reopened with one click.
- Dashboard footer displays the plugin version, copyright attribution, and GPLv2-or-later license notice.
- Administrator-only management by default.
- WordPress.org-ready `readme.txt`, uninstall cleanup, and translation path.
