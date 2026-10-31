# Changelog

## 0.2.3

- README: cover image and screenshots.

## 0.2.0

- `presence()`: avatars in the page header show who else has the page open.
- `InteractsWithHibiraLock` for edit pages: one person edits a record at a
  time, others can ask for it and the holder can save and hand it over. The
  lock moves on when its holder leaves, using Hibira presence instead of
  heartbeat polling.
- `liveTables()`: resource tables refresh for everyone who has them open when
  records are saved, deleted or restored, anywhere in the app. Changes are
  sent once per request or queued job.
- `importProgress()`: a progress card for the user's running imports and
  exports, updated after each chunk of rows.
- `chat()`: a Chat page for the panel's users, one to one or in groups, with
  unread counts in the top bar, online status and message history, stored by
  Hibira's Chat API.

## 0.1.0

First release.

- `HibiraPlugin` for Filament 3, 4 and 5 panels: points the panel's Laravel
  Echo client at Hibira from the `HIBIRA_*` settings, with no change to
  `config/filament.php`.
- `databaseNotifications()`: database notifications arrive as soon as they
  are sent, with polling off.
- Connection light next to the user menu, with a translated tooltip.
- `HibiraChannelsWidget`: live channels, presence channels and presence
  members, refreshed every 10 seconds.
- Translations: English, Traditional Chinese, Simplified Chinese, Japanese,
  Korean, Thai and Vietnamese.
