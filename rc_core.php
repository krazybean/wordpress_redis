<?php
/* 
    Plugin Name: Redis Cache
    Plugin URI: http://nourl
    Description: Caching using redis, simple
    Author: Juanito
    Author URI: http://juan
    Requirements: Redis, derp
*/
error_reporting(E_ALL); 
if ( !class_exists( 'AdminPageFramework' ) ) 
    include_once( dirname( __FILE__ ) . '/class/admin-page-framework.php' );
 
class APF_rediscache extends AdminPageFramework {

    protected $default_server = 'localhost';
    protected $default_port = '6379';
    protected $default_pass = '';
    protected $default_database = '0';

    public function setUp() {
                    
        $this->setRootMenuPage( 'Settings' );
        
        $this->addSubMenuPage(
            'Redis Cache',    // page and menu title
            'redis_cache_setting'     // page slug
        ); 
        
    }

    private function encrypt($value){
        try{ include_once(get_home_path().'wp-config.php'); } catch(Exception $e){ echo 'Message: '.$e->getMessage();}
        return trim(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, DB_NAME, $value, MCRYPT_MODE_ECB, mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND))));
    }
 
    public function decrypt($value){
        try{ include_once(get_home_path().'wp-config.php'); } catch(Exception $e){ echo 'Message: '.$e->getMessage();}
        return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, DB_NAME, base64_decode($value), MCRYPT_MODE_ECB, mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND)));
    }

    public function installation(){
        
	// setup wp_options elements on activation 
	add_option('rediscache_enabled', 'no', '', 'no');
	add_option('rediscache_server', $this->encrypt($default_server), '', 'no');
        add_option('rediscache_port', $this->encrypt($default_port), '', 'no');
        add_option('rediscache_pass', $this->encrypt($default_pass), '', 'no');
        add_option('rediscache_database', $this->encrypt($default_database), '', 'no');
    }

    public function uninstall(){
        //remove wp_option rows from the db on removal
        delete_option('rediscache_enabled');
        delete_option('rediscache_server');
        delete_option('rediscache_database');
	delete_option('rediscache_port');
	delete_option('rediscache_pass');
    }


    private function denied($failure){
        echo "<div class=\"error\">Failed to verify submission: $failure</div>";
    }

    function redis_init(){
        require_once( ABSPATH . '/wp-admin/includes/file.php' );
        $html_cache = "";
        $html = "";
	try{ include_once(get_home_path().'wp-config.php'); } catch(Exception $e){ echo 'Message: '.$e->getMessage();}
        include_once( dirname( __file__ )  . '/predis.php');
	$start = microtime();
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) { $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP']; }
	$host = $this->decrypt(get_option('rediscache_server'));
	$port = $this->decrypt(get_option('rediscache_port'));
	$pass = $this->decrypt(get_option('rediscache_pass'));
	$database = $this->decrypt(get_option('rediscache_database'));
        if(strlen($pass) >= 1){
	    $params = array(
	        'host' => "$host",
	        'port' => $port,
	        'password' => $pass,
	        'database' => $database
	    );
        }else{
            $params = array(
                'host' => "$host",
                'port' => $port,
                'database' => $database
            );
        }


        try { //Troubleshooting try
            $redis = new Predis\Client($params);

        $domain = $_SERVER['HTTP_HOST'];
        $url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
	$dkey = md5($domain);
	$ukey = md5($url);
        (isset($_SERVER['HTTP_CACHE_CONTROL']) && $_SERVER['HTTP_CACHE_CONTROL'] == 'max-age=0') ? $submit = 1 : $submit = 0;
        $cookie = var_export($_COOKIE, true);
        $loggedin = preg_match("/wordpress_logged_in/", $cookie);
        //echo "$dkey => $ukey ;";
        if ($redis->hexists($dkey, $ukey) && !$loggedin && !$submit && !strpos($url, '/feed/')) {
            $html_cache = $redis->hget($dkey, $ukey);
            $cached = 1;
            $msg = 'this is a cache';
        }elseif($submit || substr($_SERVER['REQUEST_URI'], -4) == '?r=y') {
            $redis->hdel($dkey, $ukey);
            $msg = 'cache of page deleted';
        }elseif($loggedin && substr($_SERVER['REQUEST_URI'], -4) == '?c=y') {
            if ($redis->exists($dkey)) {
                $redis->del($dkey);
                $msg = 'domain cache flushed';
            }else{
                $msg = 'no cache to flush';
            }
        }elseif($loggedin){
            $msg = 'not cached';
        }else{  //constructing the cache 
            //echo "Constructing cache";
            ob_start();
            include( get_home_path() .'./wp-blog-header.php');
            $html = ob_get_contents();
            ob_end_clean();
            if(!is_404() && !is_search()) {
                $redis->hset($dkey, $ukey, $html);
                $msg = 'cache is set';
            }
        }
        if(strlen($html_cache) >= 1){
            ob_flush();
            echo $html_cache;
            $end = microtime();
            echo "\n<!-- redis cache key: $dkey was used. -->\n";
            echo "<!-- redis cache time: ".$this->t_exec($start, $end)." -->\n";
            die();
        }elseif(strlen($html) >= 1){
            echo $html;
            die();
        }else{
        }
        }
        catch(Exception $e){
            echo '<div class="error"> Message: ' .$e->getMessage(). '</div>';
        }
        $end = microtime();
        //$debug = 1;
        //$display_powered_by_redis = 1;
        if (isset($debug)) {
            echo $msg.': ';
            echo $this->t_exec($start, $end);
        }
        if (isset($cached) && isset($display_powered_by_redis)) {
	    echo "<style>#redis_powered{float:right;margin:20px 0;background:url(http://94d65bca695befb24601-d2361d348cad2f3de9f9f0e0a54dbefa.r34.cf1.rackcdn.com/redis.png) 10px no-repeat #fff;border:1px solid #D7D8DF;padding:10px;width:190px;}
	         #redis_powered div{width:190px;text-align:right;font:10px/11px arial,sans-serif;color:#000;}</style>";
	    echo "<a href=\"http://www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/\" style=\"text-decoration:none;\"><div id=\"redis_powered\"><div>Page generated in<br/> ".$this->t_exec($start, $end)." sec</div></div></a>";
        }
    }


    private function buffer_start() {
        /*
        ob_start();
        include('./wp-blog-header.php');
        $html = ob_get_contents();
        ob_end_clean();
        $this->callback_contents($html);
        */
    }

    private function buffer_end() {
        /*$html = ob_get_contents();
        $this->callback_content($html);
        print_r($html); */
        //ob_end_clean( $this->callback_content( $html ));
        die();
    }

    private function callback_content( $html ) {
        $host = $this->decrypt(get_option('rediscache_server'));
        $port = $this->decrypt(get_option('rediscache_port'));
        $pass = $this->decrypt(get_option('rediscache_pass'));
        $database = $this->decrypt(get_option('rediscache_database'));
        if(strlen($pass) >= 1){
            $params = array(
                'host' => "$host",
                'port' => $port,
                'password' => $pass,
                'database' => $database
            );
        }else{
            $params = array(
                'host' => "$host",
                'port' => $port,
                'database' => $database
            );
        }

        $redis = new Predis\Client($params);
        $domain = $_SERVER['HTTP_HOST'];
        $url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        $dkey = md5($domain);
        $ukey = md5($url);
        if(!is_404() && !is_search()) {
            //$redis->hset($dkey, $ukey, $html);
            //$msg = 'cache is set';
        }
    }

    public function t_exec($start, $end) {
        $t = ($this->getmicrotime($end) - $this->getmicrotime($start));
        return round($t,5);
    }

    public function getmicrotime($t) {
            list($usec, $sec) = explode(" ",$t);
            return ((float)$usec + (float)$sec);
    }


    public function do_redis_cache_setting() {    
        //Cloud Flare:
	if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) { $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP']; }
        
	try{ include_once(get_home_path().'wp-config.php'); } catch(Exception $e){ echo 'Message: '.$e->getMessage();}
        
        ?>
        <h1>Redis Cache</h1>
        <h3>Details</h3>
	<?php if(get_option('rediscache_enabled') == 'no'){ $status='off';}else{$status='on';} 
            if($status == 'off'){?>
	    <form action="" method="post">
            <input type="hidden" name="action" value="cache_enable">
            <?php if (function_exists('wp_nonce_field')){wp_nonce_field();} ?>
            <input type="submit" value="Enable" class="button button-primary">
            </form>
        <?php }elseif($status == 'on'){?>
            <form action="" method="post">
            <input type="hidden" name="action" value="cache_disable">
            <?php if (function_exists('wp_nonce_field')){wp_nonce_field();} ?>
            <input type="submit" value="Disable" class="button button-primay">
            </form>
        <?php }?>
	<hr>
        <h2>Settings</h2>
        <form action="" method="post">
	<input type="hidden" name="action" value="save_settings">
	<?php if (function_exists('wp_nonce_field')){wp_nonce_field();} ?>
        <p><label for="server">Redis Server</label>
        <input type="text" name="server" value="<?php echo $this->decrypt(get_option('rediscache_server'));?>" placeholder="localhost"></p>
	<p><label for="database">Redis Instance</label>
        <input type="text" name="database" value="<?php echo $this->decrypt(get_option('rediscache_database'));?>" placeholder="0"></p>
        <p><label for="port">Redis Port</label>
	<input type="text" name="port" value="<?php echo $this->decrypt(get_option('rediscache_port'));?>" placeholder="6379"></p>
        <p><label for="password">Redis Password</label>
        <input type="password" name="password" value="<?php echo $this->decrypt(get_option('rediscache_pass'));?>" placeholder="password"></p>
	<input type="submit" value="Save Settings" class="button button-primary">
        </form>

        <?php
	if($_POST){
	$action = $_POST['action'];
            if($action == 'cache_enable'){
                if(check_admin_referer()){
                    update_option('rediscache_enabled', 'yes', '', 'no');
		    echo "<meta http-equiv=\"refresh\" content=\"0\">";
                }else{
                    $this->denied($verify);
                }
            }elseif($action == 'cache_disable'){
                if(check_admin_referer()){
                    update_option('rediscache_enabled', 'no', '', 'no');
		    echo "<meta http-equiv=\"refresh\" content=\"0\">";
                }else{
                    $this->denied($verify);
                }
            }elseif($action == 'save_settings'){
                if(!empty($_POST)){
		$server = $_POST['server']; $database=$_POST['database']; $port=$_POST['port']; $pass=$_POST['password'];
                if(check_admin_referer()){
                    update_option('rediscache_server', $this->encrypt($server), '', 'no');
		    update_option('rediscache_database', $this->encrypt($database), '', 'no');
                    update_option('rediscache_port', $this->encrypt($port), '', 'no');
                    update_option('rediscache_pass', $this->encrypt($pass), '', 'no');
                 }}
            }
        }



    }
    
}


// Instantiate the class object.
$rcplugin = new APF_rediscache;
if(get_option('rediscache_enabled') == 'yes'){
        add_action('init', array($rcplugin, 'redis_init'), 10, 1);
	add_action('wp_head', array($rcplugin, 'buffer_start'));
        add_action('wp_footer', array($rcplugin, 'buffer_end'));
    }elseif(get_option('rediscache_enabled') == 'no'){
        remove_action('init', array($rcplugin, 'redis_init'), 10, 1);
}else{}
    register_activation_hook( __FILE__, array( $rcplugin, 'installation' ) );
    register_deactivation_hook( __FILE__, array( $rcplugin, 'uninstall' ) );
?>
