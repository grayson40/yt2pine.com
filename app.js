const EXAMPLE_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

// State: idle | fetching_transcript | generating_script | done | error
let state = 'idle';
let currentTranscript = '';
let currentScript = '';

const $ = id => document.getElementById(id);

// ─── Progress steps ────────────────────────────────────────
function setStep(active) {
  // active: 0 = none, 1 = fetching, 2 = generating, 3 = done
  const steps = [$('step1'), $('step2'), $('step3')];
  steps.forEach((el, i) => {
    el.classList.remove('active', 'done');
    if (i + 1 === active) el.classList.add('active');
    else if (i + 1 < active) el.classList.add('done');
  });
}

// ─── State machine ─────────────────────────────────────────
function setState(newState, message = '') {
  state = newState;
  const status = $('status');
  status.className = '';
  status.innerHTML = '';

  const mainEl = $('main-content');
  if (mainEl) {
    const busy = newState === 'fetching_transcript' || newState === 'generating_script';
    mainEl.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  const skeleton = $('resultsSkeletonWrap');

  if (newState === 'fetching_transcript') {
    setStep(1);
    status.className = 'loading';
    status.innerHTML = `<span class="spinner" aria-hidden="true"></span><span>${message}</span>`;
    $('generateBtn').disabled = true;
    skeleton.classList.remove('visible');
  } else if (newState === 'generating_script') {
    setStep(2);
    status.className = 'loading';
    status.innerHTML = `<span class="spinner" aria-hidden="true"></span><span>${message}</span>`;
    $('generateBtn').disabled = true;
    skeleton.classList.add('visible');
  } else if (newState === 'error') {
    setStep(0);
    status.className = 'error';
    status.textContent = message;
    $('generateBtn').disabled = false;
    skeleton.classList.remove('visible');
    updateGenerateBtn();
  } else if (newState === 'done') {
    setStep(3);
    $('generateBtn').disabled = false;
    skeleton.classList.remove('visible');
    updateGenerateBtn();
  } else {
    // idle
    setStep(0);
    $('generateBtn').disabled = false;
    skeleton.classList.remove('visible');
    updateGenerateBtn();
  }
}

function updateGenerateBtn() {
  $('generateBtn').disabled = $('urlInput').value.trim() === '';
}

// ─── Toast ─────────────────────────────────────────────────
let toastTimer;
function showToast(message) {
  const toast = $('toast');
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
}

// ─── Error messages ────────────────────────────────────────
function friendlyError(err) {
  if (!err) return 'Unknown error. Please try again.';
  if (err.includes('No captions')) return "This video doesn't have captions. Try a video with subtitles enabled.";
  if (err.includes('fetch') || err.includes('network') || err.toLowerCase().includes('failed to fetch'))
    return 'Connection failed. Check your internet and try again.';
  return err;
}

function isYouTubeUrl(url) {
  return /(?:youtube\.com|youtu\.be)/.test(url);
}

// ─── Generate ──────────────────────────────────────────────
async function generate() {
  const url = $('urlInput').value.trim();
  if (!isYouTubeUrl(url)) {
    setState('error', 'Please enter a valid YouTube URL.');
    return;
  }

  setState('fetching_transcript', 'Fetching video transcript…');

  let transcriptRes;
  try {
    const r = await fetch('api/transcript.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url }),
    });
    transcriptRes = await r.json();
  } catch {
    setState('error', friendlyError('failed to fetch'));
    return;
  }

  if (!transcriptRes.success) {
    setState('error', friendlyError(transcriptRes.error));
    return;
  }

  currentTranscript = transcriptRes.transcript;
  $('transcriptOutput').textContent = currentTranscript;

  setState('generating_script', 'Generating PineScript strategy… (30–60 seconds)');

  let generateRes;
  try {
    const r = await fetch('api/generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ transcript: currentTranscript }),
    });
    generateRes = await r.json();
  } catch {
    setState('error', friendlyError('failed to fetch'));
    return;
  }

  if (!generateRes.success) {
    setState('error', friendlyError(generateRes.error));
    return;
  }

  currentScript = generateRes.script;
  $('codeOutput').textContent = currentScript;
  $('explanation').textContent = generateRes.explanation || '';
  $('results').hidden = false;
  const howto = $('howtoSection');
  if (howto) howto.hidden = false;
  setState('done');
  $('results').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─── Refine ────────────────────────────────────────────────
async function refine() {
  const instruction = $('refineInput').value.trim();
  if (!instruction || !currentScript) return;

  $('refineBtn').disabled = true;
  setState('generating_script', 'Refining strategy…');

  let res;
  try {
    const r = await fetch('api/generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ transcript: instruction, existingScript: currentScript }),
    });
    res = await r.json();
  } catch {
    setState('error', friendlyError('failed to fetch'));
    $('refineBtn').disabled = false;
    return;
  }

  if (!res.success) {
    setState('error', friendlyError(res.error));
    $('refineBtn').disabled = false;
    return;
  }

  currentScript = res.script;
  $('codeOutput').textContent = currentScript;
  $('explanation').textContent = res.explanation || '';
  $('refineInput').value = '';
  $('refineBtn').disabled = false;
  setState('done');
  showToast('Strategy updated');
}

// ─── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  $('urlInput').addEventListener('input', updateGenerateBtn);
  $('urlInput').addEventListener('keydown', e => { if (e.key === 'Enter') generate(); });
  $('generateBtn').addEventListener('click', generate);

  $('exampleBtn').addEventListener('click', () => {
    $('urlInput').value = EXAMPLE_URL;
    updateGenerateBtn();
  });

  $('copyBtn').addEventListener('click', () => {
    if (!currentScript) return;
    navigator.clipboard.writeText(currentScript).then(() => {
      showToast('Copied to clipboard!');
    }).catch(() => {
      // Fallback for non-secure contexts
      const ta = document.createElement('textarea');
      ta.value = currentScript;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      showToast('Copied to clipboard!');
    });
  });

  $('refineBtn').addEventListener('click', refine);
  $('refineInput').addEventListener('keydown', e => { if (e.key === 'Enter') refine(); });

  updateGenerateBtn();
});
