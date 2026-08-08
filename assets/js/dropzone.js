/**
 * Drop target for a single PDF. Accepts a drop, a click, the keyboard, or a
 * paste, and hands back one validated File.
 */

export function createDropzone({ zone, input, browseButton, maxBytes, onFile, onReject }) {
  let depth = 0;
  let busy = false;

  const reject = (message) => onReject?.(message);

  const validate = (file) => {
    if (!file) {
      reject('No file was found in that drop.');

      return null;
    }

    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    if (!isPdf) {
      reject(`${file.name} is not a PDF.`);

      return null;
    }
    if (file.size === 0) {
      reject(`${file.name} is empty.`);

      return null;
    }
    if (file.size > maxBytes) {
      reject(`${file.name} is ${formatBytes(file.size)}; the limit is ${formatBytes(maxBytes)}.`);

      return null;
    }

    return file;
  };

  const hand = (file) => {
    const valid = validate(file);
    if (valid) onFile(valid);
  };

  const setOver = (over) => {
    zone.classList.toggle('is-over', over && !busy);
  };

  ['dragenter', 'dragover'].forEach((type) => {
    zone.addEventListener(type, (event) => {
      event.preventDefault();
      if (type === 'dragenter') depth += 1;
      setOver(true);
    });
  });

  zone.addEventListener('dragleave', (event) => {
    event.preventDefault();
    depth = Math.max(0, depth - 1);
    if (depth === 0) setOver(false);
  });

  zone.addEventListener('drop', (event) => {
    event.preventDefault();
    depth = 0;
    setOver(false);
    if (busy) return;

    const items = event.dataTransfer?.files;
    if (!items || items.length === 0) {
      reject('That drop carried no file.');

      return;
    }
    if (items.length > 1) reject('One PDF at a time — using the first one.');

    hand(items[0]);
  });

  // Keep the browser from opening a PDF dropped next to the zone.
  ['dragover', 'drop'].forEach((type) => {
    window.addEventListener(type, (event) => {
      if (!zone.contains(event.target)) event.preventDefault();
    });
  });

  const open = () => {
    if (!busy) input.click();
  };

  zone.addEventListener('click', (event) => {
    if (event.target !== browseButton) open();
  });
  browseButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    open();
  });

  zone.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      open();
    }
  });

  input.addEventListener('change', () => {
    if (input.files?.length) hand(input.files[0]);
    input.value = '';
  });

  document.addEventListener('paste', (event) => {
    const file = [...(event.clipboardData?.files || [])][0];
    if (file) hand(file);
  });

  return {
    setBusy(value) {
      busy = value;
      zone.classList.toggle('is-busy', value);
      zone.setAttribute('aria-disabled', String(value));
    },
  };
}

export function formatBytes(bytes) {
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
}
