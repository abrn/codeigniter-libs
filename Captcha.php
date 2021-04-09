<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Captcha {

    private $ci;
    private $captcha_characters = 'abcdefghkmnopqrstuvwxyzABCDEFGHJKLMNOPQRSTUVWXYZ23456789';
    private $num_characters = 5;

    public function __construct()
    {
        $this->ci =& get_instance();

        $redis_ip = $this->ci->config->item('redis_ip');
        $redis_port = $this->ci->config->item('redis_port');
        $redis_ttl = $this->ci->config->item('redis_ttl');

        $this->redis = new Redis();
        $this->redis->connect($redis_ip, $redis_port, $redis_ttl);
    }

    /**
     * initialize a captcha
     */
    public function initialize($name, $width = 300, $height = 80)
    {
        $session_code = $this->ci->session->userdata('captcha_' . $name);

        if ($session_code !== null)
        {
            // check the cache for the image data
            $captcha_cache = $this->redis->get('captcha:' . $session_code);
            if ($captcha_cache !== false)
            {
                //return unserialize($captcha_cache);
            }
        }

        // generate a new code and image
        $code = $this->generate_code();
        $this->ci->session->set_tempdata('captcha_' . $name, $code, 600);

        $captcha_image = $this->generate_image($code, $width, $height);

        // store the image in the cache with an expiration
        $this->redis->set('captcha:' . $code, serialize($captcha_image), ['nx', 'ex'=> 120]);
        return $captcha_image;
    }


    // this MUST be called before initialize(), or the captcha code will expire with the cache
    public function verify_code($name, $code)
    {
        if (!$this->ci->config->item('captchas_enabled')) return true;

        $session_code = $this->ci->session->userdata('captcha_' . $name);

        if ($session_code !== null)
        {
            // delete the old image from cache
            $this->redis->del('captcha:' . $session_code);

            // the code matches
            if ($session_code == $code)
            {
                return true;
            }

            // generate a new code and image
            $new_code = $this->generate_code();
            $captcha_image = $this->generate_image($new_code);

            // code doesn't match, store the new captcha
            $this->ci->session->set_tempdata('captcha_' . $name, $new_code, 600);
            $this->redis->set('captcha:' . $new_code, serialize($captcha_image), ['ex'=> 120]);
        }
        else
        {
            return $session_code;
        }
        return false;
    }

    /**
     * returns a 5 character CAPTCHA code
     */
    public function generate_code()
    {
        $code = "";
        $count = 0;
        while ($count < $this->num_characters) {
            $code .= substr($this->captcha_characters, mt_rand(0, strlen($this->captcha_characters)-1), 1);
            $count++;
        }
        return $code;
    }

    /**
     * returns a CAPTCHA image in base64 format
     */
    public function generate_image($captcha_code = '', $image_width = 300, $image_height = 80)
    {
        $this->ci->load->helper('url');
        $dark_mode = isset($_SESSION['dark_mode']) ? true : false;

        // initialize the image
        $captcha_image = imagecreatetruecolor($image_width, $image_height);

        // the scale factor for magnification of distorted captcha image
        $scale = 1;
        $ratio = 0.52;
        $captcha_font = 'assets/fonts/one.ttf';

        $angles = array();
        $distance = array();
        $dims = array();
        $txtWid = 0;

        $use_random_baseline = true;
        $generate_text_boxes = true;
        $random_lines = 8;
        $draw_noise = true;
        $noise_level = 5;

        $background_color = imagecolorallocatealpha($captcha_image, 0, 0, 0, 127);
        imagecolortransparent($captcha_image, $background_color);

        if ($dark_mode)
        {
            $captcha_text_color = imagecolorallocate($captcha_image, 90, 200, 255);
            $image_noise_color = imagecolorallocate($captcha_image, 30, 35, 35);
        }
        if (!$dark_mode)
        {
            $captcha_text_color = imagecolorallocate($captcha_image, 0, 130, 200);
            $image_noise_color = imagecolorallocate($captcha_image, 200, 200, 200);
        }

        // Draw characters
        $width = $image_width * $scale;
        $height = $image_height * $scale;
        $captcha_font_size = $height * $ratio;

        $angle0 = mt_rand(10, 20);
        $angleN = mt_rand(-20, 10);

        if (mt_rand(0, 99) % 2 == 0) {
            $angle0 = -$angle0;
        }
        if (mt_rand(0, 99) % 2 == 1) {
            $angleN = -$angleN;
        }

        $step = abs($angle0 - $angleN) / (strlen($captcha_code) - 1);
        $step = ($angle0 > $angleN) ? -$step : $step;
        $angle = $angle0;

        for ($c = 0; $c < strlen($captcha_code); ++$c) {
            $angles[] = $angle;  // the angle of this character
            $dist = mt_rand(-2, 0) * $scale; // random distance between this and next character
            $distance[] = $dist;
            $char = substr($captcha_code, $c, 1); // the character to draw for this sequence

            $dim = $this->getCharacterDimensions($char, $captcha_font_size, $angle, $captcha_font); // calculate dimensions of this character

            $dim[0] += $dist;   // add the distance to the dimension (negative to bring them closer)
            $txtWid += $dim[0]; // increment width based on character width

            $dims[] = $dim;

            $angle += $step; // next angle

            if ($angle > 20) {
                $angle = 20;
                $step  = $step * -1;
            } elseif ($angle < -20) {
                $angle = -20;
                $step  = -1 * $step;
            }
        }

        $nextYPos = function($y, $i, $step) use ($height, $scale, $dims) {
            static $dir = 1;

            if ($y + $step + $dims[$i][2] + (10 * $scale) > $height) {
                $dir = 0;
            } elseif ($y - $step - $dims[$i][2] < $dims[$i][1] + $dims[$i][2] + (5 * $scale)) {
                $dir = 1;
            }

            if ($dir) {
                $y += $step;
            } else {
                $y -= $step;
            }

            return $y;
        };

        $cx = floor($width / 2 - ($txtWid / 2));
        $x  = mt_rand(5 * $scale, max($cx * 2 - (5 * $scale), 5 * $scale));

        if ($use_random_baseline) {
            $y = mt_rand($dims[0][1], $height - 10);
        } else {
            $y = ($height / 2 + $dims[0][1] / 2 - $dims[0][2]);
        }

        $st = $scale * mt_rand(5, 10);

        for ($c = 0; $c < strlen($captcha_code); ++$c) {
            $char  = substr($captcha_code, $c, 1);
            $angle = $angles[$c];
            $dim   = $dims[$c];

            if ($use_random_baseline) {
                $y = $nextYPos($y, $c, $st);
            }

            imagettftext(
                $captcha_image,
                $captcha_font_size,
                $angle,
                (int)$x,
                (int)$y,
                $captcha_text_color,
                $captcha_font,
                $char
            );

            if ($generate_text_boxes && strlen(trim($char)) && mt_rand(1,100) % 5 == 0) {
                imagesetthickness($captcha_image, 2);
                imagerectangle($captcha_image, $x, $y - $dim[1] + $dim[2], $x + $dim[0], $y + $dim[2], $captcha_text_color);
            }

            if ($c == ' ') {
                $x += $dim[0];
            } else {
                $x += $dim[0] + $distance[$c];
            }
        }


        // Generate random lines
        for($count = 0; $count < ($random_lines); $count++) {
            imageline(
                $captcha_image,
                mt_rand(0,$image_width),
                mt_rand(0,$image_height),
                mt_rand(0,$image_width),
                mt_rand(0,$image_height),
                $captcha_text_color
            );
        }

        // Generate random lines
        for($count = 0; $count < ($random_lines/2); $count++) {
            imageline(
                $captcha_image,
                mt_rand(0,$image_width),
                mt_rand(0,$image_height),
                mt_rand(0,$image_width),
                mt_rand(0,$image_height),
                $image_noise_color
            );
        }

        ob_start();
        imagepng($captcha_image);

        $image_data = ob_get_clean();
        return "data:image/png;base64," . base64_encode($image_data);
    }

    protected function getCharacterDimensions($char, $size, $angle, $font)
    {
        $box = imagettfbbox($size, $angle, $font, $char);
        return array($box[2] - $box[0], max($box[1] - $box[7], $box[5] - $box[3]), $box[1]);
    }
}