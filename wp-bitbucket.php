<?php
/*
 * Plugin Name: WP Bitbucket
 * Plugin URI: http://wordpress.org/extend/plugins/bbpress-pencil-unread
 * Description: Load blocks from BitBucket into your Wordpress, using shortcodes
 * Author: G.Breant
 * Version: 0.2.1
 * Author URI: http://sandbox.pencil2d.org/
 * License: GPL2+
 * Text Domain: wp-bitbucket
 * Domain Path: /languages/
 */
class WpBitbucket {
        /** Version ***************************************************************/
        /**
         * @public string plugin version
         */
        public $version = '0.2.1';
        /**
         * @public string plugin DB version
         */
        public $db_version = '100';
       
        /** Paths *****************************************************************/
        public $file = '';
       
        /**
         * @public string Basename of the plugin directory
         */
        public $basename = '';
        /**
         * @public string Absolute path to the plugin directory
         */
        public $plugin_dir = '';
        
    /**
        * @var The one true Instance
        */
        private static $instance;
        
        public static function instance() {
                if ( ! isset( self::$instance ) ) {
                        self::$instance = new WpBitbucket;
                        self::$instance->setup_globals();
                        self::$instance->includes();
                        self::$instance->setup_actions();
                }
                return self::$instance;
        }
        
        var $user;
        var $user_url;
        var $project;
        var $project_url;
        var $page;
        var $section;
        var $selector;
        var $input_doc;

        var $errors;
       
        /**
         * A dummy constructor to prevent from being loaded more than once.
         *
         */
        private function __construct() { /* Do nothing here */ }

        function setup_globals() {
                /** Paths *************************************************************/
                $this->file       = __FILE__;
                $this->basename   = plugin_basename( $this->file );
                $this->plugin_dir = plugin_dir_path( $this->file );
                $this->plugin_url = plugin_dir_url ( $this->file );
                $this->errors = new WP_Error();
        }
       
        function includes(){
            
            if (!class_exists('phpQuery'))
                require($this->plugin_dir . '_inc/lib/phpQuery/phpQuery.php');

            if (is_admin()){
            }
        }
       
        function setup_actions(){
           
            //localization (nothing to localize yet, so disable it)
            add_action('init', array($this, 'load_plugin_textdomain'));
            //upgrade
            add_action( 'plugins_loaded', array($this, 'upgrade'));
            
            //register scripts & styles
            add_action('init', array($this, 'register_scripts_styles'));
            //shortcode
            add_shortcode( 'wp-bitbucket', array( $this, 'process_shortcode' ) );
        }
       
        public function load_plugin_textdomain(){
            load_plugin_textdomain($this->basename, FALSE, $this->plugin_dir.'/languages/');
        }
       
        function upgrade(){
            global $wpdb;
           
            $version_db_key = $this->basename.'-db-version';
           
            $current_version = get_option($version_db_key);
           
           
            if ($current_version==$this->db_version) return false;
               
            //install
            /*
            if(!$current_version){
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                //dbDelta($sql);
            }
             */
            //update DB version
            update_option($version_db_key, $this->db_version );
        }
 
        function register_scripts_styles(){
            wp_register_style( $this->basename.'-newsfeed', $this->plugin_url . '_inc/css/newsfeed.css',false,$this->version);
        }
        
        function process_shortcode( $atts, $content="" ) {
            
            $url = trailingslashit('http://bitbucket.org');
            
            $default = array(
                'user'=>false,
                'project'=>false,
                'section' =>false
            );
            
            $args = shortcode_atts($default,$atts);
            
            //all args are required
            foreach ($args as $arg=>$value){
                if (!$value) return false;
            }

            $this->user = $args['user'];
            $this->project = $args['project'];
            $this->section = $args['section'];
            
            $this->user_url=trailingslashit($url.$this->user);
            $this->project_url=trailingslashit($this->user_url.$this->project);
            
            //selector
            switch($this->section){
                case 'readme':
                    $selector='section#readme';
                break;
                case 'newsfeed':
                    $selector='#repo-activity';
                break;
            }
            
            if(!$selector) return false;
            $this->selector = $selector;
            
            //check page is found
            $markup = self::get_page($this->project_url);
            $input_doc = phpQuery::newDocumentHTML($markup);
            
            if($input_doc){
                $this->input_doc = $input_doc;
                wp_enqueue_style( $this->basename.'-newsfeed' );
            }
            
            return self::get_block($this->section,$this->selector);
        }


        
        
    /**
     * Check if the input URL returns something or is a redirection.
     * @param type $input_url
     * @return null|boolean
     */
    function get_page($url,$base_url=false){

        $ch = curl_init();
        
        $options = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            //CURLOPT_FOLLOWLOCATION => true, //http://stackoverflow.com/questions/5147170/php-safe-mode-problem-using-curl
            CURLOPT_ENCODING       => "",
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_SSL_VERIFYPEER =>false
        );
        
        curl_setopt_array( $ch, $options );
        $content = curl_exec($ch); 
        $info = curl_getinfo($ch);

        curl_close($ch);

        $valid_http_codes = array(200,301);
        if(!in_array($info['http_code'],$valid_http_codes)) return false;

        if($info['http_code']==301){
            $content = self::get_page($info['redirect_url'],$url);
        }else{
            //
        }

        return apply_filters('wp_bitbucket_get_page',$content,$url,$base_url);
    }

    function get_block($id,$selector=false){

        if($this->input_doc){
            phpQuery::selectDocument($this->input_doc);

            $element = pq($selector);

            //check tracklist is found
            if ($element->htmlOuter()){
                $block = $element->htmlOuter();

                
                $block = str_replace('="/'.$this->user,'="'.untrailingslashit($this->user_url),$block);//replace local user links
                $block = '<div class="wp-bitbucket">'.$block.'</div>';
            }
        }
        
        if(!$block){
            $this->errors->add( 'block_not_found', sprintf(__('We were unable to fetch content from the Bitbucket project %s.  Please visit the %s instead !',$this->basename),'<strong>'.ucfirst($this->project).'</strong>','<a href="'.$this->project_url.'" target="_blank">'.__('original page',$this->basename).'</a>'));
        }
        
        $errors_block=self::get_errors();
        
        return apply_filters('wp_bitbucket_get_block',$errors_block.$block,$id);
        


    }
    
    function get_errors($code=false){
        if(!$code) {
            $codes = $this->errors->get_error_codes();
        }else{
            $codes = (array)$code;
        }
        if(!$codes) return false;
            
        foreach((array)$codes as $error_code){
            $messages = $this->errors->get_error_messages($code);
            if(!$messages) return false;
            
            $block='<div id="wp-bitbucket-notices" class="error">';
            foreach((array)$messages as $message){
                $block.='<p style="background-color:#ffebe8;border-color:#c00;margin: 0 0 16px 8px;padding: 12px;">'.$message.'</p>';
            }
            
            $block.='</div>';
        }
        return $block;

    }
}

/**
 * The main function responsible for returning the one Instance
 * to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 */
function wp_bitbucket() {
        return WpBitbucket::instance();
}
wp_bitbucket();




?>