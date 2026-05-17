const express = require('express');
const cors    = require('cors');
const { ANIME } = require('@consumet/extensions');

const app  = express();
const PORT = 3000;

app.use(cors());
app.use(express.json());

const hianime  = new ANIME.Hianime();
const animepahe = new ANIME.AnimePahe();

app.get('/', (req, res) => res.json({ status: 'AniStream Consumet running ✅', providers: ['hianime', 'animepahe'] }));

// ── Hianime (main provider) ───────────────────────────────────────────────────

// Search
app.get('/anime/hianime/:query', async (req, res) => {
  try { res.json(await hianime.search(req.params.query)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

// Info + episode list
app.get('/anime/hianime/info', async (req, res) => {
  try { res.json(await hianime.fetchAnimeInfo(req.query.id)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

// Stream sources for an episode
app.get('/anime/hianime/watch', async (req, res) => {
  try { res.json(await hianime.fetchEpisodeSources(req.query.episodeId)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

// ── AnimePahe (fallback) ──────────────────────────────────────────────────────

app.get('/anime/animepahe/:query', async (req, res) => {
  try { res.json(await animepahe.search(req.params.query)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

app.get('/anime/animepahe/info', async (req, res) => {
  try { res.json(await animepahe.fetchAnimeInfo(req.query.id)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

app.get('/anime/animepahe/watch', async (req, res) => {
  try { res.json(await animepahe.fetchEpisodeSources(req.query.episodeId)); }
  catch (e) { res.status(500).json({ error: e.message }); }
});

app.listen(PORT, () => {
  console.log(`\n✅ AniStream Consumet API → http://localhost:${PORT}\n`);
});
