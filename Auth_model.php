<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth_model extends CI_Model
{
    private $ci;
    private $redis;

    public function __construct()
    {
        parent::__construct();

        $this->ci =& get_instance();
        $this->ci->load->database();

        $redis_ip = $this->ci->config->item('redis_ip');
        $redis_port = $this->ci->config->item('redis_port');
        $redis_ttl = $this->ci->config->item('redis_ttl');

        $this->redis = new Redis();
        $this->redis->connect($redis_ip, $redis_port, $redis_ttl);
    }

    /**     Check a username against a password
     *
     * @param string $username The username
     * @param string $password The password
     * @return string
     *      'banned' => the user is banned
     *      'success' => successful login
     *      'error' => no user exists/wrong password
     */
    public function check_login($username, $password)
    {
        $user_query = $this->ci->db->select('username, password, frozen, banned, twofa_pgp, twofa_otp, dark_mode')
            ->where('username', $username)
            ->get('users');

        if ($user_query->num_rows() > 0)
        {
            $user_data = $user_query->row(0);

            //* check the password against the database
            if (password_verify($password, $user_data->password))
            {
                if ($user_data->banned == 1)
                {
                    return 'banned';
                }

                //* check if the user has darkmode set and set it in the session
                if ($user_data->dark_mode == 1)
                {
                    $this->session->set_userdata('dark_mode', true);
                }
                if ($user_data->twofa_pgp == 1)
                {
                    //* todo: twofa callback for user
                }
                elseif ($user_data->twofa_otp == 1)
                {
                    //* todo: twofa callback for user
                }

                //* update the users last login time
                $this->db->where('username', $username)
                    ->update('users', array('last_login_date' => date('Y-m-d H:i:s')));

                //* the username and password match
                return 'success';
            }
            else {
                //* the password doesn't match the username
                return 'error';
            }
        }
        //* no account with that username exists
        return 'error';
    }

    /** generate_mnemonic() - Generates a BIP39 mnemonic
     *
     * @param int $words The amount of words for the mnemonic [12 or 24]
     * @return string BIP39 mnemonic
     */
    public function generate_mnemonic($words = 12)
    {
        $mnemonic = FurqanSiddiqui\BIP39\BIP39::Generate($words);

        return $mnemonic->words[0];
    }

    /** check_mnemonic() - Check mnemonic words against an account
     *
     * @param string $username The username to check
     * @param string $mnemonic
     * @return boolean
     *      true => words match
     *      false => words do not match
     */
    public function check_mnemonic($username, $mnemonic)
    {
        $rate_limit = $this->redis->get('rate_limit:recovery:'.$username);
        if ($rate_limit !== false)
        {
            return false;
        }

        $query = $this->ci->db->select('mnemonic')
            ->where('username', $username)
            ->get('users');

        if ($query !== false && $query->num_rows() == 1)
        {
            if (password_verify($mnemonic, $query->row()->mnemonic))
            {
                $this->redis->set('rate_limit:recovery:'.$username, 1, 1800);
                return true;
            }
        }
        return false;
    }

    /** get_twofa_data() - Gets the data required for a twofa checkpoint
     *
     * @param string $username The username of the account
     * @param string $type The type
     * @return boolean
     *      true => words match
     *      false => words do not match
     */
    public function get_twofa_data($username, $type = null)
    {
        if (!$this->ci->sessions->authenticated())
        {
            $this->ci->sessions->safe_redirect('/');
        }

        if ($type == null) {

        }
        switch ($type)
        {
            case 'otp':
                $query = $this->ci->db->where('username', $username)
                    ->select('otp_secret')
                    ->get('users');
                break;
            case 'pgp':
                $query = $this->ci->db->where('username', $username)
                    ->select('pgp_key')
                    ->get('users');
        }
    }

    public function generate_pgp_message($username)
    {
        // generate pgp message
    }

    public function get_otp_code($username)
    {
        $query = $this->ci->db->select('otp_secret')
            ->where('username', $username)
            ->get('users');

        $otp = \OTPHP\TOTP::create($query->row(0)->otp_secret);

        return $otp->now();
    }

    public function get_otp_uri($username)
    {
        $query = $this->ci->db->select('otp_secret')
            ->where('username', $username)
            ->get('users');

        $otp = \OTPHP\TOTP::create($query->row(0)->otp_secret);

        return $otp->getProvisioningUri();
    }

    public function check_otp($username, $code)
    {
        $query = $this->ci->db->select('otp_secret')
            ->where('username', $username)
            ->get('users');

        $otp = \OTPHP\TOTP::create($query->row(0)->otp_secret);

        if ($otp->verify($code))
        {
            return true;
        }
        return false;
    }

    public function otp_generate()
    {
        $secret = $this->generate_id(16);

        $otp = \OTPHP\TOTP::create();
        return $otp->getSecret();
    }

    /**
     *      generate_id() - generate a string with length $num_chars
     *
     *      $num_chars = the number of characters for the string
     *      $dashes (optional) = split the string into 4 chars with dashes inbetween
     *
     *      returns generated id
     */
    public function generate_id($num_chars, $dashes = false)
    {
        $bytes = openssl_random_pseudo_bytes($num_chars);
        $chars = 'abcdefghijklmnopqrstuvwxyz1234567890';
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