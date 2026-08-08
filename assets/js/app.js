/**
 * Orchestrates one run: upload -> read each page -> post it for structuring ->
 * finalise -> show the JSON.
 *
 * The PDF bytes go to PHP for storage and hashing; recognition happens here in
 * the browser; all layout analysis happens in PHP. This file only moves data
 * between those three and keeps the UI honest about what stage it is at.
 */

import * as api from './api.js';
import { createDropzone, formatBytes } from './dropzone.js';
import { openPdf, extractPage } from './pdf-processor.js';
import { OcrEngine } from './ocr.js';
import { renderJsonTree, setAllExpanded, filterTree } from './json-viewer.js';
import { PageMap, renderStats, renderFields, renderWarnings } from './views.js';

const el = (id) => document.getElementById(id);

const ui = {
  dropzone: el('dropzone'),
  fileInput: el('file-input'),
  browse: el('browse'),
  language: el('language'),
  includeWords: el('include-words'),

  progress: el('progress'),
  progressFile: el('progress-file'),
  progressBar: el('progress-bar'),
  progressStage: el('progress-stage'),
  pagelist: el('pagelist'),
  pageRowTemplate: el('tpl-page-row'),

  recent: el('recent'),

  eyebrow: el('result-eyebrow'),
  title: el('result-title'),
  copyJson: el('copy-json'),
  downloadJson: el('download-json'),
  stats: el('stats'),
  statPages: el('stat-pages'),
  statWords: el('stat-words'),
  statBlocks: el('stat-blocks'),
  statKv: el('stat-kv'),
  statSource: el('stat-source'),
  statConfidence: el('stat-confidence'),

  jsonTree: el('json-tree'),
  jsonFilter: el('json-filter'),
  jsonExpand: el('json-expand'),
  jsonCollapse: el('json-collapse'),
  warnings: el('warnings'),
  fields: el('fields'),
  plaintext: el('plaintext'),
  pagemap: el('pagemap'),
  blockpeek: el('blockpeek'),
};

const pageMap = new PageMap(ui.pagemap, ui.blockpeek);
const pageRows = new Map();

let currentDocument = null;
let currentJobId = null;
let running = false;

const LANGUAGE_KEY = 'pdfocr.language';

/* ---------------------------------------------------------------- start-up */

const savedLanguage = localStorage.getItem(LANGUAGE_KEY);
if (savedLanguage && [...ui.language.options].some((option) => option.value === savedLanguage)) {
  ui.language.value = savedLanguage;
}
ui.language.addEventListener('change', () => localStorage.setItem(LANGUAGE_KEY, ui.language.value));

const dropzone = createDropzone({
  zone: ui.dropzone,
  input: ui.fileInput,
  browseButton: ui.browse,
  maxBytes: api.appConfig.maxBytes,
  onFile: (file) => { void run(file); },
  onReject: (message) => showError(message),
});

setupTabs();
setupJsonControls();
void refreshRecent();

/* ------------------------------------------------------------- the pipeline */

async function run(file) {
  if (running) return;
  running = true;
  dropzone.setBusy(true);
  resetResult();

  const started = performance.now();
  let job = null;
  let engine = null;

  try {
    ui.progress.hidden = false;
    ui.progressFile.textContent = `${file.name} · ${formatBytes(file.size)}`;
    stage('Uploading');

    const language = ui.language.value;
    ({ job } = await api.uploadPdf(file, language));
    currentJobId = job.id;

    stage('Opening the PDF');
    const pdf = await openPdf(await file.arrayBuffer());
    const pageCount = pdf.numPages;

    buildPageRows(pageCount);
    pageMap.reset(pageCount);
    ui.eyebrow.textContent = `${pageCount} page${pageCount === 1 ? '' : 's'} · reading`;
    ui.title.textContent = job.file_name;

    let ocrProgress = 0;
    engine = new OcrEngine(language, (message) => {
      if (typeof message.progress === 'number') ocrProgress = message.progress;
    });

    for (let number = 1; number <= pageCount; number += 1) {
      setPageState(number, 'reading', 'reading');
      pageMap.setScanning(number, true);

      const tick = setInterval(() => {
        setProgress((number - 1 + ocrProgress) / pageCount);
      }, 200);

      let page;
      try {
        page = await extractPage(pdf, number, {
          minChars: api.appConfig.textLayerMinChars,
          ocr: async (canvas) => {
            stage(`Running OCR on page ${number} of ${pageCount}`);
            ocrProgress = 0;

            return engine.recognize(canvas);
          },
        });
      } finally {
        clearInterval(tick);
        ocrProgress = 0;
      }

      stage(`Structuring page ${number} of ${pageCount}`);
      const response = await api.ingestPage(currentJobId, page, {
        page_count: pageCount,
        language,
      });

      pageMap.setPage({
        number,
        width: page.width,
        height: page.height,
        source: response.page.source,
        confidence: response.page.confidence,
        blocks: response.page.blocks,
      });

      const meta = [
        `${response.page.word_count} words`,
        `${response.page.block_count} blocks`,
        response.page.confidence === null ? null : `${Math.round(response.page.confidence)}%`,
      ].filter(Boolean).join(' · ');

      setPageState(number, 'done', response.page.source === 'text_layer' ? 'text layer' : 'ocr', meta);
      setProgress(number / pageCount);
    }

    stage('Building the JSON');
    const result = await api.finalize(currentJobId, {
      durationMs: Math.round(performance.now() - started),
      includeWords: ui.includeWords.checked,
    });

    showDocument(result.document, result.download_url);
    stage(`Done in ${((performance.now() - started) / 1000).toFixed(1)} s`);
    setProgress(1);
  } catch (error) {
    console.error(error);
    showError(error.message || String(error));
    stage('Stopped');
    if (currentJobId) {
      try {
        await api.finalize(currentJobId, { error: error.message || String(error) });
      } catch { /* the job stays in "processing"; nothing more to do here */ }
    }
  } finally {
    if (engine) await engine.stop();
    running = false;
    dropzone.setBusy(false);
    void refreshRecent();
  }
}

/* ----------------------------------------------------------------- rendering */

function showDocument(document_, downloadHref) {
  currentDocument = document_;

  ui.stats.hidden = false;
  renderStats(document_, {
    pages: ui.statPages,
    words: ui.statWords,
    blocks: ui.statBlocks,
    kv: ui.statKv,
    source: ui.statSource,
    confidence: ui.statConfidence,
  });

  ui.title.textContent = document_.document.title || document_.job.file_name;
  ui.eyebrow.textContent = `${document_.job.file_name} · ${document_.document.page_count} pages · schema ${document_.schema_version}`;

  renderJsonTree(ui.jsonTree, document_, { expandDepth: 2 });
  renderFields(ui.fields, document_);
  renderWarnings(ui.warnings, document_.warnings);
  pageMap.renderFromDocument(document_);
  ui.plaintext.textContent = document_.document.text || '(no text)';

  ui.copyJson.disabled = false;
  ui.downloadJson.classList.remove('is-disabled');
  ui.downloadJson.href = downloadHref || api.downloadUrl(document_.job.id, ui.includeWords.checked);
}

function resetResult() {
  currentDocument = null;
  currentJobId = null;
  ui.stats.hidden = true;
  ui.jsonTree.textContent = '';
  ui.fields.textContent = '';
  ui.plaintext.textContent = '';
  ui.warnings.textContent = '';
  ui.pagemap.textContent = '';
  ui.blockpeek.hidden = true;
  ui.jsonFilter.value = '';
  ui.copyJson.disabled = true;
  ui.downloadJson.classList.add('is-disabled');
  ui.downloadJson.removeAttribute('href');
  ui.pagelist.textContent = '';
  pageRows.clear();
  setProgress(0);
}

function buildPageRows(pageCount) {
  ui.pagelist.textContent = '';
  pageRows.clear();

  for (let number = 1; number <= pageCount; number += 1) {
    const row = ui.pageRowTemplate.content.firstElementChild.cloneNode(true);
    row.querySelector('.pagelist__num').textContent = `p.${number}`;
    row.dataset.state = 'queued';
    ui.pagelist.appendChild(row);
    pageRows.set(number, row);
  }
}

function setPageState(number, state, label, meta = '') {
  const row = pageRows.get(number);
  if (!row) return;

  row.dataset.state = state;
  row.querySelector('.pagelist__state').textContent = label;
  row.querySelector('.pagelist__meta').textContent = meta;
}

function stage(text) {
  ui.progressStage.textContent = text;
}

function setProgress(fraction) {
  ui.progressBar.style.width = `${Math.min(100, Math.max(0, fraction * 100)).toFixed(1)}%`;
}

function showError(message) {
  const notice = document.createElement('p');
  notice.className = 'notice';
  notice.textContent = message;
  ui.warnings.textContent = '';
  ui.warnings.appendChild(notice);
  ui.progress.hidden = false;
}

/* -------------------------------------------------------------- recent files */

async function refreshRecent() {
  try {
    const { jobs } = await api.listJobs(10);
    ui.recent.textContent = '';

    if (jobs.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'recent__empty';
      empty.textContent = 'Nothing read yet.';
      ui.recent.appendChild(empty);

      return;
    }

    for (const job of jobs) {
      ui.recent.appendChild(recentItem(job));
    }
  } catch (error) {
    ui.recent.textContent = '';
    const failed = document.createElement('li');
    failed.className = 'recent__empty';
    failed.textContent = `Cannot list recent files: ${error.message}`;
    ui.recent.appendChild(failed);
  }
}

function recentItem(job) {
  const item = document.createElement('li');
  item.className = 'recent__item';

  const open = document.createElement('button');
  open.type = 'button';
  open.className = 'recent__name';
  open.textContent = job.original_name;
  open.addEventListener('click', () => { void openJob(job.id); });

  const drop = document.createElement('button');
  drop.type = 'button';
  drop.className = 'recent__drop';
  drop.title = `Delete ${job.original_name}`;
  drop.setAttribute('aria-label', `Delete ${job.original_name}`);
  drop.textContent = '×';
  drop.addEventListener('click', async () => {
    try {
      await api.deleteJob(job.id);
      if (currentDocument?.job.id === job.id) resetResult();
      await refreshRecent();
    } catch (error) {
      showError(error.message);
    }
  });

  const meta = document.createElement('span');
  meta.className = 'recent__meta';
  meta.textContent = [
    job.status,
    `${job.page_count} p`,
    `${job.word_count} words`,
    job.mean_confidence === null ? null : `${Math.round(job.mean_confidence)}%`,
    job.size_human,
    job.created_at,
  ].filter(Boolean).join(' · ');

  item.append(open, drop, meta);

  return item;
}

async function openJob(jobId) {
  try {
    stage('Loading a stored result');
    ui.progress.hidden = false;
    const { document: stored } = await api.fetchResult(jobId, ui.includeWords.checked);
    resetResult();
    currentJobId = jobId;
    showDocument(stored, api.downloadUrl(jobId, ui.includeWords.checked));
    ui.progressFile.textContent = stored.job.file_name;
    stage('Loaded from the database');
    setProgress(1);
  } catch (error) {
    showError(error.message);
  }
}

/* ------------------------------------------------------------------ controls */

function setupTabs() {
  const tabs = [...document.querySelectorAll('.tab')];

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', (event) => {
      const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
      if (step === 0) return;
      event.preventDefault();
      const next = tabs[(index + step + tabs.length) % tabs.length];
      next.focus();
      activate(next);
    });
  });

  function activate(tab) {
    for (const other of tabs) {
      const active = other === tab;
      other.classList.toggle('is-active', active);
      other.setAttribute('aria-selected', String(active));
      const panel = document.getElementById(other.getAttribute('aria-controls'));
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    }
  }
}

function setupJsonControls() {
  ui.jsonExpand.addEventListener('click', () => setAllExpanded(ui.jsonTree, true));
  ui.jsonCollapse.addEventListener('click', () => setAllExpanded(ui.jsonTree, false));

  let timer = null;
  ui.jsonFilter.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => filterTree(ui.jsonTree, ui.jsonFilter.value), 150);
  });

  ui.copyJson.addEventListener('click', async () => {
    if (!currentDocument) return;

    const text = JSON.stringify(currentDocument, null, 2);
    try {
      await navigator.clipboard.writeText(text);
      flash(ui.copyJson, 'Copied');
    } catch {
      flash(ui.copyJson, 'Press Ctrl+C');
      const area = document.createElement('textarea');
      area.value = text;
      document.body.appendChild(area);
      area.select();
      setTimeout(() => area.remove(), 4000);
    }
  });
}

function flash(button, message) {
  const original = button.textContent;
  button.textContent = message;
  setTimeout(() => { button.textContent = original; }, 1600);
}
