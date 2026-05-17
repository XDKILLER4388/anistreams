import { getGenres } from './api.js';
import { renderCard, renderSkeletons, showToast } from './main.js';

const JIKAN = 'https://api.jikan.moe/v4';

// ── State ─────────────────────────────────────────────────────────────────────
let state = {
  query:   '',
  page:    1,
  sort:    'score',
  filters: {},
};
let totalPages = 1;

// ── DOM refs (resolved after module executes — modules are deferred) ──────────
const grid      = document.getElementById('search-grid');
const input     = document.getElementById('search-input');
const searchBtn = document.getElementById('search-btn');
const sortSel   = document.getElementById('sort-select');
const resultsEl = document.getElementById('results-info');
const pagEl     = document.getElementById('pagination');
const chipsEl   = document.getElementById('active-filters');

// ── Build A–Z grid ────────────────────────────────────────────────────────────
function buildAlpha() {
  const container = document.getElementById('alpha-grid');
  if (!container) return;
  const chars = ['#', ...Array.from({ length: 26 }, (_, i) => String.fromCharCode(65 + i))];
  container.innerHTML = chars.map(c =>
    `<button class="alpha-btn" data-type="letter" data-val="${c}">${c}</button>`
  ).join('');
  container.querySelectorAll('.alpha-btn').forEach(btn => {
    btn.addEventListener('click', () => toggleFilter('letter', btn.dataset.val, btn));
  });
}

// ── Build year filter ─────────────────────────────────────────────────────────
function buildYears() {
  const container = document.getElementById('year-filters');
  if (!container) return;
  const cur = new Date().getFullYear();
  let html = '';
  for (let y = cur; y >= cur - 10; y--) {
    html += `<button class="ftag" data-type="year" data-val="${y}">${y}</button>`;
  }
  container.innerHTML = html;
  container.querySelectorAll('.ftag').forEach(btn => {
    btn.addEventListener('click', () => toggleFilter('year', btn.dataset.val, btn));
  });
}

// ── Load genres ───────────────────────────────────────────────────────────────
async function loadGenres() {
  const container = document.getElementById('genre-filters');
  if (!container) return;
  try {
    // Use AniList genres to avoid Jikan rate limit
    const alGenres = ['Action','Adventure','Comedy','Drama','Fantasy','Horror','Mystery','Romance','Sci-Fi','Slice of Life','Sports','Supernatural','Thriller','Mecha','Music','Psychological','Ecchi','Harem','Isekai','Shounen','Shoujo','Seinen','Josei'];
    // Try Jikan first, fall back to static list
    let genres = [];
    try {
      const res = await fetch(`${JIKAN}/genres/anime`);
      if (res.ok) { const d = await res.json(); genres = d.data || []; }
    } catch {}
    if (genres.length) {
      container.innerHTML = genres.slice(0, 30).map(g =>
        `<button class="ftag" data-type="genre" data-val="${g.mal_id}" data-label="${g.name}">${g.name}</button>`
      ).join('');
    } else {
      // Static fallback
      container.innerHTML = alGenres.map((g, i) =>
        `<button class="ftag" data-type="genre" data-val="${i+1}" data-label="${g}">${g}</button>`
      ).join('');
    }
    container.querySelectorAll('.ftag').forEach(btn => {
      btn.addEventListener('click', () => toggleFilter('genre', btn.dataset.val, btn, btn.dataset.label));
    });
  } catch {
    container.innerHTML = '<span style="font-size:.75rem;color:var(--muted)">Failed to load genres</span>';
  }
}

// ── Filter toggle ─────────────────────────────────────────────────────────────
function toggleFilter(type, val, btn, label) {
  const isSame = state.filters[type] === val;

  // Deactivate all buttons of this type
  document.querySelectorAll(`[data-type="${type}"]`).forEach(b => b.classList.remove('active'));

  if (isSame) {
    delete state.filters[type];
    delete state.filters[`${type}_label`];
  } else {
    state.filters[type] = val;
    if (label) state.filters[`${type}_label`] = label;
    else delete state.filters[`${type}_label`];
    btn.classList.add('active');
  }

  state.page = 1;
  renderChips();
  doSearch();
}

// ── Active filter chips ───────────────────────────────────────────────────────
const FILTER_LABELS = {
  type:      { tv:'TV', movie:'Movie', ova:'OVA', ona:'ONA', special:'Special', music:'Music' },
  status:    { airing:'Airing', complete:'Completed', upcoming:'Upcoming' },
  rating:    { g:'G', pg:'PG', pg13:'PG-13', r17:'R-17+' },
  min_score: v => `Score ${v}+`,
  year:      v => String(v),
  letter:    v => v === '#' ? '#0–9' : `Starts with ${v}`,
};

function filterDisplayName(type, val) {
  if (type === 'genre') return state.filters.genre_label || `Genre ${val}`;
  const def = FILTER_LABELS[type];
  if (typeof def === 'function') return def(val);
  return def?.[val] || val;
}

function renderChips() {
  if (!chipsEl) return;
  const entries = Object.entries(state.filters).filter(([k]) => !k.endsWith('_label'));
  if (!entries.length) { chipsEl.innerHTML = ''; return; }
  chipsEl.innerHTML = entries.map(([type, val]) => `
    <span class="active-chip">
      ${filterDisplayName(type, val)}
      <button onclick="removeFilter('${type}')" title="Remove">✕</button>
    </span>`).join('');
}

window.removeFilter = (type) => {
  delete state.filters[type];
  delete state.filters[`${type}_label`];
  document.querySelectorAll(`[data-type="${type}"]`).forEach(b => b.classList.remove('active'));
  state.page = 1;
  renderChips();
  doSearch();
};

// ── Build Jikan URL ───────────────────────────────────────────────────────────
function buildUrl() {
  const f = state.filters;
  const p = new URLSearchParams();

  p.set('limit', 24);
  p.set('order_by', state.sort);
  p.set('sort', state.sort === 'title' ? 'asc' : 'desc');
  p.set('sfw', 'true');
  p.set('page', state.page);

  if (state.query)  p.set('q', state.query);
  if (f.type)       p.set('type', f.type);
  if (f.status)     p.set('status', f.status);
  if (f.genre)      p.set('genres', f.genre);
  if (f.rating)     p.set('rating', f.rating);
  if (f.min_score)  p.set('min_score', f.min_score);
  if (f.year) {
    p.set('start_date', `${f.year}-01-01`);
    p.set('end_date',   `${f.year}-12-31`);
  }
  if (f.letter && !state.query) {
    p.set('letter', f.letter === '#' ? '0' : f.letter);
  }

  return `${JIKAN}/anime?${p.toString()}`;
}

// ── Main search ───────────────────────────────────────────────────────────────
async function doSearch() {
  if (!grid) return;
  renderSkeletons(24, grid);
  if (resultsEl) resultsEl.textContent = 'Loading…';

  // No query + no filters = use AniList (avoids Jikan rate limit on initial load)
  const hasFilters = Object.keys(state.filters).length > 0;
  if (!state.query && !hasFilters) {
    try {
      const sortMap = { score: 'SCORE_DESC', popularity: 'POPULARITY_DESC', title: 'TITLE_ROMAJI_ASC', start_date: 'START_DATE_DESC' };
      const alSort = sortMap[state.sort] || 'SCORE_DESC';
      const query = `query($page:Int){Page(page:$page,perPage:24){pageInfo{total lastPage}media(type:ANIME,sort:${alSort},isAdult:false){id idMal title{romaji english}coverImage{large extraLarge}episodes averageScore status}}}`;
      const r = await fetch('https://graphql.anilist.co', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query, variables: { page: state.page } })
      });
      const d = await r.json();
      const items = d.data?.Page?.media || [];
      if (items.length) {
        totalPages = Math.min(d.data?.Page?.pageInfo?.lastPage || 1, 20);
        grid.innerHTML = items.map(a => renderCard({
          mal_id: a.idMal, title: a.title?.english || a.title?.romaji,
          images: { jpg: { large_image_url: a.coverImage?.extraLarge || a.coverImage?.large } },
          score: a.averageScore ? (a.averageScore/10).toFixed(1) : null,
          episodes: a.episodes, status: a.status,
        })).join('');
        if (resultsEl) resultsEl.textContent = `${(d.data?.Page?.pageInfo?.total || items.length).toLocaleString()} results`;
        renderPagination();
        return;
      }
    } catch {}
  }

  // Jikan search with retry on 429
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const res = await fetch(buildUrl());
      if (res.status === 429) {
        await new Promise(r => setTimeout(r, 1500 + attempt * 1000));
        continue;
      }
      const data = await res.json();

      if (!data.data?.length) {
        grid.innerHTML = `<div class="browse-empty">
          <div class="empty-icon">🔍</div>
          <p>No results found${state.query ? ` for "<strong>${state.query}</strong>"` : ''}</p>
          <p style="font-size:.8rem;margin-top:.5rem">Try different filters or a broader search</p>
        </div>`;
        if (resultsEl) resultsEl.textContent = '0 results';
        if (pagEl) pagEl.innerHTML = '';
        return;
      }

      totalPages = Math.min(data.pagination?.last_visible_page || 1, 20);
      state.page = data.pagination?.current_page || state.page;
      grid.innerHTML = data.data.map(a => renderCard(a)).join('');
      const total = data.pagination?.items?.total;
      if (resultsEl) resultsEl.textContent = total ? `${total.toLocaleString()} results` : `${data.data.length} results`;
      renderPagination();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    } catch(e) {
      if (attempt === 2) {
        grid.innerHTML = `<div class="browse-empty"><div class="empty-icon">⚠️</div><p>Search failed: ${e.message}</p></div>`;
        showToast('Search failed. Try again.');
      }
      await new Promise(r => setTimeout(r, 800));
    }
  }
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination() {
  if (!pagEl) return;
  if (totalPages <= 1) { pagEl.innerHTML = ''; return; }

  const cur   = state.page;
  const start = Math.max(1, cur - 2);
  const end   = Math.min(totalPages, cur + 2);
  let html    = '';

  if (cur > 1) {
    html += `<button class="btn btn-ghost" onclick="goPage(1)" style="font-size:.65rem">«</button>`;
    html += `<button class="btn btn-ghost" onclick="goPage(${cur-1})" style="font-size:.65rem">‹ Prev</button>`;
  }
  for (let i = start; i <= end; i++) {
    html += `<button class="btn ${i===cur?'btn-primary':'btn-ghost'}" onclick="goPage(${i})" style="font-size:.65rem">${i}</button>`;
  }
  if (cur < totalPages) {
    html += `<button class="btn btn-ghost" onclick="goPage(${cur+1})" style="font-size:.65rem">Next ›</button>`;
    html += `<button class="btn btn-ghost" onclick="goPage(${totalPages})" style="font-size:.65rem">»</button>`;
  }
  pagEl.innerHTML = html;
}

window.goPage = (page) => { state.page = page; doSearch(); };

// ── Suggestions ───────────────────────────────────────────────────────────────
let suggestTimer;

function showSuggestions(items) {
  closeSuggestions();
  if (!items.length) return;
  const box = document.createElement('div');
  box.id = 'suggestions';
  box.innerHTML = items.map(a => `
    <div class="suggestion-item" onclick="selectSuggestion(${a.mal_id})">
      <img src="${a.images?.jpg?.small_image_url || ''}" alt="">
      <div>
        <div style="font-size:.85rem">${a.title_english || a.title}</div>
        <div style="font-size:.7rem;color:var(--muted)">${a.type || ''} · ${a.year || ''}</div>
      </div>
    </div>`).join('');
  document.getElementById('search-wrap')?.appendChild(box);
}

function closeSuggestions() {
  document.getElementById('suggestions')?.remove();
}

window.selectSuggestion = (id) => {
  closeSuggestions();
  window.location.href = `anime.html?id=${id}&src=mal`;
};

// ── Search submit ─────────────────────────────────────────────────────────────
function submitSearch() {
  closeSuggestions();
  state.query = input?.value.trim() || '';
  state.page  = 1;
  if (state.query && state.filters.letter) {
    delete state.filters.letter;
    document.querySelectorAll('[data-type="letter"]').forEach(b => b.classList.remove('active'));
    renderChips();
  }
  doSearch();
}

// ── Init — runs immediately since modules are deferred ────────────────────────
buildAlpha();
buildYears();
loadGenres();

// Wire up static buttons (type, status, rating, min_score)
document.querySelectorAll('.ftag[data-type]').forEach(btn => {
  btn.addEventListener('click', () => toggleFilter(btn.dataset.type, btn.dataset.val, btn));
});

// Search input events
input?.addEventListener('input', () => {
  clearTimeout(suggestTimer);
  const q = input.value.trim();
  if (q.length < 2) { closeSuggestions(); return; }
  suggestTimer = setTimeout(async () => {
    try {
      const res  = await fetch(`${JIKAN}/anime?q=${encodeURIComponent(q)}&limit=6&sfw=true`);
      const data = await res.json();
      showSuggestions(data.data || []);
    } catch { closeSuggestions(); }
  }, 350);
});

input?.addEventListener('keydown', e => { if (e.key === 'Enter') submitSearch(); });
searchBtn?.addEventListener('click', submitSearch);

document.addEventListener('click', e => {
  if (!e.target.closest('#search-wrap')) closeSuggestions();
});

// Sort
sortSel?.addEventListener('change', () => {
  state.sort = sortSel.value;
  state.page = 1;
  doSearch();
});

// Clear all
document.getElementById('clear-all-btn')?.addEventListener('click', () => {
  state = { query: '', page: 1, sort: state.sort, filters: {} };
  if (input) input.value = '';
  document.querySelectorAll('.ftag.active, .alpha-btn.active').forEach(b => b.classList.remove('active'));
  renderChips();
  doSearch();
});

// Handle ?q= from nav search bar
const urlQ = new URLSearchParams(window.location.search).get('q');
if (urlQ) {
  if (input) input.value = urlQ;
  state.query = urlQ;
}

// Initial load
doSearch();
