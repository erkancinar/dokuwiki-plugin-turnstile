<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Form\Form;

/**
 * Cloudflare Turnstile for the login, password reset and registration forms
 *
 * @license GPL 2 (https://www.gnu.org/licenses/gpl-2.0.html)
 * @author  Erkan Çınar <erkancinar@gmail.com>
 */
class action_plugin_turnstile extends ActionPlugin
{
    /** form output events and the form name used in the configuration */
    protected const FORM_EVENTS = [
        'FORM_LOGIN_OUTPUT' => 'login',
        'FORM_RESENDPWD_OUTPUT' => 'resendpwd',
        'FORM_REGISTER_OUTPUT' => 'register',
    ];

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        foreach (array_keys(self::FORM_EVENTS) as $eventName) {
            $controller->register_hook($eventName, 'BEFORE', $this, 'handleFormOutput');
        }
        // logins are processed before any action, so they have their own event
        $controller->register_hook('AUTH_LOGIN_CHECK', 'BEFORE', $this, 'handleLoginCheck');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleActPreprocess');
    }

    /**
     * Put the widget in front of the submit button
     *
     * @param Event $event
     * @return void
     */
    public function handleFormOutput(Event $event)
    {
        $helper = $this->getHelper();
        if (!$helper->isFormProtected(self::FORM_EVENTS[$event->name])) return;

        $form = $event->data;
        if (!$form instanceof Form) return;

        $pos = $form->findPositionByAttribute('type', 'submit');
        if ($pos === false) return;

        $form->addHTML($helper->getHtml(self::FORM_EVENTS[$event->name]), $pos);
    }

    /**
     * Check the token of a submitted login before the password is looked at
     *
     * The event also fires for re-authentication from the session cookie (no user name), which is never checked,
     * and for HTTP Basic authentication (silent), which is checked unless the helper exempts it.
     *
     * @param Event $event
     * @return void
     */
    public function handleLoginCheck(Event $event)
    {
        global $INPUT;

        if ($event->data['user'] === '') return;

        $helper = $this->getHelper();
        if (!$helper->isFormProtected('login')) return;

        $basicAuth = !empty($event->data['silent']) || $INPUT->bool('http_credentials');
        if ($basicAuth && $helper->isBasicAuthExempt()) return;

        if ($helper->check('login')) return;

        $event->data['silent'] = true; // check() already showed the reason
        $event->result = false;
        $event->preventDefault();
        $event->stopPropagation();
    }

    /**
     * Check the token when the password reset or registration form is submitted
     *
     * On failure the "save" flag is removed, so DokuWiki shows the form again instead of processing it.
     *
     * @param Event $event
     * @return void
     */
    public function handleActPreprocess(Event $event)
    {
        global $INPUT;

        $act = act_clean($event->data);
        if (!in_array($act, ['resendpwd', 'register'], true)) return;
        if (!$INPUT->post->bool('save')) return; // the form is only being displayed

        $helper = $this->getHelper();
        if (!$helper->isFormProtected($act)) return;
        if ($helper->check($act)) return;

        $INPUT->post->set('save', false);
    }

    /**
     * @return helper_plugin_turnstile
     */
    protected function getHelper()
    {
        /** @var helper_plugin_turnstile $helper */
        $helper = plugin_load('helper', 'turnstile');
        return $helper;
    }
}
