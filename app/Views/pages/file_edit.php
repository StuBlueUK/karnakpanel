<?php $cmv = '/assets/vendor/codemirror-5.65.20/codemirror.bundle.min'; $cmf = __DIR__.'/../../../public'.$cmv; ?>
<link rel="stylesheet" href="<?=$cmv?>.css?v=<?=@filemtime($cmf.'.css')?>">
<style>
/* CodeMirror themed from the panel tokens (follows data-theme light/dark) */
.kp-ed{--k:#c678dd;--s:#98c379;--n:#d19a66;--c:#7f848e;--d:#61afef;--v:#e06c75;--b:#56b6c2;--al:rgba(255,255,255,.05);--sel:rgba(56,189,248,.25)}
[data-theme="light"] .kp-ed{--k:#a626a4;--s:#50a14f;--n:#986801;--c:#8b8d94;--d:#4078f2;--v:#e45649;--b:#0184bc;--al:rgba(2,132,199,.06);--sel:rgba(2,132,199,.18)}
.kp-ed .CodeMirror{height:65vh;min-height:320px;border:1px solid var(--line);border-radius:8px;background:var(--bg2);color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;line-height:1.55}
.kp-ed .CodeMirror-gutters{background:var(--card);border-right:1px solid var(--line)}
.kp-ed .CodeMirror-linenumber{color:var(--muted)}
.kp-ed .CodeMirror-cursor{border-left-color:var(--text)}
.kp-ed .CodeMirror-activeline-background{background:var(--al)}
.kp-ed .CodeMirror-selected,.kp-ed .CodeMirror-focused .CodeMirror-selected{background:var(--sel)}
.kp-ed .CodeMirror-matchingbracket,.kp-ed .CodeMirror-matchingtag{color:inherit!important;background:var(--sel);outline:1px solid var(--muted)}
.kp-ed .cm-keyword,.kp-ed .cm-qualifier{color:var(--k)}
.kp-ed .cm-string,.kp-ed .cm-string-2{color:var(--s)}
.kp-ed .cm-number,.kp-ed .cm-atom,.kp-ed .cm-attribute{color:var(--n)}
.kp-ed .cm-comment{color:var(--c);font-style:italic}
.kp-ed .cm-def,.kp-ed .cm-meta,.kp-ed .cm-header{color:var(--d)}
.kp-ed .cm-variable-2,.kp-ed .cm-variable-3,.kp-ed .cm-tag,.kp-ed .cm-type{color:var(--v)}
.kp-ed .cm-builtin,.kp-ed .cm-operator,.kp-ed .cm-property{color:var(--b)}
.kp-ed .cm-variable,.kp-ed .cm-bracket{color:var(--text)}
.kp-ed .cm-error{color:#f87171}
.kp-ed .CodeMirror-dialog{background:var(--card);color:var(--text);border-color:var(--line)}
.kp-ed .CodeMirror-dialog input{width:16em;background:var(--bg2);color:var(--text)}
#visWrap{display:none}
.kp-ed [aria-pressed="false"]{opacity:.65}
.kp-ed [aria-pressed="true"]{box-shadow:0 0 0 2px var(--sky) inset}
#visBar{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px}
#visBar .btn{min-width:34px}
#visFrame{width:100%;height:65vh;min-height:320px;border:1px solid var(--line);border-radius:8px;background:#fff}
</style>
<h2>✏️ Edit: <?=Helpers::e($name)?> <small style="color:var(--muted)"><?=Helpers::e($rel === '' ? '/home' : $rel)?></small></h2>
<div class="card kp-ed"><form method="post" action="/files/edit-save" id="editform">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input type="hidden" name="p" value="<?=Helpers::e($rel)?>">
<input type="hidden" name="name" value="<?=Helpers::e($name)?>">
<input type="hidden" name="eol" value="<?=Helpers::e($eol)?>">
<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
<button class="btn">Save</button>
<a class="btn small" href="/files?p=<?=urlencode($rel)?>">Cancel</a>
<?php if ($visual): ?>
<span style="display:inline-flex;gap:4px">
<button type="button" class="btn small" id="modeCode" aria-pressed="true">⌨ Code</button>
<button type="button" class="btn small" id="modeVis" aria-pressed="false">👁 Visual</button>
</span>
<?php endif; ?>
<span class="kpCodeOnly" style="display:contents">
<button type="button" class="btn small" id="wrapbtn">Wrap: off</button>
<button type="button" class="btn small" id="fontDown" title="smaller text">A−</button>
<button type="button" class="btn small" id="fontUp" title="larger text">A+</button>
<button type="button" class="btn small" id="findBtn" title="find (Ctrl+F)">🔍</button>
<button type="button" class="btn small" id="replBtn" title="find / replace (Shift+Ctrl+F)">⇄</button>
<button type="button" class="btn small" id="gotoBtn" title="go to line (Alt+G)">→Ln</button>
</span>
<span id="langPill" class="pill" style="display:none"></span>
<span id="editstatus" style="color:var(--muted);font-size:13px">Ctrl+S saves · Tab indents</span>
</div>
<?php if ($visual): ?>
<div id="visWrap">
<div id="visBar">
<select id="visBlock" title="paragraph style" style="width:auto">
<option value="">Style…</option><option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option><option value="blockquote">Quote</option><option value="pre">Preformatted</option>
</select>
<button type="button" class="btn small" data-cmd="bold" title="bold"><b>B</b></button>
<button type="button" class="btn small" data-cmd="italic" title="italic"><i>I</i></button>
<button type="button" class="btn small" data-cmd="underline" title="underline"><u>U</u></button>
<button type="button" class="btn small" data-cmd="strikeThrough" title="strikethrough"><s>S</s></button>
<button type="button" class="btn small" data-cmd="insertUnorderedList" title="bullet list">• ≡</button>
<button type="button" class="btn small" data-cmd="insertOrderedList" title="numbered list">1. ≡</button>
<button type="button" class="btn small" data-cmd="justifyLeft" title="align left">⇤</button>
<button type="button" class="btn small" data-cmd="justifyCenter" title="center">↔</button>
<button type="button" class="btn small" data-cmd="justifyRight" title="align right">⇥</button>
<button type="button" class="btn small" data-cmd="createLink" title="link">🔗</button>
<button type="button" class="btn small" data-cmd="unlink" title="remove link">⛓̸</button>
<button type="button" class="btn small" data-cmd="insertImage" title="image (URL)">🖼</button>
<button type="button" class="btn small" data-cmd="insertHorizontalRule" title="horizontal line">―</button>
<button type="button" class="btn small" data-cmd="removeFormat" title="clear formatting">⌫</button>
<button type="button" class="btn small" data-cmd="undo" title="undo">↶</button>
<button type="button" class="btn small" data-cmd="redo" title="redo">↷</button>
</div>
<iframe id="visFrame" sandbox="allow-same-origin" title="visual editor"></iframe>
<small style="color:var(--muted)">Visual mode rebuilds the HTML (spacing/formatting change, scripts don't run here). Switch back to Code to review before saving.</small>
</div>
<?php endif; ?>
<textarea name="content" id="editor" rows="25" style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;line-height:1.55;tab-size:4;min-height:50vh" wrap="off" spellcheck="false"><?=Helpers::e($src ?? '')?></textarea>
</form>
<small style="color:var(--muted)">Max 1MB. PHP changes apply immediately (no restart). Line endings are kept as they were (<?=$eol === 'crlf' ? 'CRLF' : 'LF'?>).</small></div>
<script src="<?=$cmv?>.js?v=<?=@filemtime($cmf.'.js')?>"></script>
<script>
(function(){
  var NAME = <?=json_encode($name, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var BASE = <?=json_encode($siteBase, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var form = document.getElementById('editform'), ta = document.getElementById('editor');
  var st = document.getElementById('editstatus'), dirty = false;
  function ls(k, v){ try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
  function submitForm(){ if (form.requestSubmit) form.requestSubmit(); else { form.dispatchEvent(new Event('submit', {cancelable: true})); form.submit(); } }
  window.addEventListener('beforeunload', function(e){ if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  if (!window.CodeMirror) { // asset missing: plain textarea still posts
    ta.addEventListener('input', function(){ dirty = true; });
    form.addEventListener('submit', function(){ dirty = false; });
    return;
  }

  // ---- language by file name ----
  var lower = NAME.toLowerCase(), ext = lower.indexOf('.') >= 0 ? lower.split('.').pop() : '';
  var MODES = {
    php: ['application/x-httpd-php', 'PHP'], phtml: ['application/x-httpd-php', 'PHP'], inc: ['application/x-httpd-php', 'PHP'],
    html: ['htmlmixed', 'HTML'], htm: ['htmlmixed', 'HTML'], tpl: ['htmlmixed', 'HTML'],
    css: ['css', 'CSS'], scss: ['text/x-scss', 'SCSS'], less: ['text/x-less', 'LESS'],
    js: ['javascript', 'JavaScript'], mjs: ['javascript', 'JavaScript'], cjs: ['javascript', 'JavaScript'],
    json: ['application/json', 'JSON'], webmanifest: ['application/json', 'JSON'], ts: ['text/typescript', 'TypeScript'],
    xml: ['xml', 'XML'], svg: ['xml', 'SVG'], rss: ['xml', 'XML'],
    sql: ['text/x-mysql', 'SQL'], sh: ['shell', 'Shell'], bash: ['shell', 'Shell'],
    yml: ['yaml', 'YAML'], yaml: ['yaml', 'YAML'], md: ['markdown', 'Markdown'], markdown: ['markdown', 'Markdown'],
    ini: ['properties', 'INI'], conf: ['properties', 'Config'], cfg: ['properties', 'Config'], env: ['properties', 'ENV'],
    py: ['python', 'Python'], c: ['text/x-csrc', 'C'], h: ['text/x-csrc', 'C'], java: ['text/x-java', 'Java']
  };
  var NAMES = {'.htaccess': ['properties', '.htaccess'], '.user.ini': ['properties', 'INI'], '.env': ['properties', 'ENV'], 'php.ini': ['properties', 'INI'], 'robots.txt': ['properties', 'robots.txt']};
  var lang = NAMES[lower] || MODES[ext] || [null, ''];
  if (lang[1]) { var lp = document.getElementById('langPill'); lp.textContent = lang[1]; lp.style.display = ''; }

  // indent style: follow the file (tabs if tab-indented lines dominate)
  var tabs = (ta.value.match(/^\t/mg) || []).length, spc = (ta.value.match(/^ {2,}/mg) || []).length;
  var useTabs = tabs > spc;

  var fs = Math.min(24, Math.max(12, parseInt(ls('fm-font') || '14', 10) || 14));
  var cm = CodeMirror.fromTextArea(ta, {
    mode: lang[0], lineNumbers: true, indentUnit: 4, tabSize: 4, indentWithTabs: useTabs,
    lineWrapping: false, matchBrackets: true, autoCloseBrackets: true, autoCloseTags: true,
    matchTags: {bothTags: true}, styleActiveLine: true, viewportMargin: 50,
    extraKeys: {
      'Ctrl-S': submitForm, 'Cmd-S': submitForm,
      'Ctrl-/': 'toggleComment', 'Cmd-/': 'toggleComment',
      'Tab': function(c){ if (c.somethingSelected()) c.indentSelection('add'); else c.replaceSelection(useTabs ? '\t' : '    ', 'end'); },
      'Shift-Tab': function(c){ c.indentSelection('subtract'); },
      'Alt-G': 'jumpToLine'
    }
  });
  var wrap = cm.getWrapperElement();
  function setFont(){ wrap.style.fontSize = fs + 'px'; cm.refresh(); }
  setFont();
  document.getElementById('fontUp').addEventListener('click', function(){ fs = Math.min(24, fs + 1); setFont(); ls('fm-font', String(fs)); });
  document.getElementById('fontDown').addEventListener('click', function(){ fs = Math.max(12, fs - 1); setFont(); ls('fm-font', String(fs)); });
  document.getElementById('wrapbtn').addEventListener('click', function(){
    var on = !cm.getOption('lineWrapping'); cm.setOption('lineWrapping', on);
    this.textContent = 'Wrap: ' + (on ? 'on' : 'off');
  });
  document.getElementById('findBtn').addEventListener('click', function(){ cm.focus(); cm.execCommand('find'); });
  document.getElementById('replBtn').addEventListener('click', function(){ cm.focus(); cm.execCommand('replace'); });
  document.getElementById('gotoBtn').addEventListener('click', function(){ cm.focus(); cm.execCommand('jumpToLine'); });
  function pos(){
    var c = cm.getCursor();
    st.textContent = (dirty ? '● unsaved · ' : '') + 'Ln ' + (c.line + 1) + ', Col ' + (c.ch + 1) + ' · Ctrl+S saves';
  }
  cm.on('change', function(){ dirty = true; pos(); });
  cm.on('cursorActivity', pos); pos();

  // ---- visual (WYSIWYG) mode: .html/.htm only ----
  // SECURITY: the file's HTML is attacker-controllable (any site file; admins
  // open users' files). It is rendered ONLY inside a sandboxed iframe WITHOUT
  // allow-scripts (no scripts, inline handlers, forms or popups), plus an
  // injected CSP meta. Serialized HTML only ever goes back into the editor
  // value — never into this page's DOM.
  var vis = false, vDirty = false, vDoc = null, doctype = '', fragment = false, preC = [], postC = [];
  var frame = document.getElementById('visFrame');
  var GUARD = 'data-kp-guard', HE = 'data-kp-http-equiv';
  function guardEl(d, tag, attrs){
    var el = d.createElement(tag);
    for (var k in attrs) el.setAttribute(k, attrs[k]);
    el.setAttribute(GUARD, '1'); return el;
  }
  function openVisual(){
    var src = cm.getValue();
    var m = src.match(/^\s*(<!doctype[^>]*>)/i); doctype = m ? m[1] : '';
    // a partial (header.html, analytics snippet) vs a document — optional
    // <html>/<body> tags may be omitted, so any of these marks a document
    fragment = !/<!doctype|<html[\s>]|<head[\s>]|<body[\s>]/i.test(src);
    var d = new DOMParser().parseFromString(src, 'text/html'); // inert parse
    // comments outside <html> (e.g. "saved from") never reach the srcdoc
    preC = []; postC = []; var past = false;
    Array.prototype.forEach.call(d.childNodes, function(n){
      if (n === d.documentElement) past = true;
      else if (n.nodeType === 8) (past ? postC : preC).push('<!--' + n.data + '-->');
    });
    // neutralise meta refresh (CSP can't stop it); restored on the way back
    Array.prototype.forEach.call(d.querySelectorAll('meta[http-equiv]'), function(el){
      if (/^\s*refresh\s*$/i.test(el.getAttribute('http-equiv'))) { el.setAttribute(HE, el.getAttribute('http-equiv')); el.setAttribute('http-equiv', 'x-kp-off'); }
    });
    var head = d.head, first = head.firstChild;
    head.insertBefore(guardEl(d, 'meta', {'http-equiv': 'Content-Security-Policy',
      content: "default-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:; form-action 'none'"}), first);
    if (BASE) head.insertBefore(guardEl(d, 'base', {href: BASE}), first);
    frame.onload = function(){
      vDoc = frame.contentDocument;
      if (!vDoc) return;
      vDoc.designMode = 'on';
      try { vDoc.execCommand('styleWithCSS', false, false); } catch (e) {}
      vDoc.addEventListener('input', function(){ vDirty = true; dirty = true; pos(); });
      vDoc.addEventListener('click', function(e){ if (e.target.closest && e.target.closest('a')) e.preventDefault(); });
      vDoc.addEventListener('keydown', function(e){ if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); submitForm(); } });
    };
    frame.srcdoc = '<!DOCTYPE html>' + d.documentElement.outerHTML;
  }
  function visualHtml(){
    var root = vDoc.documentElement.cloneNode(true);
    Array.prototype.forEach.call(root.querySelectorAll('[' + GUARD + ']'), function(el){ el.remove(); });
    Array.prototype.forEach.call(root.querySelectorAll('[' + HE + ']'), function(el){ el.setAttribute('http-equiv', el.getAttribute(HE)); el.removeAttribute(HE); }); // value swap keeps attribute order
    // fragment: the parser hoists leading <script>/<style>/<link>/<meta>/
    // <title> into <head> — keep them, in order, ahead of the body content
    if (fragment) { var hd = root.querySelector('head'), b = root.querySelector('body'); return (hd ? hd.innerHTML : '') + (b ? b.innerHTML : ''); }
    return (doctype ? doctype + '\n' : '') + preC.map(function(c){ return c + '\n'; }).join('')
      + root.outerHTML + '\n' + postC.map(function(c){ return c + '\n'; }).join('');
  }
  function pullVisual(){ if (vis && vDirty && vDoc) { cm.setValue(visualHtml()); vDirty = false; } }
  function setMode(v){
    if (v === vis) return;
    if (!v) { pullVisual(); }
    vis = v;
    wrap.style.display = v ? 'none' : '';
    document.getElementById('visWrap').style.display = v ? 'block' : 'none';
    document.querySelector('.kpCodeOnly').style.display = v ? 'none' : 'contents';
    document.getElementById('modeCode').setAttribute('aria-pressed', String(!v));
    document.getElementById('modeVis').setAttribute('aria-pressed', String(v));
    if (v) { vDirty = false; openVisual(); } else { cm.refresh(); cm.focus(); }
  }
  var BAD_URL = /^\s*(javascript|vbscript|data(?!:image\/(png|jpe?g|gif|webp);))/i;
  if (frame) {
    document.getElementById('modeCode').addEventListener('click', function(){ setMode(false); });
    document.getElementById('modeVis').addEventListener('click', function(){ setMode(true); });
    Array.prototype.forEach.call(document.querySelectorAll('#visBar [data-cmd]'), function(btn){
      btn.addEventListener('mousedown', function(e){ e.preventDefault(); }); // keep the selection
      btn.addEventListener('click', function(){
        if (!vDoc) return;
        var cmd = btn.getAttribute('data-cmd'), val = null;
        if (cmd === 'createLink' || cmd === 'insertImage') {
          val = prompt(cmd === 'createLink' ? 'Link URL:' : 'Image URL:', cmd === 'createLink' ? 'https://' : '');
          if (!val) return;
          if (BAD_URL.test(val)) { alert('That kind of URL is not allowed.'); return; }
        }
        vDoc.execCommand(cmd, false, val);
        vDirty = true; dirty = true; pos();
        frame.contentWindow.focus();
      });
    });
    document.getElementById('visBlock').addEventListener('change', function(){
      if (vDoc && this.value) { vDoc.execCommand('formatBlock', false, '<' + this.value + '>'); vDirty = true; dirty = true; pos(); }
      this.value = ''; frame.contentWindow.focus();
    });
  }

  form.addEventListener('submit', function(){ pullVisual(); cm.save(); dirty = false; });
})();
</script>
