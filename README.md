# RoXx Competition Results

A server-rendered WordPress block for the organization-scoped results API.
Requires WordPress 6.0+, PHP 7.4+, and access to the competition server.
HTTPS is required except for specified local development addresses. No build
step is required.

Licensed under [GPL-2.0-or-later](LICENSE).

## Local development with Docker

Start Docker Desktop (or Docker Engine with Compose), then run from this
directory:

```sh
docker compose up -d
```

Open [http://localhost:8080](http://localhost:8080), complete the WordPress
installation, and activate **RoXx Competition Results**. The plugin directory
is mounted into the container, so source changes take effect on reload. A hard
refresh may be needed for JavaScript and CSS. If WordPress initially reports a
database connection error, wait for MariaDB to finish starting and reload.

WordPress and database data persist in Docker volumes. This setup is for local
development only: it uses a publicly known database password, binds WordPress
to localhost, and enables debug logging. It is not a production deployment.
For an API running on the Mac, use `http://host.docker.internal:8000` as the
competition server URL (adjust the port as needed). The API must listen on an
interface reachable by Docker, and Django must include `host.docker.internal`
in `ALLOWED_HOSTS`. Binding Django to all interfaces can expose it to other
devices on your network; use a firewall and a test organization key.

HTTP requests transmit the key unencrypted. Use this hostname only for local
development with a trusted Docker network; Docker's host name resolution must
point to the intended host. The competition link in the block uses the same
hostname, which may not resolve in a browser outside Docker. `localhost`,
`127.0.0.1`, and `[::1]` instead refer to the WordPress container itself, not
the host Mac. For other servers use HTTPS (private hosts are rejected by
WordPress's safe HTTP client by default).

```sh
docker compose logs -f wordpress
docker compose exec wordpress cat /var/www/html/wp-content/debug.log
docker compose down
```

The debug log appears after WordPress logs an error and may contain sensitive
details. `docker compose down` stops the containers without deleting their
data. Removing the Compose volumes also deletes the local WordPress site and
database.

## Install and configure

1. Copy this directory into `wp-content/plugins/roxxcomp-results/` and activate
   **RoXx Competition Results**.
2. In **Settings → RoXx Ergebnisse**, enter the base URL of the
   competition site (for example `https://competition.example`, without
   `/api/v1/`) and an organization results API key. Create the key in the
   competition site's organization settings. The key is stored in the
   WordPress database, is never rendered in the block or sent to visitors,
   and is not shown again in the settings form.
3. In the block editor, add **Wettkampfergebnisse**, select a competition,
   choose combined or current-round-only results, and publish the page.
   Competitions marked non-public are labelled **(private)** in the selector;
   this label is not added to the published heading. The competition API must
   provide the `public` flag.

The block always uses the current stage. With JavaScript enabled, changing the
category updates the results without reloading the page. Without JavaScript,
visitors can select a category and press **Anzeigen**. It displays the
competition name as a link and a responsive ranking table. The link contains no
password or signup token and does not bypass the competition site's login or
password protection.

The organization key can read private and password-protected competitions.
Selecting one in a block makes its displayed results available to page visitors
without that password. Publish only competitions and categories intended for
that audience. Staff-only competitions are excluded by the API.

## Styling

The frontend block uses the following classes. Add overrides in your theme's
stylesheet or WordPress's Additional CSS; the plugin's defaults are in
[`assets/style.css`](assets/style.css).

| Class | Element |
| --- | --- |
| `.wp-block-roxxcomp-results` | WordPress block wrapper; also has `.roxxcomp-results`. |
| `.roxxcomp-results` | Outer results block, including error and empty states. |
| `.roxxcomp-results__title` | Competition heading; contains the competition link. |
| `.roxxcomp-results__filter` | Category form; contains a `label`, `select`, and submit `button`. |
| `.roxxcomp-results__status` | Loading, success, or error message for JavaScript category changes. |
| `.roxxcomp-results__content` | Current stage and results or empty-category message. |
| `.roxxcomp-results__stage` | Current stage name. |
| `.roxxcomp-results__table-wrap` | Scrollable, keyboard-focusable container for the table. |
| `.roxxcomp-results__table` | Ranking table, with standard `thead`, `tbody`, `th`, and `td` elements. |
| `.roxxcomp-results__notice` | Server-side error message shown in place of the results. |

For example:

```css
.roxxcomp-results__title a {
    color: #005a70;
}

.roxxcomp-results__table th {
    background: #e8f4f6;
}
```

Keep horizontal scrolling on the table wrapper for narrow screens. The submit
button is hidden when JavaScript category switching is active, but is needed
when JavaScript is unavailable.

## Operations and privacy

Each rendered block requests current data from the competition API; the plugin
does not persistently cache responses. Category changes request the current
WordPress page again and update only the selected block; they do not expose the
API key or bypass the page's access controls. Disable page and CDN caching for pages
containing results, including cached variants of category query parameters, so
changes and withdrawals are reflected. Data already copied or downloaded by
visitors cannot be recalled.

To rotate a key, create a new key on the competition site, save it in WordPress,
then revoke the old key. WordPress administrators can remove the saved key in
the settings page. The API key is stored in the WordPress database; restrict
access to database backups and administrator accounts. The plugin does not
delete settings automatically on deactivation.

An API or configuration failure is displayed in place of the results. Only
authorized WordPress editors can use the competition-list REST endpoint; it
does not return the key. API requests do not follow redirects or follow URLs
provided by the API. HTTPS requests use `wp_safe_remote_get`; private hosts
are rejected by default. Local HTTP requests bypass that check only for the
explicit development hosts listed above, with redirects and proxies disabled.
`localhost` requests connect to IPv4 loopback; use `[::1]` for IPv6.

## Integration checks

Against an isolated, installed WordPress test site with an administrator, run:

```sh
WP_LOAD_PATH=/path/to/test-wordpress/wp-load.php php tests/integration.php
node tests/editor.js
```

The test creates and deletes a test page and intercepts the external API
requests; do not run it against a production installation.
