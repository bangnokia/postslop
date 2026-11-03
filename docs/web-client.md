# Web client

`composer start` (or `php cua start --daemon` in `server/`) serves the built client at `SERVER_ADDRESS` (<http://127.0.0.1:8087> by default) and asks for the access token once:

- **Sidebar**: new post (or press <kbd>C</kbd>), the calendar, posts by status with counts, each account's state, and light, dark or system theme.
- **Calendar**: a month grid and a week by the hour. Click a free day or hour to write a post scheduled for then; click a post to open it.
- **Posts**: a grid of up to four columns, filtered by scheduled, drafts, needs attention or published, with search.
- **Compose**: as a page or a modal over any page. Per-site character counters and previews, paste, drop or pick images, or one MP4 or MOV video for X, Bluesky and LinkedIn, with descriptions, publish now or schedule, and save drafts. Publishing hands the post to the queue at once; a toast reports the result. The unsent draft, images included, is kept in this browser.
- **Post**: opens in a drawer. Edit, publish, reschedule or delete a draft; retry only the failed destinations of a sent post.
- **Accounts**: which sessions are ready, why the others are not, and the technical details. See [accounts](accounts.md).

Build it once, and again after changing it:

```sh
composer build    # writes server/public
composer web:dev  # work at http://127.0.0.1:5173 against the running server
```
