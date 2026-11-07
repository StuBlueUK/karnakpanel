// Passkeys (WebAuthn) for the 2FA page and the login second step.
// Server side: app/Core/WebAuthn.php. Binary fields travel as base64url;
// requests are form-encoded so the normal CSRF check applies.
(function () {
  'use strict';
  var b64u = function (buf) {
    var s = '', b = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  var unb64u = function (s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var r = atob(s), out = new Uint8Array(r.length);
    for (var i = 0; i < r.length; i++) out[i] = r.charCodeAt(i);
    return out;
  };
  var post = function (url, fields) {
    return fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(fields).toString() })
      .then(function (r) { return r.json().catch(function () { return { error: 'Server error (' + r.status + ')' }; }); });
  };
  var supported = function () {
    return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  };
  // browser-side failures in plain words (cancel/timeout are not errors)
  var why = function (e) {
    if (!e) return 'Passkey failed';
    if (e.name === 'NotAllowedError') return 'Cancelled, timed out or blocked by the browser — try again. If this address shows a certificate warning, browsers block passkeys: use an address with a valid certificate';
    if (e.name === 'InvalidStateError') return 'This device already has a passkey for your account';
    if (e.name === 'SecurityError') return 'This address can\'t use passkeys (needs the panel\'s real domain over HTTPS)';
    return e.message || String(e);
  };

  // 2FA page: <form id="pk-add"> with csrf, name, password; <span id="pk-msg">
  var addForm = document.getElementById('pk-add');
  if (addForm) {
    var msg = document.getElementById('pk-msg');
    var say = function (t, bad) { msg.textContent = t; msg.style.color = bad ? 'var(--err)' : ''; };
    addForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!supported()) { say('This browser does not support passkeys', true); return; }
      var csrf = addForm.elements.csrf.value, btn = addForm.querySelector('button');
      btn.disabled = true; say('Waiting for your device…');
      post('/2fa/passkey/options', { csrf: csrf, password: addForm.elements.password.value }).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Refused');
        var o = j.options;
        o.challenge = unb64u(o.challenge);
        o.user.id = unb64u(o.user.id);
        o.excludeCredentials = o.excludeCredentials.map(function (c) { return { type: c.type, id: unb64u(c.id) }; });
        return navigator.credentials.create({ publicKey: o });
      }).then(function (cred) {
        var r = cred.response;
        if (typeof r.getPublicKey !== 'function' || !r.getPublicKey()) throw new Error('This browser is too old for passkeys here — update it');
        say('Checking…');
        return post('/2fa/passkey/add', { csrf: csrf, name: addForm.elements.name.value,
          id: b64u(cred.rawId), cdj: b64u(r.clientDataJSON), ad: b64u(r.getAuthenticatorData()),
          pk: b64u(r.getPublicKey()), alg: String(r.getPublicKeyAlgorithm()) });
      }).then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Refused');
        location.reload();
      }).catch(function (e) { btn.disabled = false; say(why(e), true); });
    });
  }

  // login second step: <button id="pk-login" data-csrf="…">; <span id="pk-msg">
  var loginBtn = document.getElementById('pk-login');
  if (loginBtn) {
    var lmsg = document.getElementById('pk-msg');
    var lsay = function (t, bad) { lmsg.textContent = t; lmsg.style.color = bad ? 'var(--err)' : ''; };
    if (!supported()) { loginBtn.disabled = true; lsay('This browser does not support passkeys — use your app code', true); }
    loginBtn.addEventListener('click', function () {
      var csrf = loginBtn.getAttribute('data-csrf');
      loginBtn.disabled = true; lsay('Waiting for your device…');
      post('/login/passkey/options', { csrf: csrf }).then(function (j) {
        if (!j.ok) { if (j.to) location.href = j.to; throw new Error(j.error || 'Refused'); }
        var o = j.options;
        o.challenge = unb64u(o.challenge);
        o.allowCredentials = o.allowCredentials.map(function (c) { return { type: c.type, id: unb64u(c.id) }; });
        return navigator.credentials.get({ publicKey: o });
      }).then(function (a) {
        var r = a.response;
        lsay('Checking…');
        return post('/login/passkey', { csrf: csrf, id: b64u(a.rawId), cdj: b64u(r.clientDataJSON),
          ad: b64u(r.authenticatorData), sig: b64u(r.signature) });
      }).then(function (j) {
        if (!j.ok) { if (j.to) location.href = j.to; throw new Error(j.error || 'Refused'); }
        location.href = j.to || '/';
      }).catch(function (e) { loginBtn.disabled = false; lsay(why(e), true); });
    });
  }
})();
