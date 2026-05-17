import { getAniListTrending, normalizeAniList, getSeasonNow, getTopAnime } from './api.js';

// --- Card renderer ---
export function renderCard(anime, size = 'normal') {
  const title = anime.title || anime.title_english || anime.title_romaji || 'Unknown';
  const img = anime.images?.jpg?.large_image_url || anime.coverImage?.large || '';
  const score = anime.score || (anime.averageScore ? (anime.averageScore/10).toFixed(1) : '');
  const eps = anime.episodes ? `${anime.episodes} eps` : '';
  const id = anime.mal_id || anime.anilist_id || '';
  const idType = anime.mal_id ? 'mal' : 'al';

  return `
    <div class="anime-card" onclick="window.location='anime.html?id=${id}&src=${idType}'" role="button" tabindex="0">
      <img src="${img}" alt="${title}" loading="lazy" onerror="this.src='https://via.placeholder.com/200x300/111/444?text=No+Image'">
      ${score ? `<div class="anime-card-score">★ ${score}</div>` : ''}
      <div class="anime-card-overlay">
        <div class="play-icon">▶</div>
      </div>
      <div class="anime-card-info">
        <div class="anime-card-title" title="${title}">${title}</div>
        <div class="anime-card-meta">${eps}</div>
      </div>
    </div>`;
}

export function renderSkeletons(count, container) {
  container.innerHTML = Array(count).fill(`
    <div class="anime-card">
      <div class="skeleton" style="aspect-ratio:2/3;width:100%"></div>
      <div class="anime-card-info">
        <div class="skeleton" style="height:14px;margin-bottom:6px;border-radius:3px"></div>
        <div class="skeleton" style="height:11px;width:60%;border-radius:3px"></div>
      </div>
    </div>`).join('');
}

export function showToast(msg) {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

// --- Homepage init ---
async function initHome() {
  const heroSection = document.getElementById('hero');
  const trendingGrid = document.getElementById('trending-grid');
  const seasonGrid = document.getElementById('season-grid');
  const topGrid = document.getElementById('top-grid');

  if (!trendingGrid) return;

  // Skeletons
  renderSkeletons(12, trendingGrid);
  if (seasonGrid) renderSkeletons(12, seasonGrid);
  if (topGrid) renderSkeletons(12, topGrid);

  try {
    // Trending from AniList
    const trending = await getAniListTrending();
    const normalized = trending.map(normalizeAniList);

    // Hero
    if (heroSection && normalized.length) {
      const featured = normalized.find(a => a.banner) || normalized[0];
      const heroBg = heroSection.querySelector('.hero-bg');
      if (heroBg) heroBg.style.backgroundImage = `url('${featured.banner || featured.images?.jpg?.large_image_url}')`;
      const heroTitle = heroSection.querySelector('.hero-title');
      if (heroTitle) heroTitle.textContent = featured.title;
      const heroDesc = heroSection.querySelector('.hero-desc');
      if (heroDesc) heroDesc.textContent = (featured.genres || []).slice(0,3).map(g=>g.name).join(' · ');
      const heroBtn = heroSection.querySelector('.hero-watch-btn');
      if (heroBtn) heroBtn.href = `anime.html?id=${featured.mal_id || featured.anilist_id}&src=${featured.mal_id ? 'mal' : 'al'}`;
    }

    trendingGrid.innerHTML = normalized.map(a => renderCard(a)).join('');
  } catch (e) {
    trendingGrid.innerHTML = `<p style="color:var(--muted);grid-column:1/-1">Failed to load trending. ${e.message}</p>`;
  }

  // Season now — use AniList (no rate limit)
  if (seasonGrid) {
    try {
      const query = `query {
        Page(page: 1, perPage: 12) {
          media(type: ANIME, season: SPRING, seasonYear: 2025, sort: POPULARITY_DESC, isAdult: false) {
            id idMal title { romaji english }
            coverImage { large extraLarge }
            episodes averageScore status
          }
        }
      }`;
      const r = await fetch('https://graphql.anilist.co', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query })
      });
      const d = await r.json();
      const items = d.data?.Page?.media || [];
      if (items.length) {
        seasonGrid.innerHTML = items.map(a => renderCard(normalizeAniList(a))).join('');
      } else {
        seasonGrid.innerHTML = '';
      }
    } catch { seasonGrid.innerHTML = ''; }
  }

  // Top rated — use AniList (no rate limit)
  if (topGrid) {
    try {
      const query = `query {
        Page(page: 1, perPage: 12) {
          media(type: ANIME, sort: SCORE_DESC, isAdult: false) {
            id idMal title { romaji english }
            coverImage { large extraLarge }
            episodes averageScore status
          }
        }
      }`;
      const r = await fetch('https://graphql.anilist.co', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query })
      });
      const d = await r.json();
      const items = d.data?.Page?.media || [];
      if (items.length) {
        topGrid.innerHTML = items.map(a => renderCard(normalizeAniList(a))).join('');
      } else {
        topGrid.innerHTML = '';
      }
    } catch { topGrid.innerHTML = ''; }
  }
}

document.addEventListener('DOMContentLoaded', initHome);
