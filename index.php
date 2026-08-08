<?php
/**
 * pdfOCR — drop a PDF, get structured JSON.
 *
 * This file only renders the shell and hands configuration to the browser.
 * All recognition happens in assets/js/*, all structuring happens in src/*.
 */

declare(strict_types=1);

use PdfOcr\Http;
use PdfOcr\Uploader;

require __DIR__ . '/api/bootstrap.php';

$token = Http::token('pdfocr_token');
$languages = $config['ocr']['languages'];
$defaultLanguage = (string) $config['ocr']['default_language'];
$maxBytes = (int) $config['upload']['max_bytes'];

$clientConfig = [
    'token'             => $token,
    'maxBytes'          => $maxBytes,
    'defaultLanguage'   => $defaultLanguage,
    'textLayerMinChars' => (int) $config['structure']['text_layer_min_chars'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Drop a PDF and get its text back as structured JSON: blocks, lines, tables, key/value pairs and typed entities.">
<meta name="color-scheme" content="light dark">
<title>pdfOCR — PDF to structured JSON</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='13' font-size='13'>▤</text></svg>">
<link rel="stylesheet" href="assets/css/app.css?v=1">
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.6.172/build/pdf.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js" defer></script>
</head>
<body>
<a class="skip-link" href="#workspace">Skip to the workspace</a>

<header class="masthead">
  <div class="masthead__mark">
    <span class="masthead__glyph" aria-hidden="true">▤</span>
    <div>
      <h1>pdfOCR</h1>
      <p class="eyebrow">PDF in, structured JSON out</p>
    </div>
  </div>
  <dl class="masthead__specs">
    <div><dt>Recognition</dt><dd>In this browser</dd></div>
    <div><dt>Structuring</dt><dd>PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?></dd></div>
    <div><dt>Store</dt><dd>MySQL via PDO</dd></div>
  </dl>
</header>

<main id="workspace" class="workspace">

  <section class="rail" aria-label="Intake">
    <form id="intake" class="panel" autocomplete="off">
      <div id="dropzone" class="dropzone" tabindex="0" role="button"
           aria-describedby="dropzone-hint"
           aria-label="Drop a PDF here or press Enter to choose a file">
        <span class="tick tick--tl" aria-hidden="true"></span>
        <span class="tick tick--tr" aria-hidden="true"></span>
        <span class="tick tick--bl" aria-hidden="true"></span>
        <span class="tick tick--br" aria-hidden="true"></span>

        <svg class="dropzone__icon" viewBox="0 0 48 60" aria-hidden="true">
          <path d="M4 2h26l14 14v42H4z" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M30 2v14h14" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M13 30h22M13 38h22M13 46h14" stroke="currentColor" stroke-width="2" stroke-linecap="square"/>
        </svg>
        <p class="dropzone__title">Drop a PDF here</p>
        <p id="dropzone-hint" class="dropzone__hint">or <button type="button" class="linkish" id="browse">choose a file</button> · max <?= htmlspecialchars(Uploader::humanBytes($maxBytes), ENT_QUOTES) ?></p>
        <input type="file" id="file-input" accept="application/pdf,.pdf" hidden>
      </div>

      <div class="field">
        <label for="language">Document language</label>
        <select id="language" name="language">
          <?php foreach ($languages as $code => $label): ?>
            <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"<?= $code === $defaultLanguage ? ' selected' : '' ?>>
              <?= htmlspecialchars($label, ENT_QUOTES) ?> (<?= htmlspecialchars($code, ENT_QUOTES) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="field__note">Only used when a page has to be read with OCR.</p>
      </div>

      <label class="switch">
        <input type="checkbox" id="include-words">
        <span>Include word-level boxes in the JSON</span>
      </label>
    </form>

    <section class="panel progress" id="progress" hidden aria-live="polite">
      <h2 class="panel__title">Reading</h2>
      <p class="progress__file" id="progress-file"></p>
      <div class="meter"><div class="meter__fill" id="progress-bar" style="width:0%"></div></div>
      <p class="progress__stage" id="progress-stage">Waiting for the file</p>
      <ol class="pagelist" id="pagelist"></ol>
    </section>

    <section class="panel" aria-labelledby="recent-title">
      <h2 class="panel__title" id="recent-title">Recent files</h2>
      <ul class="recent" id="recent"><li class="recent__empty">Nothing read yet.</li></ul>
    </section>
  </section>

  <section class="results panel" aria-label="Result">
    <header class="results__head">
      <div>
        <p class="eyebrow" id="result-eyebrow">No document</p>
        <h2 id="result-title">Drop a PDF to start</h2>
      </div>
      <div class="results__actions">
        <button type="button" id="copy-json" class="button" disabled>Copy JSON</button>
        <a id="download-json" class="button button--primary is-disabled" href="#" download>Download .json</a>
      </div>
    </header>

    <div id="warnings" role="status" aria-live="polite" class="warnings"></div>

    <dl class="stats" id="stats" hidden>
      <div><dt>Pages</dt><dd id="stat-pages">—</dd></div>
      <div><dt>Words</dt><dd id="stat-words">—</dd></div>
      <div><dt>Blocks</dt><dd id="stat-blocks">—</dd></div>
      <div><dt>Key/values</dt><dd id="stat-kv">—</dd></div>
      <div><dt>Source</dt><dd id="stat-source">—</dd></div>
      <div><dt>Confidence</dt><dd id="stat-confidence">—</dd></div>
    </dl>

    <div class="tabs" role="tablist" aria-label="Result views">
      <button role="tab" class="tab is-active" id="tab-json" aria-controls="view-json" aria-selected="true">JSON</button>
      <button role="tab" class="tab" id="tab-structure" aria-controls="view-structure" aria-selected="false">Structure</button>
      <button role="tab" class="tab" id="tab-fields" aria-controls="view-fields" aria-selected="false">Fields</button>
      <button role="tab" class="tab" id="tab-text" aria-controls="view-text" aria-selected="false">Plain text</button>
    </div>

    <div class="views">
      <div role="tabpanel" id="view-json" aria-labelledby="tab-json" class="view is-active">
        <div class="view__toolbar">
          <input type="search" id="json-filter" class="input" placeholder="Filter keys and values" aria-label="Filter the JSON tree">
          <button type="button" class="button button--quiet" id="json-expand">Expand all</button>
          <button type="button" class="button button--quiet" id="json-collapse">Collapse all</button>
        </div>
        <div class="json" id="json-tree"><p class="empty">The JSON tree appears here once a document has been read.</p></div>
      </div>

      <div role="tabpanel" id="view-structure" aria-labelledby="tab-structure" class="view" hidden>
        <p class="view__lede">Every rectangle is a block the structurer found. Hover to read it.</p>
        <div class="pagemap" id="pagemap"></div>
        <div class="blockpeek" id="blockpeek" hidden></div>
      </div>

      <div role="tabpanel" id="view-fields" aria-labelledby="tab-fields" class="view" hidden>
        <p class="view__lede">Label/value candidates and typed values. This is the raw material for the field-mapping step.</p>
        <div id="fields"></div>
      </div>

      <div role="tabpanel" id="view-text" aria-labelledby="tab-text" class="view" hidden>
        <pre class="plaintext" id="plaintext"></pre>
      </div>
    </div>
  </section>
</main>

<footer class="colophon">
  <p>Files stay on this machine: the PDF is uploaded to <code>storage/uploads</code>, recognition runs in the browser, and the JSON is written to <code>storage/output</code>.</p>
</footer>

<template id="tpl-page-row">
  <li class="pagelist__row" data-page>
    <span class="pagelist__num"></span>
    <span class="pagelist__state">queued</span>
    <span class="pagelist__meta"></span>
  </li>
</template>

<script id="app-config" type="application/json"><?= Http::encode($clientConfig) ?></script>
<script type="module" src="assets/js/app.js?v=1"></script>
</body>
</html>
