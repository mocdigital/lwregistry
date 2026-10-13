/*
 * The live-chat console.
 *
 * Plain DOM and short polls, no framework and no socket: the admin panel is
 * Blade, and this deployment has no websocket server, so "live" is a fetch on a
 * timer. Three loops — a heartbeat so visitors know someone is here, a list
 * poll that rings when a new person is waiting, and a transcript poll for the
 * conversation currently open.
 */
const root = document.querySelector('.assistant-live')
if (root) boot(root)

function boot(root) {
  /*
   * The CSRF token from the cookie, not the <meta> tag. The tag is stamped once
   * at page load; a console left open past the session lifetime — a laptop that
   * slept overnight — then posts a token that no longer matches and every ping
   * comes back 419 forever, which also drops the administrator's presence. The
   * cookie is reissued by any GET, so it can be refreshed without a reload.
   */
  const csrfHeader = () => {
    const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    if (cookie) return { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) }
    const meta = document.querySelector('meta[name="csrf-token"]')?.content
    return meta ? { 'X-CSRF-TOKEN': meta } : {}
  }

  const url = {
    ping: root.dataset.ping,
    sessions: root.dataset.sessions,
    thread: (id) => `${root.dataset.threadBase}/${encodeURIComponent(id)}`,
    takeover: (id) => `${root.dataset.takeoverBase}/${encodeURIComponent(id)}`,
    send: (id) => `${root.dataset.sendBase}/${encodeURIComponent(id)}`,
    end: (id) => `${root.dataset.endBase}/${encodeURIComponent(id)}`,
  }

  // On a 419 the token is stale; a GET reissues the cookie, then the POST is
  // retried once with it. This is what stops a slept-through session turning
  // into a permanent 419 loop.
  async function post(u, body) {
    for (let attempt = 0; attempt < 2; attempt++) {
      const response = await fetch(u, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify(body ?? {}),
      })
      if (response.status !== 419) return response
      await fetch('/assistant/token', { headers: { Accept: 'application/json' } }).catch(() => {})
    }
    return fetch(u, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
      body: JSON.stringify(body ?? {}),
    })
  }
  const getJson = (u) => fetch(u, { headers: { Accept: 'application/json' } }).then((r) => r.json())

  const el = {
    waiting: root.querySelector('#live-waiting'),
    list: root.querySelector('#live-list'),
    filters: [...root.querySelectorAll('[data-filter]')],
    sound: root.querySelector('#live-sound'),
    empty: root.querySelector('#live-thread-empty'),
    head: root.querySelector('#live-thread-head'),
    name: root.querySelector('#live-thread-name'),
    email: root.querySelector('#live-thread-email'),
    thread: root.querySelector('#live-thread'),
    composer: root.querySelector('#live-composer'),
    input: root.querySelector('#live-input'),
    takeover: root.querySelector('#live-takeover'),
    end: root.querySelector('#live-end'),
  }

  let openId = null
  let filter = 'all'
  const seenWaiting = new Set()
  let lastData = { all: [], waiting: [], live: [], missed: [] }

  /* ---- the ring, generated rather than loaded so there is no asset ---- */
  let audio = null
  let ringInterval = null
  let ringStop = null

  // One "ring-ring" — the double chirp a phone makes, played once.
  function ringBurst() {
    if (!audio) return
    ;[0, 0.4].forEach((burst) => {
      ;[0, 0.18].forEach((offset, i) => {
        const osc = audio.createOscillator()
        const gain = audio.createGain()
        osc.connect(gain)
        gain.connect(audio.destination)
        osc.type = 'sine'
        osc.frequency.value = i === 0 ? 660 : 880
        const t = audio.currentTime + burst + offset
        gain.gain.setValueAtTime(0.0001, t)
        gain.gain.exponentialRampToValueAtTime(0.25, t + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.16)
        osc.start(t)
        osc.stop(t + 0.17)
      })
    })
  }

  /*
   * Ring for about ten seconds, like a phone left to ring: a burst every two
   * seconds until answered. It stops itself after ten, and stops early the
   * moment the administrator opens the waiting conversation — a phone that
   * keeps ringing after you have picked it up is the wrong kind of insistent.
   */
  function ring() {
    if (!el.sound.checked || ringInterval) return
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)()
      ringBurst()
      ringInterval = setInterval(ringBurst, 2000)
      ringStop = setTimeout(stopRing, 10000)
    } catch {
      /* Audio may be blocked until the admin interacts with the page. */
    }
  }

  function stopRing() {
    if (ringInterval) clearInterval(ringInterval)
    if (ringStop) clearTimeout(ringStop)
    ringInterval = null
    ringStop = null
  }

  const esc = (s) =>
    String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))

  /* ---- the conversation list ---- */
  function renderList() {
    const waiting = lastData.waiting ?? []
    el.waiting.innerHTML = waiting.length
      ? `<div class="alert alert-warning py-2 px-3 mb-2" style="font-size:.85rem;">
           <strong>${waiting.length}</strong> waiting for a person</div>`
      : ''

    const rows = { all: lastData.all, live: lastData.live, missed: lastData.missed }[filter] ?? []
    if (!rows.length) {
      el.list.innerHTML = `<p class="text-muted small p-3 mb-0">Nothing here yet.</p>`
      return
    }

    el.list.innerHTML = rows
      .map((r) => {
        const badge =
          r.state === 'requested'
            ? '<span class="badge bg-warning text-dark">waiting</span>'
            : r.state === 'live'
              ? '<span class="badge bg-success">live</span>'
              : r.state === 'missed'
                ? '<span class="badge bg-danger">missed</span>'
                : r.state === 'ended'
                  ? '<span class="badge bg-secondary">ended</span>'
                  : '<span class="badge bg-light text-dark">bot</span>'
        return `<button type="button" class="list-group-item list-group-item-action ${r.session_id === openId ? 'active' : ''}"
                  data-open="${esc(r.session_id)}">
            <div class="d-flex justify-content-between align-items-center">
              <span class="text-truncate" style="max-width: 65%;">${esc(r.name)}</span>
              ${badge}
            </div>
            ${r.preview ? `<small class="d-block text-truncate ${r.session_id === openId ? '' : 'text-muted'}">${esc(r.preview)}</small>` : ''}
          </button>`
      })
      .join('')
  }

  async function pollSessions() {
    try {
      const data = await getJson(url.sessions)
      lastData = data
      // Ring for any waiting conversation this console has not seen before.
      for (const w of data.waiting ?? []) {
        if (!seenWaiting.has(w.session_id)) {
          seenWaiting.add(w.session_id)
          ring()
        }
      }
      // Forget the ones no longer waiting, so a later request rings again.
      const stillWaiting = new Set((data.waiting ?? []).map((w) => w.session_id))
      for (const id of [...seenWaiting]) if (!stillWaiting.has(id)) seenWaiting.delete(id)
      // Nobody waiting anymore — someone else took it, or it was answered.
      if (!stillWaiting.size) stopRing()
      renderList()
    } catch {
      /* transient; the next tick tries again */
    }
  }

  /* ---- one open transcript ---- */
  function renderThread(data) {
    el.name.textContent = data.name ?? 'Visitor'
    el.email.textContent = data.email ?? ''
    const live = data.state === 'live'
    el.takeover.classList.toggle('d-none', live)
    el.end.classList.toggle('d-none', !live)
    el.composer.classList.toggle('d-none', !live)

    el.thread.innerHTML = (data.messages ?? [])
      .map((m) => {
        const mine = m.from === 'admin'
        const bot = m.from === 'bot'
        const align = mine ? 'text-end' : ''
        const bg = mine ? 'bg-primary text-white' : bot ? 'bg-light text-muted' : 'bg-light'
        const who = bot ? 'Assistant' : mine ? 'You' : data.name ?? 'Visitor'
        return `<div class="mb-2 ${align}">
            <div class="small text-muted mb-1">${esc(who)}</div>
            <span class="d-inline-block rounded px-3 py-2 ${bg}" style="max-width: 80%; white-space: pre-wrap; text-align:left;">${esc(m.text)}</span>
          </div>`
      })
      .join('')
    el.thread.scrollTop = el.thread.scrollHeight
  }

  async function pollThread() {
    if (!openId) return
    try {
      const data = await getJson(url.thread(openId))
      renderThread(data)
    } catch {
      /* transient */
    }
  }

  function openThread(id) {
    openId = id
    stopRing() // Attending to it — no need to keep ringing.
    el.empty.classList.add('d-none')
    el.head.classList.remove('d-none')
    el.head.classList.add('d-flex')
    el.thread.classList.remove('d-none')
    renderList()
    pollThread()
  }

  /* ---- events ---- */
  el.list.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open]')
    if (btn) openThread(btn.dataset.open)
  })

  el.filters.forEach((b) =>
    b.addEventListener('click', () => {
      filter = b.dataset.filter
      el.filters.forEach((x) => x.classList.toggle('active', x === b))
      renderList()
    }),
  )

  el.takeover.addEventListener('click', async () => {
    if (!openId) return
    await post(url.takeover(openId))
    await pollThread()
    await pollSessions()
  })

  el.end.addEventListener('click', async () => {
    if (!openId) return
    await post(url.end(openId))
    await pollThread()
    await pollSessions()
  })

  el.composer.addEventListener('submit', async (e) => {
    e.preventDefault()
    const body = el.input.value.trim()
    if (!body || !openId) return
    el.input.value = ''
    await post(url.send(openId), { body })
    await pollThread()
  })

  /* ---- the three loops ---- */
  const heartbeat = () => post(url.ping).catch(() => {})
  heartbeat()
  setInterval(heartbeat, 20000)

  pollSessions()
  setInterval(pollSessions, 4000)
  setInterval(pollThread, 3000)
}
