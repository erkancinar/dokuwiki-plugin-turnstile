<?php

/**
 * Options for the turnstile plugin
 *
 * @author Erkan Çınar <erkancinar@gmail.com>
 */

$meta['sitekey'] = ['string'];
$meta['secretkey'] = ['password', '_code' => 'base64'];
$meta['forms'] = [
    'multicheckbox',
    '_choices' => ['login', 'resendpwd', 'register', 'edit', 'discussion'],
    '_other' => 'never',
];
$meta['forusers'] = ['onoff'];
$meta['theme'] = ['multichoice', '_choices' => ['auto', 'light', 'dark']];
$meta['size'] = ['multichoice', '_choices' => ['flexible', 'normal', 'compact']];
$meta['failmode'] = ['multichoice', '_choices' => ['closed', 'open']];
$meta['basicauth'] = ['multichoice', '_choices' => ['api', 'all']];
