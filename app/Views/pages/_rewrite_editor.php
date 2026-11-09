<?php // Rewrite editor card (?rewrite=id) — shared by the Domains and Subdomains tabs. ?>
<?php if(!empty($rwdom)):?><div class="card"><h3>📝 Rewrite rules — <?=Helpers::e($rwdom['domain'])?> <small style="color:var(--muted)">OpenLiteSpeed ignores .htaccess — these run at vhost level instead</small></h3>
<form method="post" action="/domains/rewrite"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$rwdom['id']?>">
<textarea name="rules" rows="10" style="width:100%;font-family:monospace" placeholder="RewriteCond %{REQUEST_FILENAME} !-f&#10;RewriteRule ^(.*)$ index.php [L]"><?=Helpers::e($rwdom['rewrite_rules'] ?? '')?></textarea>
<small style="color:var(--muted)">RewriteCond/RewriteRule lines only (php/Options/Auth need their own pages — the importer tells you per line). Bad rules are refused, the live site keeps serving.</small><br>
<button class="btn">Save &amp; apply</button></form>
<form class="inline" method="post" action="/domains/htaccess-import" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$rwdom['id']?>"><button class="btn small">⬇ Import docroot .htaccess</button></form></div><?php endif;?>
