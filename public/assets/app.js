const config = JSON.parse(document.getElementById('config').textContent);
const strings = config.strings;
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- Example carousel ---------- */

const slides = [...document.querySelectorAll('.slide')];
const heroInput = document.getElementById('ask-hero');
const toggleButton = document.querySelector('[data-carousel="toggle"]');
const credit = document.querySelector('[data-credit]');
let currentSlide = 0;
let carouselTimer = null;

// Photos other than the first wait in data-src until their slide is up next.
function loadPhoto(slide) {
  const photo = slide.querySelector('img[data-src]');
  if (!photo) return;
  photo.src = photo.dataset.src;
  photo.removeAttribute('data-src');
}

// The suggestion in the hero box is drawn as words so that it can roll over.
const prompt = element('span', 'prompt');
prompt.setAttribute('aria-hidden', 'true');
heroInput.after(prompt);
heroInput.form.classList.add('has-prompt');

function showPrompt(text, animate) {
  const line = element('span', 'prompt-line');
  const track = element('span', 'prompt-track');
  const copy = element('span', 'prompt-text');
  copy.append(...text.split(' ').flatMap((word) => [element('span', '', word), ' ']));
  track.append(copy);
  line.append(track);

  const leaving = [...prompt.children];
  prompt.append(line);
  const hidden = { opacity: 0, filter: 'blur(6px)' };
  if (animate) {
    for (const old of leaving) {
      old.getAnimations({ subtree: true }).forEach((animation) => animation.cancel());
      old.classList.remove('marquee');
      old.animate({ transform: ['none', 'translateY(-100%)'], opacity: [1, 0], filter: ['none', 'blur(6px)'] },
        { duration: 300, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', fill: 'forwards' })
        .finished.then(() => old.remove(), () => old.remove());
    }
    [...copy.children].forEach((word, i) => word.animate(
      [{ transform: 'translateY(100%)', ...hidden }, { transform: 'none', opacity: 1, filter: 'none' }],
      { duration: 420, delay: 120 + i * 28, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', fill: 'backwards' },
    ));
  } else {
    leaving.forEach((old) => old.remove());
  }

  // Too long for the box: a second copy follows the first around.
  if (copy.offsetWidth > prompt.clientWidth && !reducedMotion) {
    track.append(copy.cloneNode(true));
    line.style.setProperty('--marquee-duration', `${(copy.offsetWidth / 40).toFixed(1)}s`);
    line.classList.add('marquee');
  }
}

function slideAcross(from, to, direction) {
  for (const slide of slides) {
    slide.getAnimations().forEach((animation) => animation.cancel());
    slide.classList.remove('leaving');
  }
  from.classList.add('leaving');
  const timing = { duration: 1000, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' };
  to.animate({ transform: [`translateX(${direction * 100}%)`, 'none'] }, timing);
  from.animate({ transform: ['none', `translateX(${direction * -100}%)`] }, timing)
    .finished.then(() => from.classList.remove('leaving'), () => {});
}

function showSlide(index, direction = 0) {
  const previous = slides[currentSlide];
  currentSlide = (index + slides.length) % slides.length;
  loadPhoto(slides[currentSlide]);
  loadPhoto(slides[(currentSlide + 1) % slides.length]);
  if (credit) {
    const [name, profile, page] = config.credits[currentSlide] ?? [];
    const author = credit.querySelector('[data-credit-author]');
    credit.hidden = !name;
    author.textContent = name ?? '';
    author.href = profile ?? '';
    credit.querySelector('[data-credit-photo]').href = page ?? '';
  }
  slides.forEach((slide, i) => {
    const isCurrent = i === currentSlide;
    slide.classList.toggle('current', isCurrent);
    slide.toggleAttribute('aria-hidden', !isCurrent);
    slide.tabIndex = isCurrent ? 0 : -1;
  });
  if (direction && !reducedMotion && previous !== slides[currentSlide]) {
    slideAcross(previous, slides[currentSlide], direction);
  }
  heroInput.placeholder = strings.try.replace('%s', config.examples[currentSlide]);
  showPrompt(heroInput.placeholder, direction !== 0 && !reducedMotion);
}

function setPlaying(playing) {
  clearInterval(carouselTimer);
  carouselTimer = playing ? setInterval(() => showSlide(currentSlide + 1, 1), 4500) : null;
  toggleButton.classList.toggle('paused', !playing);
  toggleButton.setAttribute('aria-label', playing ? strings.pause : strings.play);
}

document.querySelector('[data-carousel="prev"]').addEventListener('click', () => showSlide(currentSlide - 1, -1));
document.querySelector('[data-carousel="next"]').addEventListener('click', () => showSlide(currentSlide + 1, 1));
toggleButton.addEventListener('click', () => setPlaying(!carouselTimer));
slides.forEach((slide, i) => slide.addEventListener('click', () => ask(config.examples[i])));
loadPhoto(slides[1]);
showPrompt(heroInput.placeholder, false);
setPlaying(!reducedMotion);

/* ---------- Statement comes into focus as it scrolls past ---------- */

const statement = document.querySelector('.statement');
const words = [...statement.querySelectorAll('.word')];

// Each chip plays through its phases; the stylesheet animates each one.
const chipPhases = {
  flag: [['playing', 3000], ['resetting', 240]],
  scan: [['scanning', 550], ['done', 1600]],
};

function playChip(chip) {
  if (reducedMotion || chip.dataset.phase) return;
  const phases = [...chipPhases[chip.dataset.chip]];
  const next = () => {
    const [phase, duration] = phases.shift() ?? [];
    if (!phase) return chip.removeAttribute('data-phase');
    chip.dataset.phase = phase;
    setTimeout(next, duration);
  };
  next();
}

statement.querySelectorAll('[data-chip]').forEach((chip) => {
  chip.addEventListener('pointerenter', () => playChip(chip));
  chip.addEventListener('click', () => playChip(chip));
});

function focusWords() {
  const box = statement.getBoundingClientRect();
  // 0 when the section's top reaches 80% down the viewport, 1 when its middle reaches 45%.
  const start = innerHeight * 0.8;
  const end = innerHeight * 0.45 - box.height / 2;
  const progress = Math.min(1, Math.max(0, (start - box.top) / (start - end)));
  const lit = Math.round(progress * words.length);
  words.forEach((word, i) => {
    const arriving = i < lit && !word.classList.contains('on');
    word.classList.toggle('on', i < lit);
    const chip = arriving && word.querySelector('[data-chip]');
    if (chip) setTimeout(() => playChip(chip), 350);
  });
}
addEventListener('scroll', focusWords, { passive: true });
addEventListener('resize', focusWords);
focusWords();

/* ---------- Docked ask bar, menu ---------- */

const dock = document.querySelector('[data-dock]');
new IntersectionObserver(([entry]) => {
  dock.toggleAttribute('data-shown', !entry.isIntersecting);
}).observe(heroInput.form);

// Looping decoration runs only while it is on screen, and can be paused.
const motionWatcher = new IntersectionObserver((entries) => {
  for (const entry of entries) entry.target.toggleAttribute('data-offscreen', !entry.isIntersecting);
});
document.querySelectorAll('[data-motion]').forEach((node) => motionWatcher.observe(node));

document.querySelector('[data-pause-tile]').addEventListener('click', (event) => {
  const button = event.currentTarget;
  const paused = button.closest('.tile').classList.toggle('paused');
  button.setAttribute('aria-label', paused ? strings.play_motion : strings.pause_motion);
});

// The lock clicks shut each time it comes into view.
new IntersectionObserver(([entry]) => {
  if (entry.intersectionRatio >= 0.6) entry.target.setAttribute('data-visible', '');
  else if (!entry.isIntersecting) entry.target.removeAttribute('data-visible');
}, { threshold: [0, 0.6] }).observe(document.querySelector('[data-lock]'));

const menu = document.getElementById('menu');
document.querySelector('[data-open-menu]').addEventListener('click', () => menu.showModal());
menu.addEventListener('click', (event) => {
  if (event.target === menu || event.target.closest('[data-close-menu]')) menu.close();
});
document.querySelector('[data-focus-ask]').addEventListener('click', () => {
  setTimeout(() => heroInput.focus({ preventScroll: true }), 0);
});

/* ---------- Answers: a small, safe Markdown renderer ---------- */

function isOfficial(url) {
  try {
    const { protocol, hostname } = new URL(url);
    return protocol === 'https:'
      && config.officialDomains.some((domain) => hostname === domain || hostname.endsWith('.' + domain));
  } catch {
    return false;
  }
}

function escapeHtml(text) {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Pages the server has retrieved in this conversation. The model is told to
// link only to these; an address it made up is not among them.
const retrieved = new Set();
const pageKey = (url) => url.replace(/#.*$/, '').replace(/\/$/, '');

// Links survive only when they point at an official page the answer was drawn from.
function link(escapedUrl, label) {
  const url = escapedUrl.replaceAll('&amp;', '&');
  return isOfficial(url) && retrieved.has(pageKey(url))
    ? `<a href="${escapedUrl}" target="_blank" rel="noopener">${label}</a>`
    : label;
}

function renderInline(text) {
  return escapeHtml(text)
    .replace(/\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/g, (_, label, url) => link(url, label))
    .replace(/(^|[\s(])(https:\/\/[^\s<)]*[^\s<).,;:!?])/g, (_, before, url) => before + link(url, url))
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
}

function renderMarkdown(markdown) {
  const html = [];
  let list = null;
  let paragraph = [];
  const flush = () => {
    // Joined by line breaks, which read as spaces except in a poem.
    if (paragraph.length) html.push(`<p>${paragraph.join('\n')}</p>`);
    if (list) html.push(`<${list.tag}>${list.items.map((item) => `<li>${item}</li>`).join('')}</${list.tag}>`);
    paragraph = [];
    list = null;
  };

  for (const line of markdown.split('\n')) {
    const heading = line.match(/^#{1,6}\s+(.*)/);
    const item = line.match(/^\s*(?:(\d+)[.)]|[-*•])\s+(.*)/);
    if (heading) {
      flush();
      html.push(`<h3>${renderInline(heading[1])}</h3>`);
    } else if (item) {
      const tag = item[1] ? 'ol' : 'ul';
      if (paragraph.length || (list && list.tag !== tag)) flush();
      list ??= { tag, items: [] };
      list.items.push(renderInline(item[2]));
    } else if (line.trim() === '') {
      // A blank line between numbered steps should not restart the numbering.
      if (paragraph.length) flush();
    } else if (list && /^\s{2,}/.test(line)) {
      list.items[list.items.length - 1] += ' ' + renderInline(line.trim());
    } else {
      if (list) flush();
      paragraph.push(renderInline(line.trim()));
    }
  }
  flush();
  return html.join('');
}

/* ---------- Conversation (kept in memory only; gone when the tab closes) ---------- */

const chat = document.getElementById('chat');
const thread = chat.querySelector('[data-thread]');
const chatInput = document.getElementById('ask-chat');
let messages = [];
let request = null;

// Whether the thread is scrolled to (about) its end, and so should follow new text down.
const isPinned = () => thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;
const scrollToEnd = () => { thread.scrollTop = thread.scrollHeight; };

function element(tag, className, text) {
  const node = document.createElement(tag);
  node.className = className;
  if (text) node.textContent = text;
  return node;
}

function sourcesList(sources) {
  const list = element('ul', 'sources');
  list.hidden = true;
  for (const [i, source] of sources.entries()) {
    const anchor = element('a', '', source.title);
    anchor.href = source.url;
    anchor.target = '_blank';
    anchor.rel = 'noopener';
    anchor.append(element('small', '', `${source.org} · ${new URL(source.url).hostname.replace(/^www\./, '')}`));
    const item = document.createElement('li');
    item.style.setProperty('--i', i);
    item.append(anchor);
    list.append(item);
  }
  return list;
}

/** The row under an answer: a button that opens its sources, and one that copies it. */
function answerTools(text, sources) {
  const tools = element('div', 'tools');
  // The pages the answer links to; all that were read, if it links to none.
  const cited = sources.filter((source) => text.includes(source.url));
  const list = sourcesList(cited.length ? cited : sources);
  if (list.children.length) {
    const toggle = element('button', 'tool', `${strings.sources} · ${list.children.length}`);
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
      list.hidden = !list.hidden;
      toggle.setAttribute('aria-expanded', String(!list.hidden));
    });
    tools.append(toggle);
  }
  if (navigator.clipboard) {
    const copy = element('button', 'tool', strings.copy);
    copy.type = 'button';
    copy.addEventListener('click', async () => {
      await navigator.clipboard.writeText(text);
      copy.textContent = strings.copied;
      setTimeout(() => { copy.textContent = strings.copy; }, 2000);
    });
    tools.append(copy);
  }
  return [tools, list];
}

/** The photo a poem ends on, with its caption and credit. */
function photoFigure(photo) {
  const figure = element('figure', 'answer-photo');
  const image = element('img', '');
  image.src = photo.src;
  image.alt = '';
  image.width = photo.width;
  image.height = photo.height;
  const caption = element('figcaption', '', photo.alt);
  if (photo.credit) caption.append(' ', element('small', '', photo.credit));
  figure.append(image, caption);
  return figure;
}

function followupList(questions) {
  const list = element('div', 'followups');
  for (const question of questions) {
    const button = element('button', 'followup', question);
    button.type = 'button';
    button.addEventListener('click', () => ask(question));
    list.append(button);
  }
  return list;
}

/** Read a server-sent event stream, calling onEvent(name, data) for each event. */
async function readEvents(response, onEvent) {
  const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
  let buffer = '';
  for (;;) {
    const { value, done } = await reader.read();
    if (done) return;
    buffer += value;
    let end;
    while ((end = buffer.indexOf('\n\n')) !== -1) {
      const block = buffer.slice(0, end);
      buffer = buffer.slice(end + 2);
      const name = block.match(/^event: (.*)$/m)?.[1];
      const data = block.match(/^data: (.*)$/m)?.[1];
      if (name && data) onEvent(name, JSON.parse(data));
    }
  }
}

/* Opening and closing: the ask bar the question came from travels down to become the
   composer, and travels back when the conversation closes. The browser draws the trip
   (a view transition); the stylesheet times it. Without that, the dialog simply opens. */
const composerForm = chatInput.form;
const askBars = [heroInput.form, dock.querySelector('.ask'), composerForm];
const canMorph = !reducedMotion && 'startViewTransition' in document;
document.documentElement.classList.toggle('can-morph', canMorph);
let journey = null;

const nameBar = (bar) => askBars.forEach((other) => { other.style.viewTransitionName = other === bar ? 'ask' : ''; });
// The bar on the page right now: the docked one once the hero has scrolled away.
const pageBar = () => (dock.hasAttribute('data-shown') ? askBars[1] : askBars[0]);

function travel(from, to, closing, change) {
  if (!canMorph) return change();
  nameBar(from);
  document.documentElement.classList.toggle('chat-closing', closing);
  const transition = document.startViewTransition(() => {
    change();
    nameBar(to);
  });
  journey = transition;
  transition.finished.finally(() => {
    if (journey !== transition) return;
    nameBar(null);
    document.documentElement.classList.remove('chat-closing');
  });
}

function openChat() {
  if (chat.open) return chatInput.focus();
  travel(pageBar(), composerForm, false, () => {
    chat.showModal();
    chatInput.focus();
    scrollToEnd();
  });
}

function closeChat() {
  if (chat.open) travel(composerForm, pageBar(), true, () => chat.close());
}

async function ask(question) {
  question = question.trim();
  if (!question || request) return;
  openChat();

  thread.querySelector('.followups')?.remove();
  messages.push({ role: 'user', content: question });
  const answer = element('div', 'answer pending');
  answer.append(element('span', 'shimmer', strings.thinking));
  thread.append(element('p', 'question', question), answer);
  answer.scrollIntoView({ block: 'end' });

  let text = '';
  let sources = [];
  let followups = [];
  let photo = null;
  let failure = null;
  // Deltas often arrive several to a frame; the answer is drawn once per frame.
  let frame = 0;
  const draw = () => {
    frame = 0;
    const pinned = isPinned();
    answer.innerHTML = renderMarkdown(text);
    if (pinned) scrollToEnd();
  };
  const controller = new AbortController();
  request = controller;
  try {
    const response = await fetch('api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ lang: config.lang, messages }),
      signal: controller.signal,
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    await readEvents(response, (name, data) => {
      if (name === 'sources') {
        sources = data.filter((source) => isOfficial(source.url));
        sources.forEach((source) => retrieved.add(pageKey(source.url)));
      }
      if (name === 'followups') followups = data;
      if (name === 'poem') {
        answer.classList.add('poem');
        photo = data.photo;
      }
      if (name === 'error') failure = data.message;
      if (name === 'delta') {
        text += data.text;
        if (answer.classList.replace('pending', 'arrived')) {
          answer.addEventListener('animationend', () => answer.classList.remove('arrived'), { once: true });
        }
        frame ||= requestAnimationFrame(draw);
      }
    });
  } catch (error) {
    if (error.name === 'AbortError') return;
    failure ??= error.message;
  } finally {
    if (request === controller) request = null;
    cancelAnimationFrame(frame);
  }
  if (frame) draw();

  if (text) {
    messages.push({ role: 'assistant', content: text });
    const pinned = isPinned();
    if (photo && !failure) answer.append(photoFigure(photo));
    answer.after(...answerTools(text, sources));
    if (followups.length && !failure) thread.append(followupList(followups));
    if (pinned) scrollToEnd();
  } else {
    messages.pop();
  }
  if (failure || !text) {
    // After a partial answer the notice goes below it; otherwise it takes the answer's place.
    const notice = text ? element('div', '') : answer;
    notice.className = 'answer failed';
    notice.textContent = failure ? `${strings.error} (${failure})` : strings.error;
    if (text) thread.append(notice);
  }
}

function resetChat() {
  request?.abort();
  request = null;
  messages = [];
  retrieved.clear();
  thread.replaceChildren();
}

document.querySelectorAll('[data-ask]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const input = form.elements.q;
    // An empty hero box asks the example it is suggesting.
    const question = input.value || (input === heroInput ? config.examples[currentSlide] : '');
    input.value = '';
    ask(question);
  });
});
chat.querySelectorAll('[data-close-chat]').forEach((control) => control.addEventListener('click', (event) => {
  event.preventDefault();
  closeChat();
}));
// Esc leaves the same way the Close button does.
chat.addEventListener('cancel', (event) => {
  event.preventDefault();
  closeChat();
});
chat.querySelector('[data-new-chat]').addEventListener('click', () => {
  resetChat();
  chatInput.focus();
});
