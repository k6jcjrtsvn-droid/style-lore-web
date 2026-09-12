# Deploying Style-LORE to GoDaddy (cPanel)

This guide walks through putting the `web/` folder live on your GoDaddy hosting, from an empty cPanel account to a working site. It assumes no prior server experience — just follow the steps in order. Where cPanel screens vary slightly between GoDaddy plans, the tool names below are the ones GoDaddy uses on essentially every plan.

Budget about 30–45 minutes for the first deploy.

## What you're uploading

Everything the live site needs is inside the `web/` folder: the PHP backend (`api/`), the app itself (`index.html`), the routing/security rules (`.htaccess`), the database structure (`schema.sql`), and a folder for user uploads (`uploads/`). You'll upload the *contents* of `web/` (not the `web` folder itself) to your hosting account.

## Step 1 — Create the MySQL database

1. Log in to GoDaddy, go to your hosting account, and open **cPanel**.
2. Under **Databases**, click **MySQL Databases**.
3. Under "Create New Database," enter a name such as `stylelore` and click **Create Database**. cPanel will prefix it automatically, so it'll end up looking like `yourcpaneluser_stylelore` — that's expected.
4. Scroll to "MySQL Users" and create a new user (e.g. `stylelore`) with a strong password. Write this password down somewhere safe — you'll need it in Step 3. Again, cPanel will prefix the username automatically.
5. Scroll to "Add User to Database," choose the user and database you just created, click **Add**, and on the next screen check **ALL PRIVILEGES**, then **Make Changes**.

You now have three values to keep handy: the full database name, the full username, and the password.

## Step 2 — Import the database structure

1. Back in cPanel, under **Databases**, open **phpMyAdmin**.
2. In the left sidebar, click the database you just created.
3. Click the **Import** tab along the top.
4. Click **Choose File** and select `schema.sql` from this project.
5. Leave the other options at their defaults and click **Go** at the bottom.
6. You should see a success message and six new tables appear in the sidebar: `accounts`, `profiles`, `posts`, `likes`, `comments`, `follows`. If you see an error instead, the most common cause is importing into the wrong database — double check you clicked the right one in step 2.

## Step 3 — Fill in your config file

1. On your own computer, make a copy of `config.sample.php` and rename the copy to `config.php` (same folder). Keep `config.sample.php` around too — the app ignores it, it's just a template for reference.
2. Open `config.php` in any text editor and fill in the four values from Step 1:
   - `DB_HOST` — leave this as `localhost` (that's correct for GoDaddy shared hosting).
   - `DB_NAME` — the full database name, e.g. `yourcpaneluser_stylelore`.
   - `DB_USER` — the full username, e.g. `yourcpaneluser_stylelore`.
   - `DB_PASS` — the password you set for that user.
3. Leave `ANTHROPIC_API_KEY` blank for now — that's what keeps the optional AI features off. See "Turning on AI later" below for how to flip that on whenever you're ready.
4. Save the file.

## Step 4 — Upload the files

You can do this with cPanel's File Manager (no extra software) or an FTP client like FileZilla — File Manager is simpler for a first deploy.

1. In cPanel, open **File Manager** and navigate to `public_html`. This is the folder that serves your main domain (`yourdomain.com`). If you'd rather launch the app at a subdomain or a subfolder like `yourdomain.com/app`, create that subdomain first in cPanel's **Domains** tool and navigate into its folder instead.
2. On your own computer, select everything *inside* the `web/` folder (not the `web` folder itself) — that's `index.html`, `.htaccess`, `config.php`, `schema.sql`, `api/`, `includes/`, `uploads/` — and compress it into a single `.zip`. Do not include `config.sample.php` unless you want it there for reference; it's harmless either way since `.htaccess` blocks direct access to it.
3. Back in File Manager, click **Upload**, and upload that zip file into `public_html` (or your subfolder).
4. Once it finishes uploading, go back to the File Manager file list, right-click the uploaded zip, and choose **Extract**. Extract it into the same folder, then delete the zip file once you've confirmed the files landed correctly (you should see `index.html`, `.htaccess`, `api/`, etc. directly inside `public_html`, not nested inside an extra `web` subfolder — if they are nested, select everything inside that subfolder, move it up one level, then delete the now-empty subfolder).
5. `.htaccess` files are hidden by default. If File Manager shows only `.htaccess` is missing after extracting, click **Settings** (top right of File Manager) and enable **Show Hidden Files**, then confirm it's there.

## Step 5 — Make the uploads folder writable

The app saves photos and videos people post, and profile pictures, into `uploads/posts` and `uploads/avatars`. GoDaddy's PHP normally runs as your own cPanel user, so this usually works with no changes — but if a post or profile-picture upload fails with a "could not save the uploaded file" error, check this:

1. In File Manager, right-click the `uploads` folder, choose **Permissions** (or "Change Permissions").
2. Set it to `755`. If uploads still fail after that, try `775`.
3. Do the same check for `uploads/posts` and `uploads/avatars` individually if the problem persists.

## Step 6 — Test it

Visit your domain (or subdomain/subfolder) in a browser. You should see the Style-LORE sign-up screen. Walk through this checklist:

1. **Sign up** with a new name, email, and password (8+ characters) — you should land on the app itself, not an error.
2. **Take the style quiz** and confirm it completes.
3. **Post something to Community** — a caption at minimum, and ideally a photo — and confirm it appears in the feed.
4. **Like** your own post and confirm the heart fills in.
5. **Comment** on the post and confirm it appears underneath.
6. **Edit your profile** (name, bio, and a profile picture) and confirm it saves and the picture shows up.
7. **Follow** another test account (sign up a second time with a different email in a private/incognito window) and confirm the follow shows up on both sides.
8. **Log out and back in** with the first account's email and password to confirm login works independently of signup.

If every one of those works, the deploy is solid.

## Turning on AI later

The optional AI-assisted features (Kibbe photo refinement, style-tag checks, closet/checker verdicts) ship **off** so the site works immediately without any billing setup. To turn them on later:

1. Get an API key from [console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys), and make sure that Anthropic account has usage credits under **Settings > Billing**.
2. Edit `config.php` on the live site (File Manager has a built-in code editor — right-click the file and choose **Edit**) and paste the key into `ANTHROPIC_API_KEY`.
3. Save. That's it — no re-upload of any other file, no restart needed. The features turn on the next time someone loads the app.

To turn them back off, blank out that same line and save.

## Troubleshooting

**A page of raw PHP errors, or a blank white page, instead of the app or a JSON error.** This almost always means `config.php` is missing or has a typo (a missing quote or semicolon). Re-check it against `config.sample.php`'s format. If the page really is blank with nothing at all, check cPanel's **Errors** tool (under Metrics) for the most recent entry — it'll name the exact file and line.

**"Database connection failed" message when using the app.** Double-check the four `DB_*` values in `config.php` character-for-character against the cPanel "MySQL Databases" page — a common mistake is leaving off the `yourcpaneluser_` prefix that cPanel adds automatically to both the database name and username.

**Signing up or posting does nothing / spins forever.** Open your browser's developer tools (F12), go to the Network tab, and try again — click the failed request (shown in red) to see the actual error message returned. That message will point at one of the two issues above, or at a file-permissions issue from Step 5.

**Uploaded photos or videos don't show up.** Check the permissions on `uploads/`, `uploads/posts`, and `uploads/avatars` as described in Step 5.

## A known limitation worth knowing about

Right now, actions like liking, commenting, and following trust whatever visitor/account ID the app's own JavaScript sends — there's no server-side login session double-checking "is this really who they say they are" on every request. This matches how the original local prototype worked, and is fine for getting the app live and in front of real users. If Style-LORE grows into something people rely on for anything sensitive, the next security upgrade to consider is adding real server-side sessions (PHP sessions or a token system) so every write is checked against who's actually logged in, not just who the request claims to be.

## What's not included here

This guide covers the **website**. The Android app (a wrapper around this same site) is a separate build process — see `mobile/README-BUILD-ANDROID.md` once that's ready. An iOS build is on hold until Mac access is sorted out, per your call to do Android first.
