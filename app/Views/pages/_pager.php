<?php // shared pager: caller sets $pagerBase ('/email?'), $pagerLabel ('mailboxes'); $pg/$pages/$total come from Helpers::paginate()
if (($pages ?? 1) > 1): ?><div style="display:flex;gap:10px;align-items:center;margin-top:8px;color:var(--muted)">
<?php if (($pg ?? 1) > 1): ?><a class="btn small" href="<?=Helpers::e(($pagerBase ?? '?').'page='.(($pg ?? 1) - 1))?>">« Prev</a><?php endif; ?>
<span>Page <?=($pg ?? 1)?> of <?=($pages ?? 1)?> (<?=($total ?? 0)?> <?=Helpers::e($pagerLabel ?? 'items')?>)</span>
<?php if (($pg ?? 1) < ($pages ?? 1)): ?><a class="btn small" href="<?=Helpers::e(($pagerBase ?? '?').'page='.(($pg ?? 1) + 1))?>">Next »</a><?php endif; ?>
</div><?php endif; ?>
