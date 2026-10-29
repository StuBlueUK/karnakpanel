<?php
$csrf = Helpers::csrf();
$used = count($sites);
$canMake = $limit === null || $used < $limit;
$tplDir = __DIR__.'/../../Builder/templates';
$templates = ['blank' => ['name' => 'Blank', 'for' => 'Start from scratch']];
foreach (is_dir($tplDir) ? (array)glob("$tplDir/*/meta.json") : [] as $f) {
    $slug = basename(dirname((string)$f));
    $m = json_decode((string)file_get_contents((string)$f), true);
    if (preg_match('/^[a-z0-9-]{1,32}$/', $slug) && is_array($m) && is_file(dirname((string)$f).'/project.json')) {
        $templates[$slug] = ['name' => (string)($m['name'] ?? $slug), 'for' => (string)($m['for'] ?? ''), 'cat' => (string)($m['category'] ?? 'Other'),
            'theme' => Builder::theme(is_array($m['theme'] ?? null) ? $m['theme'] : []), 'pages' => count((array)($m['pages'] ?? []))];
    }
}
// Blank first, then grouped by category (alphabetical), then by what it's for
uksort($templates, function ($a, $b) use ($templates) {
    if ($a === 'blank' || $b === 'blank') return $a === 'blank' ? -1 : 1;
    return [$templates[$a]['cat'], $templates[$a]['for']] <=> [$templates[$b]['cat'], $templates[$b]['for']];
});
$statusPill = ['draft' => ['warn', 'Draft'], 'published' => ['', 'Live'], 'unpublished' => ['warn', 'Offline']];
?>
<div class="fwhead"><h2>🎨 Karnak Builder <small style="color:var(--muted)">drag-and-drop websites</small></h2>
<span class="pill"><?= $used ?><?= $limit !== null ? ' of '.(int)$limit : '' ?> site<?= ($limit ?? $used) === 1 ? '' : 's' ?></span></div>

<?php if ($limit === 0):?>
<div class="card"><b>Karnak Builder is not part of your plan.</b> Ask your host to enable builder sites on your package.</div>
<?php endif;?>

<?php if ($sites):?>
<div class="card"><h3>Your sites</h3><table>
<tr><th>Site</th><th>Status</th><th>Last saved</th><th>Published</th><th></th></tr>
<?php foreach ($sites as $s): [$cls, $txt] = $statusPill[$s['status']] ?? ['', $s['status']]; $label = Builder::label($s); ?>
<tr><td><b><?=Helpers::e($s['title'] !== '' ? $s['title'] : $label)?></b><br><small style="color:var(--muted)"><?=Helpers::e($label)?></small></td>
<td><span class="pill <?=$cls?>"><?=Helpers::e($txt)?></span></td>
<td><?=Helpers::e($s['saved_at'] ?? '—')?></td>
<td><?php if ($s['status'] === 'published'):?><a href="<?=Helpers::e(Builder::url($s))?>" target="_blank" rel="noopener"><?=Helpers::e($s['published_at'] ?? '')?> ↗</a><?php else:?>—<?php endif;?></td>
<td style="white-space:nowrap"><a class="btn small" href="/builder/edit?id=<?=(int)$s['id']?>">Open editor</a>
<?php if ($s['status'] === 'published'):?><form method="post" action="/builder/unpublish" style="display:inline" onsubmit="return confirm(<?=Helpers::e(json_encode('Take '.$label.' offline? Its pages are removed and a "coming soon" page shows. The design is kept.', JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>)"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="btn small">Take offline</button></form><?php endif;?>
<?php if ($s['status'] === 'unpublished'):?><form method="post" action="/builder/republish" style="display:inline" data-wait="Putting <?=Helpers::e($label)?> back online…"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="btn small" title="Publish the last saved design again">Put back online</button></form><?php endif;?>
<form method="post" action="/builder/delete" style="display:inline" onsubmit="return confirm(<?=Helpers::e(json_encode('Delete the builder site for '.$label.'? The design, its history and uploaded images are removed'.($s['status'] === 'published' ? ', and the live pages go offline' : '').'. This cannot be undone.', JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>)"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=(int)$s['id']?>"><button class="btn small danger">Delete</button></form></td></tr>
<?php endforeach;?></table></div>
<?php endif;?>

<?php if ($limit !== 0):?>
<div class="card"><h3>New site</h3>
<?php if (empty($domains)):?><p><b>Add a domain first.</b> A builder site is published to one of your domains. <a class="btn small" href="/domains">Go to Domains</a></p>
<?php elseif (!$canMake):?><p>Your plan allows <?=(int)$limit?> builder site<?= $limit === 1 ? '' : 's' ?> and all are in use. Delete one, or ask your host for more.</p>
<?php else:?>
<form class="inline" method="post" action="/builder/create">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<div style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)"><label for="kb-new-domain">Domain</label><select id="kb-new-domain" name="domain_id" required><?php foreach ($domains as $d):?><option value="<?=(int)$d['id']?>"><?=Helpers::e($d['domain'])?></option><?php endforeach;?></select></div>
<div style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)"><label for="kb-new-subdir">Folder (optional)</label><input id="kb-new-subdir" name="subdir" pattern="[A-Za-z0-9_\-]{0,64}" maxlength="64" placeholder="empty = domain root" title="Leave empty to use the whole domain, or give a folder like landing"></div>
<div style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)"><label for="kb-new-title">Site name</label><input id="kb-new-title" name="title" maxlength="120" placeholder="My business"></div>
<fieldset class="kbpick"><legend>Template — press Preview to look around one before you pick it</legend>
<div class="kbpick-cats" role="group" aria-label="Show category"><button type="button" class="btn small kbcat on" data-cat="">All</button><?php foreach (array_unique(array_filter(array_column($templates, 'cat'))) as $c):?><button type="button" class="btn small kbcat" data-cat="<?=Helpers::e($c)?>"><?=Helpers::e($c)?></button><?php endforeach;?></div>
<div class="kbpick-list"><?php $tplCat = null; foreach ($templates as $k => $t): $tc = $t['cat'] ?? ''; if ($tc !== $tplCat && $tc !== ''):?><div class="kbpick-cat" data-cat="<?=Helpers::e($tc)?>"><?=Helpers::e($tc)?></div><?php endif; $tplCat = $tc;
  $th = $t['theme'] ?? null;?>
<div class="kbtpl" data-cat="<?=Helpers::e($tc)?>"><label>
<?php if ($th):?><span class="kbtpl-sw" aria-hidden="true" style="background:<?=$th['c5']?>;color:<?=$th['c3']?>;border-radius:<?=min(14, (int)$th['radius'])?>px"><i style="background:<?=$th['c1']?>"></i><em style="font-family:<?=Helpers::e(Builder::FONTS[$th['head']])?>;color:<?=$th['c1']?>">Aa</em><span><i style="background:<?=$th['c2']?>"></i><i style="background:<?=$th['c6'] ?? $th['c4']?>"></i><i style="background:<?=$th['c4']?>"></i></span></span>
<?php else:?><span class="kbtpl-sw kbtpl-blank" aria-hidden="true">＋</span><?php endif;?>
<input type="radio" name="template" value="<?=Helpers::e($k)?>" data-name="<?=Helpers::e($t['name'])?>"<?= $k === 'blank' ? ' checked' : '' ?>><b><?=Helpers::e($t['name'])?></b><small><?=Helpers::e($t['for'])?></small>
<?php if ($th):?><small class="kbtpl-meta"><?=Helpers::e(explode(' (', Builder::LOOKS[$th['style'] ?? ''])[0])?> · <?=(int)$t['pages']?> pages · <?=Helpers::e($th['head'])?></small><?php endif;?></label>
<?php if ($th):?><button type="button" class="btn small kbtpl-view" data-tpl="<?=Helpers::e($k)?>" data-name="<?=Helpers::e($t['name'].' — '.$t['for'])?>">Preview</button><?php endif;?></div>
<?php endforeach;?></div></fieldset>
<span class="kbpick-chosen">Template: <b id="kbtpl-chosen">Blank</b></span>
<button class="btn small" style="align-self:flex-end">Create and open editor</button></form>
<dialog id="kbtpl-dlg" class="kbtpl-dlg" aria-labelledby="kbtpl-dlg-name"><div class="kbtpl-bar"><b id="kbtpl-dlg-name"></b>
<span class="kbtpl-dev" role="group" aria-label="Preview size"><button type="button" class="btn small on" data-w="">Desktop</button><button type="button" class="btn small" data-w="390">Phone</button></span>
<a id="kbtpl-dlg-open" class="btn small" target="_blank" rel="noopener">New tab ↗</a><button type="button" class="btn small" id="kbtpl-dlg-use">Use this template</button><button type="button" class="btn small" id="kbtpl-dlg-close" aria-label="Close preview">✕</button></div>
<div class="kbtpl-stage"><iframe id="kbtpl-dlg-frame" title="Template preview"></iframe></div>
<small class="kbtpl-note">Example text and grey image boxes — you change all of it in the editor. Links inside the preview open its other pages.</small></dialog>
<small style="color:var(--muted)">The folder must be empty (a stock "coming soon" page is fine). Builder sites are static pages: fast, nothing to update, no database. Nothing goes live until you press Publish.</small>
<?php endif;?></div>
<?php endif;?>

<div class="card"><small style="color:var(--muted)">Published pages end with a small "<?=Helpers::e(Builder::CREDIT_TEXT)?>" line<?= $credit ? '' : ' — switched off for your account' ?>. Visitor numbers for builder sites are on the <a href="/stats">Stats</a> page.</small></div>
