=== Fabrement Lite ===
Contributors: mxverentsov
Tags: mcp, mcp-server, claude, chatgpt, gutenberg
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP server for WordPress. Connect Claude, ChatGPT, Cursor or any MCP client and manage content, Gutenberg blocks, templates and SEO.

== Description ==

Fabrement Lite makes your WordPress site an MCP (Model Context Protocol) server. Connect the AI client you already use, say what you want in plain words, and it is done on your site.

It works with Claude, ChatGPT, Codex, Cursor and any other MCP client. The plugin is free and works by itself: the client talks to your site directly, as the Administrator who approved it, with an Editor's permissions only.

= How it works =

Connect your client once (see Installation), then ask for what you need. A few examples:

* "Create a draft page called Pricing from the blocks this site has, and send me the preview link."
* "On the About page, change the heading of the team section."
* "Write SEO descriptions for the pages that have none."

= What you can manage =

* **Pages and posts**: create, edit, preview and publish pages, posts and custom records. New records start as drafts.
* **Gutenberg blocks**: the client finds the blocks your theme and plugins provide, fills them in, and changes one block on a page while the rest stays as it was. Blocks made with Advanced Custom Fields (ACF) PRO are supported.
* **Synced patterns**: sections shared by several pages, edited in one place.
* **Templates**: a header, the page content and a footer, built from blocks and assigned to pages. A post type's default template, the 404 template and archive templates are set in wp-admin.
* **Terms** of the site's taxonomies.
* **Media**: the media library.
* **SEO**, with Yoast SEO: titles and descriptions of pages, posts and terms, and schema.org data of pages and posts.
* **Forms**, with Contact Form 7: create and edit forms.
* **Translations**, with WPML: language versions of pages, terms, templates, patterns and forms.

The plugin has 41 tools, and 6 more on a site with WPML. The instructions that teach the client how to use them come with the plugin.

= Content checks =

Every text the client sends is checked before it is saved, and only HTML that WordPress accepts in a post goes in. When something is refused, the client is told what and where, and the page stays as it was. No tool writes or runs PHP, JavaScript, SQL or shell commands.

= Screens in wp-admin =

* **MCP Connection**: setup steps for each client and the list of connected clients, each with a Disconnect button.
* **Templates**: build a header, the page content and a footer from blocks in the block editor, and assign the template to pages, post types and archives.

= Fabrement, the full edition =

Nothing in Lite is locked or time-limited. Fabrement is a separate product by the same team that adds AI generation of custom blocks and page layouts and a design system, and uses a Fabrement account. Read about it at [fabrement.com](https://fabrement.com/).

== Installation ==

1. Install and activate the plugin on a single WordPress site.
2. Open **Fabrement > MCP Connection**.
3. Choose your AI client and follow the steps shown.
4. Approve the access with your WordPress account (an Administrator).

Signing in through OAuth uses HTTPS. An OAuth connection ends when it is disconnected, after 90 days unused, or when the user's password changes. Application passwords are available as well, and work on local development sites too. Either way the access opens only the Fabrement MCP endpoint, not wp-admin or the rest of the REST API.

== Screenshots ==

1. MCP Connection: pick your AI client, follow the steps, see who is connected.
2. Templates: each template with the pages, post types and archives it is assigned to.
3. A template in the block editor: a header, the Content Source block and a footer.
4. A page an AI client built from the theme's own blocks.

== Frequently Asked Questions ==

= Which AI clients work with it? =

Claude, ChatGPT, Codex, Cursor and any other client that supports MCP. The MCP Connection screen has ready steps for each of them.

= Is it free? Do I need an account or an API key? =

The plugin is free and needs only your own AI client. It connects to the site's MCP endpoint, `/wp-json/fabrement/v1/mcp`.

= What can the connected client change? =

Content and data: pages and posts, blocks, media, terms, forms, templates and the SEO of pages, posts and terms. An Administrator approves the access, and the client works with an Editor's permissions, never the Administrator's. Content whose permissions an Editor does not have, such as WooCommerce products, is edited in wp-admin. Templates take an Editor: the client builds them from blocks and assigns them to pages; a post type's default template, the 404 template and archive templates are set in wp-admin. Through its tools it cannot install or change plugins, themes or users, create or change post types and taxonomies, or change menus, site settings, site-wide SEO settings or permalinks: these stay in wp-admin. Each tool checks the same permission WordPress asks for in wp-admin, so the client can do no more than an Editor can. You can disconnect a client at any time on the MCP Connection screen.

= Does it work with Advanced Custom Fields? =

Yes, with Gutenberg blocks made with ACF PRO: the client places them on a page and fills in their fields.

= What happens when I delete the plugin? =

Your pages, posts and templates stay, so installing the plugin again brings everything back. The connected clients and the application passwords issued for them are removed (kept while another Fabrement edition is installed).

= How do I switch to the full edition? =

Deactivate Lite, then activate the other edition. One edition is active at a time. Nothing is deleted: your content, your templates and their assignments stay in WordPress, and their content-source settings in `uploads/fabrement-lite/`.

== External services ==

The plugin connects only to the services listed here. Nothing is sent to Fabrement or 7Fridays: there is no telemetry, licence check or update service, and installing or activating the plugin makes no external request.

**The AI client you connect.** Whatever the client asks for through the tools (content, settings, media details) is returned to it and so reaches its provider. This happens only for a client you approved, and only for the tools it calls. The provider's own terms and privacy policy apply.

**OAuth connection of Claude or ChatGPT.** Used only when one of these clients starts an OAuth connection to the site; OAuth can be turned off in Fabrement → MCP Connection, and then no request is made. Connections made with an application password, and clients whose return address is on your own computer (Claude Code, Codex), do not use it.

* Client verification: when a client identifies itself by an address on claude.ai or chatgpt.com, the site reads the public metadata document at that address (its name and allowed return addresses) to check the request; it is not stored. The request carries no content, credentials or cookies; like every WordPress HTTP request it includes WordPress's standard user-agent (the WordPress version and the site's address).
* Connect button: the "Connect to Claude (1-click)" button on Fabrement → MCP Connection opens claude.ai in the user's browser with this site's name and MCP address filled in. This happens only when the user clicks it.
* Return to the client: after the user approves the connection on the site's consent screen, their browser is sent to the client's return address on claude.ai (`https://claude.ai/api/mcp/auth_callback`) or chatgpt.com (`https://chatgpt.com/connector_platform_oauth_redirect`, `https://chatgpt.com/connector/oauth/…`) with a one-time authorization code, the site's address and the client's own state value. The client exchanges the code with the site for an access token, which it then sends with its tool calls. If the user declines, only an "access denied" answer is sent. No other address is accepted.

* Claude is provided by Anthropic: [terms](https://www.anthropic.com/legal/consumer-terms), [privacy policy](https://www.anthropic.com/legal/privacy).
* ChatGPT is provided by OpenAI: [terms](https://openai.com/policies/terms-of-use/), [privacy policy](https://openai.com/policies/privacy-policy/).

**Media upload by URL.** When the connected client asks the upload-media tool to add a file from a web address, the site downloads that one file with the WordPress HTTP API and adds it to the Media Library. The address is chosen by the client, so the service is whichever site hosts the file, and its own terms and privacy policy apply. No site content or credentials are sent; the request includes WordPress's standard user-agent (the WordPress version and the site's address). Private and local network addresses are refused. Files sent inline (base64) involve no download.

== Source code ==

The JavaScript in `assets/editor-plugin/build` and `assets/templates-app/build` is compiled from the TypeScript sources shipped in the same plugin, in `assets/editor-plugin/src` and `assets/templates-app/src`. To rebuild either one, run `npm install` and `npm run build` in its folder (Node.js and npm are required; Vite is the build tool).

Bundled third-party code, all under GPL-compatible licences: WordPress MCP Adapter and PHP MCP Schema (GPL-2.0-or-later), Pimple, PSR Container and Symfony polyfills (MIT), Zod and the generated Tailwind CSS (MIT), and the Manrope font (SIL Open Font License 1.1, `assets/fonts/OFL.txt`). The full list with licence texts is in `THIRD-PARTY-LICENSES.txt`.

== Changelog ==

= 1.5.0 =
* First release of Fabrement Lite. The version number follows the Fabrement product line.
