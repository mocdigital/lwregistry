/*
 * The blueprint canvas: nodes, pins and wires.
 *
 * Written against the DOM rather than in Vue because the admin is Blade and
 * Livewire, and because the whole point of this surface is that it owns its own
 * state — Livewire re-rendering the subtree would wipe every node mid-drag,
 * which is why the container carries wire:ignore.
 *
 * No node-editor library. What is needed here is: draw a box, drag it, draw a
 * line between two dots, and hand the result back as JSON. That is a few
 * hundred lines, against a dependency whose own model would then have to be
 * translated into ours on every load and save anyway.
 *
 * The pins a node has come from the SERVER, not from a list repeated here, so
 * the canvas and the runtime cannot disagree about what can be connected.
 */

const canvas = document.getElementById('bp-canvas')

if (canvas) {
  const palette = document.getElementById('bp-palette')
  const inspector = document.getElementById('bp-inspector')
  const status = document.getElementById('bp-status')

  /** @type {{nodes: any[], wires: any[], nodeTypes: Record<string, any>}} */
  let graph = { nodes: [], wires: [], nodeTypes: {} }
  let selectedKey = null

  /* Wire being drawn: the pin the drag started from. */
  let pending = null

  const blueprintId = canvas.dataset.blueprint
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''

  const say = (text, tone = 'text-muted') => {
    if (status) {
      status.className = `small ${tone}`
      status.textContent = text
    }
  }

  /* ------------------------------------------------------------ loading --- */

  async function load() {
    if (!blueprintId) return

    const response = await fetch(`/admin/chatbot/blueprints/${blueprintId}`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      say('Could not load this blueprint.', 'text-danger')

      return
    }

    graph = await response.json()
    selectedKey = null
    drawPalette()
    draw()
    say('Loaded.')
  }

  /* ------------------------------------------------------------ drawing --- */

  function drawPalette() {
    if (!palette) return

    palette.innerHTML = ''

    for (const [type, meta] of Object.entries(graph.nodeTypes ?? {})) {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'btn btn-sm btn-outline-secondary'
      button.textContent = `+ ${meta.label}`
      button.title = meta.hint
      button.addEventListener('click', () => addNode(type))
      palette.appendChild(button)
    }
  }

  function draw() {
    canvas.innerHTML = ''
    canvas.style.position = 'relative'
    canvas.style.overflow = 'auto'

    /*
     * Wires are drawn on one SVG behind the nodes. Per-wire elements would
     * each need their own coordinate space, and a line that has to cross the
     * whole canvas does not belong to either box it connects.
     */
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
    svg.setAttribute('id', 'bp-wires')
    Object.assign(svg.style, {
      position: 'absolute',
      inset: '0',
      width: '4000px',
      height: '2000px',
      pointerEvents: 'none',
    })
    canvas.appendChild(svg)

    for (const node of graph.nodes) canvas.appendChild(nodeElement(node))

    drawWires()
  }

  function nodeElement(node) {
    const meta = graph.nodeTypes[node.type] ?? { label: node.type }

    const box = document.createElement('div')
    box.dataset.key = node.key
    box.className = 'card shadow-sm'
    Object.assign(box.style, {
      position: 'absolute',
      left: `${node.x}px`,
      top: `${node.y}px`,
      width: '240px',
      cursor: 'grab',
      borderColor: node.key === selectedKey ? '#0d6efd' : '',
    })

    const header = document.createElement('div')
    header.className = 'card-header py-1 px-2 d-flex justify-content-between align-items-center'
    header.innerHTML = `<span class="small fw-semibold">${escapeHtml(meta.label)}</span>`

    const remove = document.createElement('button')
    remove.type = 'button'
    remove.className = 'btn btn-sm btn-link text-danger p-0 lh-1'
    remove.innerHTML = '&times;'
    remove.title = 'Delete node'
    remove.addEventListener('click', (event) => {
      event.stopPropagation()
      deleteNode(node.key)
    })
    header.appendChild(remove)

    const body = document.createElement('div')
    body.className = 'card-body py-2 px-2'
    body.innerHTML = `
      <div class="small text-truncate">${escapeHtml(node.config?.text ?? '—')}</div>
      <div class="text-muted" style="font-size:.7rem">${escapeHtml(node.key)}</div>
    `

    box.append(header, body, pinRow(node))

    // The input pin. One per node: a step is entered one way.
    const inPin = document.createElement('span')
    inPin.className = 'bp-pin bp-pin-in'
    inPin.title = 'Input'
    Object.assign(inPin.style, pinStyle, { left: '-7px', top: '18px', background: '#64748b' })
    inPin.addEventListener('mouseup', () => completeWire(node.key))
    box.appendChild(inPin)

    box.addEventListener('mousedown', (event) => startDrag(event, node, box))
    box.addEventListener('click', () => selectNode(node.key))

    return box
  }

  const pinStyle = {
    position: 'absolute',
    width: '12px',
    height: '12px',
    borderRadius: '9999px',
    border: '2px solid #fff',
    cursor: 'crosshair',
  }

  /** One output pin per pin the SERVER says this node has. */
  function pinRow(node) {
    const row = document.createElement('div')
    row.className = 'card-footer py-1 px-2 d-flex flex-column gap-1'

    for (const pin of node.pins ?? []) {
      const line = document.createElement('div')
      line.className = 'd-flex justify-content-end align-items-center gap-1 position-relative'
      line.innerHTML = `<span class="text-muted" style="font-size:.7rem">${escapeHtml(pin)}</span>`

      const dot = document.createElement('span')
      dot.className = 'bp-pin'
      Object.assign(dot.style, pinStyle, { position: 'relative', background: '#0d6efd' })
      dot.title = `Drag from "${pin}" to another node's input`
      dot.addEventListener('mousedown', (event) => {
        event.stopPropagation()
        pending = { from: node.key, pin }
        say(`Wiring from ${node.key} · ${pin} — drop on a node's input pin.`)
      })

      line.appendChild(dot)
      row.appendChild(line)
    }

    return row
  }

  function drawWires() {
    const svg = document.getElementById('bp-wires')
    if (!svg) return

    svg.innerHTML = ''

    for (const wire of graph.wires) {
      const from = graph.nodes.find((n) => n.key === wire.from)
      const to = graph.nodes.find((n) => n.key === wire.to)
      if (!from || !to) continue

      // Pin order decides which row the wire leaves from, so a choice node's
      // four outputs do not all emerge from the same point.
      const index = Math.max(0, (from.pins ?? []).indexOf(wire.pin))
      const x1 = from.x + 240
      const y1 = from.y + 92 + index * 22
      const x2 = to.x
      const y2 = to.y + 18

      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
      const bend = Math.max(40, Math.abs(x2 - x1) / 2)
      path.setAttribute('d', `M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`)
      path.setAttribute('stroke', '#94a3b8')
      path.setAttribute('stroke-width', '2')
      path.setAttribute('fill', 'none')
      svg.appendChild(path)
    }
  }

  /* ------------------------------------------------------------ editing --- */

  function addNode(type) {
    const key = `${type}_${Math.random().toString(36).slice(2, 7)}`

    graph.nodes.push({
      key,
      type,
      config: { text: '' },
      x: 60 + canvas.scrollLeft,
      y: 60 + canvas.scrollTop,
      pins: defaultPins(type),
    })

    draw()
    selectNode(key)
  }

  /*
   * Mirrors NodeType::pinsFor for a node that has just been created and has
   * not been near the server yet. A choice node starts with no options, so it
   * starts with no pins — they appear as options are added.
   */
  function defaultPins(type) {
    if (type === 'choice') return []

    return graph.nodeTypes[type]?.pins ?? ['out']
  }

  function deleteNode(key) {
    graph.nodes = graph.nodes.filter((n) => n.key !== key)
    // A wire to a node that is gone is a dangling reference the runner walks
    // into and stops at, so both ends go with it.
    graph.wires = graph.wires.filter((w) => w.from !== key && w.to !== key)

    if (selectedKey === key) selectedKey = null

    draw()
    drawInspector()
  }

  function completeWire(toKey) {
    if (!pending) return

    if (pending.from === toKey) {
      say('A node cannot wire into itself.', 'text-danger')
      pending = null

      return
    }

    // One wire per output pin: two would make the flow ambiguous, and the
    // table's unique key would reject the save anyway.
    graph.wires = graph.wires.filter((w) => !(w.from === pending.from && w.pin === pending.pin))
    graph.wires.push({ from: pending.from, pin: pending.pin, to: toKey })

    pending = null
    drawWires()
    say('Wired.')
  }

  function startDrag(event, node, box) {
    if (event.target.classList.contains('bp-pin')) return

    const startX = event.clientX
    const startY = event.clientY
    const originX = node.x
    const originY = node.y

    box.style.cursor = 'grabbing'

    const move = (moveEvent) => {
      node.x = Math.max(0, originX + (moveEvent.clientX - startX))
      node.y = Math.max(0, originY + (moveEvent.clientY - startY))
      box.style.left = `${node.x}px`
      box.style.top = `${node.y}px`
      drawWires()
    }

    const up = () => {
      box.style.cursor = 'grab'
      document.removeEventListener('mousemove', move)
      document.removeEventListener('mouseup', up)
    }

    document.addEventListener('mousemove', move)
    document.addEventListener('mouseup', up)
  }

  function selectNode(key) {
    selectedKey = key
    draw()
    drawInspector()
  }

  /* ---------------------------------------------------------- inspector --- */

  function drawInspector() {
    if (!inspector) return

    const node = graph.nodes.find((n) => n.key === selectedKey)

    if (!node) {
      inspector.innerHTML = '<p class="small text-muted mb-0">Click a node to edit what it says.</p>'

      return
    }

    inspector.innerHTML = ''

    inspector.appendChild(field('What it says', node.config.text ?? '', (value) => {
      node.config.text = value
      draw()
    }, true))

    if (node.type === 'ask') {
      inspector.appendChild(field('Store the answer as', node.config.field ?? '', (v) => {
        node.config.field = v
      }))
      inspector.appendChild(
        field('Check the answer is (blank, or "email")', node.config.validate ?? '', (v) => {
          node.config.validate = v
        }),
      )
      inspector.appendChild(checkbox('May be skipped', Boolean(node.config.optional), (v) => {
        node.config.optional = v
      }))
    }

    if (node.type === 'choice') {
      const list = document.createElement('div')
      list.className = 'mb-2'

      for (const [i, option] of (node.config.options ?? []).entries()) {
        const row = document.createElement('div')
        row.className = 'input-group input-group-sm mb-1'
        row.innerHTML = `
          <input class="form-control" value="${escapeHtml(option.label ?? '')}" placeholder="Button label">
          <input class="form-control" value="${escapeHtml(option.pin ?? '')}" placeholder="pin" style="max-width:110px">
          <button class="btn btn-outline-danger" type="button">&times;</button>
        `

        const [labelInput, pinInput] = row.querySelectorAll('input')
        labelInput.addEventListener('input', () => {
          option.label = labelInput.value
        })
        pinInput.addEventListener('input', () => {
          option.pin = pinInput.value
          node.pins = (node.config.options ?? []).map((o) => o.pin)
          draw()
        })

        row.querySelector('button').addEventListener('click', () => {
          node.config.options.splice(i, 1)
          node.pins = node.config.options.map((o) => o.pin)
          draw()
          drawInspector()
        })

        list.appendChild(row)
      }

      const add = document.createElement('button')
      add.type = 'button'
      add.className = 'btn btn-sm btn-outline-secondary'
      add.textContent = '+ Option'
      add.addEventListener('click', () => {
        node.config.options = node.config.options ?? []
        node.config.options.push({ label: 'New option', pin: `opt${node.config.options.length + 1}` })
        node.pins = node.config.options.map((o) => o.pin)
        draw()
        drawInspector()
      })

      inspector.append(list, add)
    }
  }

  function field(label, value, onInput, multiline = false) {
    const wrap = document.createElement('div')
    wrap.className = 'mb-2'

    const id = `bp-${Math.random().toString(36).slice(2, 8)}`
    wrap.innerHTML = `<label class="form-label small mb-1" for="${id}">${escapeHtml(label)}</label>`

    const input = document.createElement(multiline ? 'textarea' : 'input')
    input.id = id
    input.className = 'form-control form-control-sm'
    if (multiline) input.rows = 2
    input.value = value
    input.addEventListener('input', () => onInput(input.value))

    wrap.appendChild(input)

    return wrap
  }

  function checkbox(label, checked, onChange) {
    const wrap = document.createElement('div')
    wrap.className = 'form-check mb-2'

    const id = `bp-${Math.random().toString(36).slice(2, 8)}`
    const input = document.createElement('input')
    input.type = 'checkbox'
    input.className = 'form-check-input'
    input.id = id
    input.checked = checked
    input.addEventListener('change', () => onChange(input.checked))

    const text = document.createElement('label')
    text.className = 'form-check-label small'
    text.htmlFor = id
    text.textContent = label

    wrap.append(input, text)

    return wrap
  }

  /* ------------------------------------------------------------- saving --- */

  document.getElementById('bp-save')?.addEventListener('click', async () => {
    const response = await fetch(`/admin/chatbot/blueprints/${blueprintId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
      },
      body: JSON.stringify({
        nodes: graph.nodes.map(({ key, type, config, x, y }) => ({
          key,
          type,
          config,
          x: Math.round(x),
          y: Math.round(y),
        })),
        wires: graph.wires,
      }),
    })

    if (response.ok) {
      say('Saved.', 'text-success')

      return
    }

    // The server checks things the canvas cannot — a wire to a node that is
    // not here, two nodes sharing a key — and says which.
    const body = await response.json().catch(() => ({}))
    say(body.message ?? 'Could not save this graph.', 'text-danger')
  })

  document.getElementById('bp-reload')?.addEventListener('click', load)

  // A drag that ends anywhere but on an input pin is a wire nobody drew.
  document.addEventListener('mouseup', () => {
    if (pending) {
      pending = null
      say('Wiring cancelled.')
    }
  })

  function escapeHtml(value) {
    return String(value ?? '').replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    )
  }

  void load()
}
