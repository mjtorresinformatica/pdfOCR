/**
 * Tesseract.js wrapper. One worker is created per document and reused for every
 * page that needs OCR, because loading the language data is the slow part.
 */

let ready = null;

function tesseract() {
  if (ready) return ready;

  ready = new Promise((resolve, reject) => {
    const attempt = (tries = 0) => {
      if (window.Tesseract) resolve(window.Tesseract);
      else if (tries > 100) reject(new Error('tesseract.js did not load. Check the network connection to the CDN.'));
      else setTimeout(() => attempt(tries + 1), 50);
    };
    attempt();
  });

  return ready;
}

export class OcrEngine {
  /**
   * @param {string}   language  Tesseract language code, e.g. "spa"
   * @param {function} onStatus  ({status, progress}) => void
   */
  constructor(language, onStatus = () => {}) {
    this.language = language;
    this.onStatus = onStatus;
    this.worker = null;
  }

  async start() {
    if (this.worker) return this.worker;

    const lib = await tesseract();
    this.worker = await lib.createWorker(this.language, 1, {
      logger: (message) => this.onStatus(message),
      errorHandler: (error) => console.error('[tesseract]', error),
    });

    return this.worker;
  }

  /** @returns {Promise<{words: Array, confidence: number|null}>} */
  async recognize(canvas) {
    const worker = await this.start();
    const { data } = await worker.recognize(canvas, {}, { text: true, blocks: true });

    return {
      words: collectWords(data),
      confidence: typeof data.confidence === 'number' ? Math.round(data.confidence * 100) / 100 : null,
    };
  }

  async stop() {
    if (!this.worker) return;
    await this.worker.terminate();
    this.worker = null;
  }
}

/**
 * Tesseract's output shape moved around between major versions: v5 exposes a
 * flat `words` array, v6 nests everything under `blocks`. Handle both, and fall
 * back to splitting line text when only line boxes came back.
 */
function collectWords(data) {
  if (Array.isArray(data.words) && data.words.length > 0) {
    return data.words.map(toWord).filter(Boolean);
  }

  const words = [];
  const lines = [];

  for (const block of data.blocks || []) {
    for (const paragraph of block.paragraphs || []) {
      for (const line of paragraph.lines || []) {
        lines.push(line);
        for (const word of line.words || []) {
          const mapped = toWord(word);
          if (mapped) words.push(mapped);
        }
      }
    }
  }

  if (words.length > 0) return words;

  for (const line of lines) {
    for (const word of splitLine(line)) words.push(word);
  }

  return words;
}

function toWord(word) {
  const text = (word.text || '').trim();
  if (!text || !word.bbox) return null;

  return {
    text,
    x0: word.bbox.x0,
    y0: word.bbox.y0,
    x1: word.bbox.x1,
    y1: word.bbox.y1,
    conf: typeof word.confidence === 'number' ? Math.round(word.confidence * 100) / 100 : null,
  };
}

function splitLine(line) {
  const text = (line.text || '').trim();
  if (!text || !line.bbox) return [];

  const width = line.bbox.x1 - line.bbox.x0;
  const chars = text.length || 1;
  const out = [];
  let cursor = 0;

  for (const token of text.split(/(\s+)/)) {
    if (token.length === 0) continue;
    if (token.trim() !== '') {
      out.push({
        text: token,
        x0: line.bbox.x0 + (width * cursor) / chars,
        y0: line.bbox.y0,
        x1: line.bbox.x0 + (width * (cursor + token.length)) / chars,
        y1: line.bbox.y1,
        conf: typeof line.confidence === 'number' ? line.confidence : null,
      });
    }
    cursor += token.length;
  }

  return out;
}
