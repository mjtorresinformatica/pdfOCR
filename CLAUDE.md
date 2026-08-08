# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**pdfOCR** turns a PDF into a structured JSON document. Drop a PDF on the page;
the text comes back as pages → blocks → lines (→ words), plus detected tables,
label/value pairs and typed entities (dates, amounts, NIF/CIF, IBAN, email,
phone). Every run is stored in MySQL so results can be reopened and, in the next
milestone, used to learn field mappings.

It replaces a single-file `index.html` (Tailwind + inline JS, plain text output).
The originals are kept, unmodified, in `legacy/` for reference.

## Stack and hard constraints

- **PHP 8.1+** (Laragon serves 8.3), no Composer. `src/autoload.php` is a
  hand-rolled PSR-4 loader for the `PdfOcr\` namespace.
- **MySQL via PDO** only. Prepared statements everywhere, `ERRMODE_EXCEPTION`,
  `EMULATE_PREPARES = false`.
- **Vanilla JS (ES modules) and vanilla CSS.** No framework, no build step, no
  bundler. Two CDN libraries only: pdf.js 3.6.172 and tesseract.js 5.1.1.
- **No server-side OCR binary.** Tesseract and Poppler's rasteriser are not
  installed on this machine, so recognition happens in the browser. Do not add a
  dependency on `exec()`/`shell_exec()`.

## Architecture: who does what

```
browser                                  PHP (src/)                MySQL
───────                                  ──────────                ─────
drop PDF ──► api/upload.php ───────────► Uploader (validate,     ocr_jobs
                                          hash, store)
pdf.js: text layer? ──yes──► words
        └─no──► render canvas ► tesseract.js ► words
                │
                └─ per page ─► api/ingest.php ──► DocumentStructurer  ocr_pages
                                                  EntityExtractor     ocr_blocks
                                                                      ocr_entities
             api/finalize.php ─────────────────► DocumentAssembler ─► storage/output/<job>.json
```

The split is deliberate: **the browser recognises, PHP structures.** All layout
analysis (line grouping, block segmentation, table detection, key/value
detection, entity typing) lives in PHP so it can be tuned, tested and reused by
a future batch/CLI ingester without a browser.

### Coordinate contract

Everything crossing the wire uses **PDF points with a top-left origin**.
`assets/js/pdf-processor.js` normalises both paths into that space (the text
layer via `pdfjsLib.Util.transform(viewport.transform, item.transform)`, OCR by
dividing canvas pixels by the raster scale). PHP assumes it and never converts.

## Layout

```
index.php                 page shell; hands config + session token to the client
api/
  bootstrap.php           shared setup: autoload, config, storage dirs, $connect, $runEndpoint
  upload.php              POST multipart  -> creates a job
  ingest.php              POST json       -> structures and stores one page
  finalize.php            POST json       -> assembles + writes the JSON document
  result.php              GET             -> rebuilds a stored document
  download.php            GET             -> the same JSON as a file attachment
  jobs.php                GET / DELETE    -> recent jobs, delete a job
src/
  autoload.php            PSR-4 loader
  Database.php            PDO holder; creates the database if it is missing
  Migrator.php            applies db/schema.sql (idempotent, stamped)
  Http.php                JSON responses, body parsing, same-origin + token checks
  Uploader.php            upload validation (size, extension, MIME, %PDF- magic)
  JobRepository.php       every SQL statement in the project
  DocumentStructurer.php  words -> lines -> blocks; tables; key/values
  EntityExtractor.php     regex + normalisation + checksum validation
  DocumentAssembler.php   DB rows -> the final JSON document
assets/css/app.css        all styling
assets/js/
  app.js                  orchestrates a run; owns the UI state
  api.js                  fetch wrappers
  pdf-processor.js        pdf.js: text layer or rasterise
  ocr.js                  tesseract.js worker wrapper
  dropzone.js             drop / click / keyboard / paste intake
  json-viewer.js          collapsible JSON tree with filtering
  views.js                page map, field tables, stats
config/config.php         all tunables; config.local.php overrides it (gitignored)
db/schema.sql             the schema, written to be re-runnable
storage/uploads|output    PDFs and JSON; blocked from the web by storage/.htaccess
legacy/                   the original index.html and img2PDF.html
```

## Running it

Laragon serves the folder at <http://localhost/pdfOCR/>. Nothing else is needed:
on first request `Database::pdo()` creates the `pdfocr` database if missing and
`Migrator` applies `db/schema.sql`.

```powershell
php -l src\DocumentStructurer.php                          # lint one file
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
mysql -u root pdfocr -e 'SHOW TABLES;'                     # Laragon: bin\mysql\...\bin\mysql.exe
```

To reset: drop the `pdfocr` database, delete `storage/schema.applied`, reload.

## Output shape (`schema_version` 1.0)

```jsonc
{
  "schema_version": "1.0",
  "generated_at": "2026-08-08T22:59:41+02:00",
  "job":      { "id", "file_name", "size_bytes", "sha256", "language", "engine", "created_at" },
  "document": { "page_count", "source", "mean_confidence", "title", "text" },
  "summary":  { "word_count", "char_count", "line_count", "block_count", "table_count", "key_value_count" },
  "key_values": [ { "key": "fecha", "label": "Fecha", "value": "12/03/2025", "occurrences": [ … ] } ],
  "tables":     [ { "page", "columns", "column_anchors", "header": [ … ], "rows": [ [ … ] ] } ],
  "entities":   { "date": [ { "page", "raw", "value" } ], "amount": [ … ], "iban": [ … ] },
  "pages":      [ { "number", "width", "height", "unit": "pt", "rotation", "source",
                    "confidence", "word_count", "text",
                    "blocks": [ { "id": "p1-b3", "type", "text", "bbox", "rel_bbox",
                                  "confidence", "line_count", "lines": [ … ] } ],
                    "tables": [ … ], "key_values": [ … ] } ],
  "warnings":   [ "Page 2 produced no text." ]
}
```

- `source` is `text_layer`, `ocr`, `mixed` or `empty`; `confidence` is `null` for
  text-layer pages, because nothing was guessed.
- `bbox` is absolute points; `rel_bbox` is `{x,y,w,h}` in 0–1 of the page, which
  is what the page map draws.
- Word-level boxes are omitted unless the run asks for them (checkbox in the UI,
  `include_words` on finalize, `?words=1` on result/download). They roughly
  triple the file size.

Bump `app.schema_version` in `config/config.php` when the shape changes.

## Structuring heuristics (and their knobs)

All in `config.php` under `structure`:

| Knob | Meaning |
| --- | --- |
| `text_layer_min_chars` | Below this many characters, a page is rasterised and OCR'd instead of trusting its text layer. |
| `line_tolerance` | Vertical distance, in median glyph heights, for two words to share a line. |
| `block_gap_factor` | Vertical gap, in median line heights, that starts a new block. |
| `kv_block_ratio` | Share of lines matching `Label: value` for a block to be typed `key_value`. |

Block types: `heading`, `paragraph`, `list`, `key_value`, `table`. Blocks also
split when the font size changes between lines — that rule only fires when both
lines report a size, which is true for the text layer and not for OCR, where
glyph heights swing with ascenders and descenders. This is why headings separate
cleanly in digital PDFs and sometimes merge into the block below in scans.

Table detection needs at least two lines whose words split into the same number
of wide-gap segments; columns are then snapped to median x anchors.

## Conventions

- PHP: `declare(strict_types=1)`, typed properties and signatures, one class per
  file, constructor property promotion. Comments explain *why*, not *what*.
- SQL: every statement lives in `JobRepository`. **Never repeat a named
  placeholder** in one statement — native prepares reject it (use `:created_at`
  and `:updated_at`, or a correlated subquery, as `savePage()` does).
- JS: ES modules, no globals except the two CDN libraries. Build DOM with
  `createElement`/`textContent`; **never `innerHTML`** with document content —
  OCR text is untrusted input.
- CSS: custom properties at `:root`, dark mode via `prefers-color-scheme`,
  reduced motion respected. No utility-class framework.
- Endpoints: `POST` for writes, same-origin check, session token, and errors
  returned as `{ ok: false, error }` — never a bare 500, so the dropzone can
  always say what went wrong.

## Known limitations

- Rotated or vertical text is not handled: the text-layer path assumes an
  upright baseline.
- Multi-column pages are read in raw top-to-bottom order; there is no column
  detection yet, so a two-column page interleaves.
- Tesseract occasionally drops very large display type and mangles `@` in
  scanned email addresses. Confidence in the JSON is the signal to check.
- A run is bound to one browser tab: closing it mid-way leaves the job in
  `processing`. Nothing cleans those up yet.

## Next milestone: learning the fields

The data model is already prepared for it and deliberately unused:

- `ocr_entities` stores every key/value candidate (`kind = 'key_value'`) and
  typed entity (`kind = 'entity'`) with its page and bounding box.
- `ocr_field_labels` is empty and waiting: `document_type`, `field_name`,
  `field_value`, `origin` (`manual` | `rule` | `model`), with an FK to the
  entity a label came from.

The intended flow is: classify the document type, let a user confirm or correct
which candidate maps to which business field, store that in `ocr_field_labels`,
then derive rules (label synonyms, position, entity type) to pre-fill the next
document of the same type. The **Fields** tab in the UI is the surface this will
grow from. Do not start this work unless asked.
