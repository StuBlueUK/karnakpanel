-- 046_panel_docroot.sql — panel-domain.sh once stored the bare domain name as
-- docroot for the panel's own domain row (the vhost always served
-- /home/<user>/<domain>/public_html, so the Domains UI displayed a path that
-- matched nothing on disk). Heal any relative docroot to the layout path.
-- Idempotent: the WHERE clause matches nothing once healed (safe re-run).
UPDATE domains d JOIN users u ON u.id = d.user_id
SET d.docroot = CONCAT('/home/', u.username, '/', d.domain, '/public_html')
WHERE d.docroot NOT LIKE '/%';
