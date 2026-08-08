/**
 * Result views: the schematic page map, the field tables, the stat row and the
 * plain-text pane. Everything is built with DOM nodes, never innerHTML.
 */

const BLOCK_TYPES = ['heading', 'paragraph', 'key_value', 'table', 'list'];

/**
 * The page map draws one sheet per page with a rectangle for every block the
 * structurer found, so the layout it inferred is visible at a glance.
 */
export class PageMap {
  constructor(container, peek) {
    this.container = container;
    this.peek = peek;
    this.sheets = new Map();
  }

  reset(pageCount) {
    this.container.textContent = '';
    this.sheets.clear();
    this.hidePeek();

    for (let number = 1; number <= pageCount; number += 1) {
      const sheet = document.createElement('div');
      sheet.className = 'sheet';
      sheet.dataset.page = String(number);

      const label = document.createElement('div');
      label.className = 'sheet__label';
      const left = document.createElement('span');
      left.textContent = `p. ${number}`;
      const right = document.createElement('span');
      right.textContent = 'queued';
      label.append(left, right);
      sheet.appendChild(label);

      this.container.appendChild(sheet);
      this.sheets.set(number, { sheet, label: right });
    }
  }

  setScanning(pageNumber, scanning) {
    const entry = this.sheets.get(pageNumber);
    if (!entry) return;
    entry.sheet.classList.toggle('is-scanning', scanning);
    if (scanning) entry.label.textContent = 'reading';
  }

  /** @param {{number:number,width:number,height:number,source:string,confidence:?number,blocks:Array}} page */
  setPage(page) {
    const entry = this.sheets.get(page.number);
    if (!entry) return;

    const { sheet, label } = entry;
    sheet.classList.remove('is-scanning');
    sheet.querySelectorAll('.sheet__block').forEach((node) => node.remove());

    if (page.width > 0 && page.height > 0) {
      sheet.style.aspectRatio = `${page.width} / ${page.height}`;
    }

    for (const block of page.blocks || []) {
      const box = block.rel_bbox;
      if (!box) continue;

      const element = document.createElement('div');
      element.className = 'sheet__block';
      element.dataset.type = BLOCK_TYPES.includes(block.type) ? block.type : 'paragraph';
      element.style.left = `${box.x * 100}%`;
      element.style.top = `${box.y * 100}%`;
      element.style.width = `${Math.max(box.w * 100, 0.6)}%`;
      element.style.height = `${Math.max(box.h * 100, 0.5)}%`;
      element.title = block.text ? `${block.type}\n${block.text.slice(0, 160)}` : block.type;

      if (block.text) {
        element.addEventListener('mouseenter', () => this.showPeek(block, page.number));
        element.addEventListener('click', () => this.showPeek(block, page.number));
      }

      sheet.insertBefore(element, sheet.querySelector('.sheet__label'));
    }

    const parts = [page.source === 'text_layer' ? 'text' : page.source];
    if (typeof page.confidence === 'number') parts.push(`${Math.round(page.confidence)}%`);
    label.textContent = parts.join(' · ');
  }

  renderFromDocument(document_) {
    this.reset(document_.pages.length);
    for (const page of document_.pages) this.setPage(page);
  }

  showPeek(block, pageNumber) {
    this.peek.textContent = '';

    const head = document.createElement('div');
    head.className = 'blockpeek__head';
    head.textContent = `page ${pageNumber} · ${block.type} · ${block.id}`;

    const body = document.createElement('div');
    body.textContent = block.text;

    this.peek.append(head, body);
    this.peek.hidden = false;
  }

  hidePeek() {
    this.peek.hidden = true;
    this.peek.textContent = '';
  }
}

export function renderStats(document_, elements) {
  elements.pages.textContent = document_.document.page_count;
  elements.words.textContent = document_.summary.word_count.toLocaleString();
  elements.blocks.textContent = document_.summary.block_count.toLocaleString();
  elements.kv.textContent = document_.summary.key_value_count.toLocaleString();
  elements.source.textContent = { text_layer: 'text layer', ocr: 'OCR', mixed: 'mixed' }[document_.document.source]
    || document_.document.source;
  elements.confidence.textContent = document_.document.mean_confidence === null
    ? 'n/a'
    : `${Math.round(document_.document.mean_confidence)}%`;
}

export function renderFields(container, document_) {
  container.textContent = '';

  const keyValues = document_.key_values || [];
  const entities = document_.entities || {};
  const tables = document_.tables || [];

  if (keyValues.length === 0 && Object.keys(entities).length === 0 && tables.length === 0) {
    container.appendChild(emptyNote('No labelled values or typed entities were found in this document.'));

    return;
  }

  if (keyValues.length > 0) {
    container.appendChild(group('Label / value candidates', table(
      ['Key', 'Label', 'Value', 'Pages'],
      keyValues.map((kv) => [
        cell(kv.key, 'key'),
        cell(kv.label),
        cell(kv.value),
        cell([...new Set(kv.occurrences.map((o) => o.page))].join(', ')),
      ])
    )));
  }

  for (const [type, values] of Object.entries(entities)) {
    container.appendChild(group(`${type.replace(/_/g, ' ')} (${values.length})`, table(
      ['Value', 'As printed', 'Page'],
      values.map((entity) => [
        cell(entity.value === null ? '—' : String(entity.value)),
        cell(entity.raw),
        cell(String(entity.page)),
      ])
    )));
  }

  tables.forEach((detected, index) => {
    const rows = detected.rows || [];
    const header = detected.header || rows.shift() || [];
    container.appendChild(group(
      `Table ${index + 1} · page ${detected.page} · ${detected.columns} columns`,
      table(header.map(String), rows.map((row) => row.map((value) => cell(String(value)))))
    ));
  });
}

export function renderWarnings(container, warnings) {
  container.textContent = '';
  if (!warnings || warnings.length === 0) return;

  for (const warning of warnings) {
    const notice = document.createElement('p');
    notice.className = 'notice notice--warn';
    notice.textContent = warning;
    container.appendChild(notice);
  }
}

function group(title, content) {
  const section = document.createElement('section');
  section.className = 'fieldgroup';

  const heading = document.createElement('h3');
  heading.textContent = title;

  section.append(heading, content);

  return section;
}

function table(headers, rows) {
  const element = document.createElement('table');
  element.className = 'grid';

  const thead = document.createElement('thead');
  const headRow = document.createElement('tr');
  for (const header of headers) {
    const th = document.createElement('th');
    th.textContent = header;
    headRow.appendChild(th);
  }
  thead.appendChild(headRow);

  const tbody = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    for (const value of row) {
      tr.appendChild(value instanceof HTMLElement ? value : cell(String(value)));
    }
    tbody.appendChild(tr);
  }

  element.append(thead, tbody);

  return element;
}

function cell(text, className = '') {
  const td = document.createElement('td');
  td.textContent = text ?? '';
  if (className) td.className = className;

  return td;
}

function emptyNote(text) {
  const p = document.createElement('p');
  p.className = 'empty';
  p.textContent = text;

  return p;
}
