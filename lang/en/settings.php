<?php

/**
 * English language file for the turnstile plugin settings
 *
 * @author Erkan Çınar <erkancinar@gmail.com>
 */

$lang['sitekey'] = 'Turnstile site key (Cloudflare dashboard → Turnstile). The plugin stays inactive while a key is empty.';
$lang['secretkey'] = 'Turnstile secret key';
$lang['forms'] = 'Forms to protect';
$lang['forms_o_login'] = 'Login';
$lang['forms_o_resendpwd'] = 'Password reset';
$lang['forms_o_register'] = 'Registration';
$lang['theme'] = 'Widget theme';
$lang['theme_o_auto'] = 'Automatic';
$lang['theme_o_light'] = 'Light';
$lang['theme_o_dark'] = 'Dark';
$lang['size'] = 'Widget size';
$lang['size_o_flexible'] = 'Flexible (full width)';
$lang['size_o_normal'] = 'Normal';
$lang['size_o_compact'] = 'Compact';
$lang['failmode'] = 'When Cloudflare cannot be reached or rejects the secret key';
$lang['failmode_o_closed'] = 'Reject the form (safer)';
$lang['failmode_o_open'] = 'Accept the form without the check and log an error';
$lang['basicauth'] = 'Logins with HTTP Basic authentication (no widget can be shown)';
$lang['basicauth_o_api'] = 'Allow without the check only on the remote API endpoints while the API is enabled';
$lang['basicauth_o_all'] = 'Allow without the check everywhere (only if your web server logs users in via HTTP authentication)';
