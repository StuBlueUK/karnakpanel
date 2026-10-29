# How to deploy your site to KarnakPanel

Three ways, fastest first. All paths land in `/home/<you>/<domain>/public_html`
(the only directory the web server serves).

## 1. File manager (quick fixes)

Files page → open your domain folder → Upload, or New file + Edit for small
changes. PHP applies instantly, no restart. Best for: single-file edits,
dropping in an `index.html`, uploading a theme zip (then ask — no unzip in the
panel yet, upload extracted files or use method 2).

## 2. FTPS (bulk uploads, local tooling)

Connect with **TLS/SSL enabled** (plaintext is refused), port 21:

| Field    | Value                              |
|----------|------------------------------------|
| Host     | your server IP or domain           |
| User     | panel username, or `user_suffix`   |
| Password | panel password (main login)        |

Main login = your panel password; extra logins are created on the FTPS page
and jailed to your home. FileZilla/WinSCP: set encryption to
"Require explicit FTP over TLS".

## 3. Git push-to-deploy (planned)

Not live yet — specified workflow so you can prepare repos now:

```bash
# one time per site (from the panel, coming soon):
# creates site@server:~/git/<domain>.git deploying to your docroot
git remote add live site@<server>:git/<domain>.git
git push live main
```

Pushing `main` will deploy to `public_html` (excluding `.git/`), keep file
ownership/permissions correct, and log each deploy. Until it ships, deploy via
method 2: `git archive main | unzip` locally, then upload the files.

## Deploy checklist (all methods)

- [ ] Folder empty or holding only the stock `index.html` (installers refuse
      to overwrite real sites; uploads merge — stale files linger, delete them)
- [ ] `index.php` beats `index.html` when both exist
- [ ] PHP errors? Check the 🐘 PHP page error log, not the browser
- [ ] Need it hidden first? 🚧 Maintain mode parks the domain behind a
      holding page while you work
- [ ] WordPress/phpBB? Use 🧰 Software instead of manual upload
