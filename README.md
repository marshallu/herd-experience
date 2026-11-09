# Herd Experience

Shared security and admin defaults for Herd WordPress VIP applications (www.marshall.edu, formarshallu.org, marshallforall.org, mupages.marshall.edu, muartistseries.org).

## What it does

### REST API access

Settings > Reading has a **REST API access** setting with three modes:

- Show the REST API to everyone (WordPress default)
- Show the REST API to everyone except the users endpoints (**default**)
- Show the REST API only to logged-in users

Restricted routes return `401 rest_cannot_access` to logged-out visitors, including `?_embed` sub-requests. On multisite only Super Admins see and can change the setting.

```php
// Force a mode in code.
add_filter( 'herd_experience_rest_api_access', fn() => 'logged-in' );

// Routes logged-out visitors can always read. Exact match.
add_filter( 'herd_experience_rest_api_allowlist', fn( $routes ) => array_merge( $routes, array( '/wp/v2/posts' ) ) );
```

### Author archives

Author archive slugs are login names, so author archives (`/author/{slug}/`, `?author=N`, author feeds) return a 404, author links point at the homepage, and the users provider is removed from the core sitemap.

```php
// Keep author archives on a site that needs them.
add_filter( 'herd_experience_disable_author_archives', '__return_false' );
```

### Security headers

Front-end responses get:

| Header | Value |
| --- | --- |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), usb=()` |

A header that something earlier in the request already set is skipped. HSTS is left to the VIP edge.

```php
// Change or drop headers.
add_filter(
	'herd_experience_security_headers',
	function ( $headers ) {
		unset( $headers['X-Frame-Options'] );
		return $headers;
	}
);
```

### Disable comments

Comments are disabled by default everywhere. When disabled, comments and pings are closed, existing comments are hidden, comment feeds redirect to the homepage, the Recent Comments widget and comment blocks are removed, the pingback XML-RPC methods are removed, and the Comments menu, Discussion settings, dashboard widget, meta boxes, list-table column, and admin bar node are removed.

Block Notes (comment type `note`, WordPress 6.9+) keep working: `/wp/v2/comments` only accepts requests for allowed types, and comment queries that name a type still run.

**Multisite**

- **Network Admin > Settings > Comments** — "Disable comments on all sites" (default Yes). Super Admins only.
- **Settings > General > Comments** on each site:
  - *Follow the network setting* (default)
  - *Disable comments on this site*
  - *Allow comments on this site* — Super Admins only, overrides a network-wide disable.

  Site admins can only turn comments off for their site, and only while the network allows comments. When comments are disabled network-wide, or a Super Admin has allowed them on the site, site admins see the setting read-only.

**Single site** — Settings > General > Comments: disable (default) or allow.

```php
// Force it in code; hides the setting.
define( 'HERD_EXPERIENCE_DISABLE_COMMENTS', true );

// Or decide per request.
add_filter( 'herd_experience_disable_comments', '__return_false' );

// Comment types that keep working (default: note).
add_filter( 'herd_experience_disable_comments_allowed_types', fn( $types ) => $types );

// Comment blocks removed from the inserter.
add_filter( 'herd_experience_disable_comments_disallowed_blocks', fn( $blocks ) => $blocks );
```

Replaces the `marshallu/herd-disable-comments` plugin; with the default settings the behavior matches it, except that Block Notes now work.

### Activity log

Records who changed what, in one network-wide table (`{base_prefix}herd_activity_log`, keyed by `blog_id`) rather than an option or one table per site:

| Area | Events |
| --- | --- |
| Users | created, profile fields changed (names only, not values), role changed, added to / removed from a site, deleted, Super Admin granted / revoked, signed in (SSO or password) |
| Sites | created, deleted, domain/path/public/archived/spam/deleted changed |
| Settings | site options (`blogname`, `blog_public`, `siteurl`, `admin_email`, `default_role`, `users_can_register`, Herd Experience settings, …) and network options (`registration`, `upload_filetypes`, the comments switch, …) with old and new values |
| Code | plugin activated / deactivated, theme switched |
| Content | posts, pages, and media trashed, restored, or permanently deleted |

The actor is the current user, or `wp-cli` / `cron` when there is none. IP addresses are not stored.

- **Multisite:** Network Admin > **Activity Log** (Super Admins), filterable by site ID (`0` = network events), username, and action.
- **Single site:** Tools > **Activity Log** (administrators).

The table is created with `dbDelta` the first time it's needed. Entries older than 365 days are pruned daily in batches by a cron event on the main site.

```php
// Turn logging off.
define( 'HERD_EXPERIENCE_DISABLE_ACTIVITY_LOG', true );

// Keep entries longer.
add_filter( 'herd_experience_activity_log_retention_days', fn() => 730 );

// Log more options (values are stored).
add_filter( 'herd_experience_activity_log_options', fn( $options ) => array_merge( $options, array( 'my_option' ) ) );
add_filter( 'herd_experience_activity_log_network_options', fn( $options ) => $options );

// Change or drop an entry before it's saved (return false to skip).
add_filter( 'herd_experience_activity_log_entry', fn( $entry ) => $entry );

// Log your own events.
Herd_Experience_Activity_Log::log( 'profile_published', 'Published profile for Jane Doe.', array( 'object_type' => 'post', 'object_id' => 123 ) );
```

### Environment indicator

The admin bar shows the current environment (`VIP_GO_APP_ENVIRONMENT`, or `wp_get_environment_type()` off VIP): red on production, green on local, amber everywhere else. Filter the name with `herd_experience_environment` and the colors with `herd_experience_environment_colors`.

### Admin color scheme

Every user gets the **Marshall** admin color scheme (black menu, [brand greens](https://www.marshall.edu/marsha/colors/)), whatever scheme they had saved, and the color scheme picker is removed from Users > Profile. Text on green uses Dark Accent Green `#028833` (4.6:1 against white) rather than Primary Green, which is only 3:1.

The WordPress 7.1 block and site editors only recognize core's schemes, so they stay blue under Marshall until core supports custom schemes ([#65776](https://core.trac.wordpress.org/ticket/65776)).

```php
// Offer Marshall as one more choice and keep core's Default.
add_filter( 'herd_experience_admin_colors_force', '__return_false' );
```

The stylesheet is compiled from `assets/admin-colors/marshall/colors.scss` with core's color scheme template. Rebuild it after editing the colors or upgrading WordPress:

```bash
bin/build-admin-colors.sh 7.1   # wordpress-develop branch
```

### WordPress logo

The WordPress (W) logo menu is removed from the admin bar for every user, on single sites and multisite.

### Network Admin columns (multisite)

Loaded only on multisite:

- **Sites** and **Users** get an **ID** column.
- **Plugins** gets a **Usage** column: *Network activated*, *Not active on any site*, or *Active on N sites* with a collapsible list of those sites (linked to each site's Plugins screen, with archived, spam, and deactivated sites labeled). Not shown on the Must-Use and Drop-ins tabs.
- **Themes** gets a **Usage** column: *Active on N sites* and, for parent themes, *Parent theme on N sites*.

Usage is read straight from each site's options table, 100 sites per query, and cached for 12 hours in the site transients `herd_experience_plugin_usage` and `herd_experience_theme_usage`. The cache is cleared when a site's active plugins or theme change and when a site is created or deleted.

Replaces the ID and Usage columns from the Multisite Enhancements plugin. Column keys (`herd_site_id`, `herd_user_id`, `herd_usage`), CSS classes, and transients don't overlap with that plugin's, so both can run during the cutover (each shows its own column).

## Install

Install it as an mu-plugin so it can't be deactivated. Add it to each VIP codebase with Composer:

```json
"extra": {
	"installer-paths": {
		"client-mu-plugins/{$name}/": ["marshallu/herd-experience"]
	}
}
```

Then require it from `client-mu-plugins/plugin-loader.php`:

```php
require_once __DIR__ . '/herd-experience/herd-experience.php';
```

## Coding standards

```bash
composer install
composer phpcs   # scan
composer phpcbf  # auto-fix
composer lint    # php -l
```
