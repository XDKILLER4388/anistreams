// API layer — Local DB (primary) + Jikan v4 + AniList GraphQL + Consumet (streaming)
const JIKAN    = 'https://api.jikan.moe/v4';
const ANILIST  = 'https://graphql.anilist.co';
const LOCAL_API = 'backend/api';

import { CONSUMET_URL } from './config.js';
const CONSUMET = CONSUMET_URL;

// ── Cache — 10 min TTL ────────────────────────────────────────────────────────
const cache = new Map();
async function fetchCached(url, opts = {}) {
  const hit = cache.get(url);
  if (hit && Date.now() - hit.ts < 600_000) return hit.data;
  const res = await fetch(url, opts);
  if (!res.ok) throw new Error(`API ${res.status}: ${url}`);
  const data = await res.json();
  cache.set(url, { data, ts: Date.now() });
  return data;
}

// ── Normalize DB row → same shape as Jikan response ──────────────────────────
function normalizeDbAnime(row) {
  return {
    mal_id:   row.mal_id,
    title:    row.title,
    title_english: row.title,
    title_japanese: row.title_jp,
    synopsis: row.synopsis,
    images:   { jpg: { large_image_url: row.cover_image } },
    score:    row.score,
    episodes: row.episodes,
    status:   row.status,
    type:     row.type,
    year:     row.year,
    genres:   (row.genre || '').split(',').filter(Boolean).map(g => ({ name: g.trim() })),
  };
}

// ── Local DB helpers ──────────────────────────────────────────────────────────
async function dbGetAnimeList(params = {}) {
  const p = new URLSearchParams(params);
  try {
    const res = await fetch(`${LOCAL_API}/anime.php?${p}`);
    if (!res.ok) return null;
    const data = await res.json();
    if (data.error) return null;
    return data;
  } catch { return null; }
}

// ── Jikan ─────────────────────────────────────────────────────────────────────
export async function getTrending() {
  // Try local DB first (airing, sorted by score)
  const local = await dbGetAnimeList({ status: 'Currently Airing', limit: 12, page: 1 });
  if (local?.data?.length) return local.data.map(normalizeDbAnime);
  // Fallback to Jikan
  const d = await fetchCached(`${JIKAN}/top/anime?filter=airing&limit=12`);
  return d.data;
}
export async function getTopAnime(page = 1) {
  // Try local DB first
  const local = await dbGetAnimeList({ limit: 24, page });
  if (local?.data?.length) {
    return {
      data: local.data.map(normalizeDbAnime),
      pagination: local.pagination,
    };
  }
  return fetchCached(`${JIKAN}/top/anime?page=${page}&limit=24`);
}
export async function getSeasonNow(limit = 12) {
  // Try local DB first
  const local = await dbGetAnimeList({ status: 'Currently Airing', limit, page: 1 });
  if (local?.data?.length) return local.data.map(normalizeDbAnime);
  const d = await fetchCached(`${JIKAN}/seasons/now?limit=${limit}`);
  return d.data;
}
export async function getAnimeById(id) {
  // Try local DB first
  try {
    const res = await fetch(`${LOCAL_API}/anime.php?id=${id}`);
    if (res.ok) {
      const data = await res.json();
      if (data && !data.error) return normalizeDbAnime(data);
    }
  } catch {}
  // Fallback to Jikan
  const d = await fetchCached(`${JIKAN}/anime/${id}/full`);
  return d.data;
}
export async function getAnimeEpisodes(id, page = 1) {
  // Try local DB first
  try {
    const res = await fetch(`${LOCAL_API}/anime.php?action=aired_episodes&id=${id}`);
    if (res.ok) {
      const data = await res.json();
      if (data.source === 'db' && data.episodes?.length) {
        // Paginate locally (25 per page)
        const perPage = 25;
        const start = (page - 1) * perPage;
        const slice = data.episodes.slice(start, start + perPage);
        return {
          data: slice.map(ep => ({
            mal_id: ep.episode_number,
            title:  ep.title,
            aired:  ep.aired,
          })),
          pagination: {
            has_next_page: start + perPage < data.episodes.length,
            last_visible_page: Math.ceil(data.episodes.length / perPage),
          },
        };
      }
    }
  } catch {}
  return fetchCached(`${JIKAN}/anime/${id}/episodes?page=${page}`);
}
export async function searchAnime(query, filters = {}) {
  // Try local DB first for simple queries
  if (query || filters.genre || filters.year || filters.status) {
    const params = { limit: 24, page: filters.page || 1 };
    if (query)          params.q      = query;
    if (filters.year)   params.year   = filters.year;
    if (filters.status) params.status = filters.status;
    const local = await dbGetAnimeList(params);
    if (local?.data?.length) {
      return {
        data: local.data.map(normalizeDbAnime),
        pagination: {
          items: { total: local.pagination?.total },
          current_page: local.pagination?.page,
          last_visible_page: Math.ceil((local.pagination?.total || 0) / 24),
          has_next_page: (local.pagination?.page * 24) < local.pagination?.total,
        },
      };
    }
  }
  // Fallback to Jikan
  let url = `${JIKAN}/anime?q=${encodeURIComponent(query)}&limit=24`;
  if (filters.genre)  url += `&genres=${filters.genre}`;
  if (filters.year)   url += `&start_date=${filters.year}-01-01&end_date=${filters.year}-12-31`;
  if (filters.status) url += `&status=${filters.status}`;
  if (filters.type)   url += `&type=${filters.type}`;
  if (filters.page)   url += `&page=${filters.page}`;
  return fetchCached(url);
}
export async function getGenres() {
  const d = await fetchCached(`${JIKAN}/genres/anime`);
  return d.data;
}
export async function getAnimeByGenre(genreId, page = 1) {
  return fetchCached(`${JIKAN}/anime?genres=${genreId}&page=${page}&limit=24&order_by=score&sort=desc`);
}

// ── AniList ───────────────────────────────────────────────────────────────────
export async function getAniListAnime(malId) {
  const query = `
    query ($idMal: Int) {
      Media(idMal: $idMal, type: ANIME) {
        id idMal title { romaji english native }
        description coverImage { extraLarge }
        bannerImage averageScore popularity
        genres tags { name }
        streamingEpisodes { title thumbnail url site }
        externalLinks { site url type }
        trailer { id site }
      }
    }`;
  const res  = await fetch(ANILIST, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, variables: { idMal: malId } })
  });
  const json = await res.json();
  return json.data?.Media;
}

export async function getAniListTrending(page = 1) {
  const query = `
    query ($page: Int) {
      Page(page: $page, perPage: 12) {
        media(type: ANIME, sort: TRENDING_DESC, isAdult: false) {
          id idMal title { romaji english }
          coverImage { large extraLarge }
          bannerImage averageScore episodes
          genres status trailer { id site }
        }
      }
    }`;
  const res  = await fetch(ANILIST, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, variables: { page } })
  });
  const json = await res.json();
  return json.data?.Page?.media || [];
}

export function normalizeAniList(item) {
  return {
    mal_id:    item.idMal,
    anilist_id: item.id,
    title:     item.title?.english || item.title?.romaji,
    title_jp:  item.title?.native,
    images:    { jpg: { large_image_url: item.coverImage?.extraLarge || item.coverImage?.large } },
    banner:    item.bannerImage,
    score:     item.averageScore ? (item.averageScore / 10).toFixed(1) : 'N/A',
    episodes:  item.episodes,
    genres:    (item.genres || []).map(g => ({ name: g })),
    status:    item.status,
    trailer:   item.trailer,
    streaming: item.streamingEpisodes || []
  };
}

// ── Consumet (HLS streaming) ──────────────────────────────────────────────────

/**
 * Search consumet for an anime — tries hianime then animepahe.
 * Returns { id, provider } or null.
 */
export async function searchConsumet(title) {
  const clean = title.toLowerCase().split(':')[0].trim();

  // Try hianime
  try {
    const res  = await fetch(`${CONSUMET}/anime/hianime/${encodeURIComponent(title)}`);
    const data = await res.json();
    const results = data.animes || data.results || [];
    const match = results.find(r =>
      r.name?.toLowerCase().includes(clean) ||
      r.title?.toLowerCase().includes(clean)
    ) || results[0];
    if (match?.id) return { id: match.id, provider: 'hianime' };
  } catch {}

  // Try animepahe fallback
  try {
    const res  = await fetch(`${CONSUMET}/anime/animepahe/${encodeURIComponent(title)}`);
    const data = await res.json();
    const results = data.results || [];
    const match = results.find(r => r.title?.toLowerCase().includes(clean)) || results[0];
    if (match?.id) return { id: match.id, provider: 'animepahe' };
  } catch {}

  return null;
}

/**
 * Get episode list from consumet.
 * Returns array of { id, number, title }
 */
export async function getConsumetEpisodes(consumetInfo) {
  if (!consumetInfo) return [];
  const { id, provider } = consumetInfo;
  try {
    const res  = await fetch(`${CONSUMET}/anime/${provider}/info?id=${encodeURIComponent(id)}`);
    const data = await res.json();
    // hianime wraps episodes differently
    return data.seasons?.[0]?.episodes || data.episodes || [];
  } catch { return []; }
}

/**
 * Get HLS stream sources for an episode.
 * Returns { sources: [{url, quality, isM3U8}], subtitles: [{url, lang}] }
 */
export async function getConsumetStream(episodeId, provider = 'hianime') {
  try {
    const url  = `${CONSUMET}/anime/${provider}/watch?episodeId=${encodeURIComponent(episodeId)}`;
    const res  = await fetch(url);
    if (res.ok) {
      const data = await res.json();
      if (data.sources?.length) return data;
    }
  } catch {}

  // animepahe fallback
  try {
    const url  = `${CONSUMET}/anime/animepahe/watch?episodeId=${encodeURIComponent(episodeId)}`;
    const res  = await fetch(url);
    if (res.ok) {
      const data = await res.json();
      if (data.sources?.length) return data;
    }
  } catch {}

  return null;
}
