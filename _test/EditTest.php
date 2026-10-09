<?php

namespace {

    /**
     * Helper that answers every verification with a fixed result instead of asking Cloudflare
     *
     * The class name has to follow the plugin naming scheme, getConf() and getLang() derive the plugin from it.
     */
    class helper_plugin_turnstile_fixedresult extends helper_plugin_turnstile
    {
        /** @var string result of every verification */
        public $result = self::RESULT_OK;
        /** @var string[] action of each verification */
        public $actions = [];

        /** @inheritdoc */
        public function verify($token, $action, $remoteIp = '')
        {
            $this->actions[] = $action;
            return $this->result;
        }
    }
}

namespace dokuwiki\plugin\turnstile\test {

    use dokuwiki\Extension\Event;
    use dokuwiki\Remote\Api;
    use DokuWikiTest;
    use helper_plugin_turnstile;
    use helper_plugin_turnstile_fixedresult;
    use TestRequest;
    use TestResponse;

    /**
     * Tests for protecting page edits
     *
     * Anonymous requests run through doku.php like a browser would send them. Logged-in requests trigger the action
     * event directly, the test request does not set up a login.
     *
     * @group plugin_turnstile
     * @group plugins
     */
    class EditTest extends DokuWikiTest
    {
        protected const PAGE = 'turnstile_edit';
        protected const TEXT = 'Text written in the editor';

        protected $pluginsEnabled = ['turnstile'];

        public function setUp(): void
        {
            parent::setUp();
            global $conf, $DOKU_PLUGINS;
            $conf['plugin']['turnstile']['sitekey'] = '1x00000000000000000000AA';
            $conf['plugin']['turnstile']['secretkey'] = '1x0000000000000000000000000000000AA';
            $conf['plugin']['turnstile']['forms'] = 'login,resendpwd,register,edit';
            // plugin instances are cached globally, make them read the configuration above
            unset($DOKU_PLUGINS['helper']['turnstile'], $DOKU_PLUGINS['action']['turnstile']);
            // TestRequest saves the session, which does not exist when the test run sent output before init.php
            $_SESSION = $_SESSION ?? [];
            // the wiki data is only reset between test classes
            if (page_exists(self::PAGE)) unlink(wikiFN(self::PAGE));
        }

        /**
         * Make the plugin use a helper with a fixed verification result
         *
         * @param string $result
         * @return helper_plugin_turnstile_fixedresult
         */
        protected function fixResult($result)
        {
            global $DOKU_PLUGINS;
            $helper = new helper_plugin_turnstile_fixedresult();
            $helper->result = $result;
            $DOKU_PLUGINS['helper']['turnstile'] = $helper;
            return $helper;
        }

        /**
         * Send the editor form of the test page as an anonymous user
         *
         * @param string $button do[...] key of the pressed button
         * @param array $fields further form fields
         * @return TestResponse
         */
        protected function submitEditor($button, array $fields = [])
        {
            $request = new TestRequest();
            return $request->post(
                $fields + ['id' => self::PAGE, 'do' => [$button => '1'], 'wikitext' => self::TEXT, 'summary' => 'test'],
                '/doku.php'
            );
        }

        /**
         * Trigger the action event like the action router does
         *
         * @param string $act
         * @return string the action that would run
         */
        protected function preprocess($act)
        {
            $event = new Event('ACTION_ACT_PREPROCESS', $act);
            $event->trigger();
            return $act;
        }

        public function testEditorShowsWidget(): void
        {
            $request = new TestRequest();
            $html = $request->get(['id' => self::PAGE, 'do' => 'edit'])->getContent();
            // assertMatchesRegularExpression() is missing in the PHPUnit version of Kaos
            $widget = '/<div class="[^"]*\bcf-turnstile\b[^"]*" [^>]*data-action="edit"/';
            $this->assertSame(1, preg_match($widget, $html), 'widget bound to the edit form');
        }

        public function testEditorWithoutEditSelectedShowsNoWidget(): void
        {
            global $conf;
            $conf['plugin']['turnstile']['forms'] = 'login';
            $request = new TestRequest();
            $html = $request->get(['id' => self::PAGE, 'do' => 'edit'])->getContent();
            $this->assertStringContainsString('dw__editform', $html);
            $this->assertStringNotContainsString('cf-turnstile', $html);
        }

        public function testSaveWithoutTokenBecomesPreview(): void
        {
            $html = $this->submitEditor('save')->getContent();
            $this->assertFalse(page_exists(self::PAGE), 'the page must not be written');
            $this->assertStringContainsString('dw__editform', $html, 'the editor is shown again');
            $this->assertStringContainsString(self::TEXT, $html, 'the submitted text is kept');
            $this->assertStringContainsString((new helper_plugin_turnstile())->getLang('failed'), $html);
        }

        public function testSaveWithValidTokenIsWritten(): void
        {
            $helper = $this->fixResult(helper_plugin_turnstile::RESULT_OK);
            $this->submitEditor('save', ['cf-turnstile-response' => 'token']);
            $this->assertSame(self::TEXT, rawWiki(self::PAGE));
            $this->assertSame(['edit'], $helper->actions, 'one verification bound to the edit form');
        }

        public function testSectionSaveWithoutTokenKeepsPage(): void
        {
            $page = "====== One ======\nfirst\n\n====== Two ======\nsecond\n";
            saveWikiText(self::PAGE, $page, 'created');

            // DokuWiki cuts the last character of the prefix, the editor appends one
            $section = ['prefix' => "====== One ======\nfirst\n\n.", 'suffix' => ''];
            $this->submitEditor('save', $section + ['wikitext' => "====== Two ======\nchanged\n"]);
            $this->assertSame($page, rawWiki(self::PAGE));

            $this->fixResult(helper_plugin_turnstile::RESULT_OK);
            $this->submitEditor('save', $section + ['wikitext' => "====== Two ======\nchanged\n"]);
            $this->assertStringContainsString('first', rawWiki(self::PAGE));
            $this->assertStringContainsString('changed', rawWiki(self::PAGE));
        }

        public function testConflictFormShowsWidget(): void
        {
            saveWikiText(self::PAGE, 'saved by somebody else', 'created');
            $this->fixResult(helper_plugin_turnstile::RESULT_OK);
            // the editor was opened before the other save
            $html = $this->submitEditor('save', ['cf-turnstile-response' => 'token', 'date' => 1])->getContent();
            $this->assertSame('saved by somebody else', rawWiki(self::PAGE));
            $this->assertStringContainsString('cf-turnstile', $html, 'the conflict form can be saved with a new check');
        }

        public function testPreviewIsNotChecked(): void
        {
            $helper = $this->fixResult(helper_plugin_turnstile::RESULT_INVALID);
            $html = $this->submitEditor('preview')->getContent();
            $this->assertSame([], $helper->actions, 'a preview must not use up the token');
            $this->assertStringContainsString(self::TEXT, $html);
            $this->assertFalse(page_exists(self::PAGE));
        }

        /**
         * @return array[]
         */
        public function provideFailmode(): array
        {
            return [
                'closed rejects' => ['closed', false],
                'open accepts' => ['open', true],
            ];
        }

        /**
         * @dataProvider provideFailmode
         */
        public function testUnavailableServiceFollowsFailmode(string $failmode, bool $written): void
        {
            global $conf;
            $conf['plugin']['turnstile']['failmode'] = $failmode;
            $this->fixResult(helper_plugin_turnstile::RESULT_UNAVAILABLE);
            $this->submitEditor('save', ['cf-turnstile-response' => 'token']);
            $this->assertSame($written, page_exists(self::PAGE));
        }

        public function testSaveWithoutEditSelectedIsNotChecked(): void
        {
            global $conf;
            $conf['plugin']['turnstile']['forms'] = 'login,resendpwd,register';
            $this->submitEditor('save');
            $this->assertSame(self::TEXT, rawWiki(self::PAGE));
        }

        public function testLoggedInUserIsNotAskedByDefault(): void
        {
            global $INPUT;
            $INPUT->server->set('REMOTE_USER', 'testuser');
            $helper = new helper_plugin_turnstile();
            $this->assertTrue($helper->isFormProtected('edit'), 'the configuration check stays as it was');
            $this->assertFalse($helper->isRequestProtected('edit'));
            $this->assertSame('save', $this->preprocess('save'));
        }

        public function testLoggedInUserIsAskedWhenEnabled(): void
        {
            global $INPUT, $conf;
            $INPUT->server->set('REMOTE_USER', 'testuser');
            $conf['plugin']['turnstile']['forusers'] = 1;
            $this->assertTrue((new helper_plugin_turnstile())->isRequestProtected('edit'));
            $this->assertSame('preview', $this->preprocess('save'));
        }

        public function testRemoteApiSaveIsNotChecked(): void
        {
            global $conf;
            $conf['remote'] = 1;
            $conf['remoteuser'] = '@user';
            $conf['useacl'] = 0;
            $helper = $this->fixResult(helper_plugin_turnstile::RESULT_INVALID);
            $api = new Api();
            $this->assertTrue($api->call('core.savePage', ['page' => self::PAGE, 'text' => self::TEXT]));
            $this->assertSame(self::TEXT, rawWiki(self::PAGE));
            $this->assertSame([], $helper->actions);
        }
    }
}
