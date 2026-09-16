/* ============================================================
   CONFIG — wire up your real destinations here
   WAITLIST_URL    → your waitlist form link (all CTAs).
   RECAP_VIDEO_URL → embed URL of the May 2026 Austin recap
                     (YouTube/Vimeo embed). Empty = image zoom.
   ============================================================ */
const WAITLIST_URL = ( window.vstConfig && window.vstConfig.waitlistUrl ) || "https://joinvistara.com/";
/* Per-video embed URLs (YouTube/Vimeo "embed" links).
   austin = hero recap · denver = Denver recap · p1–p3 = featured
   participant testimonials · p4–p6 = testimonial strip.
   Leave empty = clicking zooms the thumbnail instead. */
const VIDEO_URLS = { austin:"", denver:"", p1:"", p2:"", p3:"", p4:"", p5:"", p6:"" };
/* Endpoint for the "Want this on your calendar?" form (POST).
   Leave empty = the form falls back to opening WAITLIST_URL. */
const FORM_ENDPOINT = "";
/* Additional gallery images: paste URLs here and the mosaic
   automatically becomes a paged carousel (arrows + dots + auto-rotate);
   the lightbox then cycles through every photo. */
const GALLERY_EXTRA = [];

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.querySelectorAll('[data-waitlist]').forEach(a => { if (WAITLIST_URL) a.setAttribute('href', WAITLIST_URL); });

/* nav + progress */
const nav = document.getElementById('nav');
const bar = document.getElementById('progress');
const onScroll = () => {
  nav.classList.toggle('on', window.scrollY > 24);
  const h = document.documentElement;
  bar.style.width = (h.scrollTop / (h.scrollHeight - h.clientHeight) * 100) + '%';
};
onScroll();
window.addEventListener('scroll', onScroll, {passive:true});

/* reveal */
const io = new IntersectionObserver(es => es.forEach(e => {
  if (e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); }
}), {threshold:.15, rootMargin:'0px 0px -6% 0px'});
document.querySelectorAll('.rv').forEach(el => io.observe(el));

/* ticker loop */
const tk = document.getElementById('tk');
if (tk) tk.innerHTML = tk.innerHTML.repeat(6);

/* cursor spotlight + card glow tracking */
if (window.matchMedia('(pointer:fine)').matches && !reduce){
  document.body.classList.add('has-pointer');
  const spot = document.querySelector('.spot');
  window.addEventListener('pointermove', e => {
    spot.style.setProperty('--mx', e.clientX + 'px');
    spot.style.setProperty('--my', e.clientY + 'px');
  }, {passive:true});
  document.querySelectorAll('.cell').forEach(c => {
    c.addEventListener('pointermove', e => {
      const r = c.getBoundingClientRect();
      c.style.setProperty('--cx', (e.clientX - r.left) + 'px');
      c.style.setProperty('--cy', (e.clientY - r.top) + 'px');
    });
  });
}

/* hero text decode */
if (!reduce){
  const CHARS = '!<>-_\\/[]{}—=+*^?#01';
  document.querySelectorAll('[data-scramble]').forEach((el, i) => {
    const final = el.textContent;
    let frame = 0;
    const total = 26;
    setTimeout(() => {
      const t = setInterval(() => {
        frame++;
        const done = Math.floor(final.length * (frame / total));
        let out = '';
        for (let j = 0; j < final.length; j++){
          out += j < done ? final[j] : CHARS[Math.floor(Math.random() * CHARS.length)];
        }
        el.textContent = out;
        if (frame >= total){ el.textContent = final; clearInterval(t); }
      }, 34);
    }, 350 + i * 420);
  });
}

/* neural constellation */
const cv = document.getElementById('net');
if (cv && !reduce){
  const cx = cv.getContext('2d');
  let W, H, pts = [], raf;
  const N = () => Math.min(90, Math.floor(W * H / 26000));
  function size(){
    const r = cv.parentElement.getBoundingClientRect();
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    W = r.width; H = r.height;
    cv.width = W * dpr; cv.height = H * dpr;
    cx.setTransform(dpr, 0, 0, dpr, 0, 0);
    pts = Array.from({length: N()}, () => ({
      x: Math.random() * W, y: Math.random() * H,
      vx: (Math.random() - .5) * .35, vy: (Math.random() - .5) * .35,
      r: Math.random() * 1.6 + .5,
      c: Math.random() < .18 ? '240,121,62' : (Math.random() < .5 ? '139,92,246' : '56,201,216')
    }));
  }
  function tick(){
    cx.clearRect(0, 0, W, H);
    for (const p of pts){
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0 || p.x > W) p.vx *= -1;
      if (p.y < 0 || p.y > H) p.vy *= -1;
      cx.beginPath(); cx.arc(p.x, p.y, p.r, 0, 7);
      cx.fillStyle = 'rgba(' + p.c + ',.7)'; cx.fill();
    }
    for (let i = 0; i < pts.length; i++){
      for (let j = i + 1; j < pts.length; j++){
        const a = pts[i], b = pts[j];
        const dx = a.x - b.x, dy = a.y - b.y, d = dx*dx + dy*dy;
        if (d < 13000){
          cx.beginPath(); cx.moveTo(a.x, a.y); cx.lineTo(b.x, b.y);
          cx.strokeStyle = 'rgba(139,92,246,' + (.16 * (1 - d / 13000)) + ')';
          cx.lineWidth = 1; cx.stroke();
        }
      }
    }
    raf = requestAnimationFrame(tick);
  }
  size(); tick();
  window.addEventListener('resize', () => { cancelAnimationFrame(raf); size(); tick(); });
}

/* video modal (all portals + testimonial cards) */
const modal = document.getElementById('modal');
const slot  = document.getElementById('modalSlot');
function openModal(el){
  const url = VIDEO_URLS[el.dataset.video] || "";
  const img = el.querySelector('img');
  const label = el.getAttribute('aria-label') || 'Vistara video';
  slot.innerHTML = url
    ? '<iframe src="' + url + '" title="' + label + '" allow="autoplay; fullscreen" allowfullscreen></iframe>'
    : '<img src="' + img.src + '" alt="' + (img.alt || label) + '">';
  modal.classList.add('open');
  modal.setAttribute('aria-hidden','false');
  document.body.style.overflow = 'hidden';
}
function closeModal(){
  modal.classList.remove('open','has-nav');
  modal.setAttribute('aria-hidden','true');
  slot.innerHTML = '';
  document.body.style.overflow = '';
}
document.querySelectorAll('[data-video]').forEach(el => {
  el.addEventListener('click', () => openModal(el));
  el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); openModal(el); } });
});
modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', closeModal));
window.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

/* calendar form */
const calForm = document.getElementById('calForm');
if (calForm){
  calForm.addEventListener('submit', e => {
    e.preventDefault();
    if (FORM_ENDPOINT){
      calForm.action = FORM_ENDPOINT;
      calForm.method = 'POST';
      calForm.submit();
    } else {
      window.open(WAITLIST_URL, '_blank', 'noopener');
    }
  });
}

/* gallery — paginated mosaic + lightbox over the full set */
const mosaic = document.querySelector('.gal-mosaic');
if (mosaic){
  const slotsEls = Array.from(mosaic.children);
  const baseImgs = slotsEls.map(el => { const im = el.querySelector('img'); return {src: im.src, alt: im.alt}; });
  const allImgs = baseImgs.concat(GALLERY_EXTRA.map(u => ({src: u, alt: 'Vistara — event gallery'})));
  const PER = slotsEls.length;
  const pages = Math.ceil(allImgs.length / PER);
  const prevB = document.getElementById('gPagePrev');
  const nextB = document.getElementById('gPageNext');
  const dotsEl = document.getElementById('gDots');
  let page = 0, gIdx = 0, gTimer = null, gPaused = false;
  function renderPage(p){
    page = (p + pages) % pages;
    slotsEls.forEach((el, i) => {
      const idx = (page * PER + i) % allImgs.length;
      const it = allImgs[idx];
      el.dataset.gindex = idx;
      const im = el.querySelector('img');
      im.src = it.src; im.alt = it.alt;
      el.querySelector('.gidx').textContent = String(idx + 1).padStart(2, '0');
      el.setAttribute('aria-label', 'View Vistara gallery photo ' + (idx + 1) + ' of ' + allImgs.length);
      el.classList.remove('gswap'); void el.offsetWidth; el.classList.add('gswap');
    });
    if (dotsEl) Array.from(dotsEl.children).forEach((d, i) => d.classList.toggle('on', i === page));
  }
  function restartAuto(){
    if (gTimer) clearInterval(gTimer);
    if (!reduce && pages > 1) gTimer = setInterval(() => { if (!gPaused) renderPage(page + 1); }, 6000);
  }
  if (pages > 1){
    prevB.hidden = false; nextB.hidden = false;
    for (let i = 0; i < pages; i++){
      const d = document.createElement('button');
      d.className = 'gdot'; d.setAttribute('aria-label', 'Gallery page ' + (i + 1));
      d.addEventListener('click', () => { renderPage(i); restartAuto(); });
      dotsEl.appendChild(d);
    }
    prevB.addEventListener('click', () => { renderPage(page - 1); restartAuto(); });
    nextB.addEventListener('click', () => { renderPage(page + 1); restartAuto(); });
    const shell = mosaic.closest('.gal-shell');
    shell.addEventListener('pointerenter', () => gPaused = true);
    shell.addEventListener('pointerleave', () => gPaused = false);
    shell.addEventListener('focusin', () => gPaused = true);
    shell.addEventListener('focusout', () => gPaused = false);
    restartAuto();
  }
  renderPage(0);

  const showG = i => {
    gIdx = (i + allImgs.length) % allImgs.length;
    const it = allImgs[gIdx];
    slot.innerHTML = '<img src="' + it.src + '" alt="' + it.alt + '">';
  };
  const openG = i => {
    showG(i);
    modal.classList.add('open','has-nav');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  };
  slotsEls.forEach(el => {
    el.addEventListener('click', () => openG(Number(el.dataset.gindex)));
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); openG(Number(el.dataset.gindex)); } });
  });
  modal.querySelectorAll('[data-gnav]').forEach(b =>
    b.addEventListener('click', e => { e.stopPropagation(); showG(gIdx + Number(b.dataset.gnav)); }));
  window.addEventListener('keydown', e => {
    if (!modal.classList.contains('open') || !modal.classList.contains('has-nav')) return;
    if (e.key === 'ArrowLeft') showG(gIdx - 1);
    if (e.key === 'ArrowRight') showG(gIdx + 1);
  });
}

/* testimonial stage */
const stageFg = document.getElementById('stageFg');
if (stageFg){
  const stageImg = document.getElementById('stageImg');
  const stageBlur = document.getElementById('stageBlur');
  const thumbsEl = document.getElementById('tThumbs');
  const thumbs   = Array.from(thumbsEl.querySelectorAll('.thumb'));
  let tIdx = 0;
  function setStage(i){
    tIdx = (i + thumbs.length) % thumbs.length;
    const th = thumbs[tIdx];
    const src = th.querySelector('img').src;
    stageImg.src = src;
    stageBlur.style.backgroundImage = 'url("' + src + '")';
    stageFg.dataset.video = th.dataset.tv;
    thumbs.forEach((t,j) => t.classList.toggle('is-active', j === tIdx));
    stageImg.classList.remove('swap'); void stageImg.offsetWidth; stageImg.classList.add('swap');
    const left = th.offsetLeft - (thumbsEl.clientWidth - th.offsetWidth) / 2;
    thumbsEl.scrollTo({left, behavior:'smooth'});
  }
  setStage(0);
  document.getElementById('tPrev').addEventListener('click', () => setStage(tIdx - 1));
  document.getElementById('tNext').addEventListener('click', () => setStage(tIdx + 1));
  thumbs.forEach((t,j) => t.addEventListener('click', () => setStage(j)));
  stageFg.closest('.stage-wrap').addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft') setStage(tIdx - 1);
    if (e.key === 'ArrowRight') setStage(tIdx + 1);
  });
}
