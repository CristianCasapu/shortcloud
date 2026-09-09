# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.1.3] – 2026-09-09

- Works straight from the app store: on the first request after the app is enabled, the rewrite
  rule is written into .htaccess (when writable) without a visit to the settings page.
- The setup check and the settings page now probe the short address over HTTP instead of only
  reading .htaccess, so an ignored .htaccess (nginx, Apache without AllowOverride) is reported
  as such, with the snippet to use. go.php answers the probe before booting Nextcloud.

## [1.1.2] – 2026-09-09

- No more polling: the rewrite rule is checked exactly when something could have removed it —
  on app updates, on the first request after a Nextcloud version change — plus one daily check
  in the maintenance window as a safety net.
- The album sync asks the database one cheap question first (row count and highest id) and only
  does work when album links were added or removed; it runs every 15 minutes.

## [1.1.1] – 2026-09-09

- The rewrite block now sits above Nextcloud's marker line in .htaccess, so `occ upgrade` and
  `occ maintenance:update:htaccess` keep it.
- The self-healing job runs every 5 minutes and is time sensitive (time-insensitive jobs only run
  inside the maintenance window, so it had never run); app updates trigger the check immediately.
- The album sync job is time sensitive for the same reason.

## [1.1.0] – 2026-09-09

- Public album links of the Photos and Memories apps get short links too: they are picked up
  within five minutes (or at once when the Shortcloud page is opened), follow the album link
  and answer "gone" when it is removed. New endpoint `POST /api/v1/album/{token}` for apps
  such as Memories to fetch the short link on demand.

## [1.0.1] – 2026-09-09

- Styles were only injected into the sidebar bundle: the Shortcloud page and the administration page now carry their own.
- Toast notifications are styled (dialogs stylesheet).
- A deleted account takes its short links with it.
- New commands: `occ shortcloud:list`, `occ shortcloud:delete <id|slug>`.
- Screenshots in the repository.

## [1.0.0] – 2026-09-09

First release.

### Added
- A short link, `/go/<slug>`, for every public share, made automatically when the share is created
  from the web interface, the desktop client or a mobile client.
- *Copy short link* in the sharing sidebar, with the slug editable in place.
- Custom slugs and hand-made short links for any address, from the Shortcloud page or
  `occ shortcloud:add`.
- Extra short domains served by the same server.
- QR code, hit counter and time of the last visit for every link. Visitor addresses are not stored.
- Links follow their share: a changed share token keeps the link working, a deleted share answers
  "gone".
- Administration settings: prefix, slug length, automatic creation, groups allowed to create,
  external targets (none / a list of hosts / any), reserved slugs, extra domains, pause.
- One-click installation of the rewrite rule into `.htaccess` (Apache), the snippet for other web
  servers, and a setup check that reports whether the rule works.
- `occ shortcloud:setup` and `occ shortcloud:backfill` for existing shares.
- English and Romanian.
