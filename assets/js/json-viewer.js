/**
 * Collapsible JSON tree. Built with DOM nodes rather than innerHTML so document
 * text can never be interpreted as markup.
 */

const MAX_INLINE_STRING = 220;

export function renderJsonTree(container, value, { expandDepth = 2 } = {}) {
  container.textContent = '';
  container.appendChild(buildNode(null, value, 0, expandDepth));
}

export function setAllExpanded(container, expanded) {
  container.querySelectorAll('.node').forEach((node) => {
    if (!node.querySelector(':scope > .node__toggle')) return;
    node.classList.toggle('is-collapsed', !expanded);
    const toggle = node.querySelector(':scope > .node__toggle');
    if (toggle) {
      toggle.textContent = expanded ? '−' : '+';
      toggle.setAttribute('aria-expanded', String(expanded));
    }
  });
}

/**
 * Hides every branch that does not contain the query. An empty query restores
 * the tree to its default depth.
 */
export function filterTree(container, query) {
  const needle = query.trim().toLowerCase();

  container.querySelectorAll('mark.hit').forEach((mark) => {
    mark.replaceWith(document.createTextNode(mark.textContent));
  });

  const nodes = container.querySelectorAll('.node');

  if (needle === '') {
    nodes.forEach((node) => { node.hidden = false; });
    setAllExpanded(container, false);
    container.querySelectorAll('.node--root, .node--root > .node__children > .node').forEach((node) => {
      node.classList.remove('is-collapsed');
      const toggle = node.querySelector(':scope > .node__toggle');
      if (toggle) {
        toggle.textContent = '−';
        toggle.setAttribute('aria-expanded', 'true');
      }
    });

    return 0;
  }

  let hits = 0;

  nodes.forEach((node) => {
    const matches = node.textContent.toLowerCase().includes(needle);
    node.hidden = !matches;

    if (!matches) return;

    node.classList.remove('is-collapsed');
    const toggle = node.querySelector(':scope > .node__toggle');
    if (toggle) {
      toggle.textContent = '−';
      toggle.setAttribute('aria-expanded', 'true');
    }

    const own = node.querySelector(':scope > .node__self');
    if (own && own.textContent.toLowerCase().includes(needle)) {
      hits += highlight(own, needle);
    }
  });

  return hits;
}

function highlight(row, needle) {
  let count = 0;
  const walker = document.createTreeWalker(row, NodeFilter.SHOW_TEXT);
  const targets = [];

  while (walker.nextNode()) {
    if (walker.currentNode.nodeValue.toLowerCase().includes(needle)) targets.push(walker.currentNode);
  }

  for (const textNode of targets) {
    const parts = textNode.nodeValue.split(new RegExp(`(${escapeRegExp(needle)})`, 'ig'));
    const fragment = document.createDocumentFragment();

    for (const part of parts) {
      if (part.toLowerCase() === needle) {
        const mark = document.createElement('mark');
        mark.className = 'hit';
        mark.textContent = part;
        fragment.appendChild(mark);
        count += 1;
      } else if (part !== '') {
        fragment.appendChild(document.createTextNode(part));
      }
    }
    textNode.replaceWith(fragment);
  }

  return count;
}

function buildNode(key, value, depth, expandDepth) {
  const node = document.createElement('div');
  node.className = depth === 0 ? 'node node--root' : 'node';

  const self = document.createElement('div');
  self.className = 'node__self';

  if (key !== null) {
    const keySpan = document.createElement('span');
    keySpan.className = 'node__key';
    keySpan.textContent = Array.isArray(key) ? key[0] : `"${key}"`;
    self.appendChild(keySpan);

    const colon = document.createElement('span');
    colon.className = 'node__colon';
    colon.textContent = ': ';
    self.appendChild(colon);
  }

  const isBranch = value !== null && typeof value === 'object';

  if (!isBranch) {
    self.appendChild(leaf(value));
    node.appendChild(self);

    return node;
  }

  const isArray = Array.isArray(value);
  const entries = isArray ? value.map((v, i) => [String(i), v]) : Object.entries(value);

  const summary = document.createElement('span');
  summary.className = 'node__summary';
  summary.textContent = isArray
    ? `[${entries.length}]`
    : `{${entries.length} ${entries.length === 1 ? 'key' : 'keys'}}`;
  self.appendChild(summary);
  node.appendChild(self);

  const children = document.createElement('div');
  children.className = 'node__children';
  for (const [childKey, childValue] of entries) {
    children.appendChild(buildNode(isArray ? [childKey] : childKey, childValue, depth + 1, expandDepth));
  }
  node.appendChild(children);

  const collapsed = depth >= expandDepth && entries.length > 0;
  node.classList.toggle('is-collapsed', collapsed);

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'node__toggle';
  toggle.textContent = collapsed ? '+' : '−';
  toggle.setAttribute('aria-expanded', String(!collapsed));
  toggle.setAttribute('aria-label', key === null ? 'Toggle document' : `Toggle ${key}`);
  toggle.addEventListener('click', () => {
    const nowCollapsed = !node.classList.contains('is-collapsed');
    node.classList.toggle('is-collapsed', nowCollapsed);
    toggle.textContent = nowCollapsed ? '+' : '−';
    toggle.setAttribute('aria-expanded', String(!nowCollapsed));
  });
  node.insertBefore(toggle, node.firstChild);

  return node;
}

function leaf(value) {
  const span = document.createElement('span');

  if (value === null) {
    span.className = 'val val--null';
    span.textContent = 'null';
  } else if (typeof value === 'string') {
    span.className = 'val val--string';
    const clipped = value.length > MAX_INLINE_STRING;
    span.textContent = `"${clipped ? `${value.slice(0, MAX_INLINE_STRING)}…` : value}"`;
    if (clipped) {
      span.title = `${value.length} characters — click to expand`;
      span.style.cursor = 'pointer';
      span.addEventListener('click', () => { span.textContent = `"${value}"`; });
    }
  } else if (typeof value === 'number') {
    span.className = 'val val--number';
    span.textContent = String(value);
  } else {
    span.className = 'val val--boolean';
    span.textContent = String(value);
  }

  return span;
}

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
