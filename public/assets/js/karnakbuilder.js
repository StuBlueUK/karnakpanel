/* Karnak Builder editor (GrapesJS 0.23.6, vendored). Runs under
   script-src 'self': no eval, no inline handlers; every piece of user data
   reaches the DOM through textContent/attributes, never innerHTML.
   Server contract: BuilderController (load/save/publish/preview/…). */
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('kb-config').textContent);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var el = function (tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { n.addEventListener(ev, attrs.on[ev]); });
      else if (attrs[k] === true) n.setAttribute(k, '');
      else if (attrs[k] !== false && attrs[k] != null) n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  };

  // a CSP report here means the engine needed something we block — say so
  document.addEventListener('securitypolicyviolation', function (e) {
    if (window.console) console.warn('[kb] CSP blocked', e.violatedDirective, e.blockedURI);
  });

  var state = { lock: cfg.lock, dirty: false, saving: null, site: { title: cfg.title || '', lang: 'en', theme: {} }, meta: {}, readOnly: !cfg.lock };

  // ---------- status / toast / dialog ----------
  function status(t, cls) { var s = $('#kbe-status'); s.textContent = t; s.className = 'kbe-status' + (cls ? ' ' + cls : ''); }
  var toastT;
  function toast(t, bad) {
    var n = $('#kbe-toast'); n.textContent = t; n.className = 'kbe-toast' + (bad ? ' bad' : ''); n.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { n.hidden = true; }, bad ? 9000 : 4000);
  }
  function dialog(title, body, buttons) {
    var d = $('#kbe-dialog');
    $('#kbe-dtitle').textContent = title;
    var b = $('#kbe-dbody'); b.textContent = ''; (Array.isArray(body) ? body : [body]).forEach(function (x) { if (x) b.appendChild(typeof x === 'string' ? el('p', { text: x }) : x); });
    var f = $('#kbe-dfoot'); f.textContent = '';
    (buttons || [{ label: 'Close' }]).forEach(function (bt) {
      f.appendChild(el('button', { type: bt.submit ? 'submit' : 'button', class: 'kbe-btn' + (bt.primary ? ' kbe-primary' : '') + (bt.danger ? ' kbe-danger' : ''), text: bt.label, on: { click: function (ev) {
        if (bt.run && bt.run(ev) === false) { ev.preventDefault(); return; }
        d.close();
      } } }));
    });
    if (!d.open) d.showModal();
  }
  function field(label, input, hint) {
    return el('label', { class: 'kbe-field' }, [el('span', { text: label }), input, hint ? el('small', { text: hint }) : null]);
  }

  // ---------- server ----------
  function post(url, data) {
    var fd = new FormData();
    fd.append('csrf', cfg.csrf); fd.append('id', String(cfg.id)); fd.append('lock', state.lock || '');
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(answer);
  }
  function get(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(answer);
  }
  function answer(r) {
    return r.text().then(function (t) {
      var j; try { j = JSON.parse(t); } catch (e) { j = { ok: false, error: r.status === 401 || r.redirected ? 'Signed out — log in again in another tab, then save' : 'Server error (' + r.status + ')' }; }
      if (j && j.locked) lockLost();
      return j;
    });
  }

  // ---------- theme (mirror of Builder::themeCss) ----------
  var DEF = { c1: '#1f3a8a', c2: '#3b82f6', c3: '#0f172a', c4: '#f8fafc', c5: '#ffffff', head: 'Inter', body: 'Inter', radius: 12 };
  function theme() {
    var t = {}, s = state.site.theme || {};
    Object.keys(DEF).forEach(function (k) { t[k] = s[k] != null ? s[k] : DEF[k]; });
    ['c1', 'c2', 'c3', 'c4', 'c5'].forEach(function (k) { if (!/^#[0-9a-f]{6}$/i.test(t[k])) t[k] = DEF[k]; });
    if (!cfg.fonts[t.head]) t.head = DEF.head;
    if (!cfg.fonts[t.body]) t.body = DEF.body;
    t.radius = Math.max(0, Math.min(40, parseInt(t.radius, 10) || 0));
    t.c6 = /^#[0-9a-f]{6}$/i.test(s.c6 || '') ? s.c6 : t.c2;
    t.style = s.style && cfg.looks[s.style] != null ? s.style : '';
    return t;
  }
  function themeCss() {
    var t = theme();
    return ':root{--kb-c1:' + t.c1 + ';--kb-c2:' + t.c2 + ';--kb-c3:' + t.c3 + ';--kb-c4:' + t.c4 + ';--kb-c5:' + t.c5 + (t.style ? ';--kb-c6:' + t.c6 : '') +
      ';--kb-head:' + cfg.fonts[t.head] + ';--kb-body:' + cfg.fonts[t.body] + ';--kb-radius:' + t.radius + 'px}\n';
  }
  var CANVAS_EXTRA = 'html body{margin:0;font-family:var(--kb-body);font-size:17px;line-height:1.65;color:var(--kb-c3);background:var(--kb-c5)}' +
    '.kb-embed iframe{pointer-events:none}[data-kb=menu]{min-height:24px}.kb-col:empty{outline:1px dashed rgba(100,116,139,.45)}' +
    '.kb-slider{grid-auto-flow:row;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));overflow:visible}.kb-slider::before{content:"Photo slider: one photo at a time on the site (swipe, or arrows where the browser supports them)";grid-column:1/-1;font:600 12px/1.3 system-ui,sans-serif;opacity:.6}' +
    '.kb-placeholder{cursor:pointer;display:grid;place-items:center}.kb-placeholder::after{content:"Double-click to add a photo";font:600 13px/1.3 system-ui,sans-serif;color:var(--kb-c3);opacity:.6;padding:8px;text-align:center}' +
    'html{scrollbar-width:thin;scrollbar-color:rgba(100,116,139,.55) transparent}::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(100,116,139,.55);border-radius:99px}::-webkit-scrollbar-thumb:hover{background:rgba(100,116,139,.85)}';
  // Each page has its own frame. A frame:load passes the frame that loaded
  // (Canvas.getDocument() can still be the previous page's at that moment),
  // so paint that one; otherwise the current page's.
  function paintCanvas(o) {
    var doc = o && o.window ? o.window.document : editor && editor.Canvas.getDocument();
    if (!doc || !doc.head) return;
    var s = doc.getElementById('kb-theme');
    if (!s) { s = doc.createElement('style'); s.id = 'kb-theme'; doc.head.appendChild(s); }
    // the style's rules last, as on the published site (after site.css)
    s.textContent = themeCss() + cfg.baseCss + CANVAS_EXTRA + (cfg.looks[theme().style] || '');
  }

  // ---------- blocks ----------
  var ph = '<div class="kb-placeholder"></div>';
  var menu = '<nav data-kb="menu"><a href="/">Home</a></nav>';
  var BLOCKS = [
    ['Layout', 'kb-section', '▭ Section', '<section class="kb-section"><div class="kb-container"><h2>Section title</h2><p>Write something here.</p></div></section>'],
    ['Layout', 'kb-soft', '▭ Soft section', '<section class="kb-section kb-soft"><div class="kb-container"><h2>Section title</h2><p>A section on the soft background colour.</p></div></section>'],
    ['Layout', 'kb-dark', '▬ Dark band', '<section class="kb-section kb-dark kb-center"><div class="kb-container"><h2>Bold statement</h2><p class="kb-lead">A contrasting band for a key message.</p><div class="kb-btns"><a class="kb-btn" href="#">Get started</a></div></div></section>'],
    ['Layout', 'kb-cols2', '⫼ 2 columns', '<div class="kb-row" style="--kb-cols:2"><div class="kb-col"><p>Column one</p></div><div class="kb-col"><p>Column two</p></div></div>'],
    ['Layout', 'kb-cols3', '⫼ 3 columns', '<div class="kb-row" style="--kb-cols:3"><div class="kb-col"><p>One</p></div><div class="kb-col"><p>Two</p></div><div class="kb-col"><p>Three</p></div></div>'],
    ['Layout', 'kb-cols4', '⫼ 4 columns', '<div class="kb-row" style="--kb-cols:4"><div class="kb-col"><p>One</p></div><div class="kb-col"><p>Two</p></div><div class="kb-col"><p>Three</p></div><div class="kb-col"><p>Four</p></div></div>'],
    ['Layout', 'kb-spacer', '↕ Spacer', '<div class="kb-spacer"></div>'],
    ['Layout', 'kb-divider', '― Divider', '<hr class="kb-divider">'],
    ['Basic', 'kb-heading', 'H Heading', '<h2>Heading</h2>'],
    ['Basic', 'kb-text', '¶ Text', '<p>Write your text here. Select it to change the words, the font or the colour.</p>'],
    ['Basic', 'kb-image', '🖼 Image', { type: 'image', attributes: { alt: '' }, style: { 'max-width': '100%' } }],
    ['Basic', 'kb-button', '⬭ Button', '<a class="kb-btn" href="#">Button</a>'],
    ['Basic', 'kb-list', '• List', '<ul class="kb-list"><li>First point</li><li>Second point</li><li>Third point</li></ul>'],
    ['Basic', 'kb-video', '▶ Video', '<div class="kb-embed" data-kb="video"></div>'],
    ['Basic', 'kb-map', '📍 Map', '<div class="kb-embed kb-map" data-kb="map"></div>'],
    ['Sections', 'kb-header', '☰ Header + menu', '<header class="kb-header"><div class="kb-container"><a class="kb-brand" href="/">Your brand</a>' + menu + '</div></header>'],
    ['Sections', 'kb-hero', '★ Hero', '<section class="kb-hero"><div class="kb-container"><span class="kb-eyebrow">Welcome</span><h1>A headline that says what you do</h1><p class="kb-lead">One or two sentences on who you help and why you are the right choice.</p><div class="kb-btns"><a class="kb-btn" href="#">Get in touch</a><a class="kb-btn kb-ghost" href="#">Learn more</a></div></div></section>'],
    ['Sections', 'kb-split', '◧ Text + image', '<section class="kb-section"><div class="kb-container"><div class="kb-row kb-mid" style="--kb-cols:2"><div class="kb-col"><span class="kb-eyebrow">About</span><h2>Tell your story</h2><p>Explain what makes you different. Keep it short and friendly.</p><a class="kb-btn" href="#">Find out more</a></div><div class="kb-col"><div class="kb-media">' + ph + '</div></div></div></div></section>'],
    ['Sections', 'kb-features', '◫ Feature cards', '<section class="kb-section kb-soft"><div class="kb-container"><h2 class="kb-center">What we offer</h2><div class="kb-row" style="--kb-cols:3"><div class="kb-col"><div class="kb-card"><div class="kb-icon">✦</div><h3>First service</h3><p>A short description of this service.</p></div></div><div class="kb-col"><div class="kb-card"><div class="kb-icon">✦</div><h3>Second service</h3><p>A short description of this service.</p></div></div><div class="kb-col"><div class="kb-card"><div class="kb-icon">✦</div><h3>Third service</h3><p>A short description of this service.</p></div></div></div></div></section>'],
    ['Sections', 'kb-gallery', '▦ Gallery', '<section class="kb-section"><div class="kb-container"><h2>Gallery</h2><div class="kb-gallery">' + ph + ph + ph + ph + ph + ph + '</div></div></section>'],
    ['Sections', 'kb-quote', '❝ Testimonial', '<section class="kb-section kb-soft"><div class="kb-container"><div class="kb-row" style="--kb-cols:2"><div class="kb-col"><div class="kb-card"><blockquote class="kb-quote">“Brilliant service from start to finish. Highly recommended.”<cite>— Happy customer</cite></blockquote></div></div><div class="kb-col"><div class="kb-card"><blockquote class="kb-quote">“Friendly, fast and great value. We will be back.”<cite>— Another customer</cite></blockquote></div></div></div></div></section>'],
    ['Sections', 'kb-pricing', '£ Pricing', '<section class="kb-section"><div class="kb-container"><h2 class="kb-center">Pricing</h2><div class="kb-row" style="--kb-cols:3"><div class="kb-col"><div class="kb-card kb-price"><h3>Basic</h3><div class="kb-amount">£19</div><ul><li>Feature one</li><li>Feature two</li></ul><a class="kb-btn kb-ghost" href="#">Choose</a></div></div><div class="kb-col"><div class="kb-card kb-price kb-featured"><h3>Standard</h3><div class="kb-amount">£39</div><ul><li>Everything in Basic</li><li>Feature three</li></ul><a class="kb-btn" href="#">Choose</a></div></div><div class="kb-col"><div class="kb-card kb-price"><h3>Premium</h3><div class="kb-amount">£79</div><ul><li>Everything in Standard</li><li>Feature four</li></ul><a class="kb-btn kb-ghost" href="#">Choose</a></div></div></div></div></section>'],
    ['Sections', 'kb-cta', '➜ Call to action', '<section class="kb-section kb-dark kb-center"><div class="kb-container"><h2>Ready to start?</h2><p class="kb-lead">Get in touch today for a friendly chat.</p><div class="kb-btns"><a class="kb-btn" href="#">Contact us</a></div></div></section>'],
    ['Sections', 'kb-footer', '▁ Footer', '<footer class="kb-footer"><div class="kb-container"><div class="kb-row" style="--kb-cols:3"><div class="kb-col"><b>Your brand</b><p>A line about your business.</p></div><div class="kb-col">' + menu + '</div><div class="kb-col"><p>hello@example.com<br>01234 567890</p></div></div></div></footer>']
  ];
  // brand marks: Simple Icons (CC0; LinkedIn from v10.4.0, removed from later releases at LinkedIn's request)
  var SOCIAL = [
    ['Facebook', 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
    ['Instagram', 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077'],
    ['X', 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z'],
    ['LinkedIn', 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'],
    ['TikTok', 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
    ['YouTube', 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z'],
    ['WhatsApp', 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z']
  ];
  var soc = function (i) { return '<a class="kb-soc" href="#" target="_blank" aria-label="' + SOCIAL[i][0] + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + SOCIAL[i][1] + '"/></svg></a>'; };
  var faq = function (q, a) { return '<details class="kb-faq"><summary>' + q + '</summary><p>' + a + '</p></details>'; };
  var slide = '<figure class="kb-slide"><div class="kb-placeholder"></div><figcaption>Caption for this photo</figcaption></figure>';
  var member = function (n, r) { return '<div class="kb-col"><div class="kb-card kb-center"><div class="kb-placeholder kb-avatar"></div><h3>' + n + '</h3><p><b>' + r + '</b></p><p>A line about what they do.</p></div></div>'; };
  var price = function (n, c, d) { return '<li><b>' + n + '</b><span class="kb-cost">' + c + '</span>' + (d ? '<small>' + d + '</small>' : '') + '</li>'; };
  // appended, never inserted: starter() picks blocks by index
  BLOCKS.push(
    ['Basic', 'kb-social', '◎ Social links', '<div class="kb-social">' + SOCIAL.map(function (x, i) { return soc(i); }).join('') + '</div>'],
    ['Sections', 'kb-faqs', '？ FAQ', '<section class="kb-section"><div class="kb-container"><div class="kb-narrow"><h2 class="kb-center">Questions and answers</h2>' +
      faq('What is the first question?', 'Write the answer here. Visitors click the question to open it.') + faq('What is the second question?', 'Another short, helpful answer.') +
      faq('What is the third question?', 'Select a question and use the copy icon to add more.') + '</div></div></section>'],
    ['Sections', 'kb-slider', '⇆ Photo slider', '<section class="kb-section"><div class="kb-container"><div class="kb-slider">' + slide + slide + slide + slide + '</div></div></section>'],
    ['Sections', 'kb-hours', '🕘 Opening hours', '<section class="kb-section"><div class="kb-container"><div class="kb-narrow"><h2>Opening hours</h2><table class="kb-table kb-hours"><tbody>' +
      '<tr><th scope="row">Monday – Friday</th><td>9:00 – 17:30</td></tr><tr><th scope="row">Saturday</th><td>10:00 – 16:00</td></tr><tr><th scope="row">Sunday</th><td>Closed</td></tr><tr><th scope="row">Bank holidays</th><td>Closed</td></tr>' +
      '</tbody></table></div></div></section>'],
    ['Sections', 'kb-team', '☺ Team', '<section class="kb-section kb-soft"><div class="kb-container"><h2 class="kb-center">Meet the team</h2><div class="kb-row" style="--kb-cols:3">' +
      member('Alex', 'Founder') + member('Sam', 'Manager') + member('Jo', 'Specialist') + '</div></div></section>'],
    ['Sections', 'kb-prices', '£ Price list', '<section class="kb-section"><div class="kb-container"><h2 class="kb-center">Price list</h2><div class="kb-row" style="--kb-cols:2">' +
      '<div class="kb-col"><h3>First category</h3><ul class="kb-menu">' + price('Service one', '£25', '') + price('Service two', 'from £40', 'A short note about this service') + price('Service three', '£60', '') + '</ul></div>' +
      '<div class="kb-col"><h3>Second category</h3><ul class="kb-menu">' + price('Service four', '£30', '') + price('Service five', '£45', '') + price('Service six', 'from £80', 'A short note about this service') + '</ul></div>' +
      '</div></div></section>'],
    // blog: filled from the published posts at Publish / Preview
    ['Blog', 'kb-posts-latest', '📰 Latest posts', '<section class="kb-section"><div class="kb-container"><h2 class="kb-center">Latest news</h2><div data-kb="posts-3"></div></div></section>'],
    ['Blog', 'kb-posts-all', '☷ Post list', '<section class="kb-section"><div class="kb-container"><div data-kb="posts-all"></div></div></section>'],
    ['Blog', 'kb-post-meta', '🏷 Post date & tags', '<div data-kb="post-meta"></div>']
  );
  var POST_LIST = '<section class="kb-section"><div class="kb-container"><h1>Blog</h1><div data-kb="posts-all"></div></div></section>';
  function starter() {
    return BLOCKS[15][3] + '<section class="kb-section"><div class="kb-container"><h1>Your page</h1><p>Drag blocks from the left onto the page.</p></div></section>' + BLOCKS[23][3];
  }

  // ---------- embeds: only allow-listed hosts, built from parsed URLs ----------
  function videoUrl(v) {
    var m;
    v = String(v || '').trim();
    if ((m = v.match(/^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/|youtube-nocookie\.com\/embed\/)([A-Za-z0-9_-]{11})/))) return 'https://www.youtube-nocookie.com/embed/' + m[1];
    if ((m = v.match(/^https?:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{4,12})/))) return 'https://player.vimeo.com/video/' + m[1];
    return '';
  }
  function mapUrl(v) {
    v = String(v || '').trim();
    var m = v.match(/^https:\/\/www\.openstreetmap\.org\/export\/embed\.html\?bbox=(-?[\d.]+)(?:%2C|,)(-?[\d.]+)(?:%2C|,)(-?[\d.]+)(?:%2C|,)(-?[\d.]+)(?:&(?:amp;)?layer=(mapnik|cyclemap|transportmap|hot))?(?:&(?:amp;)?marker=(-?[\d.]+)(?:%2C|,)(-?[\d.]+))?/);
    if (!m) return '';
    var u = 'https://www.openstreetmap.org/export/embed.html?bbox=' + [m[1], m[2], m[3], m[4]].join('%2C') + '&layer=' + (m[5] || 'mapnik');
    if (m[6]) u += '&marker=' + m[6] + '%2C' + m[7];
    return u;
  }
  var DEFAULT_MAP = 'https://www.openstreetmap.org/export/embed.html?bbox=-0.1400%2C51.4990%2C-0.1150%2C51.5110&layer=mapnik';

  // ---------- editor ----------
  var editor;
  function boot(data) {
    var project = data.project;
    // a fresh template ships plain HTML pages (kbSeed); the first save
    // replaces it with real editor data
    var seed = project && Array.isArray(project.kbSeed) ? project.kbSeed : null;
    state.site = data.site || state.site;
    if (!state.site.title) state.site.title = cfg.title || '';
    (data.pages || []).forEach(function (p, i) { if (p && p.pid) state.meta[p.pid] = p; else if (i === 0) state.meta._home = p; });
    if (project && project.kbSite) state.site = project.kbSite;
    if (project && project.kbPages) state.meta = project.kbPages;

    editor = grapesjs.init({
      container: '#kbe-gjs', height: '100%', width: 'auto', fromElement: false, storageManager: false,
      protectedCss: '', avoidInlineStyle: true, forceClass: true, showOffsets: true, noticeOnUnload: false,
      parser: { optionsHtml: { allowScripts: false, allowUnsafeAttr: false, allowUnsafeAttrValue: false } },
      panels: { defaults: [] },
      blockManager: { appendTo: '#kbe-blocks' },
      styleManager: { appendTo: '#kbe-styles' },
      layerManager: { appendTo: '#kbe-layers' },
      traitManager: { appendTo: '#kbe-traits' },
      selectorManager: { appendTo: '#kbe-selectors', componentFirst: true },
      deviceManager: { devices: [
        { id: 'desktop', name: 'Desktop', width: '' },
        { id: 'tablet', name: 'Tablet', width: '768px', widthMedia: '992px' },
        { id: 'mobile', name: 'Mobile', width: '375px', widthMedia: '600px' }
      ] },
      assetManager: { assets: [], upload: '/builder/upload', uploadFile: uploadFiles, autoAdd: true, multiUpload: true, embedAsBase64: false,
        dropzone: false, custom: false },
      pageManager: seed ? { pages: seed.map(function (p) { return { id: String(p.id), name: String(p.name || 'Page'), component: String(p.html || '') }; }) }
        : project ? {} : { pages: [{ id: 'home', component: starter() }] },
      canvas: { styles: [] },
      // types register as a plugin: init parses the seeded pages, and a type
      // added after that never applies to them
      plugins: [function (ed) { editor = ed; defineTypes(); }]
    });

    editor.I18n.addMessages({ en: { assetManager: { modalTitle: 'Images — click one to put it on the page', uploadTitle: 'Drop images here or click to upload' } } });
    BLOCKS.forEach(function (b) { editor.Blocks.add(b[1], { label: b[2], category: b[0], content: b[3], select: true, activate: b[1] === 'kb-image' }); });
    if (project && !seed) {
      var clean = {}; Object.keys(project).forEach(function (k) { if (k !== 'kbSite' && k !== 'kbPages') clean[k] = project[k]; });
      editor.loadProjectData(clean);
    }
    ensureMeta();
    editor.on('canvas:frame:load', paintCanvas);
    editor.on('canvas:frame:load', function (o) {
      o.window.document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.kb-faq > summary')) e.preventDefault();
      });
      o.window.document.addEventListener('dblclick', function (e) {
        if (e.target.closest && e.target.closest('.kb-placeholder') && isSlot(editor.getSelected())) openImages();
      });
    });
    editor.on('load', function () {
      paintCanvas(); fillPages(); refreshMenus(); loadAssets();
      editor.UndoManager.clear();
      state.dirty = false;
      clearTimeout(saveT);
      if (state.readOnly) lockLost(true); else status('All changes saved', 'ok');
    });
    editor.on('update', function () { if (!state.booting) markDirty(); });
    // after a page switch the new frame may still be loading (a style added
    // now is lost when its document is replaced): repaint until it's ready
    editor.on('page:select', function () {
      fillPages();
      var tries = 0;
      (function again() {
        paintCanvas();
        var doc = editor.Canvas.getDocument();
        if (++tries < 20 && !(doc && doc.readyState === 'complete' && doc.getElementById('kb-theme'))) setTimeout(again, 100);
      })();
    });
    editor.on('asset:remove', function (a) {
      var n = String(a.get('src') || '').split('/').pop();
      if (/^[a-z0-9][a-z0-9_-]{0,80}\.(webp|jpg|png|gif)$/.test(n)) post('/builder/asset-delete', { name: n }).then(function (j) { if (!j.ok) toast(j.error || 'Could not delete the image', true); });
    });
  }

  function defineTypes() {
    var dc = editor.DomComponents;
    var embed = function (kind, conv, label, def) {
      dc.addType('kb-' + kind, {
        isComponent: function (n) { return n.getAttribute && n.getAttribute('data-kb') === kind ? { type: 'kb-' + kind } : false; },
        model: {
          defaults: { droppable: false, 'kb-src': def || '', traits: [{ type: 'text', name: 'kb-src', label: label, changeProp: true, placeholder: 'Paste the link here' }] },
          init: function () {
            var s = '';
            var f = this.components().at(0);
            if (f && f.getAttributes) s = f.getAttributes().src || '';
            if (s && !this.get('kb-src')) this.set('kb-src', s, { silent: true });
            this.on('change:kb-src', this.build); this.build();
          },
          build: function () {
            var u = conv(this.get('kb-src'));
            if (!u && this.get('kb-src')) { toast(kind === 'video' ? 'Use a YouTube or Vimeo link' : 'Use an OpenStreetMap "Share → HTML" embed link', true); }
            this.components(u ? [{ tagName: 'iframe', attributes: { src: u, title: kind === 'video' ? 'Video' : 'Map', allowfullscreen: kind === 'video' ? 'allowfullscreen' : false, loading: 'lazy' }, selectable: false, hoverable: false }] : []);
          }
        }
      });
    };
    embed('video', videoUrl, 'YouTube or Vimeo link', '');
    embed('map', mapUrl, 'OpenStreetMap embed link', DEFAULT_MAP);
    // FAQ items stay open in the editor so every answer can be edited; the
    // published page starts them closed ('open' is never in the model)
    dc.addType('kb-faq', {
      isComponent: function (n) { return n.tagName === 'DETAILS' && n.classList.contains('kb-faq') ? { type: 'kb-faq' } : false; },
      view: { onRender: function () { this.el.open = true; }, init: function () { this.listenTo(this.model, 'change:attributes', function () { this.el.open = true; }); } }
    });
    // a social icon: one link with its icon locked inside it
    dc.addType('kb-soc', {
      extend: 'link',
      isComponent: function (n) { return n.tagName === 'A' && n.classList.contains('kb-soc') ? { type: 'kb-soc' } : false; },
      model: {
        defaults: { droppable: false, editable: false, traits: [
          { type: 'text', name: 'href', label: 'Profile link', placeholder: 'https://… (WhatsApp: https://wa.me/447…)' },
          { type: 'text', name: 'aria-label', label: 'Name (read aloud)' }] },
        init: function () {
          (function lock(c) { c.components().forEach(function (k) { k.set({ selectable: false, hoverable: false, draggable: false, droppable: false, layerable: false, editable: false }); lock(k); }); })(this);
        }
      }
    });
    // blog blocks: no content of their own; the canvas shows what will appear
    var blogHint = function (kb) {
      if (kb === 'post-meta') return 'Post date and tags appear here';
      return kb === 'posts-all' ? 'All posts appear here (10 per page, with tag pages)' : 'The newest ' + kb.slice(6) + ' posts appear here';
    };
    dc.addType('kb-blog', {
      isComponent: function (n) { var k = n.getAttribute && n.getAttribute('data-kb'); return k && (/^posts-(all|[1-9]|1[0-2])$/.test(k) || k === 'post-meta') ? { type: 'kb-blog' } : false; },
      model: {
        defaults: { droppable: false, editable: false, traits: [] },
        init: function () {
          if (/^posts-[0-9]+$/.test(this.getAttributes()['data-kb'] || '')) {
            this.set('traits', [{ type: 'select', name: 'data-kb', label: 'How many', options: [{ id: 'posts-3', name: '3 posts' }, { id: 'posts-6', name: '6 posts' }, { id: 'posts-9', name: '9 posts' }] }]);
          }
        }
      },
      view: {
        init: function () { this.listenTo(this.model, 'change:attributes', this.hint); },
        onRender: function () { this.hint(); },
        hint: function () {
          var n = this.el; n.textContent = '';
          n.appendChild(el('p', { style: 'padding:22px;text-align:center;border:2px dashed currentColor;opacity:.6', text: blogHint(this.model.getAttributes()['data-kb'] || '') }));
        }
      }
    });
    dc.addType('kb-menu', {
      isComponent: function (n) { return n.getAttribute && n.getAttribute('data-kb') === 'menu' ? { type: 'kb-menu' } : false; },
      model: { defaults: { droppable: false, editable: false, traits: [], propagate: ['selectable', 'hoverable', 'editable'] } }
    });
  }

  // ---------- pages ----------
  function pages() { return editor.Pages.getAll(); }
  function pid(p) { return p.getId(); }
  function isPost(m) { return !!m && m.kind === 'post'; }
  function pathOf(m, i) { return i === 0 ? '/' : '/' + (isPost(m) ? 'blog/' : '') + (m.slug || '') + '/'; }
  function count(kind) { return pages().filter(function (p) { return (isPost(state.meta[pid(p)]) ? 'post' : 'page') === kind; }).length; }
  function slugify(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64); }
  function ensureMeta() {
    var list = pages();
    list.forEach(function (p, i) {
      var id = pid(p);
      if (!state.meta[id]) state.meta[id] = i === 0 && state.meta._home ? state.meta._home : { title: i === 0 ? 'Home' : (p.get('name') || 'Page'), slug: i === 0 ? '' : slugify(p.get('name') || 'page-' + i), desc: '', noindex: false, nav: true };
    });
    delete state.meta._home;
    if (list[0]) state.meta[pid(list[0])].slug = '';
  }
  function fillPages() {
    var s = $('#kbe-page'), cur = editor.Pages.getSelected();
    s.textContent = '';
    var posts = null;
    pages().forEach(function (p, i) {
      var m = state.meta[pid(p)] || {};
      var o = el('option', { value: pid(p), text: (m.title || 'Page') + (isPost(m) && m.draft ? ' (draft)' : '') + '  —  ' + pathOf(m, i), selected: cur && pid(cur) === pid(p) });
      if (!isPost(m)) { s.appendChild(o); return; }
      if (!posts) s.appendChild(posts = el('optgroup', { label: 'Blog posts' }));
      posts.appendChild(o);
    });
  }
  function refreshMenus() {
    var items = pages().filter(function (p) { var m = state.meta[pid(p)] || {}; return m.nav !== false && !isPost(m); }).map(function (p, i) {
      var m = state.meta[pid(p)] || {};
      return { tagName: 'a', attributes: { href: '/' + (m.slug ? m.slug + '/' : '') }, components: [{ type: 'textnode', content: m.title || 'Page' }] };
    });
    pages().forEach(function (p) {
      var root = p.getMainComponent();
      if (!root) return;
      root.find('[data-kb="menu"]').forEach(function (n) { n.components(items.map(function (x) { return JSON.parse(JSON.stringify(x)); })); });
    });
  }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function pageForm(p, isNew, asPost) {
    var first = !isNew && pages()[0] && pid(pages()[0]) === pid(p);
    var m = isNew ? { title: '', slug: '', desc: '', noindex: false, nav: !asPost } : Object.assign({}, state.meta[pid(p)] || {});
    if (isNew && asPost) Object.assign(m, { kind: 'post', date: today(), tags: [], draft: false });
    var post = isPost(m), noun = post ? 'post' : 'page';
    var t = el('input', { maxlength: '120', value: m.title || '', required: true });
    var sl = el('input', { maxlength: '64', value: m.slug || '', pattern: '[a-z0-9]([a-z0-9-]*[a-z0-9])?', disabled: first, placeholder: first ? '(home page)' : 'about-us' });
    var touched = !!m.slug;
    sl.addEventListener('input', function () { touched = true; });
    t.addEventListener('input', function () { if (!first && !touched) sl.value = slugify(t.value); });
    var d = el('textarea', { maxlength: '300', rows: '3' }); d.value = m.desc || '';
    var ni = el('input', { type: 'checkbox', checked: !!m.noindex });
    var nv = el('input', { type: 'checkbox', checked: m.nav !== false });
    var dt = el('input', { type: 'date', value: m.date || today(), required: true });
    var tg = el('input', { maxlength: '400', value: (m.tags || []).join(', '), placeholder: 'news, offers' });
    var dr = el('input', { type: 'checkbox', checked: !!m.draft });
    var body = post ? [field('Post title', t), field('Address', sl, 'Lower-case letters, digits and dashes: /blog/' + 'my-first-post/'),
      field('Date', dt, 'Posts are listed newest first'), field('Tags', tg, 'Separate with commas (up to 10). Each tag gets its own page.'),
      field('Summary', d, 'Shown on the post list and by Google (300 characters max)'),
      el('label', { class: 'kbe-check' }, [dr, ' Draft — keep it off the live site (Preview still shows it)']), el('label', { class: 'kbe-check' }, [ni, ' Hide from search engines (noindex)'])]
      : [field('Page name', t), field('Address', sl, first ? 'The home page is always at /' : 'Lower-case letters, digits and dashes: /' + 'about-us/'),
      field('Search description', d, 'Shown by Google under the page title (300 characters max)'),
      el('label', { class: 'kbe-check' }, [nv, ' Show in the menu']), el('label', { class: 'kbe-check' }, [ni, ' Hide from search engines (noindex)'])];
    var btns = [{ label: isNew ? 'Add ' + noun : 'Save', primary: true, submit: true, run: function () {
      var title = t.value.trim(), slug = first ? '' : slugify(sl.value);
      if (!title) { toast('Give the ' + noun + ' a name', true); return false; }
      if (!first && !/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/.test(slug)) { toast('The address needs letters or digits', true); return false; }
      if (!first && ['assets', '_kb', 'index', '404', 'sitemap', 'robots'].concat(post ? ['tag', 'page', 'feed'] : []).indexOf(slug) >= 0) { toast('That address is reserved — pick another', true); return false; }
      var clash = pages().some(function (q) { var o = state.meta[pid(q)] || {}; return (isNew || pid(q) !== pid(p)) && isPost(o) === post && o.slug === slug; });
      if (clash) { toast('Another ' + noun + ' already uses ' + (post ? '/blog/' : '/') + slug + '/', true); return false; }
      var meta = { title: title.slice(0, 120), slug: slug, desc: d.value.trim().slice(0, 300), noindex: ni.checked, nav: post ? false : nv.checked };
      if (post) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dt.value)) { toast('Pick a date for the post', true); return false; }
        var seen = {}, tags = [];
        tg.value.split(',').forEach(function (x) { x = x.trim().slice(0, 40); var k = slugify(x); if (k && !seen[k] && tags.length < 10) { seen[k] = 1; tags.push(x); } });
        Object.assign(meta, { kind: 'post', date: dt.value, tags: tags, draft: dr.checked });
      }
      if (isNew) {
        if (post ? count('post') >= 200 : count('page') >= 50) { toast(post ? '200 posts is the most a site can have' : '50 pages is the most a site can have', true); return false; }
        // the first post brings its /blog/ list page (customers style it like any page)
        var hasBlog = pages().some(function (q) { var o = state.meta[pid(q)] || {}; return !isPost(o) && o.slug === 'blog'; });
        if (post && !hasBlog && count('page') < 50) {
          var bp = editor.Pages.add({ name: 'Blog', component: BLOCKS[15][3] + POST_LIST + BLOCKS[23][3] });
          state.meta[pid(bp)] = { title: 'Blog', slug: 'blog', desc: '', noindex: false, nav: true };
        }
        var np = editor.Pages.add({ name: title, component: BLOCKS[15][3] + '<section class="kb-section"><div class="kb-container"><div class="kb-narrow"><h1>' + '</h1>' +
          (post ? '<div data-kb="post-meta"></div><p>Write your post here.</p>' : '<p>Start building this page.</p>') + '</div></div></section>' + BLOCKS[23][3] });
        state.meta[pid(np)] = meta;
        var h = np.getMainComponent().find('h1')[0]; if (h) h.components(title);
        editor.Pages.select(np);
      } else {
        state.meta[pid(p)] = meta; p.set('name', title);
      }
      refreshMenus(); fillPages(); markDirty();
    } }];
    if (!isNew && !first) btns.push({ label: 'Delete ' + noun, danger: true, run: function () {
      dialog('Delete "' + (m.title || noun) + '"?', 'The ' + noun + ' is removed from the draft. It disappears from the live site at your next Publish.', [
        { label: 'Delete', danger: true, run: function () { var home = pages()[0]; editor.Pages.select(home); delete state.meta[pid(p)]; editor.Pages.remove(p); refreshMenus(); fillPages(); markDirty(); } },
        { label: 'Cancel' }]);
      return false;
    } });
    btns.push({ label: 'Cancel' });
    dialog(isNew ? 'New ' + noun : (post ? 'Post settings' : 'Page settings'), body, btns);
  }

  // ---------- export (what the server renders from) ----------
  function exportDraft() {
    var out = [];
    pages().forEach(function (p, i) {
      var root = p.getMainComponent(), m = state.meta[pid(p)] || {};
      var o = { pid: pid(p), slug: i === 0 ? '' : m.slug, title: m.title || 'Page', desc: m.desc || '', noindex: !!m.noindex, nav: m.nav !== false,
        html: root ? root.getInnerHTML() : '', css: root ? editor.getCss({ component: root, avoidProtected: true }) : '' };
      if (i > 0 && isPost(m)) Object.assign(o, { kind: 'post', date: m.date || '', tags: m.tags || [], draft: !!m.draft });
      out.push(o);
    });
    return { site: { title: state.site.title || '', lang: state.site.lang || 'en', theme: theme() }, pages: out };
  }
  function exportProject() {
    var p = editor.getProjectData();
    p.kbSite = { title: state.site.title || '', lang: state.site.lang || 'en', theme: theme() };
    p.kbPages = state.meta;
    return p;
  }

  // ---------- save / lock ----------
  var saveT;
  function markDirty() {
    if (state.readOnly) return;
    state.dirty = true; status('Unsaved changes');
    clearTimeout(saveT); saveT = setTimeout(save, 4000);
  }
  function save() {
    clearTimeout(saveT);
    if (state.readOnly) return Promise.resolve(false);
    if (state.saving) return state.saving.then(function () { return state.dirty ? save() : true; });
    status('Saving…');
    state.dirty = false;
    var body = { project: JSON.stringify(exportProject()), draft: JSON.stringify(exportDraft()) };
    state.saving = post('/builder/save', body).then(function (j) {
      state.saving = null;
      if (!j.ok) { state.dirty = true; status('Not saved', 'bad'); if (!j.locked) toast(j.error || 'Not saved', true); return false; }
      status(state.dirty ? 'Unsaved changes' : 'Saved ' + j.saved, state.dirty ? '' : 'ok');
      return true;
    }, function () { state.saving = null; state.dirty = true; status('Offline — not saved', 'bad'); return false; });
    return state.saving;
  }
  function lockLost(atStart) {
    state.readOnly = true;
    var b = $('#kbe-banner'); b.textContent = '';
    b.appendChild(el('span', { text: atStart ? 'This site is open in another editor tab or session, so this one is read-only.' : 'Another editor tab or session took over this site — this one is read-only now. Your last changes here were not saved.' }));
    b.appendChild(el('button', { type: 'button', class: 'kbe-btn kbe-primary', text: 'Edit here instead', on: { click: function () {
      post('/builder/takeover', {}).then(function (j) {
        if (!j.ok) { toast(j.error || 'Could not take over', true); return; }
        state.lock = j.lock; state.readOnly = false; b.hidden = true;
        if (!atStart) location.reload(); else status('Editing', 'ok');
      });
    } } }));
    b.hidden = false; status('Read-only', 'bad');
  }
  setInterval(function () { if (!state.readOnly && state.lock) post('/builder/ping', {}); }, 240000);
  setInterval(function () { if (state.dirty && !state.saving) save(); }, 30000);
  window.addEventListener('pagehide', function () {
    if (state.readOnly || !state.lock || !navigator.sendBeacon) return;
    var fd = new FormData(); fd.append('csrf', cfg.csrf); fd.append('id', String(cfg.id)); fd.append('lock', state.lock);
    navigator.sendBeacon('/builder/release', fd);
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
  window.addEventListener('beforeunload', function (e) { if (state.dirty && !state.readOnly) { save(); e.preventDefault(); e.returnValue = ''; } });

  // ---------- images ----------
  function loadAssets() {
    get('/builder/assets?id=' + cfg.id).then(function (j) { if (j.ok) editor.Assets.add(j.data.map(function (a) { return { src: a.src, name: a.name, type: 'image' }; })); });
  }
  // shrink in the browser first (max 2560px, WebP/JPEG), then the server re-encodes
  function shrink(file) {
    return new Promise(function (ok) {
      if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type) || file.type === 'image/gif') return ok(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, 2560 / Math.max(w, h));
        if (s === 1 && file.size <= cfg.upload) return ok(file);
        var c = document.createElement('canvas'); c.width = Math.round(w * s); c.height = Math.round(h * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) { ok(b ? new File([b], file.name.replace(/\.[a-z0-9]+$/i, '') + '.webp', { type: 'image/webp' }) : file); }, 'image/webp', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); ok(file); };
      img.src = url;
    });
  }
  function uploadFiles(e) {
    var files = Array.prototype.slice.call((e.dataTransfer || e.target).files || []).slice(0, 10);
    if (!files.length) return;
    var first = null;
    status('Uploading…');
    Promise.all(files.map(shrink)).then(function (list) {
      var chain = Promise.resolve();
      list.forEach(function (f) {
        chain = chain.then(function () {
          if (f.size > cfg.upload) { toast(f.name + ' is too large even after shrinking (limit ' + Math.round(cfg.upload / 104857.6) / 10 + ' MB)', true); return; }
          var fd = { }; fd['files[]'] = f;
          return post('/builder/upload', fd).then(function (j) {
            if (!j.ok) { toast(j.error || 'Upload failed', true); return; }
            var added = editor.Assets.add(j.data);
            if (!first) first = [].concat(added)[0] || null;
          });
        });
      });
      return chain;
    }).then(function () {
      // an upload only fills the library; put the (first) new image on the page too
      if (first && editor.Assets.isOpen()) placeImage(first);
      status(state.dirty ? 'Unsaved changes' : 'All changes saved');
    });
  }
  // template photo boxes (div.kb-placeholder) are slots: a picture replaces the box
  function isSlot(c) { return !!c && c.getClasses().indexOf('kb-placeholder') >= 0; }
  // selected image → swap its picture; slot → image in its place; else → new image after the selection
  function placeImage(asset) {
    var src = asset.getSrc(), sel = editor.getSelected();
    if (sel && sel.is('image')) sel.set({ src: src });
    else if (isSlot(sel)) editor.select([].concat(sel.replaceWith({ type: 'image', src: src, classes: ['kb-photo'].concat(sel.getClasses().filter(function (c) { return c !== 'kb-placeholder'; })), attributes: { alt: '' } }))[0]);
    else if (sel && sel.parent()) editor.select(sel.parent().append({ type: 'image', src: src, attributes: { alt: '' }, style: { 'max-width': '100%' } }, { at: sel.index() + 1 })[0]);
    else { toast('Click the spot on the page where the image should go, then pick it again', true); return; }
    editor.Assets.close();
  }
  function openImages() { editor.Assets.open({ types: ['image'], accept: 'image/*', select: placeImage }); }

  // ---------- theme dialog ----------
  function themeDialog() {
    var t = theme();
    var names = { c1: 'Brand (dark)', c2: 'Accent', c6: 'Accent 2', c3: 'Text', c4: 'Soft background', c5: 'Page background' };
    var inputs = {};
    var body = [field('Site name', inputs.title = el('input', { maxlength: '120', value: state.site.title || '' }), 'Used in page titles and search results')];
    // one-click looks: style + colours + fonts + corners together
    var pre = el('div', { class: 'kbe-presets' });
    (cfg.presets || []).forEach(function (p) {
      var b = el('button', { type: 'button', class: 'kbe-preset', title: cfg.lookNames[p.theme.style] || '', on: { click: function () {
        ['c1', 'c2', 'c3', 'c4', 'c5', 'head', 'body', 'radius', 'style'].forEach(function (k) { if (p.theme[k] != null) inputs[k].value = String(p.theme[k]); });
        inputs.c6.value = p.theme.c6 || p.theme.c2;
        live();
      } } }, [el('i'), el('span', { text: p.name })]);
      b.firstChild.style.background = 'linear-gradient(135deg,' + p.theme.c1 + ',' + p.theme.c2 + ' 55%,' + (p.theme.c6 || p.theme.c2) + ')';
      pre.appendChild(b);
    });
    // a div, not field()'s label: a label would pass clicks on its text to the first preset
    if (pre.firstChild) body.push(el('div', { class: 'kbe-field' }, [el('span', { text: 'Quick looks' }), pre, el('small', { text: 'Sets the style, colours, fonts and corners in one go; adjust anything after' })]));
    var ssel = el('select');
    Object.keys(cfg.lookNames).forEach(function (k) { ssel.appendChild(el('option', { value: k, text: cfg.lookNames[k], selected: k === t.style })); });
    body.push(field('Style', inputs.style = ssel, 'Accent → Accent 2 is the gradient. Midnight and Luxe expect a dark page background and light text (in Luxe, Accent is the gold); Bold and Retro use Accent 2 as a bright highlight behind dark text; Editorial uses Brand (dark) for its buttons'));
    var row = el('div', { class: 'kbe-swatches' });
    Object.keys(names).forEach(function (k) { row.appendChild(field(names[k], inputs[k] = el('input', { type: 'color', value: t[k] }))); });
    body.push(row);
    var fsel = function (v) { var s = el('select'); Object.keys(cfg.fonts).forEach(function (f) { s.appendChild(el('option', { value: f, text: f, selected: f === v })); }); return s; };
    body.push(field('Heading font', inputs.head = fsel(t.head)), field('Text font', inputs.body = fsel(t.body)));
    body.push(field('Corner rounding', inputs.radius = el('input', { type: 'range', min: '0', max: '40', value: String(t.radius) })));
    body.push(field('Language code', inputs.lang = el('input', { maxlength: '12', value: state.site.lang || 'en', pattern: '[a-z]{2,3}(-[A-Za-z0-9]{2,8})?' }), 'e.g. en, en-GB, fr'));
    var live = function () {
      state.site.theme = { c1: inputs.c1.value, c2: inputs.c2.value, c3: inputs.c3.value, c4: inputs.c4.value, c5: inputs.c5.value, head: inputs.head.value, body: inputs.body.value, radius: inputs.radius.value };
      if (inputs.style.value) { state.site.theme.style = inputs.style.value; state.site.theme.c6 = inputs.c6.value; }
      paintCanvas();
    };
    Object.keys(inputs).forEach(function (k) { inputs[k].addEventListener('input', live); });
    var before = JSON.stringify(state.site);
    dialog('Theme and site settings', body, [{ label: 'Done', primary: true, submit: true, run: function () {
      state.site.title = inputs.title.value.trim().slice(0, 120);
      state.site.lang = /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/.test(inputs.lang.value) ? inputs.lang.value : 'en';
      live();
      if (JSON.stringify(state.site) !== before) markDirty();
    } }]);
  }

  // ---------- history ----------
  function historyDialog() {
    get('/builder/revisions?id=' + cfg.id).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not list versions', true); return; }
      var list = el('ul', { class: 'kbe-revs' });
      if (!j.data.length) list.appendChild(el('li', { text: 'No earlier versions yet — one is kept every time you save or publish.' }));
      j.data.forEach(function (r) {
        list.appendChild(el('li', {}, [el('span', { text: (r.kind === 'publish' ? '🚀 Published ' : '💾 Saved ') + r.when }), el('button', { type: 'button', class: 'kbe-btn', text: 'Restore', on: { click: function () {
          get('/builder/revision?id=' + cfg.id + '&name=' + encodeURIComponent(r.name)).then(function (x) {
            if (!x.ok) { toast(x.error || 'Could not load that version', true); return; }
            var p = x.project, clean = {};
            Object.keys(p).forEach(function (k) { if (k !== 'kbSite' && k !== 'kbPages') clean[k] = p[k]; });
            state.booting = true;
            if (Array.isArray(p.kbSeed)) {
              // the version a template started as: rebuild its pages from HTML
              var old = pages().slice(), meta = {};
              p.kbSeed.forEach(function (sd, i) {
                var np = editor.Pages.add({ name: String(sd.name || 'Page'), component: String(sd.html || '') });
                meta[pid(np)] = (p.kbPages || {})[sd.id] || { title: String(sd.name || 'Page'), slug: i === 0 ? '' : slugify(sd.name || 'page-' + i), desc: '', noindex: false, nav: true };
              });
              editor.Pages.select(pages()[old.length]);
              old.forEach(function (o) { editor.Pages.remove(o); });
              state.meta = meta;
            } else {
              editor.loadProjectData(clean);
              state.meta = p.kbPages || {};
            }
            if (p.kbSite) state.site = p.kbSite;
            ensureMeta(); fillPages(); refreshMenus(); paintCanvas();
            state.booting = false;
            $('#kbe-dialog').close();
            markDirty();
            toast('Version from ' + r.when + ' loaded into the draft. It goes live when you Publish.');
          });
        } } })]));
      });
      dialog('Earlier versions', [list, el('p', { class: 'kbe-hint', text: 'The newest 20 saves are kept, plus publish points from the last 90 days. Restoring changes only the draft.' })]);
    });
  }

  // ---------- preview / publish ----------
  function preview() {
    var w = window.open('about:blank', '_blank');
    save().then(function (ok) {
      if (!ok) { if (w) w.close(); return; }
      post('/builder/preview', {}).then(function (j) {
        if (!j.ok) { if (w) w.close(); toast(j.error || 'Preview failed', true); return; }
        if (w) w.location.href = j.url; else location.href = j.url;
      });
    });
  }
  function publish() {
    dialog('Publish ' + cfg.label + '?', [cfg.status === 'published' ? 'The live site is replaced with your saved draft.' : 'Your saved draft goes live at ' + cfg.url,
      cfg.credit ? 'Each page ends with a small "' + cfg.creditText + '" line.' : null], [
      { label: 'Publish now', primary: true, run: function () {
        status('Publishing…');
        save().then(function (ok) {
          if (!ok) return;
          return post('/builder/publish', {}).then(function (j) {
            if (!j.ok) { status('Not published', 'bad'); toast(j.error || 'Publish failed', true); return; }
            cfg.status = 'published';
            status('Published', 'ok');
            dialog('Your site is live', [el('p', {}, ['Open ', el('a', { href: j.url, target: '_blank', rel: 'noopener', text: j.url })])]);
          });
        });
      } }, { label: 'Cancel' }]);
  }

  // ---------- chrome wiring ----------
  $$('[data-device]').forEach(function (b) { b.addEventListener('click', function () {
    editor.setDevice(b.getAttribute('data-device'));
    $$('[data-device]').forEach(function (x) { x.classList.toggle('on', x === b); });
  }); });
  $$('[data-tab]').forEach(function (b) { b.addEventListener('click', function () {
    var t = b.getAttribute('data-tab');
    $$('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
    $$('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== t; });
  }); });
  var acts = {
    'undo': function () { editor.UndoManager.undo(); }, 'redo': function () { editor.UndoManager.redo(); },
    'save': function () { save().then(function (ok) { if (ok) toast('Draft saved'); }); }, 'preview': preview, 'publish': publish,
    'theme': themeDialog, 'images': openImages, 'history': historyDialog,
    'page-add': function () { pageForm(null, true); }, 'post-add': function () { pageForm(null, true, true); }, 'page-settings': function () { pageForm(editor.Pages.getSelected(), false); }
  };
  $$('[data-act]').forEach(function (b) { b.addEventListener('click', function () { if (editor) acts[b.getAttribute('data-act')](); }); });
  $('#kbe-page').addEventListener('change', function (e) { editor.Pages.select(e.target.value); });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); acts.save(); }
  });
  editorSelectTraits();
  function editorSelectTraits() {
    // selecting an image/link/embed jumps to its settings
    document.addEventListener('kb-ready', function () {
      editor.on('component:selected', function (c) {
        if (c && (c.is('image') || c.is('link') || c.is('kb-soc') || c.is('kb-video') || c.is('kb-map'))) $('[data-tab="traits"]').click();
      });
    });
  }

  status('Loading…');
  get('/builder/load?id=' + cfg.id).then(function (j) {
    if (!j.ok) { status('Could not load', 'bad'); toast(j.error || 'Could not load the site', true); return; }
    state.booting = true;
    boot(j);
    state.booting = false;
    document.dispatchEvent(new Event('kb-ready'));
  }, function () { status('Could not load', 'bad'); });
})();
