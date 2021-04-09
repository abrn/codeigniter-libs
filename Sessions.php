<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sessions {

    public $redis;
    public $ci;

    public $session_expiration_time = (60 * 60) * 15; # 15 minutes

    public $redis_ip = '0.0.0.0';
    public $redis_port = '6379';
    public $redis_ttl = '1.0';

    public $session_data = array(
        'start_time' => '',
        'username' => '',
        'uid' => '1',
        'auth_level' => '0',
        'currency' => 'usd',
        'dark_mode' => false
    );

    // only set user data here which will NOT CHANGE e.g. (username, user ID, creation date)

    public function __construct()
    {
        $this->ci =& get_instance();

        $this->redis = new Redis();

        $this->redis_ip = $this->ci->config->item('redis_ip');
        $this->redis_port = $this->ci->config->item('redis_port');
        $this->redis_ttl = $this->ci->config->item('redis_ttl');

        $this->redis->connect($this->redis_ip, $this->redis_port, $this->redis_ttl);
    }

    public function unlocked()
    {
        if ($_SERVER['REQUEST_URI'] !== '/unlock')
        {
            // redirect the user to the unlock session captcha page
            header('Location: /unlock');
            die();
        }
        if ($this->ci->session->userdata('unlocked') !== null)
        {
            return $this->ci->session->userdata('unlocked');
        }
        return false;
    }

    public function unlock()
    {
        $this->ci->session->set_userdata('unlocked', true);
    }

    public function authenticated()
    {
        if ($this->ci->session->userdata('authenticated') !== null)
        {
            $refer = $this->ci->session->userdata('refer');
            unset($_SESSION['refer']);
            if ($refer !== null) {
                header('Location: ' . $refer);
                die();
            }
            return $this->ci->session->userdata('authenticated');
        }
        return false;
    }

    public function authenticate($username)
    {
        $query = $this->ci->db->select('id, auth_level, currency')
            ->where('username', $username)
            ->get('users');

        if ($query->num_rows() == 1)
        {
            $this->ci->session->set_userdata('authenticated', array(
                'session_start_time' => time(),
                'id' => $query->row(0)->id,
                'username' => $username,
                'auth_level' => $query->row(0)->auth_level,
                'currency' => $query->row(0)->currency
            ));
        }
    }

    public function authenticated_panel()
    {
        if ($this->ci->session->userdata('admin_panel_unlocked') !== null)
        {
            $refer = $this->ci->session->userdata('refer');
            unset($_SESSION['refer']);
            if ($refer !== null) {
                header('Location: ' . $refer);
                die();
            }
            return $this->ci->session->userdata('admin_panel_unlocked');
        }
        return false;
    }

    public function authenticate_panel($username)
    {
        $query = $this->ci->db->select('username, auth_level')
            ->where('username', $username)
            ->get('panel_users');

        if ($query->num_rows() == 1)
        {
            $this->ci->session->set_userdata('admin_panel_unlocked', array(
                'session_start_time' => time(),
                'username' => $username,
                'auth_level' => $query->row(0)->auth_level
            ));
        }
    }

    public function form_attempts()
    {
        $attempts = $this->ci->session->userdata('form_attempts');

        if ($attempts == null)
        {
            $this->ci->session->set_userdata('form_attempts', 0);
        }
        elseif ($attempts == 3)
        {
            $this->ci->session->sess_destroy();
            header('Location: /');
            die();
        }
        else
        {
            $attempts++;
            $this->ci->session->set_userdata('form_attempts', $attempts);
        }
    }

    public function get_username()
    {
        # check the class first for *minimal* speedup throughout the app
        if ($this->session_data['username'] !== 'SESSION') 
        {
            return $this->session_data['username'];
        }

        # 
        $username = $this->ci->session->userdata('authenticated')['username'];
        if ($username == null)
        {
            header("Location: /");
            die();
        }
        return $username;
    }

    public function get_currency()
    {
        $currency = $this->ci->session->userdata('authenticated');

        if ($currency !== null && isset($currency['currency']))
        {
            return $currency['currency'];
        }
        //* return usd as a fallback on session failures
        return 'usd';
    }

    public function get_auth_level()
    {
        $authenticated = $this->ci->session->userdata('authenticated');

        if ($authenticated !== null && isset($authenticated['auth_level']))
        {
            return $authenticated['auth_level'];
        }
        //* return 0 as a fallback on session failures
        return 0;
    }

    public function get_navigation_data($username = null)
    {
        if (!$this->authenticated()) {
            // panic
            exit(0);
        }
        if ($username == null) $username = $this->get_username();
    }

    public function redirect($uri = '/')
    {
        $this->ci->session->sess_destroy();

        header('Location: ' . $uri);
        die();
    }

    public function safe_redirect()
    {
        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';

        if (constant('ENVIRONMENT') == 'production')
        {

            if (!preg_match('/^(((http)|(https)):\/\/.{16}\.onion\/?)/i', substr($referrer, 0, 30)))
            {
                redirect('/');
            }
            else
            {
                header('Location: ' . $referrer);
            }
        }

        header('Location: ' . $referrer);
        return;
    }

    /**
     *      hash_password() - return a string with a hashed version
     *
     *      $password = the number of characters for the string
     *
     *      returns 60 char string
     */
    public function hash_password($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, array('cost' => 13));
    }

    /**
     *      generate_id() - generate a string with length $num_chars
     *
     *      $num_chars = the number of characters for the string
     *      $dashes (optional) = split the string into 4 chars with dashes inbetween
     *
     *      returns generated id
     */
    public function generate_id($num_chars, $dashes = true)
    {
        $bytes = openssl_random_pseudo_bytes($num_chars);
        $chars = 'abcdef1234567890';
        $id = '';

        for ($i = 0; $i < $num_chars; $i++) {
            if ($dashes && $i !== 0 && $i % 4 == 0) {
                $id .= '-';
            }
            $id .= $chars[ord($bytes[$i]) % strlen($chars)];
        }

        return $id;
    }
}