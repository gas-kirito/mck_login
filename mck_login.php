<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'mck_login';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.1';
$plugin['author'] = 'Jukka Svahn+Casalegno Marco';
$plugin['author_uri'] = '';
$plugin['description'] = 'Handles site-wide logins, sessions, password recovery and self-registering';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * Handles site-wide logins, sessions and self-registering
 *
 * @package mck_login
 * @author Casalegno Marco <http://www.kreatore.it/>
 * @author Jukka Svahn <http://rahforum.biz>
 * @license GNU GPLv2
 * @link http://www.kreatore.it/txp/mck_login
 * @link https://github.com/gocom/mck_login
 *
 * Requires Textpattern v4.4.1 (or newer) and PHP v5.2 (or newer)
 */

/**
 * Handles form validation and saving, all of the non-tag stuff
 */

class mck_login
{
    static public $form_errors = array();
    static public $action;

    /**
     * Constructor.
     */

    public function __construct()
    {
        if (!defined('mck_login_pub_path'))
        {
            define('mck_login_pub_path', preg_replace('|//$|','/', rhu.'/'));
        }

        if (!defined('mck_login_admin_path'))
        {
            define('mck_login_admin_path', '/textpattern/');
        }

        if (!defined('mck_login_admin_domain'))
        {
            define('mck_login_admin_domain', '');
        }

        register_callback(array($this, 'logInHandler'), 'textpattern');
        register_callback(array($this, 'logOutHandler'), 'textpattern');
        register_callback(array($this, 'confirmResetHandler'), 'textpattern');
    }

    /**
     * Add and get form validation errors
     * @param string $message Either l10n string, or single line of text
     * @param string $type For which form the error is for.
     * @return array
     * <code>
     *        mck_login::error('abc_l10n_string');
     * </code>
     */
    
    static public function error($message=NULL, $type=NULL) {
        
        if(!$type)
            $type = self::$action;
        
        if(!isset(self::$form_errors[$type]))
            self::$form_errors[$type] = array();
        
        if($message !== NULL)
            self::$form_errors[$type][] = $message;
        
        return self::$form_errors[$type];
    }

    /**
     * Log out handler.
     */

    public function logOutHandler()
    {
        if ($logout = gps('mck_logout') && $user = is_logged_in() && self::$action = 'logout')
        {
            callback_event('mck_login.logout');

            safe_update(
                'txp_users',
                "nonce = '".doSlash(md5(uniqid(mt_rand(), true)))."'",
                "name = '".doSlash($user['name'])."'"
            );

            setcookie('txp_login_public', '', time() - 3600, mck_login_pub_path);
            setcookie('txp_login', '', time() - 3600, mck_login_admin_path, mck_login_admin_domain);
            unset($_COOKIE['txp_login_public']);
        }
    }

    /**
     * Log in handler.
     */

    public function logInHandler()
    {
        extract(doArray(array(
            'name' => ps('mck_login_name'),
            'pass' => ps('mck_login_pass'),
            'stay' => ps('mck_login_stay'),
            'form' => ps('mck_login_form'),
        ), 'trim'));

        if ($form && !is_logged_in() && strpos($form, ';') && self::$action = 'login')
        {
            callback_event('mck_login.login');

            if (!$pass || !$name)
            {
                self::error('name_and_pass_required');
                return;
            }

            $form = explode(';', (string) $form);

            if ($form[1] != md5($form[0] . get_pref('blog_uid')))
            {
                self::error('invalid_token');
                return;
            }

            if ((int) $form[0] < @strtotime('-30 minutes'))
            {
                self::error('form_expired');
                return;
            }

            include_once txpath . '/lib/txplib_admin.php';
            include_once txpath . '/include/txp_auth.php';
            
            if (txp_validate($name, $pass, false) === false)
            {
                callback_event('mck_login.invalid_login');
                self::error('invalid_login');
                sleep(3);
                return;
            }

            $c_hash = md5(uniqid(mt_rand(), true));
            $nonce = md5($name.pack('H*', $c_hash));
            $value = substr(md5($nonce), -10).$name;
            $privs = fetch('privs', 'txp_users', 'name', $name);

            safe_update(
                'txp_users',
                "nonce = '".doSlash($nonce)."', last_access = now()",
                "name='".doSlash($name)."'"
            );

            setcookie(
                'txp_login_public',
                $value,
                $stay ? time()+3600*24*30 : 0,
                mck_login_pub_path
            );

            if ($privs > 0)
            {
                setcookie('txp_login', $name.','.$c_hash, $stay ? time() + 3600*24*365 : 0, mck_login_admin_path, mck_login_admin_domain);
            }

            $_COOKIE['txp_login_public'] = $value;
            callback_event('mck_login.logged_in');
        }
    }

    /**
     * Reset password.
     */

    public function confirmResetHandler()
    {
        if ($reset = ps('mck_reset') && !is_logged_in())
        {
            self::$action = 'reset';
            
            callback_event('mck_login.reset_confirm');
            
            sleep(3);

            $confirm = pack('H*', $reset);
            $reset = substr($confirm, 5);
            
            if (!strpos($reset, ';'))
            {
                self::error('invalid_token');
                return;
            }
            
            $name = explode(';', $reset);
            $redirect = array_pop($name);
            $name = implode(';', $name);
            
            $r = 
                safe_row(
                    'nonce, email',
                    'txp_users',
                    "name='".doSlash($name)."'"
                );
            
            $packed = pack('H*', substr(md5($r['nonce'] . $redirect), 0, 10)) . $name . ';' . $redirect;
            
            if (!$r || !$r['nonce'] || $confirm !== $packed)
            {
                sleep(3);
                self::error('invalid_token');
                return;
            }

            include_once txpath . '/lib/txplib_admin.php';
            include_once txpath . '/include/txp_auth.php';

            $pass = generate_password(12);
            $hash = txp_hash_password($pass);
            
            if (
                safe_update(
                    'txp_users',
                    "pass='".doSlash($hash)."',
                    nonce='".doSlash(md5($name.pack('H*', md5(uniqid(mt_rand(), true)))))."'",
                    "name='".doSlash($name)."'"
                ) === false
            )
            {
                
                self::error('saving_failed');
                return;
            }
            
            $message = 
                gTxt('greeting').' '.$name.','.n.n.
                gTxt('your_password_is').': '.$password.n.n.
                gTxt('log_in_at').': '.hu.$redirect;
            
            $subject = 
                gTxt('mck_login_your_new_password', 
                    array('{sitename}' => get_pref('sitename'))
                );
            
            if (txpMail($r['email'], $subject, $message) === false)
            {
                self::error('could_not_mail');
                return;
            }

            callback_event('mck_login.reset_confirmed');

            header('Location: ' .hu.$redirect);

            $msg = gTxt('mck_login_redirect_message', array('{url}' => htmlspecialchars(hu.$redirect)));
            die($msg);
        }
    }

    /**
     * Send password reset confirmation message
     * @param array $atts
     * @return bool
     * @access private
     */

    static public function send_reset($atts) {
        
        extract(doArray(array(
            'name' => ps('mck_reset_name'),
            'form' => ps('mck_reset_form'),
        ), 'trim'));
        
        $is_logged_in = mck_login(true) !== false;
        
        if(!$form || !strpos($form, ';') || $is_logged_in) {
            return false;
        }
        
        self::$action = 'reset';
        
        callback_event('mck_login.reset');
        
        $form = explode(';', (string) $form);
        
        if($form[1] != md5($form[0] . get_pref('blog_uid'))) {
            self::error('invalid_token');
            return false;
        }
        
        if((int) $form[0] < @strtotime('-30 minutes')) {
            self::error('form_expired');
            return false;
        }
        
        $r = 
            safe_row(
                'email, nonce',
                'txp_users',
                "name='".doSlash($name)."'"
            );
        
        if(!$r) {
            self::error('invalid_username');
            return false;
        }
        
        $confirm = 
            bin2hex(
                pack('H*', substr(md5($r['nonce'] . $atts['go_to_after']), 0, 10)). 
                $name . ';' . $atts['go_to_after']
            );
        
        $message = 
            gTxt('greeting').' '.$name.','.n.n.
            gTxt('password_reset_confirmation').': '.n.n.
            hu.'?mck_reset='.$confirm;
        
        if(txpMail($r['email'], $atts['subject'], $message) === false) {
            self::error('could_not_mail');
            return false;
        }
        
        callback_event('mck_login.reset_sent');
        return true;
    }

    /**
     * Save a new user
     * @param array $atts
     * @return bool
     * @see generate_password(), txp_hash_password()
     * @access private
     */

    static public function add_user($atts) {
    
        extract(doArray(array(
            'email' => ps('mck_register_email'),
            'name' => ps('mck_register_name'),
            'RealName' => ps('mck_register_realname'),
            'form' => ps('mck_register_form'),
        ), 'trim'));
        
        if(!$form || !strpos($form, ';'))
            return false;
        
        self::$action = 'register';

        callback_event('mck_login.register');
        
        if(self::$form_errors)
            return false;
        
        $ip = remote_addr();
        
        if(is_blacklisted($ip)) {
            self::error('ip_blacklisted');
            return false;
        }
        
        if(fetch('ip', 'txp_discuss_ipban', 'ip', $ip)) {
            self::error('you_have_been_banned');
            return false;
        }
        
        if(!$email || !$name || !$RealName) {
            self::error('all_fields_required');
            return false;
        }
        
        $form = explode(';', (string) $form);
        
        if($form[1] != md5($form[0] . get_pref('blog_uid'))) {
            self::error('invalid_token');
            return false;
        }
        
        if((int) $form[0] < @strtotime('-30 minutes')) {
            self::error('form_expired');
            return false;
        }
        
        if(self::field_strlen($email) > 100)
            self::error('email_too_long');
        
        elseif(!is_valid_email($email))
            self::error('invalid_email');
        
        if(self::field_strlen($name) < 3)
            self::error('username_too_short');
        
        elseif(self::field_strlen($name) > 64)
            self::error('username_too_long');
        
        if(self::field_strlen($RealName) > 64)
            self::error('realname_too_long');
        
        if(self::error())
            return false;
        
        if(
            safe_row(
                'name', 
                'txp_users',
                "name='".doSlash($name)."' OR email='".doSlash($email)."' LIMIT 0, 1"
            )
        ) {
        
            if(fetch('email', 'txp_users', 'email', $email)) {
                self::error('email_in_use');
            }

            /* -- removed
             *    self::error('username_taken');
             */
            // -- FIX: spit error only if username already exists
            if(fetch('name', 'txp_users', 'name' , $name)) {
                self::error('username_taken');
            }

            return false;
        }
        
        sleep(3);
    
        include_once txpath . '/lib/txplib_admin.php';
        include_once txpath . '/include/txp_auth.php';
    
        $password = generate_password(12);
        $hash = txp_hash_password($password);
        $privs = (int) $atts['privs'];

        if(
            safe_insert(
                'txp_users',
                "privs='{$privs}', 
                name='".doSlash($name)."',
                email='".doSlash($email)."',
                RealName='".doSlash($RealName)."',
                nonce='".doSlash(md5(uniqid(mt_rand(), true)))."',
                pass='".doSlash($hash)."'"
            ) === false
        ) {
            self::error('saving_failed');
            return false;
        }
        
        $message = 
            gTxt('greeting').' '.$name.','.
            n.n.gTxt('your_password_is').': '.$password.
            n.n.gTxt('log_in_at').': '.$atts['log_in_url'];
      
        if(txpMail($email, $atts['subject'], $message) === false) {
            self::error('could_not_mail');
            return false;
        }
        
        callback_event('mck_login.registered');
        return true;
    }

    /**
     * Save a new password
     * @return bool
     * @see txp_validate(), txp_hash_password()
     * @access private
     */

    static public function save_password() {
        
        extract(doArray(array(
            'old_pass' => ps('mck_password_old'),
            'new_pass' => ps('mck_password_new'),
            'confirm_pass' => ps('mck_password_confirm'),
            'token' => ps('mck_login_token'),
            'form' => ps('mck_password_form'),
        ), 'trim'));
        
        if(!$form || mck_login(true) === false)
            return false;
        
        self::$action = 'password';
        
        callback_event('mck_login.save_password');
        
        if(self::error())
            return false;
        
        if(!$old_pass || !$new_pass || !$confirm_pass) {
            self::error('all_fields_required');
            return false;
        }
        
        if($token != mck_login_token()) {
            self::error('invalid_csrf_token');
            return false;
        }
        
        $length = function_exists('mb_strlen') ? 
            mb_strlen($new_pass, 'UTF-8') : strlen($new_pass);
        
        if(6 > $length)
            self::error('password_too_short');
        
        if($new_pass !== $confirm_pass)
            self::error('passwords_do_not_match');
        
        $name = mck_login(array('name' => 'name'));
        
        include_once txpath . '/lib/txplib_admin.php';
        include_once txpath . '/include/txp_auth.php';
            
        if(txp_validate($name, $old_pass, false) === false) {
            self::error('old_password_incorrect');
            sleep(3);
        }
        
        if(self::error())
            return false;
        
        $hash = txp_hash_password($new_pass);
        
        if(
            safe_update(
                'txp_users',
                "pass='".doSlash($hash)."'",
                "name='".doSlash($name)."'"
            ) === false
        ) {
            self::error('saving_failed');
            return false;
        }
        
        callback_event('mck_login.password_saved');
    }
    
    /**
     * Get string length for pre-save validation.
     * @param string $str
     * @return int
     * @see DB::DB()
     * @access private
     */

    static public function field_strlen($str) {
        global $DB;
        
        $version = (int) @$DB->version[0];
        
        if(!function_exists('mb_strlen') || $version < 5)
            return strlen($str);
        
        return mb_strlen($str, 'UTF-8');
    }
}

new mck_login();

/**
 * Password reset form
 * @param array $atts
 * @param string $atts[action] Form's action (target location)
 * @param string $atts[id] Form's HTML id.
 * @param string $atts[class] Form's HTML class.
 * @param string $atts[go_to_after] The page (page) the confirmation URL directs users. i.e. about/reset-page
 * @param string $atts[subject] Confirmation email's subject.
 * @param string $thing
 * @return string HTML markup
 * <code>
 *        <txp:mck_reset_form>
 *            <txp:mck_login_errors />
 *            <txp:mck_login_input type="text" name="mck_reset_name" />
 *            <button type="submit">Send reset request</button>
 *        <txp:else />
 *            Confirmation email has been sent with a reset link.
 *        </txp:mck_reset_form>
 * </code>
 */

    function mck_reset_form($atts, $thing=''){
    
        global $pretext, $sitename;
    
        $opt = lAtts(array(
            'action' => $pretext['request_uri'] . '#mck_reset_form',
            'id' => 'mck_reset_form',
            'class' => 'mck_reset_form',
            'go_to_after' => '',
            'subject' => '['.$sitename.'] '.gTxt('password_reset_confirmation_request'),
        ), $atts);
        
        if(mck_login(true) !== false)
            return;
        
        $r = mck_login::send_reset($opt);
        extract($opt);
        
        if($r === true && !mck_login::error())
            return parse(EvalElse($thing, false));
        
        $token = ps('mck_reset_form');
        
        if(!$token || !mck_login::error()) {
            $timestamp = strtotime('now');
            $token = $timestamp.';'.md5($timestamp . get_pref('blog_uid'));
        }
        
        if(mck_login::error())
            $class .= ' mck_login_error';
        
        mck_login_errors('reset');
        
        $r =
            '<form method="post" id="'.htmlspecialchars($id).'" class="'.htmlspecialchars($class).'" action="'.htmlspecialchars($action).'">'.n.
                hInput('mck_reset_form', $token).n.
                parse(EvalElse($thing, true)).n.
                callback_event('mck_login.reset_form').
            '</form>';
        
        mck_login_errors(null);
        return $r;
    }

/**
 * Return user data
 * @param array|bool $atts
 * @param string $atts[name] Options: name, RealName, email, privs.
 * @param bool $atts[escape] Convert special characters to HTML entities.
 * @return mixed
 * @see is_logged_in()
 * <code>
 *        <txp:mck_login name="email" />
 * </code>
 */

    function mck_login($atts){
        static $data = NULL;
        
        if($data === NULL) {
            $data = is_logged_in();
        }
        
        if($atts === true) {
            return $data;
        }
    
        extract(lAtts(array(
            'name' => 'RealName',
            'escape' => 1,
        ),$atts));

        if(!$data || !isset($data[$name]))
            return;
        
        return $escape ? htmlspecialchars($data[$name]) : $data[$name];
    }

/**
 * Check if the user is logged in, or that the specified value matches.
 *
 * If used as a self-closing single tag, will display 401 error page
 * on failure.
 *
 * @param  array  $atts
 * @param  string $atts[name]  If NULL, checks if visitor is logged in.
 * @param  string $atts[value] Match to.
 * @param  string $thing
 * @return string
 * @see    mck_login()
 * @example
 * &lt;txp:mck_login_if&gt;
 *     User is logged in.
 * &lt;txp:else /&gt;
 *     User is not logged in.
 * &lt;/txp:mck_login_if&gt;
 */

    function mck_login_if($atts, $thing = null)
    {
        extract(lAtts(array(
            'name'  => null,
            'value' => '',
        ), $atts));

        $r = ($data = mck_login(true)) !== false && ($name === null || isset($data[$name]) && $data[$name] === $value);

        if ($thing !== null)
        {
            return parse(EvalElse($thing, $r));
        }

        if ($r === false)
        {
            txp_die(gTxt('auth_required'), 401);
        }
    }

/**
 * Register form
 * @param array $atts
 * @param int $atts[privs] Privileges the user is created with.
 * @param string $atts[action] Form's action (target location).
 * @param string $atts[id] Form's HTML id. 
 * @param string $atts[class] Form's HTML class.
 * @param string $atts[log_in_url] "Log in at" URL used in the sent email.
 * @param string $atts[subject] Email message's subject.
 * @param string $thing
 * @return string HTML markup.
 * <code>
 *        <txp:mck_register_form>
 *            <txp:mck_login_errors />
 *            <txp:mck_login_input type="text" name="mck_register_email" />
 *            <txp:mck_login_input type="text" name="mck_register_name" />
 *            <txp:mck_login_input type="text" name="mck_register_realname" />
 *            <button type="submit">Register</button>
 *        <txp:else />
 *            Email sent with your login details.
 *        </txp:mck_register_form>
 * </code>
 */

    function mck_register_form($atts, $thing=''){
    
        global $pretext, $sitename;
    
        $opt = lAtts(array(
            'privs' => 0,
            'action' => $pretext['request_uri'].'#mck_register_form',
            'id' => 'mck_register_form',
            'class' => 'mck_register_form',
            'log_in_url' => hu,
            'subject' => '['.$sitename.'] '.gTxt('your_new_password'),
        ), $atts);
        
        $r = mck_login::add_user($opt);
        extract($opt);
        
        if($r === true && !mck_login::error())
            return parse(EvalElse($thing, false));
        
        $token = ps('mck_register_form');
        
        if(!$token || mck_login::error()) {
            $timestamp = strtotime('now');
            $token = $timestamp.';'.md5($timestamp . get_pref('blog_uid'));
        }
        
        if(mck_login::error())
            $class .= ' mck_login_error';
        
        mck_login_errors('register');
        
        $r =
            '<form method="post" id="'.htmlspecialchars($id).'" class="'.htmlspecialchars($class).'" action="'.htmlspecialchars($action).'">'.n.
                hInput('mck_register_form', $token).n.
                parse(EvalElse($thing, true)).n.
                callback_event('mck_login.register_form').
            '</form>';
        
        mck_login_errors(null);
        
        return $r;
    }

/**
 * Displays a login form
 * @param array $atts
 * @param string $atts[action] Form's action (target location).
 * @param string $atts[id] Form's HTML id.
 * @param string $atts[class] Form's HTML class.
 * @param string $thing
 * @return string HTML markup.
 * <code>
 *        <txp:mck_login_form>
 *            <txp:mck_login_errors />
 *            <txp:mck_login_input type="text" name="mck_login_name" />
 *            <txp:mck_login_input type="password" name="mck_login_pass" />
 *            <button type="submit">Log in</button>
 *        <txp:else />
 *            You are logged in. <a href="?mck_logout=1">Log out</a>.
 *        </txp:mck_login_form>
 * </code>
 */

    function mck_login_form($atts, $thing=''){
        
        global $pretext;
    
        extract(lAtts(array(
            'action' => $pretext['request_uri'].'#mck_login_form',
            'id' => 'mck_login_form',
            'class' => 'mck_login_form',
        ), $atts));
        
        if(mck_login(true) !== false)
            return parse(EvalElse($thing, false));
        
        $token = ps('mck_login_form');
        
        if(!$token || mck_login::error()) {
            $timestamp = strtotime('now');
            $token = $timestamp.';'.md5($timestamp . get_pref('blog_uid'));
        }
        
        if(mck_login::error())
            $class .= 'mck_login_error';
        
        mck_login_errors('login');
        
        $thing = 
            '<form method="post" id="'.htmlspecialchars($id).'" class="'.htmlspecialchars($class).'" action="'.htmlspecialchars($action).'">'.n.
                hInput('mck_login_form', $token).n.
                parse(EvalElse($thing, true)).n.
                callback_event('mck_login.login_form').
            '</form>';
        
        mck_login_errors(null);
        
        return $thing;
    }

/**
 * Displays password changing form.
 *
 * @param  array  $atts
 * @param  string $atts[action] Form's action (target location)
 * @param  string $atts[id]     Form's HTML id
 * @param  string $atts[class]  Form's HTML class
 * @param  string $thing
 * @return string HTML markup
 * @example
 * &lt;txp:mck_password_form&gt;
 *     &lt;txp:mck_login_errors /&gt;
 *     &lt;txp:mck_login_input type="password" name="mck_password_old" /&gt;
 *     &lt;txp:mck_login_input type="password" name="mck_password_new" /&gt;
 *     &lt;txp:mck_login_input type="password" name="mck_password_confirm" /&gt;
 *     &lt;button type="submit"&gt;Save new password&lt;/button&gt;
 * &lt;txp:else /&gt;
 *     Password changed.
 * &lt;/txp:mck_password_form&gt;
 */

    function mck_password_form($atts, $thing = '')
    {
        global $pretext;

        extract(lAtts(array(
            'action' => $pretext['request_uri'].'#mck_password_form',
            'id'     => 'mck_password_form',
            'class'  => 'mck_password_form',
        ), $atts));

        if (mck_login(true) === false)
        {
            return;
        }

        $r = mck_login::save_password();

        if ($r === true && !mck_login::error())
        {
            return parse(EvalElse($thing, false));
        }

        if (mck_login::error())
        {
            $class .= 'mck_login_error';
        }

        mck_login_errors('password');

        $thing = tag_start('form', array(
            'method' => 'post',
            'action' => $action,
            'id'     => $id,
            'class'  => $class,
        )).
        hInput('mck_login_token', mck_login_token()).
        hInput('mck_password_form', 1).
        parse(EvalElse($thing, true)).
        callback_event('mck_login.password_form').
        tag_end('form');

        mck_login_errors(null);
        return $thing;
    }

/**
 * Renders a HTML form input.
 *
 * @param  array  $atts Attributes
 * @return string HTML markup
 * @example
 * &lt;txp:mck_login_input type="text" name="foo" value="bar" /&gt;
 */

    function mck_login_input($atts)
    {
        static $uid = 1;

        $r = lAtts(array(
            'type'     => 'text',
            'name'     => '',
            'value'    => '',
            'class'    => 'mck_login_input',
            'id'       => '',
            'label'    => '',
            'required' => 1,
            'remember' => 1,
        ), $atts, false);

        extract($r);

        if ($type == 'token')
        {
            return hInput('mck_login_token', mck_login_token());
        }

        if ($required)
        {
            $r['class'] .= ' mck_login_required';
        }

        if (isset($_POST[$name]))
        {
            if ($type == 'checkbox' && ps($name) == $value)
            {
                $r['checked'] = 'checked';
            }

            if ($type != 'password' && $remember)
            {
                $r['value'] = ps($name);
            }

            if (ps($name) === '' && $required)
            {
                $r['class'] .= ' mck_login_error';
            }
        }

        if (!$id && $uid++)
        {
            $r['id'] = 'mck_login_' . md5($name . $uid);
        }

        if ($label)
        {
            $label = '<label for="'.txpspecialchars($r['id']).'">'.txpspecialchars($r['label']).'</label>'.n;
        }

        $r = array_merge((array) $atts, (array) $r);
        unset($r['label']);

        if ($required != 'required')
        {
            unset($r['required']);
        }

        $out = array();

        foreach ($r as $name => $value)
        {
            if ($value !== '' || $name === 'value')
            {
                $out[] = txpspecialchars($name).'="'.txpspecialchars($value).'"';
            }
        }

        return $label . '<input '. implode(' ', $out).' />';
    }

/**
 * Displays error messages
 * @param array|string $atts
 * @param string $atts[for] Sets which form's errors are shown. Either login, reset, password, register.
 * @param string $atts[wraptag] HTML wraptag.
 * @param string $atts[break] HTML tag used to separate the items.
 * @param string $atts[class] Wraptag's HTML class.
 * @param int $atts[offset] Skip number of errors from the beginning.
 * @param int $atts[limit] Limit number of shown errors.
 * @return string HTML markup
 * <code>
 *        <txp:mck_login_errors for="reset" wraptag="p" break="" />
 * </code>
 */

    function mck_login_errors($atts) {
        
        static $parent = NULL;
        
        if(is_string($atts) || $atts === NULL) {
            $parent = $atts;
            mck_login::$action = $atts;
            return;
        }
        
        extract(lAtts(array(
            'for' => $parent,
            'wraptag' => 'ul',
            'break' => 'li',
            'class' => '',
            'offset' => 0,
            'limit' => NULL,
        ), $atts));
        
        $r = mck_login::error();
        
        if(!$r)
            return;
            
        if($offset || $limit)
            $r = array_slice($r, $offset, $limit);
        
        $out = array();
        
        foreach($r as $msg) {
            $pfx = gTxt('mck_login_'.$msg);
            
            $out[] = 
                '<span class="mck_login_error_'.md5($msg).'">'.
                    ($pfx == 'mck_login_' . $msg  ? gTxt($msg) : $pfx).
                '</span>';
        }
        
        return $out ? doWrap($out, $wraptag, $break, $class) : '';
    }

/**
 * Generate a ciphered token.
 * @return string
 * <code>
 *        <txp:mck_login_token />
 * </code>
 */

    function mck_login_token() {
        
        static $token;
        
        if(!$token) {

            $nonce = 
                fetch(
                    'nonce', 'txp_users', 'name', 
                    mck_login(array('name' => 'name'))
                );
            
            $token = md5($nonce . get_pref('blog_uid'));
        }
        
        return $token;
    }

/**
 * Bouncer. Checks token, and protects against CSRF attempts.
 * @param mixed $void
 * @param string $thing
 * @return mixed
 * <code>
 *        <txp:mck_login_bouncer />
 * </code>
 */

    function mck_login_bouncer($void=NULL, $thing=NULL) {
        if(gps('mck_login_token') != mck_login_token()) {
            
            sleep(3);
        
            if($thing !== NULL)
                return false;
            
            txp_die(gTxt('mck_login_invalid_csrf_token'), '401');
        }
        
        if($thing !== NULL && !$void)
            return parse($thing);
    }

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. mck_login

p. A public-side plugin for "Textpattern CMS":http://textpattern.com. Handles site-wide logins, sessions, password recovery and self-registering. Made by "Jukka Svahn":http://twitter.com/gocom and "Casalegno Marco":http://www.kreatore.it/.

p. This repo branches from "Casalegno Marco's":http://www.kreatore.it/ Textpattern plugin, "mck_login":http://forum.textpattern.com/viewtopic.php?id=37380. While this mck_login "fork" doesn't really share any code with the original code base, it is based on it, initially started as a simple patch.

p. The main idea [of mine] was to fix security issues the original release of mck_login had. Work started by removing the all of the code which was duplicated from Textpattern's core, and then fixing all the simple, yet critical, security issues.

p. After patching everything and taking advantage of core features, I concentrated to adding number of new features. The content and layout which once was hard-coded to the plugin, became changeable with tags and localization strings. No longer a form was a single tag, but set of tag. After that came security enchantments; brute force prevention, form tokens to prevent CSRF attacks, nonces and time-limited, eventually expiring forms. And finally, tools for extending the plugins in form of callbacks events and hooks.

h2. List of tags

p. As the work progressed, mck_login's tag weaponry doubled.

* @<txp:mck_login />@
* @<txp:mck_login_if>@
* @<txp:mck_login_form />@
* @<txp:mck_register_form />@
* @<txp:mck_password_form />@
* @<txp:mck_reset_form />@
* @<txp:mck_login_bouncer />@
* @<txp:mck_login_token />@
* @<txp:mck_login_errors />@

p. Please see "./examples/":https://github.com/gocom/mck_login/tree/master/examples directory for usage instructions and examples. The "plugin's source (mck_login.php) includes":https://github.com/gocom/mck_login/blob/master/mck_login.php documentation (PHPdoc) and outlines all tag attributes and has embedded minimal inline-examples too.

h2. Extending and callbacks

p. The plugin comes with range of callback events, hooking points which 3rd party plugins/developers can use to integrate with mck_login inner-workings. This allows extending mck_login's feature set. For example adding anti-spam plugins, or extra form validation to the mix is no-brainer.

* mck_login.reset_confirm
* mck_login.reset_confirmed
* mck_login.logout
* mck_login.login
* mck_login.invalid_login
* mck_login.logged_in
* mck_login.reset_form
* mck_login.reset
* mck_login.reset_sent
* mck_login.register_form
* mck_login.register
* mck_login.registered
* mck_login.login_form
* mck_login.password_form
* mck_login.save_password
* mck_login.password_saved

p. Hooking (registering callback) to the events happens with Textpattern's very own @register_callback()@ function, in the exact same fashion as one would normally do when writing a plugin for core Textpattern.

p. See "/extending/abc_trap":https://github.com/gocom/mck_login/blob/master/extending/abc_trap.php for usage example. Abc_trap.php is an example plugin, that adds a hidden spam trap field to the registration form.

h2. Installing mck_login

p. Installing the plugin is easy, especially if you are already using "plugin cache directory":http://textpattern.net/wiki/index.php?title=Advanced_Preferences#Plugin_cache_directory_path. If you haven't, set it up (create a directory, set the path in your site Preferences).

# Grab a copy of "mck_login.php":https://github.com/gocom/mck_login/blob/master/mck_login.php.
# Copy and save it to your plugin cache directory (as @mck_login_v0.1.php@).
# The plugin is installed and activated.

p. You may also want to grab a localization file, a textpack.

# "Grab a localization file from textpacks directory":https://github.com/gocom/mck_login/tree/master/textpacks. There are few languages available.
# Copy and paste the files contents to your site's Language panel (TXP/Admin/Preferences > Language). At the bottom of the page you should see a _Install Textpack_ field.
# --- END PLUGIN HELP ---
-->
<?php
}
?>
