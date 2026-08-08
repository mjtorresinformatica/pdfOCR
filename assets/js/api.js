/**
 * Thin wrapper over the /api endpoints. Every call returns parsed JSON and
 * throws an Error carrying the server's message, so callers can show it as-is.
 */

const config = JSON.parse(document.getElementById('app-config').textContent);

export const appConfig = config;

async function parse(response) {
  const text = await response.text();
  let data;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(`The server returned a non-JSON response (HTTP ${response.status}).`);
  }

  if (!response.ok || data.ok === false) {
    const detail = data.detail ? ` (${data.detail})` : '';
    throw new Error((data.error || `Request failed with HTTP ${response.status}.`) + detail);
  }

  return data;
}

export async function uploadPdf(file, language) {
  const body = new FormData();
  body.append('file', file);
  body.append('language', language);
  body.append('token', config.token);

  return parse(await fetch('api/upload.php', { method: 'POST', body }));
}

export async function ingestPage(jobId, page, extra = {}) {
  const response = await fetch('api/ingest.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ job_id: jobId, token: config.token, page, ...extra }),
  });

  return parse(response);
}

export async function finalize(jobId, { durationMs = null, includeWords = false, error = null } = {}) {
  const response = await fetch('api/finalize.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      job_id: jobId,
      token: config.token,
      duration_ms: durationMs,
      include_words: includeWords,
      ...(error ? { error } : {}),
    }),
  });

  return parse(response);
}

export async function fetchResult(jobId, includeWords = false) {
  return parse(await fetch(`api/result.php?job=${encodeURIComponent(jobId)}${includeWords ? '&words=1' : ''}`));
}

export async function listJobs(limit = 12) {
  return parse(await fetch(`api/jobs.php?limit=${limit}`));
}

export async function deleteJob(jobId) {
  const url = `api/jobs.php?job=${encodeURIComponent(jobId)}&token=${encodeURIComponent(config.token)}`;

  return parse(await fetch(url, { method: 'DELETE' }));
}

export function downloadUrl(jobId, includeWords = false) {
  return `api/download.php?job=${encodeURIComponent(jobId)}${includeWords ? '&words=1' : ''}`;
}
