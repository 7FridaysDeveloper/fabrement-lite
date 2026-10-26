# Fabrement Lite

A WordPress plugin that makes your site an MCP (Model Context Protocol) server. Connect the AI client
you already use — Claude, ChatGPT, Codex, Cursor or any other MCP client — say what you want in plain
words, and it is done on your site with the blocks, theme and plugins it already has.

- Website: [fabrement.com](https://fabrement.com/)
- Requires WordPress 6.9+, PHP 8.0+ and an administrator account
- MCP endpoint: `/wp-json/fabrement/v1/mcp`
- Text domain: `fabrement-lite`
- License: GPL-2.0-or-later

[![Claude Design to WordPress: HTML Export to Gutenberg Blocks in Under 2 Hours](https://img.youtube.com/vi/swOd_r8PwNQ/maxresdefault.jpg)](https://www.youtube.com/watch?v=swOd_r8PwNQ&t=44s)

▶ [Claude Design to WordPress: HTML Export to Gutenberg Blocks in Under 2 Hours](https://www.youtube.com/watch?v=swOd_r8PwNQ&t=44s) (YouTube)

## Fabrement Pro

Lite is free and complete on its own: nothing is locked or time-limited. **Fabrement Pro**, the full
edition by the same team, adds AI generation of custom blocks and page layouts, a design system,
site-wide CSS/JS and more, and uses a Fabrement account. The video above shows Pro at work. Read about
it at [fabrement.com](https://fabrement.com/).

One edition is active at a time: to switch, deactivate Lite and activate Pro. Your content stays in
WordPress.

## Installing

Download `fabrement-lite.zip` from the
[latest release](https://github.com/7FridaysDeveloper/fabrement-lite/releases/latest) and upload it in
*Plugins → Add New → Upload Plugin*, then activate it.

> Don't use the green **Code → Download ZIP** button — that is the source code, without the built
> scripts and dependencies. Download `fabrement-lite.zip` from
> [Releases](https://github.com/7FridaysDeveloper/fabrement-lite/releases/latest).

## Connecting a client

1. Open **Fabrement → MCP Connection**.
2. Choose your AI client and follow the steps shown.
3. Approve the access as an administrator.

Claude and ChatGPT sign in with OAuth (HTTPS). Claude Code, Codex, Cursor and local sites can use an
application password instead; the one the plugin issues works **only** on the MCP endpoint — every
other REST route and XML-RPC refuse it. A client can be disconnected at any time on the same screen.

The client talks to your site directly. Nothing is sent to Fabrement or 7Fridays: no account, no
telemetry, no licence check and no update service.

## What a client can do

| Area | Tools |
|---|---|
| Pages, posts, custom post types | list, read, create (always as a draft), edit one block, publish, delete, preview |
| Gutenberg blocks | find installed core, ACF and third-party blocks, read their schema and checked markup guides |
| Theme styles | read how the theme draws text, headings, links and buttons (theme.json) |
| Templates | a header, the content slot and a footer from blocks; assign to pages, post types, archives |
| Synced patterns | shared headers, footers and sections |
| Fabrement fields | custom fields for a post type or an options page, and their values |
| Menus, media, terms | create, edit, delete; upload media from a URL or inline |
| Site settings | title, tagline, site icon, 404 page |
| SEO (Yoast SEO) | titles, descriptions, defaults per post type, schema.org data |
| Forms (Contact Form 7) | create, edit, delete |
| Translations (WPML) | language versions of pages, terms, templates, patterns and forms |

61 tools, 6 more with WPML. Every tool requires `manage_options`. No tool installs or changes
plugins, themes or users, changes post types, taxonomies, the front page or permalinks, or writes
code. Every text a client sends is checked first: scripts, event handlers, `<iframe>`/`<style>` and
executable links are refused, and nothing is saved.

The instructions that teach the client how to use the tools ship with the plugin (`skills/`) and are
read through the `get-skill-doc` tool.

## Fabrement fields in a theme

```php
fabrement()->fields( $post_id )->get_text( 'subtitle' );
fabrement()->options( 'contacts' )->get_text( 'phone' );
```

Readers return values and objects (Image, Link, Post, Term) and print nothing; the theme escapes its
own output. Field definitions are JSON files in `uploads/fabrement-lite/fields/`; a value is stored
as post meta `fab_<field name>`.

## Admin screens

- **MCP Connection** — setup for each client, the connected clients and a Disconnect button.
- **Templates** — create, rename, delete and assign templates; edit them in the block editor.
- **Site General Settings** — options pages.

## Files

```
fabrement-lite.php       plugin header and bootstrap
uninstall.php            removes the connection state; the site's content stays
readme.txt               the WordPress.org readme
src/                     PHP (namespace Fabrement\)
  Modules/Mcp/           MCP server, tools (Roster.php lists them), OAuth, password scope
  Modules/ContentSource/ the content slot block of templates
  Modules/Fields/        Fabrement fields: definitions, values, editor
  Modules/Seo/           Yoast SEO bridge
  Modules/Translation/   WPML bridge
templates/               admin screens and the OAuth consent page
assets/editor-plugin/    field editor (TypeScript, Vite)
assets/templates-app/    Templates screen (React, Vite)
skills/                  instructions for the AI client; skills/blocks/ holds per-block guides
tests/                   Unit (no WordPress), Integration, Site (Docker), Wp, Wpml
tools/dead-code/         PHPStan dead-code check
build.sh, watch.sh       build the release zip / rebuild the bundles on change
```

## Development

```
composer install
cd assets/editor-plugin && npm ci && npm run build
cd assets/templates-app && npm ci && npm run build
WP_ROOT=/path/to/wordpress composer test      # unit tests
./watch.sh                                    # rebuild the bundles on every change
```

Run `composer dump-autoload` after adding a PHP class.

## Releasing

1. Raise the version in `fabrement-lite.php` (header and `FABREMENT_BLOCKS_VERSION`) and the
   `Stable tag` in `readme.txt`.
2. Build — it runs the tests and writes `dist/fabrement-lite-X.Y.Z.zip` with the folder
   `fabrement-lite/` inside:

   ```
   ./build.sh
   ```

3. Commit and tag: `git tag vX.Y.Z && git push origin main --tags`.
4. Create the release and attach the zip under the name `fabrement-lite.zip`:

   ```
   cp dist/fabrement-lite-X.Y.Z.zip fabrement-lite.zip
   gh release create vX.Y.Z fabrement-lite.zip --title "X.Y.Z" --notes "…"
   ```

Installed sites are not updated automatically: the plugin has no update checker.
