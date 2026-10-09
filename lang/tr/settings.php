<?php

/**
 * Turkish language file for the turnstile plugin settings
 *
 * @author Erkan Çınar <erkancinar@gmail.com>
 */

$lang['sitekey'] = 'Turnstile site anahtarı (Cloudflare paneli → Turnstile). Anahtarlardan biri boşken eklenti devre dışıdır.';
$lang['secretkey'] = 'Turnstile gizli anahtarı';
$lang['forms'] = 'Korunacak formlar';
$lang['forms_o_login'] = 'Giriş';
$lang['forms_o_resendpwd'] = 'Parola sıfırlama';
$lang['forms_o_register'] = 'Kayıt';
$lang['forms_o_edit'] = 'Sayfa düzenleme (sayfa kaydedilirken denetlenir)';
$lang['forms_o_discussion'] = 'Discussion eklentisinin yorumları (Turnstile desteği olan bir discussion sürümü gerekir)';
$lang['forusers'] = 'Giriş yapmış kullanıcılara da sor (sayfa düzenleme; giriş, parola sıfırlama ve kayıt zaten yalnız anonim kullanıcılara gösterilir)';
$lang['theme'] = 'Kutucuk teması';
$lang['theme_o_auto'] = 'Otomatik';
$lang['theme_o_light'] = 'Açık';
$lang['theme_o_dark'] = 'Koyu';
$lang['size'] = 'Kutucuk boyutu';
$lang['size_o_flexible'] = 'Esnek (tam genişlik)';
$lang['size_o_normal'] = 'Normal';
$lang['size_o_compact'] = 'Küçük';
$lang['failmode'] = 'Cloudflare\'e ulaşılamadığında ya da gizli anahtar reddedildiğinde';
$lang['failmode_o_closed'] = 'Formu reddet (daha güvenli)';
$lang['failmode_o_open'] = 'Formu doğrulamasız kabul et ve hata günlüğüne yaz';
$lang['basicauth'] = 'HTTP Basic kimlik doğrulamasıyla girişler (kutucuk gösterilemez)';
$lang['basicauth_o_api'] = 'Yalnız uzak API uçlarında ve API açıkken doğrulamasız izin ver';
$lang['basicauth_o_all'] = 'Her yerde doğrulamasız izin ver (yalnız web sunucunuz kullanıcıları HTTP kimlik doğrulamasıyla giriş yaptırıyorsa)';
