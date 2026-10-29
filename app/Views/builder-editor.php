<?php
// Karnak Builder editor — standalone page (no panel layout) so it can run
// under script-src 'self': no inline script or handlers anywhere here.
// Config travels as a JSON data block (never executed).
$v = fn(string $f) => '?v='.(int)@filemtime(__DIR__.'/../../public'.$f);
$gj = '/assets/vendor/grapesjs-0.23.6';
$bname = !empty($kbBrand['name']) ? (string)$kbBrand['name'] : 'KarnakPanel';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karnak Builder — <?=Helpers::e($kbCfg['label'])?></title>
<link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?=$gj?>/grapes.min.css">
<link rel="stylesheet" href="/assets/css/karnakbuilder.css<?=$v('/assets/css/karnakbuilder.css')?>">
<?php if (!empty($kbBrand['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$kbBrand['accent'])):?><style>:root{--kbe-accent:<?=$kbBrand['accent']?>}</style><?php endif;?>
</head>
<body>
<script type="application/json" id="kb-config"><?=json_encode($kbCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)?></script>
<header class="kbe-top">
  <a class="kbe-back" href="/builder" title="Back to <?=Helpers::e($bname)?>">← Sites</a>
  <div class="kbe-site"><b>Karnak Builder</b><small><?=Helpers::e($kbCfg['label'])?></small></div>
  <div class="kbe-pages">
    <label class="kbe-vh" for="kbe-page">Page</label>
    <select id="kbe-page" title="Page being edited"></select>
    <button type="button" class="kbe-btn" data-act="page-settings" title="Page name, address and search settings">⚙</button>
    <button type="button" class="kbe-btn" data-act="page-add" title="Add a page">＋ Page</button>
    <button type="button" class="kbe-btn" data-act="post-add" title="Add a blog post (listed at /blog/)">＋ Post</button>
  </div>
  <div class="kbe-devices" role="group" aria-label="Device">
    <button type="button" class="kbe-btn on" data-device="desktop" title="Desktop">🖥</button>
    <button type="button" class="kbe-btn" data-device="tablet" title="Tablet">📱</button>
    <button type="button" class="kbe-btn" data-device="mobile" title="Mobile">📲</button>
  </div>
  <div class="kbe-tools">
    <button type="button" class="kbe-btn" data-act="undo" title="Undo (Ctrl+Z)">↶</button>
    <button type="button" class="kbe-btn" data-act="redo" title="Redo (Ctrl+Shift+Z)">↷</button>
    <button type="button" class="kbe-btn" data-act="theme" title="Colours, fonts and site settings">🎨 Theme</button>
    <button type="button" class="kbe-btn" data-act="images" title="Your uploaded images">🖼 Images</button>
    <button type="button" class="kbe-btn" data-act="history" title="Earlier versions">🕘 History</button>
  </div>
  <span class="kbe-status" id="kbe-status" role="status" aria-live="polite">Loading…</span>
  <div class="kbe-go">
    <button type="button" class="kbe-btn" data-act="save" title="Save the draft (Ctrl+S)">Save</button>
    <button type="button" class="kbe-btn" data-act="preview" title="Open the saved draft in a new tab">Preview</button>
    <button type="button" class="kbe-btn kbe-primary" data-act="publish" title="Put the saved draft live on <?=Helpers::e($kbCfg['label'])?>">Publish</button>
  </div>
</header>
<div class="kbe-banner" id="kbe-banner" hidden></div>
<main class="kbe-main">
  <aside class="kbe-left" aria-label="Blocks"><div class="kbe-tabs"><b>Blocks</b><small>drag onto the page</small></div><div id="kbe-blocks"></div></aside>
  <div class="kbe-canvas"><div id="kbe-gjs"></div></div>
  <aside class="kbe-right" aria-label="Settings">
    <div class="kbe-tabs" role="tablist">
      <button type="button" class="kbe-tab on" data-tab="styles" role="tab">Style</button>
      <button type="button" class="kbe-tab" data-tab="traits" role="tab">Settings</button>
      <button type="button" class="kbe-tab" data-tab="layers" role="tab">Layers</button>
    </div>
    <div class="kbe-pane" data-pane="styles"><div id="kbe-selectors"></div><div id="kbe-styles"></div></div>
    <div class="kbe-pane" data-pane="traits" hidden><div id="kbe-traits"></div><p class="kbe-hint">Select an element on the page to change its link, image text or video address.</p></div>
    <div class="kbe-pane" data-pane="layers" hidden><div id="kbe-layers"></div></div>
  </aside>
</main>
<dialog class="kbe-dialog" id="kbe-dialog"><form method="dialog" class="kbe-dform"><h3 id="kbe-dtitle"></h3><div id="kbe-dbody"></div><div class="kbe-dfoot" id="kbe-dfoot"></div></form></dialog>
<div class="kbe-toast" id="kbe-toast" role="alert" hidden></div>
<script src="<?=$gj?>/grapes.min.js"></script>
<script src="/assets/js/karnakbuilder.js<?=$v('/assets/js/karnakbuilder.js')?>"></script>
</body>
</html>
