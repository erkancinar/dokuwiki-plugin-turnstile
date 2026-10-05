<?php

namespace dokuwiki\plugin\turnstile\test;

use dokuwiki\MailUtils;
use DokuWikiTest;

/**
 * General tests for the turnstile plugin
 *
 * @group plugin_turnstile
 * @group plugins
 */
class GeneralTest extends DokuWikiTest
{
    /**
     * Simple test to make sure the plugin.info.txt is in correct format
     */
    public function testPluginInfo(): void
    {
        $file = __DIR__ . '/../plugin.info.txt';
        $this->assertFileExists($file);

        $info = confToHash($file);

        $this->assertArrayHasKey('base', $info);
        $this->assertArrayHasKey('author', $info);
        $this->assertArrayHasKey('email', $info);
        $this->assertArrayHasKey('date', $info);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('desc', $info);
        $this->assertArrayHasKey('url', $info);

        $this->assertEquals('turnstile', $info['base']);
        // preg_match instead of assertMatchesRegularExpression: older releases ship PHPUnit 8
        $this->assertSame(1, preg_match('/^https?:\/\//', $info['url']));
        $this->assertTrue(
            class_exists(MailUtils::class) ? MailUtils::isValid($info['email']) : mail_isvalid($info['email'])
        );
        $this->assertSame(1, preg_match('/^\d\d\d\d-\d\d-\d\d$/', $info['date']));
        $this->assertTrue(false !== strtotime($info['date']));
    }

    /**
     * Every conf['...'] entry in conf/default.php has a corresponding meta['...'] entry in conf/metadata.php
     * and the other way round.
     */
    public function testPluginConf(): void
    {
        $conf_file = __DIR__ . '/../conf/default.php';
        $meta_file = __DIR__ . '/../conf/metadata.php';

        if (!file_exists($conf_file) && !file_exists($meta_file)) {
            self::markTestSkipped('No config files exist -> skipping test');
        }

        if (file_exists($conf_file)) {
            include($conf_file);
        }
        if (file_exists($meta_file)) {
            include($meta_file);
        }

        $this->assertEquals(
            gettype($conf),
            gettype($meta),
            'Both ' . DOKU_PLUGIN . 'turnstile/conf/default.php and ' . DOKU_PLUGIN .
            'turnstile/conf/metadata.php have to exist and contain the same keys.'
        );

        if ($conf !== null && $meta !== null) {
            foreach ($conf as $key => $value) {
                $this->assertArrayHasKey(
                    $key,
                    $meta,
                    'Key $meta[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'turnstile/conf/metadata.php'
                );
            }

            foreach ($meta as $key => $value) {
                $this->assertArrayHasKey(
                    $key,
                    $conf,
                    'Key $conf[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'turnstile/conf/default.php'
                );
            }
        }
    }

    /**
     * Every language has the same keys as English
     */
    public function testLanguageKeys(): void
    {
        foreach (['lang.php', 'settings.php'] as $file) {
            $lang = [];
            include(__DIR__ . '/../lang/en/' . $file);
            $english = array_keys($lang);
            sort($english);

            foreach (glob(__DIR__ . '/../lang/*/' . $file) as $translation) {
                $lang = [];
                include($translation);
                $keys = array_keys($lang);
                sort($keys);
                $this->assertEquals($english, $keys, 'Keys differ in ' . $translation);
            }
        }
    }
}
