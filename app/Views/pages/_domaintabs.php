<?php // shared tab bar for Domains / Subdomains / Redirects — caller sets $tab; counts optional ($total, $subs, $redirects) ?>
<div class="tabs">
<a class="tab<?=$tab==='domains'?' active':''?>" href="/domains">Domains<?php if(($total ?? 0) > 0):?> <span class="count"><?=(int)$total?></span><?php endif;?></a>
<a class="tab<?=$tab==='subdomains'?' active':''?>" href="/domains?tab=subdomains">Subdomains<?php if(count($subs ?? []) > 0):?> <span class="count"><?=count($subs)?></span><?php endif;?></a>
<a class="tab<?=$tab==='redirects'?' active':''?>" href="/redirects">Redirects<?php if($tab==='redirects' && count($redirects ?? []) > 0):?> <span class="count"><?=count($redirects)?></span><?php endif;?></a>
</div>
