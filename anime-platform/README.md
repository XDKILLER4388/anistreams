# AniStream — Anime Streaming Platform

## What's Built

- **Homepage** — trending, seasonal, top-rated anime from Jikan + AniList APIs
- **Anime Detail Page** — full info, episode grid, related entries, favorites
- **Watch Page** — 6 embed servers with ad/redirect blocking, episode list, auto-next
- **Search Page** — real-time suggestions, genre/type/status filters, pagination
- **Backend (PHP)** — user auth, favorites, watch history, auto-sync cron job
- **Database (MySQL)** — anime, episodes, users, favorites, history tables

## How to Use

1. **Open in browser**: `http://localhost/Aninew/anime-platform/index.html`
2. **Click any anime** → Watch → **6 server buttons** appear
3. **If a server shows an error**, click the next one — different sources for the same episode
4. **Episodes with ● FREE badge** = direct YouTube embed from Muse Asia/Ani-One

## Ad & Redirect Blocking

Already built-in:
- `sandbox` attribute blocks popups and new tabs completely
- `window.open` is disabled
- Focus-stealing detection refocuses your window
- `beforeunload` blocks iframe-triggered navigation

## Servers

- **Server 1-4**: vidsrc.xyz, vidsrc.me, vidsrc.to, vidsrc.cc (MAL ID based)
- **Server 5**: megaplay.buzz (MAL ID based)
- **DUB**: vidsrc with English dubbing flag
- **YouTube**: Auto-detected from AniList `streamingEpisodes` (Muse Asia, Ani-One, etc.)

## Database Setup (Optional)

1. Import `backend/db.sql` into MySQL
2. Update credentials in `backend/config.php`
3. Schedule `backend/cron/fetch_anime.php` to run daily: `0 3 * * * php fetch_anime.php`

## Notes

- The frontend works **without the backend** — it pulls live data from Jikan + AniList APIs
- Some anime aren't mapped on all servers — that's why there are 6 fallbacks
- YouTube embeds (● FREE) have YouTube's own ads, but no redirects/popups
- The `sandbox` attribute is the strongest protection — iframes physically can't open new tabs

## Troubleshooting

**"We're Sorry! Error 410"** → That server doesn't have this anime. Click the next server button.

**Black screen / infinite loading** → The embed server is down or slow. Try another server.

**Redirects still happening** → Make sure you're on `localhost`, not `file://`. The sandbox only works over HTTP.
