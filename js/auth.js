// Auth state — shared across all pages
// Use absolute path so it works from any page depth
const _base = location.pathname.replace(/\/[^/]*$/, '').replace(/\/js$/, '');
const AUTH_API = _base + '/backend/api/auth.php';

// All auth requests must include credentials so PHP session cookies are sent
function authFetch(url, opts = {}) {
  return fetch(url, { credentials: 'include', ...opts });
}

export async function getUser() {
  try {
    const r = await authFetch(AUTH_API + '?action=me');
    const d = await r.json();
    return d.user || null;
  } catch { return null; }
}

export async function login(email, password) {
  const r = await authFetch(AUTH_API + '?action=login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  return r.json();
}

export async function register(username, email, password) {
  const r = await authFetch(AUTH_API + '?action=register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, email, password })
  });
  return r.json();
}

export async function logout() {
  await authFetch(AUTH_API + '?action=logout');
  location.reload();
}

// Render nav user area
export function renderNavUser(user) {
  const area = document.getElementById('nav-user');
  if (!area) return;
  if (user) {
    area.innerHTML = `
      <a href="downloads.html" class="btn btn-ghost" style="font-size:.72rem;padding:.35rem .8rem">⬇ Downloads</a>
      ${user.role === 'admin' ? `<a href="admin.html" class="btn btn-ghost" style="font-size:.72rem;padding:.35rem .8rem;color:#fbbf24;border-color:#fbbf24">⚙ Admin</a>` : ''}
      <span style="font-size:.8rem;color:var(--muted);margin:0 .3rem">${user.username}</span>
      <button onclick="import('./js/auth.js').then(m=>m.logout())" class="btn btn-ghost" style="font-size:.72rem;padding:.35rem .8rem">Logout</button>`;
  } else {
    area.innerHTML = `
      <button onclick="document.getElementById('auth-modal').style.display='flex'" class="btn btn-ghost" style="font-size:.72rem;padding:.35rem .8rem">Login</button>
      <button onclick="document.getElementById('auth-modal').style.display='flex';switchTab('register')" class="btn btn-primary" style="font-size:.72rem;padding:.35rem .8rem">Register</button>`;
  }
}

// Auth modal HTML — inject into any page
export function injectAuthModal() {
  if (document.getElementById('auth-modal')) return;
  const modal = document.createElement('div');
  modal.id = 'auth-modal';
  modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1000;align-items:center;justify-content:center';
  modal.innerHTML = `
    <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:2rem;width:100%;max-width:380px;position:relative">
      <button onclick="document.getElementById('auth-modal').style.display='none'"
        style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer">✕</button>

      <div style="display:flex;gap:1rem;margin-bottom:1.5rem">
        <button id="tab-login" onclick="switchTab('login')"
          style="flex:1;padding:.6rem;background:var(--text);color:var(--bg);border:none;border-radius:6px;font-family:'Orbitron',sans-serif;font-size:.7rem;letter-spacing:1px;cursor:pointer">LOGIN</button>
        <button id="tab-register" onclick="switchTab('register')"
          style="flex:1;padding:.6rem;background:none;color:var(--muted);border:1px solid var(--border);border-radius:6px;font-family:'Orbitron',sans-serif;font-size:.7rem;letter-spacing:1px;cursor:pointer">REGISTER</button>
      </div>

      <div id="auth-error" style="display:none;color:#f87171;font-size:.8rem;margin-bottom:1rem;padding:.6rem;background:rgba(248,113,113,.1);border-radius:6px"></div>

      <!-- Login form -->
      <form id="form-login" onsubmit="handleLogin(event)">
        <input type="email" id="login-email" placeholder="Email" required
          style="width:100%;padding:.8rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'Rajdhani',sans-serif;font-size:.95rem;margin-bottom:.8rem;box-sizing:border-box">
        <input type="password" id="login-pass" placeholder="Password" required
          style="width:100%;padding:.8rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'Rajdhani',sans-serif;font-size:.95rem;margin-bottom:1rem;box-sizing:border-box">
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;font-size:.8rem">LOGIN</button>
      </form>

      <!-- Register form -->
      <form id="form-register" style="display:none" onsubmit="handleRegister(event)">
        <input type="text" id="reg-username" placeholder="Username" required
          style="width:100%;padding:.8rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'Rajdhani',sans-serif;font-size:.95rem;margin-bottom:.8rem;box-sizing:border-box">
        <input type="email" id="reg-email" placeholder="Email" required
          style="width:100%;padding:.8rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'Rajdhani',sans-serif;font-size:.95rem;margin-bottom:.8rem;box-sizing:border-box">
        <input type="password" id="reg-pass" placeholder="Password (min 6 chars)" required
          style="width:100%;padding:.8rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'Rajdhani',sans-serif;font-size:.95rem;margin-bottom:1rem;box-sizing:border-box">
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;font-size:.8rem">CREATE ACCOUNT</button>
      </form>
    </div>`;
  document.body.appendChild(modal);

  // Close on backdrop click
  modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
}

// Global helpers used by inline onclick
window.switchTab = (tab) => {
  document.getElementById('form-login').style.display    = tab === 'login'    ? 'block' : 'none';
  document.getElementById('form-register').style.display = tab === 'register' ? 'block' : 'none';
  document.getElementById('tab-login').style.background    = tab === 'login'    ? 'var(--text)' : 'none';
  document.getElementById('tab-login').style.color         = tab === 'login'    ? 'var(--bg)'   : 'var(--muted)';
  document.getElementById('tab-register').style.background = tab === 'register' ? 'var(--text)' : 'none';
  document.getElementById('tab-register').style.color      = tab === 'register' ? 'var(--bg)'   : 'var(--muted)';
  document.getElementById('auth-error').style.display = 'none';
};

window.handleLogin = async (e) => {
  e.preventDefault();
  const errEl = document.getElementById('auth-error');
  errEl.style.display = 'none';
  const res = await login(
    document.getElementById('login-email').value,
    document.getElementById('login-pass').value
  );
  if (res.error) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }
  location.reload();
};

window.handleRegister = async (e) => {
  e.preventDefault();
  const errEl = document.getElementById('auth-error');
  errEl.style.display = 'none';
  const res = await register(
    document.getElementById('reg-username').value,
    document.getElementById('reg-email').value,
    document.getElementById('reg-pass').value
  );
  if (res.error) { errEl.textContent = res.error; errEl.style.display = 'block'; return; }
  location.reload();
};
