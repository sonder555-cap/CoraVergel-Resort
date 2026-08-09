<?php
/* ══════════════════════════════════════════════════════════════
   FACEBOOK OAUTH CREDENTIALS
   ══════════════════════════════════════════════════════════════
   Get these from https://developers.facebook.com/apps

   1. Click "Create App" → choose type "Consumer" (or "Authenticate
      and request data from users with Facebook Login") → continue,
      give it a name like "CoraVergel Resort", create.
   2. On your app's dashboard, find "Facebook Login" and click
      "Set Up" (if it's not already added, add it as a product).
   3. Facebook Login → Settings (left sidebar). Under
      "Valid OAuth Redirect URIs" add EXACTLY:
        http://localhost/Capstone1-2026/user/facebook_callback.php
      Save changes.
   4. Left sidebar → App Settings → Basic. Copy the "App ID" and
      click "Show" next to "App Secret" to reveal and copy it.
   5. Paste both into the two constants below.

   NOTE: while your app is in "Development" mode, only accounts
   added as Testers/Developers/Admins under Roles can actually log
   in with it. Go to App Roles → Roles to add test Facebook
   accounts, or switch the app to "Live" mode (top of dashboard)
   once you're ready for real guests to use it — Live mode requires
   Facebook's App Review for the "email" permission if you want
   guests' email addresses, though "public_profile" works without
   review.
   ══════════════════════════════════════════════════════════════ */

define('FACEBOOK_APP_ID',       'YOUR_FACEBOOK_APP_ID_HERE');
define('FACEBOOK_APP_SECRET',   'YOUR_FACEBOOK_APP_SECRET_HERE');
define('FACEBOOK_REDIRECT_URI', 'http://localhost/Capstone1-2026/user/facebook_callback.php');
