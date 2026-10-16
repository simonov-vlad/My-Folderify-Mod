=== Folderify - Media Library Folders ===
Plugin Name: Folderify
Plugin URI: https://folderify.oppoyo.com/
Demo URI: https://mo.oppoyo.com/demo/wp-admin/upload.php
Author: Eric Nelson
Author URI: https://oppoyo.com/
Tags: media organizer, file manager, media library folders, media library folder, media folders
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Organize your WordPress media library with unlimited folders, drag & drop, and easy performance.

== Description ==

**Folderify** is a high-performance, React-based WordPress plugin that helps you keep your media library organized — no matter how many thousands of images, videos, audio files, or documents you have.

**[Live Demo](https://mo.oppoyo.com/demo/wp-admin/upload.php)** — Try it for yourself!

### 🔥 The Ultimate Free Alternative
Why pay for premium folder plugins? **Folderify Free** now includes absolutely *everything* you would normally have to pay for in the **Pro/Premium** versions of *FileBird, Folders (by Premio), Real Media Library, Media Library Organizer, CatFolders,* and *Wicked Folders*. Enjoy advanced features like nested subfolders, role-based permissions, color-coding, ZIP downloads, and more—completely free of charge!

### ✨ Minimalist & Unique UI
Unlike other bulky plugins that clutter your WordPress admin area, Folderify features a completely unique, ultra-minimalist design. It seamlessly blends into the native WordPress interface while providing a clean, distraction-free environment that is incredibly easy and intuitive to use. No heavy panels, no confusing settings—just pure performance and elegance.

### 🗂️ Core Features (100% Free)

We recently unlocked all of our Pro features and made them completely free for everyone!

* **Unlimited Root-Level Folders** — Organize your media library without limits.
* **Nested Subfolders** — Create a deep hierarchy of folders and subfolders.
* **Drag & Drop** — Effortlessly move media between folders or reorder them.
* **Folder Colors** — Color-code your folders for instant visual recognition.
* **Private Per-User Folders** — Let each user manage their own private folder structure.
* **Role-Based Permissions** — Granularly control who can create, edit, or delete folders.
* **Download Folder as ZIP** — Quickly download entire folders as a single archive.
* **Duplicate Folders** — Clone folder structures with a single click.
* **Breadcrumb Navigation** — Navigate large folder hierarchies with ease.
* **Media File Replacement** — Replace any media file while preserving its URL and all database references.
* **Smart Imports** — Import existing folders from **CSV**, **FileBird**, or **Folders (Premio)**.
* **CSV Export** — Easily export your folder structure for backup or migration.
* **Automatic Backups** — Peace of mind with daily automated folder structure backups.
* **Workflow Optimization** — Remembers your last active folder for faster uploading.
* **Folder Panel in Post Types** — Use the folder system within your posts and pages.
* **Page Builder Friendly** — Seamlessly works inside Gutenberg, Elementor, WPBakery, Beaver Builder, and Divi.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via **Plugins > Add New**.
2. Activate through the **Plugins** screen.
3. Go to **Media > Library** to see the folder sidebar.
4. Manage permissions and settings at **Folderify**.

== Frequently Asked Questions ==

= Will it slow down my website? =
No. Folderify uses its own custom database tables for folder indexing. This completely avoids costly JOIN queries on `wp_posts` and scales to 100,000+ files with no performance impact.

= Can I export folders? =
Yes. Go to **Folderify > Export** to export your existing folders structure.

= Does it work with Elementor's media picker? =
Yes. The folder panel appears inside Elementor's media modal.

= What happens if I deactivate the plugin? =
Your media files are untouched. Folder assignments are stored separately and can be re-activated at any time.

== Changelog ==

= 2.1 (custom build) =
* New: real folders on disk. Folders are mirrored as directories in wp-content/uploads; uploads go straight into the active folder, assigning media moves files (with thumbnails) and updates links in posts, renaming/moving a folder renames its directory.
* New: "Uploads sync" tab. Scan uploads to add unknown files to the Media Library and build the folder tree from directories, or push the existing folder tree to disk. Works in batches with a progress bar.
* New: `slug` column in the folders table (the real directory name).

= 2.0 --- 24 May, 2026 =
* Major Update: All Pro features are now 100% FREE and included in the core plugin!
* Added (Free): Nested Subfolders
* Added (Free): Folder Colors
* Added (Free): Private Per-User Folders
* Added (Free): Role-Based Permissions
* Added (Free): Download Folder as ZIP
* Added (Free): Duplicate Folders
* Added (Free): Smart Imports (FileBird, Folders by Premio)
* Added (Free): Automatic Backups
* Improved: Fully compatible and tested with WordPress 7.0.
* Removed: Freemius SDK and all premium restrictions completely removed for a faster, lighter experience.

= 1.1.2 =
* Fixed: Restricted drag-and-drop initialization to Folderify-active contexts only, resolving a conflict that prevented text selection on other admin pages (e.g., Plugins list).

= 1.1.0 =
* Added: NEW "Rewards" (Creator Program) section — Get 1 year of Folderify Pro for free by creating a review or social media post.
* Added: "Rewards" is now the default landing tab in Settings for better visibility.
* Added: "Get Pro for Free" promotional link in the sidebar for quick access to the rewards program.
* Improved: Refined Media Replacement UI with full Drag-and-Drop support and live preview.
* Improved: Hardened Media Replacement logic to prevent file metadata issues and accidental deletions.

= 1.0.0 =
* Initial production release.
* Unlimited folders with drag & drop.
* Global and private folder modes.
* Role-based permission system.
* Media Replace feature with URL and database reference updates.
* Import/Export support.
* React-powered UI with WordPress components integration.
