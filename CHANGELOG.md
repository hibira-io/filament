# Changelog

## 0.5.0

- `->waitingRooms()` adds a Waiting rooms page: live waiting and admitted
  counts per room, and actions to pause, open, let in now, post a notice
  on the waiting page, close, create, edit and delete rooms. Limit it with
  `authorize:`. Needs hibira-io/laravel 0.4 or later (`composer require
  hibira-io/laravel:^0.4`); with an older version the page says so.

## 0.4.0

- `php artisan hibira:filament`: one command to set up an app. It adds the
  settings to `.env` and asks for the credentials, turns on the broadcasting
  auth route, creates the notifications table, adds the plugin to your panel
  providers, then checks the connection, guards and user model. `--check`
  only checks.
- Every database notification now updates the bell as soon as it is
  stored, without `isEventDispatched: true` and without a second queued
  broadcast. The user's notification channel is authorized for you, and
  on Filament 4 and 5 the first notification no longer waits for a page
  load. Opt out with
  `->databaseNotifications(live: false)`.
- `liveTables()` also refreshes relation managers and table widgets, and
  watches the models of the panel's relation managers.
- `liveRecords()`: view pages show changes made elsewhere at once; edit
  pages say who changed the record and load their changes when you are
  ready, keeping your other unsaved edits.

## 0.3.1

- Works with hibira-io/laravel 0.2 and 0.3. On 0.3 the push SDK is
  optional, so `composer require hibira-io/filament` no longer needs `-W`
  on a fresh Laravel 13 app.

## 0.3.0

- AI agents in "Who's here" and edit locks: `Agent::make('id')->edit($record, fn () => ...)`
  shows the agent on the record's pages with a robot avatar and holds the
  record's edit lock while it works. People can't save over it, it waits for
  or asks people who hold the lock (`wait:`), and lets go when done.
- When the lock passes to you from someone else, the edit form reloads with
  their saved changes, so you never save over them.
- Fixed: with two or more people on a page, the "Who's here" avatars stacked
  vertically instead of in a row.

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
