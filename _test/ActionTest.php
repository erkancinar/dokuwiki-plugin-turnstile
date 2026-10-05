<?php

namespace dokuwiki\plugin\turnstile\test;

use dokuwiki\Extension\Event;
use DokuWikiTest;

/**
 * Tests which login attempts the action component checks
 *
 * None of these requests carries a token, so a check always fails without a network request.
 *
 * @group plugin_turnstile
 * @group plugins
 */
class ActionTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['turnstile'];

    /** @var string|null SCRIPT_FILENAME before the test changed it */
    protected $scriptFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? null;
        global $conf, $DOKU_PLUGINS;
        $conf['plugin']['turnstile']['sitekey'] = '1x00000000000000000000AA';
        $conf['plugin']['turnstile']['secretkey'] = '1x0000000000000000000000000000000AA';
        // plugin instances are cached globally, make them read the configuration above
        unset($DOKU_PLUGINS['helper']['turnstile'], $DOKU_PLUGINS['action']['turnstile']);
    }

    /**
     * Run the login check event like auth_setup() does, with a default action that records the call
     *
     * @param array $data
     * @return bool whether the regular password check would have run
     */
    protected function login(array $data): bool
    {
        $passwordChecked = false;
        $data += ['password' => 'secret', 'sticky' => false, 'silent' => false];
        $event = new Event('AUTH_LOGIN_CHECK', $data);
        $event->trigger(function () use (&$passwordChecked) {
            $passwordChecked = true;
            return true;
        });
        return $passwordChecked;
    }

    public function testFormLoginWithoutTokenIsStopped(): void
    {
        $this->assertFalse($this->login(['user' => 'alice']));
    }

    public function testCookieReauthenticationIsNotChecked(): void
    {
        $this->assertTrue($this->login(['user' => '']));
    }

    /**
     * Simulate an HTTP Basic authentication login against the given script
     *
     * @param string $script executed file, relative to DOKU_INC
     * @return bool whether the regular password check would have run
     */
    protected function basicAuthLogin(string $script): bool
    {
        global $INPUT;
        $INPUT->set('http_credentials', true);
        $_SERVER['SCRIPT_FILENAME'] = DOKU_INC . $script;
        return $this->login(['user' => 'apiclient', 'silent' => true]);
    }

    public function tearDown(): void
    {
        if ($this->scriptFile === null) {
            unset($_SERVER['SCRIPT_FILENAME']);
        } else {
            $_SERVER['SCRIPT_FILENAME'] = $this->scriptFile;
        }
        parent::tearDown();
    }

    public function testBasicAuthOnApiIsNotChecked(): void
    {
        global $conf;
        $conf['remote'] = 1;
        $this->assertTrue($this->basicAuthLogin('lib/exe/jsonrpc.php'));
        $this->assertTrue($this->basicAuthLogin('lib/exe/xmlrpc.php'));
    }

    public function testBasicAuthOnPagesIsChecked(): void
    {
        global $conf;
        $conf['remote'] = 1;
        $this->assertFalse($this->basicAuthLogin('doku.php'));
        $this->assertFalse($this->basicAuthLogin('lib/exe/fetch.php'));
        // a URL that only looks like the API, or a file that does not exist
        $this->assertFalse($this->basicAuthLogin('doku.php/x/lib/exe/jsonrpc.php'));
    }

    public function testBasicAuthOnApiIsCheckedWhileApiIsDisabled(): void
    {
        global $conf;
        $conf['remote'] = 0;
        $this->assertFalse($this->basicAuthLogin('lib/exe/jsonrpc.php'));
    }

    public function testBasicAuthCanBeAllowedEverywhere(): void
    {
        global $conf, $DOKU_PLUGINS;
        $conf['plugin']['turnstile']['basicauth'] = 'all';
        unset($DOKU_PLUGINS['helper']['turnstile']);
        $this->assertTrue($this->basicAuthLogin('doku.php'));
    }

    public function testLoginNotSelectedIsNotChecked(): void
    {
        global $conf, $DOKU_PLUGINS;
        $conf['plugin']['turnstile']['forms'] = 'register';
        unset($DOKU_PLUGINS['helper']['turnstile']);
        $this->assertTrue($this->login(['user' => 'alice']));
    }

    public function testDisabledPluginDoesNotCheck(): void
    {
        global $conf, $DOKU_PLUGINS;
        $conf['plugin']['turnstile']['sitekey'] = '';
        unset($DOKU_PLUGINS['helper']['turnstile']);
        $this->assertTrue($this->login(['user' => 'alice']));
    }

    public function testPasswordResetWithoutTokenIsNotSaved(): void
    {
        global $INPUT;
        $INPUT->post->set('save', 1);
        $act = 'resendpwd';
        $event = new Event('ACTION_ACT_PREPROCESS', $act);
        $event->trigger();
        $this->assertFalse($INPUT->post->bool('save'));
    }

    public function testPasswordResetFormDisplayIsNotChecked(): void
    {
        global $MSG;
        $before = count($MSG ?? []);
        $act = 'resendpwd';
        $event = new Event('ACTION_ACT_PREPROCESS', $act);
        $event->trigger();
        $this->assertCount($before, $MSG ?? [], 'a failed check would have added an error message');
    }
}
