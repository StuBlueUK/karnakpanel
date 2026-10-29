<h2>📜 Daemon: <?=Helpers::e($d['name'])?> <small style="color:var(--muted)">newest first</small></h2>
<div class="card"><pre style="white-space:pre-wrap;font-size:12px"><?php foreach($lines as $l):?><?=Helpers::e($l)?>
<?php endforeach;?><?php if(empty($lines)):?>No log output yet.<?php endif;?></pre></div>
<p><a href="/daemons">← Back to daemons</a></p>
