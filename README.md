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

### Environment indicator

The admin bar shows the current environment (`VIP_GO_APP_ENVIRONMENT`, or `wp_get_environment_type()` off VIP): red on production, green on local, amber everywhere else. Filter the name with `herd_experience_environment` and the colors with `herd_experience_environment_colors`.

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
