<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\Logger;

/**
 * Cloudflare Turnstile helper: widget markup and server-side token verification
 *
 * Other plugins can protect their own forms with it:
 *
 *     $turnstile = plugin_load('helper', 'turnstile');
 *     if ($turnstile && $turnstile->isEnabled()) {
 *         $form->addHTML($turnstile->getHtml('myform'), $pos); // when rendering
 *         if (!$turnstile->check('myform')) return;          // when handling the POST
 *     }
 *
 * @license GPL 2 (https://www.gnu.org/licenses/gpl-2.0.html)
 * @author  Erkan Çınar <erkancinar@gmail.com>
 */
class helper_plugin_turnstile extends Plugin
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /** the token is valid for the given action */
    public const RESULT_OK = 'ok';
    /** the token is missing, malformed, expired, already used or belongs to another action */
    public const RESULT_INVALID = 'invalid';
    /** Cloudflare could not give an answer (network, outage, rejected secret key) */
    public const RESULT_UNAVAILABLE = 'unavailable';

    /** Cloudflare documents 2048 characters as the maximum token length */
    protected const MAX_TOKEN_LENGTH = 2048;

    /** @var bool the API script only needs to be loaded once per page */
    protected $scriptAdded = false;

    /**
     * Both keys are configured
     *
     * Without keys the plugin stays inactive, so installing it never locks anyone out.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->getSiteKey() !== '' && $this->getSecretKey() !== '';
    }

    /**
     * The plugin is enabled and the given form is selected in the configuration
     *
     * @param string $form one of login, resendpwd, register
     * @return bool
     */
    public function isFormProtected($form)
    {
        if (!$this->isEnabled()) return false;
        $forms = array_map('trim', explode(',', (string)$this->getConf('forms')));
        return in_array($form, $forms, true);
    }

    /**
     * May an HTTP Basic authentication login skip the check?
     *
     * Basic authentication has no form to show the widget in. Exempting it everywhere would let bots try passwords
     * through the Authorization header of any page, so by default only the remote API endpoints are exempt, and
     * only while the remote API is enabled.
     *
     * @return bool
     */
    public function isBasicAuthExempt()
    {
        global $conf, $INPUT;

        if ($this->getConf('basicauth') === 'all') return true;
        if (empty($conf['remote'])) return false;

        // compare the executed file, not the URL: SCRIPT_NAME may carry path info on some server setups
        $script = realpath($INPUT->server->str('SCRIPT_FILENAME'));
        if ($script === false) return false;
        foreach (['jsonrpc.php', 'xmlrpc.php'] as $endpoint) {
            if ($script === realpath(DOKU_INC . 'lib/exe/' . $endpoint)) return true;
        }
        return false;
    }

    /**
     * HTML for the Turnstile widget, to be placed inside the form before the submit button
     *
     * The widget adds the hidden input "cf-turnstile-response" to the surrounding form.
     *
     * @param string $action short name of the protected form, bound into the token by Cloudflare
     * @return string
     */
    public function getHtml($action)
    {
        $html = '';
        if (!$this->scriptAdded) {
            $html .= '<script src="' . hsc(self::SCRIPT_URL) . '" async defer></script>';
            $this->scriptAdded = true;
        }
        $html .= '<div class="plugin_turnstile cf-turnstile"'
            . ' data-sitekey="' . hsc($this->getSiteKey()) . '"'
            . ' data-action="' . hsc($action) . '"'
            . ' data-theme="' . hsc((string)$this->getConf('theme')) . '"'
            . ' data-size="' . hsc((string)$this->getConf('size')) . '"'
            . '></div>';
        $html .= '<noscript><div class="plugin_turnstile_noscript">'
            . hsc($this->getLang('noscript'))
            . '</div></noscript>';
        return $html;
    }

    /**
     * Verify the submitted token and apply the configured failure policy
     *
     * Shows an error message to the user when the check fails.
     *
     * @param string $action the action given to getHtml()
     * @return bool true when the request may continue
     */
    public function check($action)
    {
        global $INPUT;

        $token = $INPUT->post->str('cf-turnstile-response');
        $result = $this->verify($token, $action, clientIP(true));

        if ($result === self::RESULT_OK) return true;

        if ($result === self::RESULT_UNAVAILABLE && $this->getConf('failmode') === 'open') {
            // already logged in verify(); the request continues without the check
            return true;
        }

        $message = $result === self::RESULT_UNAVAILABLE ? 'unavailable' : 'failed';
        msg($this->getLang($message), -1);
        return false;
    }

    /**
     * Ask Cloudflare whether the token is valid
     *
     * @param string $token value of the "cf-turnstile-response" field
     * @param string $action expected action, compared when Cloudflare reports one
     * @param string $remoteIp client address, optional
     * @return string one of the RESULT_* constants
     */
    public function verify($token, $action, $remoteIp = '')
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return self::RESULT_INVALID;
        }

        $data = ['secret' => $this->getSecretKey(), 'response' => $token];
        if ($remoteIp !== '') $data['remoteip'] = $remoteIp;

        $body = $this->requestVerification($data);
        if ($body === false) return self::RESULT_UNAVAILABLE;

        $answer = json_decode($body, true);
        if (!is_array($answer)) {
            Logger::error('turnstile: unreadable verification response', substr($body, 0, 200));
            return self::RESULT_UNAVAILABLE;
        }

        if (!empty($answer['success'])) {
            // a token issued for one form must not unlock another one
            // isset instead of empty(): the string "0" is a valid, non-empty action
            if (isset($answer['action']) && $answer['action'] !== '' && $answer['action'] !== $action) {
                return self::RESULT_INVALID;
            }
            return self::RESULT_OK;
        }

        $errors = isset($answer['error-codes']) && is_array($answer['error-codes']) ? $answer['error-codes'] : [];
        if (array_intersect($errors, ['missing-input-secret', 'invalid-input-secret'])) {
            Logger::error('turnstile: the secret key was rejected, check the plugin configuration', $errors);
            return self::RESULT_UNAVAILABLE;
        }
        if (in_array('internal-error', $errors, true)) {
            Logger::error('turnstile: Cloudflare reported an internal error', $errors);
            return self::RESULT_UNAVAILABLE;
        }
        return self::RESULT_INVALID;
    }

    /**
     * POST the verification request to Cloudflare
     *
     * Uses DokuWiki's HTTP client, so the proxy settings of the wiki apply.
     *
     * @param array $data form fields for siteverify
     * @return string|false response body, false when no usable answer was received
     */
    protected function requestVerification(array $data)
    {
        $http = new DokuHTTPClient();
        $http->timeout = 10;
        $body = $http->post(self::VERIFY_URL, $data);
        if ($body === false) {
            Logger::error('turnstile: verification request failed', $http->status . ' ' . $http->error);
        }
        return $body;
    }

    /**
     * @return string
     */
    protected function getSiteKey()
    {
        return trim((string)$this->getConf('sitekey'));
    }

    /**
     * The secret is stored obfuscated by the configuration manager
     *
     * @return string
     */
    protected function getSecretKey()
    {
        return trim((string)conf_decodeString((string)$this->getConf('secretkey')));
    }
}
