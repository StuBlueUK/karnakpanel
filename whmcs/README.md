# KarnakPanel × WHMCS — 5-minute setup

## 1. Panel side (2 min)
1. Log in as **admin** → **API** → mint a token labelled `whmcs`.
   Copy it — shown once. (Tokens are sha256-stored; leak of the DB alone
   grants nothing. Revoke/re-mint anytime on the same page.)
2. Note an exact **package name** from the Packages page (e.g. `Bronze`).

## 2. WHMCS side (3 min)
1. Copy `modules/servers/karnakpanel/` into your WHMCS `modules/servers/`.
2. Setup → Products/Services → Servers → Add New Server:
   Type **KarnakPanel**, hostname = panel FQDN (port 8090 is automatic),
   username = anything, **password = the API token**. Save + Test Connection.
3. Product → Module Settings: pick the server group, set **Panel package**
   to the exact package name. The client's WHMCS username becomes the panel
   username (lowercased; must be `a-z0-9_`).

That's it — Create / Suspend / Unsuspend / Terminate / Password / Usage
all run automatically on the product's actions. The **Usage & status**
admin button shows live disk/BW without leaving WHMCS.

## Notes
- TLS is fully verified (valid panel cert required — LE on prod ✅).
- Bad tokens return 401 and count toward the panel fail2ban jail.
- Terminate deletes sites, mail, DBs and DNS (panel backups of files kept).
- **Pin the token**: on the panel API page, set the `whmcs` token's IP to
  your WHMCS server IP (or the panel's own IP if WHMCS runs on the same
  box). A token stolen from a compromised WHMCS then works nowhere else.
  Same-box hosting is fine — WHMCS is an ordinary PHP app in its own
  account — but confirm the ionCube loader is available for your PHP
  version first, as WHMCS requires it.
