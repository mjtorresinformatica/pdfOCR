/**
 * Reads a PDF page by page and returns positioned words.
 *
 * Two paths, chosen per page:
 *   - the PDF carries a text layer  -> read it directly (exact, instant)
 *   - the page is a scan            -> rasterise and hand the canvas to OCR
 *
 * Both paths emit the same shape, in PDF points with a top-left origin:
 *   { text, x0, y0, x1, y1, conf, size }
 */

const PDFJS_VERSION = '3.6.172';
const TARGET_DPI = 220;
const MAX_RASTER_PX = 2600;

let ready = null;

/** Waits for the deferred CDN script and configures the worker once. */
export function pdfjs() {
  if (ready) return ready;

  ready = new Promise((resolve, reject) => {
    const attempt = (tries = 0) => {
      if (window.pdfjsLib) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc =
          `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.worker.min.js`;
        resolve(window.pdfjsLib);
      } else if (tries > 100) {
        reject(new Error('pdf.js did not load. Check the network connection to the CDN.'));
      } else {
        setTimeout(() => attempt(tries + 1), 50);
      }
    };
    attempt();
  });

  return ready;
}

export async function openPdf(arrayBuffer) {
  const lib = await pdfjs();

  return lib.getDocument({ data: arrayBuffer }).promise;
}

/**
 * @param {object} pdf        pdf.js document
 * @param {number} pageNumber 1-based
 * @param {object} options    { minChars, ocr }  ocr: async (canvas, scale) => {words, confidence}
 */
export async function extractPage(pdf, pageNumber, { minChars = 120, ocr = null } = {}) {
  const lib = await pdfjs();
  const page = await pdf.getPage(pageNumber);
  const viewport = page.getViewport({ scale: 1 });

  const base = {
    number: pageNumber,
    width: round(viewport.width),
    height: round(viewport.height),
    rotation: page.rotate || 0,
  };

  const layerWords = await textLayerWords(lib, page, viewport);
  const charCount = layerWords.reduce((total, word) => total + word.text.length, 0);

  if (charCount >= minChars || (charCount > 0 && !ocr)) {
    page.cleanup();

    return { ...base, source: 'text_layer', words: layerWords };
  }

  if (!ocr) {
    page.cleanup();

    return { ...base, source: 'empty', words: [] };
  }

  const { canvas, scale } = await rasterise(page, viewport);
  const { words, confidence } = await ocr(canvas, pageNumber);
  canvas.width = 0;
  canvas.height = 0;
  page.cleanup();

  return {
    ...base,
    source: 'ocr',
    confidence,
    words: words.map((word) => ({
      text: word.text,
      x0: round(word.x0 / scale),
      y0: round(word.y0 / scale),
      x1: round(word.x1 / scale),
      y1: round(word.y1 / scale),
      conf: word.conf,
    })),
  };
}

/**
 * Turns text items into word boxes. pdf.js gives one box per run of text, so
 * each run is split on whitespace and its width shared out by character count —
 * close enough for layout grouping, and exact for the text itself.
 */
async function textLayerWords(lib, page, viewport) {
  const content = await page.getTextContent();
  const words = [];

  for (const item of content.items) {
    if (!item.str || !item.str.trim()) continue;

    const m = lib.Util.transform(viewport.transform, item.transform);
    const fontHeight = Math.hypot(m[2], m[3]) || Math.abs(m[3]) || 10;
    const runWidth = item.width || fontHeight * item.str.length * 0.5;
    const left = m[4];
    const baseline = m[5];
    const top = baseline - fontHeight * 0.8;
    const bottom = baseline + fontHeight * 0.2;
    const chars = item.str.length;

    let cursor = 0;
    for (const token of item.str.split(/(\s+)/)) {
      if (token.length === 0) continue;

      if (token.trim() !== '') {
        words.push({
          text: token,
          x0: round(left + (runWidth * cursor) / chars),
          y0: round(top),
          x1: round(left + (runWidth * (cursor + token.length)) / chars),
          y1: round(bottom),
          size: round(fontHeight),
        });
      }
      cursor += token.length;
    }
  }

  return words;
}

async function rasterise(page, baseViewport) {
  let scale = TARGET_DPI / 72;
  const longest = Math.max(baseViewport.width, baseViewport.height) * scale;
  if (longest > MAX_RASTER_PX) scale *= MAX_RASTER_PX / longest;

  const viewport = page.getViewport({ scale });
  const canvas = document.createElement('canvas');
  canvas.width = Math.ceil(viewport.width);
  canvas.height = Math.ceil(viewport.height);

  const context = canvas.getContext('2d');
  context.fillStyle = '#ffffff'; // Tesseract reads dark-on-light best.
  context.fillRect(0, 0, canvas.width, canvas.height);

  await page.render({ canvasContext: context, viewport }).promise;

  return { canvas, scale };
}

function round(value) {
  return Math.round(value * 100) / 100;
}
