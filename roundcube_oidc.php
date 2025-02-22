<?php

// Require composer autoload for direct installs
@include __DIR__ . '/vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

    /**
     * Roundcube OIDC
     *
     * Login to roundcube with OpenID Connect provider
     *
     * @license	MIT License: <http://opensource.org/licenses/MIT>
     * @author Varun Patil
     * @category  Plugin for RoundCube WebMail
     */
    class roundcube_oidc extends rcube_plugin
    {
        public $task = 'login|logout';
        private $map;

        function init() {
            $this->load_config('config.inc.php.dist');
            $this->load_config('config.inc.php');
            $this->add_hook('template_object_loginform', array($this, 'loginform'));
            $this->add_hook('logout_after', array($this, 'logout_after'));
        }

        function altReturn($ERROR) {
            // Get mail object
            $RCMAIL = rcmail::get_instance();

            // Check if overridden login page
            $altLogin = $RCMAIL->config->get('oidc_login_page');

            // Include and exit
            if (isset($altLogin) && !empty($altLogin)) {
                include $altLogin;
                exit;
            }
        }

        function logout_after() {
            $RCMAIL = rcmail::get_instance();

            $oidc = new OpenIDConnectClient(
                $RCMAIL->config->get('oidc_url'),
                $RCMAIL->config->get('oidc_client'),
                $RCMAIL->config->get('oidc_secret')
            );

            rcube_utils::setcookie('roundcube_oidc_login_hint', '');

            // TODO: We could save idToken during login and use it here
            $oidc->signOut(null, null);
        }

        public function loginform($content) {
            // Get mail object
            $RCMAIL = rcmail::get_instance();
            $auto_redirect = $RCMAIL->config->get('oidc_auto_redirect');

            if ($auto_redirect === true) {
                $content['content'] = '';
            }
            else {
                // Add the login link
                $content['content'] .= "<p> <a href='?oidc=1'> Login with OIDC </a> </p>";

                // Check if we are starting or resuming oidc auth
                if (!isset($_GET['code']) && !isset($_GET['oidc'])) {
                    $this->altReturn(null);
                    return $content;
                }
            }

            // Define error for alt login
            $ERROR = '';

            // Get mail object
            $RCMAIL = rcmail::get_instance();

            // Get master password and default imap server
            $password = $RCMAIL->config->get('oidc_imap_master_password');
            $imap_server = $RCMAIL->config->get('default_host');

            // Build provider
            $oidc = new OpenIDConnectClient(
                $RCMAIL->config->get('oidc_url'),
                $RCMAIL->config->get('oidc_client'),
                $RCMAIL->config->get('oidc_secret')
            );
            $oidc->setRedirectURL($oidc->getRedirectURL() . '/');
            $oidc->addScope(explode(' ', $RCMAIL->config->get('oidc_scope')));

            // Try to decrypt login_hint cookie
            $login_hint = $RCMAIL->decrypt($_COOKIE['roundcube_oidc_login_hint']);
            if ($login_hint) {
                $oidc->addAuthParam(['login_hint' => $login_hint]);
            }

            // Get user information
            try {
                $oidc->authenticate();
                $user = json_decode(json_encode($oidc->getVerifiedClaims()), true);
            } catch (\Exception $e) {
                $ERROR = 'OIDC Authentication Failed <br/>' . $e->getMessage();
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
                $this->altReturn($ERROR);
                return $content;
            }

            // Parse fields
            $uid = $user[$RCMAIL->config->get('oidc_field_uid')];
            $mail = $uid;
            $password = get($user[$RCMAIL->config->get('oidc_field_password')], $password);
            $imap_server = get($user[$RCMAIL->config->get('oidc_field_server')], $imap_server);

            if (!$uid) {
                $content['content'] .= "<p class='alert-danger'> User ID (mail) not provided. </p>";
                $this->altReturn($ERROR);
                return $content;
            }

            // Check if master user is present
            $master = $RCMAIL->config->get('oidc_config_master_user');
            if ($master != '') {
                $uid .= $RCMAIL->config->get('oidc_master_user_separator') . $master;
            }

            // Trigger auth hook
            $auth = $RCMAIL->plugins->exec_hook('authenticate', array(
                'user' => $uid,
                'pass' => $password,
                'cookiecheck' => true,
                'valid'       => true,
            ));

            // Login to IMAP
            if ($RCMAIL->login($auth['user'], $password, $imap_server, $auth['cookiecheck'])) {
                rcube_utils::setcookie('roundcube_oidc_login_hint', $RCMAIL->encrypt($mail));

                // Update user profile
                $user_identity = $RCMAIL->user->get_identity();
                $iid = $user_identity['identity_id'];
                if (isset($iid) && (!$user_identity['name'] || $user_identity['email'] == $uid)) {
                    $data = array('email' => $mail);

                    $claim_name = $user['name'];
                    if (isset($claim_name)) {
                        $data['name'] = $claim_name;
                    }

                    $RCMAIL->user->update_identity($iid, $data);
                }

                $RCMAIL->session->remove('temp');
                $RCMAIL->session->regenerate_id(false);
                $RCMAIL->session->set_auth_cookie();
                $RCMAIL->log_login();
                $query = array();
                $redir = $RCMAIL->plugins->exec_hook('login_after', $query + array('_task' => 'mail'));
                unset($redir['abort'], $redir['_err']);
                $query = array('_action' => '');
                $OUTPUT = new rcmail_html_page();
                $redir = $RCMAIL->plugins->exec_hook('login_after', $query + array('_task' => 'mail'));
                $RCMAIL->session->set_auth_cookie();

                $OUTPUT->redirect($redir, 0, true);
            } else {
                $ERROR = 'IMAP authentication failed!';
                $content['content'] .= "<p class='alert-danger'> $ERROR </p>";
            }

            $this->altReturn($ERROR);
            return $content;
        }

    }

    function get(&$var, $default=null) {
        return isset($var) ? $var : $default;
    }

