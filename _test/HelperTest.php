<?php

namespace {

    /**
     * Helper with a canned Cloudflare answer instead of a network request
     *
     * The class name has to follow the plugin naming scheme, getConf() and getLang() derive the plugin from it.
     */
    class helper_plugin_turnstile_teststub extends helper_plugin_turnstile
    {
        /** @var string|false body returned instead of asking Cloudflare */
        public $answer = false;
        /** @var array|null data of the last verification request */
        public $sent;

        /** @inheritdoc */
        protected function requestVerification(array $data)
        {
            $this->sent = $data;
            return $this->answer;
        }
    }
}

namespace dokuwiki\plugin\turnstile\test {

    use DokuWikiTest;
    use helper_plugin_turnstile;
    use helper_plugin_turnstile_teststub;

    /**
     * Tests for the verification logic of the turnstile helper
     *
     * @group plugin_turnstile
     * @group plugins
     */
    class HelperTest extends DokuWikiTest
    {
        protected $pluginsEnabled = ['turnstile'];

        public function setUp(): void
        {
            parent::setUp();
            global $conf;
            // Cloudflare's documented dummy keys
            $conf['plugin']['turnstile']['sitekey'] = '1x00000000000000000000AA';
            $conf['plugin']['turnstile']['secretkey'] = '1x0000000000000000000000000000000AA';
        }

        /**
         * @param string|false $answer
         * @return helper_plugin_turnstile_teststub
         */
        protected function stub($answer)
        {
            $helper = new helper_plugin_turnstile_teststub();
            $helper->answer = $answer;
            return $helper;
        }

        public function testDisabledWithoutKeys(): void
        {
            global $conf;
            $conf['plugin']['turnstile']['secretkey'] = '';
            $helper = $this->stub(false);
            $this->assertFalse($helper->isEnabled());
            $this->assertFalse($helper->isFormProtected('login'));
        }

        public function testFormSelection(): void
        {
            global $conf;
            $conf['plugin']['turnstile']['forms'] = 'login,register';
            $helper = $this->stub(false);
            $this->assertTrue($helper->isFormProtected('login'));
            $this->assertTrue($helper->isFormProtected('register'));
            $this->assertFalse($helper->isFormProtected('resendpwd'));
        }

        public function testDiscussionComments(): void
        {
            global $conf, $INPUT;
            $conf['plugin']['turnstile']['forms'] = 'login,discussion';
            $helper = $this->stub(false);
            $this->assertTrue($helper->isRequestProtected('discussion'), 'anonymous comment');
            $INPUT->server->set('REMOTE_USER', 'testuser');
            $this->assertFalse($helper->isRequestProtected('discussion'), 'logged-in users only with forusers');
            $conf['plugin']['turnstile']['forusers'] = 1;
            $this->assertTrue($helper->isRequestProtected('discussion'));

            $meta = [];
            include __DIR__ . '/../conf/metadata.php';
            $this->assertContains('discussion', $meta['forms']['_choices'], 'selectable in the Configuration Manager');
        }

        public function testEncodedSecretIsDecoded(): void
        {
            global $conf;
            $conf['plugin']['turnstile']['secretkey'] = '<b>' . base64_encode('decoded-secret');
            $helper = $this->stub('{"success":true}');
            $helper->verify('token', 'login');
            $this->assertEquals('decoded-secret', $helper->sent['secret']);
        }

        public function testEmptyTokenNeedsNoRequest(): void
        {
            $helper = $this->stub('{"success":true}');
            $this->assertEquals(helper_plugin_turnstile::RESULT_INVALID, $helper->verify('', 'login'));
            $this->assertNull($helper->sent);
        }

        public function testOversizedTokenIsInvalid(): void
        {
            $helper = $this->stub('{"success":true}');
            $this->assertEquals(helper_plugin_turnstile::RESULT_INVALID, $helper->verify(str_repeat('a', 2049), 'login'));
        }

        public function testRemoteIpIsSent(): void
        {
            $helper = $this->stub('{"success":true}');
            $helper->verify('token', 'login', '192.0.2.10');
            $this->assertEquals('192.0.2.10', $helper->sent['remoteip']);
            $this->assertEquals('token', $helper->sent['response']);
        }

        /**
         * @return array[] Cloudflare answer, expected result
         */
        public function provideAnswers(): array
        {
            return [
                'success without action (dummy keys)' => ['{"success":true,"error-codes":[]}', 'ok'],
                'success with matching action' => ['{"success":true,"action":"login"}', 'ok'],
                'success for another form' => ['{"success":true,"action":"register"}', 'invalid'],
                'success for a form named 0' => ['{"success":true,"action":"0"}', 'invalid'],
                'success with empty action' => ['{"success":true,"action":""}', 'ok'],
                'wrong token' => ['{"success":false,"error-codes":["invalid-input-response"]}', 'invalid'],
                'token used twice' => ['{"success":false,"error-codes":["timeout-or-duplicate"]}', 'invalid'],
                'bad request' => ['{"success":false,"error-codes":["bad-request"]}', 'invalid'],
                'secret rejected' => ['{"success":false,"error-codes":["invalid-input-secret"]}', 'unavailable'],
                'secret missing' => ['{"success":false,"error-codes":["missing-input-secret"]}', 'unavailable'],
                'cloudflare error' => ['{"success":false,"error-codes":["internal-error"]}', 'unavailable'],
                'no answer' => [false, 'unavailable'],
                'not json' => ['<html>gateway timeout</html>', 'unavailable'],
            ];
        }

        /**
         * @dataProvider provideAnswers
         * @param string|false $answer
         * @param string $expected
         */
        public function testVerify($answer, $expected): void
        {
            $this->assertEquals($expected, $this->stub($answer)->verify('token', 'login'));
        }

        public function testCheckClosedRejectsWhenUnavailable(): void
        {
            global $conf, $INPUT;
            $conf['plugin']['turnstile']['failmode'] = 'closed';
            $INPUT->post->set('cf-turnstile-response', 'token');
            $this->assertFalse($this->stub(false)->check('login'));
        }

        public function testCheckOpenAcceptsWhenUnavailable(): void
        {
            global $conf, $INPUT;
            $conf['plugin']['turnstile']['failmode'] = 'open';
            $INPUT->post->set('cf-turnstile-response', 'token');
            $this->assertTrue($this->stub(false)->check('login'));
        }

        public function testCheckOpenStillRejectsInvalidToken(): void
        {
            global $conf, $INPUT;
            $conf['plugin']['turnstile']['failmode'] = 'open';
            $INPUT->post->set('cf-turnstile-response', 'token');
            $this->assertFalse($this->stub('{"success":false,"error-codes":["invalid-input-response"]}')->check('login'));
        }

        public function testWidgetHtml(): void
        {
            $helper = $this->stub(false);
            $first = $helper->getHtml('login');
            $this->assertStringContainsString('data-sitekey="1x00000000000000000000AA"', $first);
            $this->assertStringContainsString('data-action="login"', $first);
            $this->assertStringContainsString(helper_plugin_turnstile::SCRIPT_URL, $first);
            $this->assertStringNotContainsString('1x0000000000000000000000000000000AA', $first, 'secret leaked');
            // the API script is loaded only once per page
            $this->assertStringNotContainsString(helper_plugin_turnstile::SCRIPT_URL, $helper->getHtml('register'));
        }

        /**
         * Real request with Cloudflare's always-passing and always-failing dummy secrets
         *
         * @group internet
         */
        public function testCloudflareDummyKeys(): void
        {
            global $conf;
            $helper = new helper_plugin_turnstile();
            $this->assertEquals(helper_plugin_turnstile::RESULT_OK, $helper->verify('XXXX.DUMMY.TOKEN.XXXX', 'login'));

            $conf['plugin']['turnstile']['secretkey'] = '2x0000000000000000000000000000000AA';
            $helper = new helper_plugin_turnstile();
            $this->assertEquals(helper_plugin_turnstile::RESULT_INVALID, $helper->verify('XXXX.DUMMY.TOKEN.XXXX', 'login'));
        }
    }
}
