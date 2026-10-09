<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Form\Form;

/**
 * Cloudflare Turnstile for the login, password reset and registration forms and for page editing
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
        'FORM_EDIT_OUTPUT' => 'edit',
        // shown instead of the editor when somebody else saved the page meanwhile, it saves the text as well
        'FORM_CONFLICT_OUTPUT' => 'edit',
    ];

    /** forms that logged-in users see too; whether they are asked depends on the "forusers" setting */
    protected const USER_FORMS = ['edit'];

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
        if (!$this->isProtected(self::FORM_EVENTS[$event->name])) return;

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
     * Check the token when an edited page is saved or the password reset or registration form is submitted
     *
     * A failed page save becomes a preview: nothing is written and the editor shows the submitted text again, the
     * way DokuWiki handles a missing security token. Preview and draft requests are not checked, so they do not use
     * up the single-use token. Pages written through the remote API or by reverting to an old revision have no
     * form to show the widget in and are not covered.
     *
     * A failed password reset or registration loses its "save" flag, so DokuWiki shows the form again instead of
     * processing it.
     *
     * @param Event $event
     * @return void
     */
    public function handleActPreprocess(Event $event)
    {
        global $INPUT;

        $act = act_clean($event->data);
        if ($act === 'save') {
            if ($this->isProtected('edit') && !$this->getHelper()->check('edit')) {
                $event->data = 'preview';
            }
            return;
        }

        if (!in_array($act, ['resendpwd', 'register'], true)) return;
        if (!$INPUT->post->bool('save')) return; // the form is only being displayed

        $helper = $this->getHelper();
        if (!$helper->isFormProtected($act)) return;
        if ($helper->check($act)) return;

        $INPUT->post->set('save', false);
    }

    /**
     * Should the current request be checked for the given form?
     *
     * Forms only anonymous users see keep the plain configuration check, so their behaviour does not depend on
     * the "forusers" setting.
     *
     * @param string $form
     * @return bool
     */
    protected function isProtected($form)
    {
        $helper = $this->getHelper();
        if (in_array($form, self::USER_FORMS, true)) return $helper->isRequestProtected($form);
        return $helper->isFormProtected($form);
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
