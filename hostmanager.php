<?php
/**
 * Plugin Name: Manager
 * Plugin URI: https://themecloud.io
 * Description: This plugin is ideal to effortlessly manage your website.
 * Version: 0.1.0
 * Author: Themecloud
 * Author URI: https://themecloud.io
 * License: GPLv2 or later
 */


use Tenweb_Manager\Helper;

if (!defined('ABSPATH')) {
    exit;
}


/* TEST HOSTMANAGER */
// Add the admin menu
add_action('admin_menu', 'test_plugin_setup_menu');
 
function test_plugin_setup_menu(){
    add_menu_page( 'HostManager Page', 'HostManager', 'manage_options', 'HostManager', 'hostmanager_init' );
}

function hostmanager_init(){
    
    var_dump(get_plugin_updates());

}

add_action( 'admin_init', 'tenweb_check_plugin_requirements' );
 




function tenweb_check_plugin_requirements()
{
   
    global $wp_version;
    $php_version = explode("-", PHP_VERSION);
    $php_version = $php_version[0];
    $result = (
        version_compare($wp_version, '4.4', ">=") &&
        version_compare($php_version, '5.3.0', ">=")
    );

    return $result;
}
function tenweb_cache_menu() {
        global $wp_admin_bar;
        $wp_admin_bar->add_menu(array(
                'parent' => $menu_id,
                'title' => "TEST OK",
                'id' => 'tenweb_clear_so_cache',
                'href' => '#',
                'meta' => array('title' => __('Clear SO Cache', "buwd"), 'onclick' => 'tenwebClearSOCache()'))
            );
}
//use Tenweb_Manager\Manager;

if (tenweb_check_plugin_requirements()) {
	function object_to_array($obj) {
		    //only process if it's an object or array being passed to the function
		    if(is_object($obj) || is_array($obj)) {
		        $ret = (array) $obj;
		        foreach($ret as &$item) {
		            //recursively process EACH element regardless of type
		            $item = object_to_array($item);
		        }
		        return $ret;
		    }
		    //otherwise (i.e. for scalar values) return without modification
		    else {
		        return $obj;
		    }
		}
	
		
    function manager_plugin_admin_notices() {
    	global $pluginsupdates;
		var_dump( $pluginsupdates);
    	/* global $pluginsupdateslist;
    	//$plugins = get_plugins();
            $pluginslist=get_plugins();
	    	$pluginsupdates=  object_to_array(get_plugin_updates());
	    	$curl = curl_init();

			curl_setopt_array($curl, array(
			  CURLOPT_URL => 'http://localhost/wp-json/wp/v2/plugins',
			  CURLOPT_RETURNTRANSFER => true,
			  CURLOPT_ENCODING => '',
			  CURLOPT_MAXREDIRS => 10,
			  CURLOPT_TIMEOUT => 0,
			  CURLOPT_FOLLOWLOCATION => true,
			  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			  CURLOPT_CUSTOMREQUEST => 'GET',
			));
			
			$response = curl_exec($curl);
			
			curl_close($curl);
			echo $response;
	    	
			
	        // Plugins vulnerabilities
	        
	        if($pluginsupdates){
	        	$plugins=array_merge($pluginslist, $pluginsupdates);
	        }else{
	        	$plugins=$pluginslist;
	        }
	        $pluginsupdateslist=$plugins;

            foreach ($plugins as $slug => $plugin) {
            	// echo $plugin['Version'];
            	if(isset($plugin->update->new_version)){
            		$pluginupdate=$plugin->update->new_version;
            		echo $pluginupdate;
            	}else{
            		$pluginupdate=0;
            	}
                //$state = new ProductState($slug, $slug, $plugin['Title'], $plugin['Description'], 'plugin', $plugin['Version'], $pluginupdate, 1);
                //$state->set_active($slug);
                //$plugins_state[] = $state->get_wp_info();
            }
        	

      
    		// var_dump(get_plugins());
    		echo "<br> ______________________ <br>";
    		// var_dump($pluginsupdates);
    		//echo "<br> ______________________ <br>";
    		//print("<pre>".print_r($pluginstest,true)."</pre>");
			//echo '<div class="updated"><p><strong>MANAGER</strong>initiated</p></div>';*/
		}
		add_action('admin_notices', 'manager_plugin_admin_notices');
	

    include_once dirname(__FILE__) . '/config.php';
    include_once TENWEB_INCLUDES_DIR . '/class-helper.php';
    include_once dirname(__FILE__) . '/manager.php';

    add_action('plugins_loaded', array('Tenweb_Manager\Manager', 'get_instance'), 1);
}


register_activation_hook(__FILE__, 'tenweb_activate');
/* register_deactivation_hook(__FILE__, 'tenweb_deactivate');
add_action('admin_init', 'tenweb_plugin_redirect');

function tenweb_plugin_redirect()
{
    if (get_site_option('tenweb_plugin_do_activation_redirect', false)) {
        delete_option('tenweb_plugin_do_activation_redirect');

        if(!get_site_option(TENWEB_PREFIX . '_access_token')){
            $registration_link = Tenweb_Manager\Manager::get_instance()->get_registration_link();
            wp_redirect($registration_link);
        }
    }
}

*/
function tenweb_activate($to_die = "1")
{

    //when tenweb_check_plugin_requirements() return false
    include_once dirname(__FILE__) . '/config.php';

    $error_msg = array();
    if (tenweb_check_plugin_requirements() == false) {
        array_push($error_msg, "PHP or Wordpress version not compatible with plugin.");
    }

    if (plugin_basename(__FILE__) !== TENWEB_SLUG) {
        array_push($error_msg, "Plugin foldername/filename.php must be " . TENWEB_SLUG);
    }

    //send new state
    delete_site_transient(TENWEB_PREFIX . '_send_states_transient');
    update_site_option(TENWEB_PREFIX . '_version', TENWEB_VERSION);
    update_site_option(TENWEB_PREFIX . '_activated', '1');

    if (!is_file(WPMU_PLUGIN_DIR . '/10web-manager/10web-manager.php')) {
        update_site_option('tenweb_plugin_do_activation_redirect', true);
    }

    if (!empty($error_msg) && ($to_die == "1" || $to_die == "")) {
        $error_msg = implode("<br/>", $error_msg);
        die($error_msg);
    } else {
        return $error_msg;
    }
}

/* 
function tenweb_deactivate()
{
    if (tenweb_check_plugin_requirements() == false) {
        return;
    }

    call_user_func(array('\Tenweb_Manager\Helper', 'send_state_before_deactivation'));
}

/* 

function tenweb_plugin_add_new_image_size() {
    add_image_size( 'tenweb_optimizer_mobile', 320, 640, true );
    add_image_size( 'tenweb_optimizer_tablet', 768, 1024, true );
}



if (is_file(WPMU_PLUGIN_DIR . '/10web-manager/10web-manager.php')) {
    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_constants.php')) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_constants.php';
    }

    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_redirect.php') && TW_REDIRECT === true) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_redirect.php';
    }

    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_cli.php')) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_cli.php';
    }

    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_login_protection/login_protection.php')) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_login_protection/login_protection.php';
    }

    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_redeclare.php')) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_redeclare.php';
    }

    if (file_exists(WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_wp_login_check.php')) {
        include_once WPMU_PLUGIN_DIR . '/10web-manager/mu/10web_wp_login_check.php';
    }

    add_action('init', 'tenweb_plugin_add_new_image_size');
}

*/