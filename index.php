<?php
/*
Plugin Name: Iubenda Extra Plugins
Plugin URI: https://www.shambix.com/
Description: Iubenda Extra Plugins
Author: Shambix
Version: 1.0.2-beta
Author URI: https://www.shambix.com/
License: 
Text Domain: iep
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) die;

class iubenda_extra_class{
	private $_plugin_ver = '1.0.2-beta';
	private $_db_ver = '1.0';
	public  $textdomain = 'iep';
	protected static $_instance = null;
	private $_sep = '__';
	
	public function __construct(){
		global $wpdb;
		
		$this->BASE_PATH = rtrim( dirname(__FILE__), '/' ); 
		$this->BASE_URL = trim( plugin_dir_url( __FILE__ ), '/' ); 
		
		if(function_exists('iubenda')){
			$this->includes();
			//$this->declare_hooks();
			add_action( 'plugins_loaded', array( $this, 'plugins_loaded') );

			add_filter('iub_supported_form_sources', array($this, 'register_other_forms'), 20, 1 );  
			add_filter('iub_after_call_give_forms', array($this, 'get_give_forms'), 10, 1 );  
			add_filter('iub_after_call_speakout_forms', array($this, 'get_speakout_forms'), 10, 1 );  
			
			//since June 24, 2022
			add_filter('iub_after_call_charitable_forms', array($this, 'get_charitable_forms'), 10, 1 );  
			add_filter('iub_after_call_ninjaform_forms', array($this, 'get_ninjaform_forms'), 10, 1 );  
			
			//since Sept 2, 2022
			add_filter('iub_after_call_gravityform_forms', array($this, 'get_gravityform_forms'), 10, 1 );  
		}else{
			add_action('admin_notices', array($this, 'installation_notices') );
		}
		
	}	
	
	public function installation_notices() {
		$class = 'notice notice-warning';
		$message = __( 'Iubenda Extra Plugins: Please activate Cookie and Consent Solution for the GDPR & ePrivacy.', 'iep' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) ); 
	}

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
	
	protected function includes(){}
	
	protected function declare_hooks(){
		## Activation & deactivation
		register_activation_hook(__FILE__, array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));
		
		## Plugin related
		add_action( 'init', array($this, 'plugins_init') );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded'),  );
	}
	
	public function get_version(){ 
		return $this->_plugin_ver . ' / ' . $this->_db_ver; 
	}
	
	public function activate(){
		## clear local cache
		$this->clear_local_cache();
	}
	
	public function deactivate(){
		## clear local cache
		$this->clear_local_cache();
		return true;
	}

	public function plugins_init(){}
	
	public function plugins_loaded(){
		
		//add_action('iub_before_call_give_forms', array($this, 'get_give_forms') );
		//add_action('iub_before_call_speakout_forms', array($this, 'get_speakout_forms') );

		add_action('give_insert_payment', array( $this, 'givewp_insert_payment' ), 1, 2 );
		//add_action('give_checkout_before_gateway', array( $this, 'sync_give_donor_post' ), 9999, 3 );
		//add_action( 'give_pre_form_output', array( $this, 'give_pre_form_output' ), 10, 3 );	
		
		add_action( 'create_signature_after', array( $this, 'sync_signature' ), 9999, 2 );
		
		add_action( 'ninja_forms_after_submission', array( $this, 'sync_ninja_forms' ) );
		add_action( 'charitable_after_save_donation', array( $this, 'sync_charitable' ), 9999, 2 );

		add_action( 'gform_after_submission', array( $this, 'sync_gravity_form' ), 10, 2 );
	}

	private function clear_local_cache(){
		$cache_key = array();
		foreach($cache_key as $id => $key){
			delete_transient( $key );
		}
		return true;
	}

	public function whos_called($index = 2){
		$out = '';
		if($trace = debug_backtrace()){
			if( isset($trace[$index]) ){
				if( isset($trace[$index]['class']) ){
					$out .= $trace[$index]['class'] . $trace[$index]['type'];
				}
				if( isset($trace[$index]['function']) ){
					$out .= $trace[$index]['function'] . '()';
				}
				if( isset($trace[$index]['line']) ){
					$out .= ':' . $trace[$index]['line'];
				}
			}
		}
		
		return $out;
	}

	public function write_log($txt){
		//turn off the log
		return true;
		
		$log  = $this->whos_called() . PHP_EOL;
		$log .= (is_array($txt) || is_object($txt)) ? print_r($txt, 1) : $txt;
		$log .= PHP_EOL . str_repeat('-', 80) . PHP_EOL;
		file_put_contents( $this->BASE_PATH . '/log_' . date('YmdHi') . '.log', $log, FILE_APPEND );
	}


	/* this will be removed and replaces by $this->_get_form_part_from_url */
	protected function _get_form_from_url($url, $html_id){
		if ( ! function_exists( 'file_get_html' ) ) {
			require_once( IUBENDA_PLUGIN_PATH . 'iubenda-cookie-class/simple_html_dom.php' );
		}

		$html = file_get_html( $url );
		if ( is_object( $html ) ) {
			if($find = $html->getElementById($html_id)){
				return $find;
			}
		}

		return false;
	}

	
	protected function _get_form_part_from_url($url, $start_string, $end_string){
		if($html = file_get_contents($url)){
			$pos1 = strpos($html, $start_string);
			if($pos1 !== false){
				$pos2 = strpos($html, $end_string, $pos1);
				if($pos2 !== false){
					$matches = substr($html, $pos1, $pos2 - $pos1) . $end_string;
					//$this->write_log($matches);
					return $matches;
				}
			}
		}
		return false;
	}
	
	protected function _form_fields_parser($the_form, $filter = false, $input_name_filter = false, $include_empty_name_attribute = false){
		if( !is_array($filter) ){
			$filter = array(
				'input',
				'textarea',
				'select'
			);
		}
		
		// Return
		$form_fields = false;
		
		// DOMDoc parser
		if ( iubenda()->options['cs']['parser_engine'] == 'new' ) {
			libxml_use_internal_errors( true );

			$document = new DOMDocument();

			// set document arguments
			$document->formatOutput = true;
			$document->preserveWhiteSpace = false;

			// load HTML
			$document->loadHTML( $the_form );

			// search for nodes
			foreach ( $filter as $input_field ) {
				$fields_raw = $document->getElementsByTagName( $input_field );

				if ( ! empty( $fields_raw ) && is_object( $fields_raw ) ) {
					foreach ( $fields_raw as $field ) {
						$field_name = $field->getAttribute( 'name' );
						$field_type = $field->getAttribute( 'type' );

						if( is_array($input_name_filter) && !in_array($field_name, $input_name_filter) ) continue;

						// exclude submit
						if ( ! empty( $field_type ) && ! in_array( $field_type, array( 'submit' ) ) ){
							//$form_fields[] = $field->getAttribute( 'name' );
							//$form_fields[] = ['name' => $field->getAttribute( 'name' ), 'type' => $field->getAttribute( 'type' )];
							
							if( !empty($field_name) ){
								$form_fields[] = $field_name;
								//$form_fields[] = sprintf('%s: %s', $field_type, $field_name);
							}elseif( $include_empty_name_attribute ){
								$form_fields[] = $field_name;
								//$form_fields[] = sprintf('%s: %s', $field_type, $field_name);
							}
						}
					}
				}
			}

			libxml_use_internal_errors( false );

		// Simple HTML Dom parser
		} else {

			// Ensure helper class were loaded
			if ( ! function_exists( 'str_get_html' ) ) {
				require_once( IUBENDA_PLUGIN_PATH . 'iubenda-cookie-class/simple_html_dom.php' );
			}

			$html = str_get_html( $the_form, $lowercase = true, $force_tags_closed = true, $strip = false );

			if ( is_object( $html ) ) {
				// search for nodes
				foreach ( $filter as $input_field ) {
					$fields_raw = $html->find( $input_field );

					if ( is_array( $fields_raw ) ) {
						foreach ( $fields_raw as $field ) {
							$field_name = $field->name;
							$field_type = $field->type;

							// exclude submit
							if ( ! empty( $field_type ) && ! in_array( $field_type, array( 'submit' ) ) ){
								$form_fields[] = $field->getAttribute( 'name' );
								//$form_fields[] = ['name' => $field->getAttribute( 'name' ), 'type' => $field->getAttribute( 'type' )];
							}
						}
					}
				}
			}
		}		
		
		return $form_fields;
	}



	public function is_give_active(){
		return function_exists('Give');
	}

	public function is_speakout_active(){
		return function_exists('dk_speakout_translate');
	}

	public function register_other_forms( $sources ){
		if($this->is_give_active()){
			$sources['give'] = 'Give - Donation';
		}
		
		if($this->is_speakout_active()){
			$sources['speakout'] = 'Speakout';
		}
		
		if($this->is_charitable_active()){
			$sources['charitable'] = 'WP Charitable';
		}
		
		if($this->is_ninjaform_active()){
			$sources['ninjaform'] = 'Ninja Form';
		}
		
		if($this->is_gravityform_active()){
			$sources['gravityform'] = 'Gravity Form';
		}
		
		//$this->write_log($sources);
		
		return $sources;
	}

	public function get_give_forms($forms){
		$source = 'give';
		
		$form_fields_ref = false;
		
		$restricted_fields = array(
			'submit',
			'file',
			'quiz',
			'recaptcha',
		);
		
		$args = array(
			'post_type'		 => 'give_forms',
			'posts_per_page' => -1
		);
		
		$posts = get_posts( $args );
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$url = get_permalink($post->ID);
				
				if ( ! empty( $url ) ) {
					$formdata = array(
						'object_type'	 => 'post', // object type where the form data is stored
						'object_id'		 => $post->ID, // unique object id
						'form_source'	 => $source, // source slug
						'form_title'	 => $post->post_title, // form title
						//'form_date'		 => $post->post_modified, // form last modified date
						'form_fields'	 => array() // form field names array
					);
				}

				if( $form_fields_ref === false ){
					
					$input_fields = array(
						'input',
						'textarea',
						'select'
					);
					
					$input_name_filter = array(
						'give-form-id',
						'give_first',
						'give_last',
						'give_email',
						'privacy_policy',
					);

					// Extract form part
					$the_form = $this->_get_form_from_url($url, 'give-form-' . $post->ID . '-wrap');

					// Parser
					if( $form_fields = $this->_form_fields_parser($the_form, $input_fields, $input_name_filter) ){
						$form_fields_ref = $form_fields;
						$formdata['form_fields'] = $form_fields_ref;
					}
					
				}else{
					$formdata['form_fields'] = $form_fields_ref;
				}

				$forms[] = $formdata;
				
				//echo '<pre>'; print_r( $forms ); echo '</pre>'; die;
			}
		}

		return $forms;
	}

	public function get_speakout_form_from_shortcode($id){
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.speakout.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.petition.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.wpml.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/emailpetition.php' );
		
		$the_form = do_shortcode('[emailpetition id="' . $id . '"]');
		if ( ! empty( $the_form ) ) return $the_form;
		
		return false;
	}
	
	public function get_speakout_forms($forms){
		$source = 'speakout';
		
		$restricted_fields = array(
			'submit',
			'file',
			'quiz',
			'recaptcha',
		);
		
		///if ( ! current_user_can( 'publish_posts' ) ) wp_die( 'Insufficient privileges: You need to be an editor to do that.' );

		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.speakout.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.petition.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/class.wpml.php' );
		include_once( WP_PLUGIN_DIR . '/speakout-oxfam/includes/emailpetition.php' );
		$the_petitions = new dk_speakout_Petition();
		
		// get petitions
		$count = $the_petitions->count();
		$petitions = $the_petitions->all( 0, $count );
		foreach ( $petitions as $petition ){
			
			$the_form = do_shortcode('[emailpetition id="' . $petition->id . '"]');
			
			if ( ! empty( $the_form ) ) {
				$formdata = array(
					'object_type'	 => 'post', // object type where the form data is stored
					'object_id'		 => $petition->id, // unique object id
					'form_source'	 => $source, // source slug
					'form_title'	 => $petition->title, // form title
					//'form_date'		 => '', //$petition->created_date, // form last modified date
					'form_fields'	 => array() // form field names array
				);
				
				$input_fields = array(
					'input',
					'textarea',
					'select'
				);
				
				// Parser
				if( $form_fields = $this->_form_fields_parser($the_form, $input_fields) ){
					$formdata['form_fields'] = $form_fields;
				}
				
				$forms[] = $formdata;
				
				//echo $the_form; 
				//echo '<hr><pre>'; print_r( $forms ); echo '</pre>'; exit;					
			}
		}
		
		return $forms;
	}


	public function givewp_insert_payment($donationId, $donationData){
		global $wp_version;
		//$this->write_log('$donationData'); $this->write_log($donationData);
		//$this->write_log('$_POST'); $this->write_log($_POST);

		$public_api_key = iubenda()->options['cons']['public_api_key'];

		// Escape on ajax request because it will be handle by injected JS "frontend.js"
		// Or escape if the public api key is not defined
		// Check current WP version is newer than 4.7 to use the wp_doing_ajax function
		if ( ( version_compare( $wp_version, '4.7', '>=' ) && wp_doing_ajax() ) || ! $public_api_key ) {
			return;
		}

		$form_id   = (int)$donationData['give_form_id'];
		$form_args = array(
			'post_status' 	=> array('mapped'),
			'source'		=> 'give',
			'id'			=> $form_id,
		);

		$form = iubenda()->forms->get_form_by_object_id($form_args);
		//$this->write_log('$form'); $this->write_log($form);

		if ( ! $form ) {
			return;
		}

		//__________________________________________________________________________________________________________________________
		$value_email = $value_first_name = $value_last_name = $value_full_name = '';
		if($arr_subject = $form->form_subject){
			$field_email = $arr_subject['email'];
			$field_first_name = $arr_subject['first_name'];
			$field_last_name = $arr_subject['last_name'];
			$field_full_name = $arr_subject['full_name'];
			
			$value_email = ( $field_email && isset($_POST[$field_email]) ) ? $_POST[$field_email] : '';
			$value_first_name = ( $field_first_name && isset($_POST[$field_first_name]) ) ? $_POST[$field_first_name] : '';
			$value_last_name = ( $field_last_name && isset($_POST[$field_last_name]) ) ? $_POST[$field_last_name] : '';
			$value_full_name = ( $field_full_name && isset($_POST[$field_full_name]) ) ? $_POST[$field_full_name] : '';
		}
		
		$data_legal_notices = $data_preferences = false;
		if($arr_legal_notices = $form->form_legal_notices){
			foreach($arr_legal_notices as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);

					$yes_no = ( in_array($_POST[$key], array('on', 'yes', 1)) ) ? true : false;
					$data_preferences[$key] = $yes_no;
				}				
			}
		}

		//get html form
		$html = $this->get_form_html($_POST['give-form-url'], $form_id);
		
		$consent_data = array(
			'subject' => array(
				'email' => $value_email,
				'first_name' => $value_first_name,
				'last_name' => $value_last_name,
			),
			'legal_notices' => $data_legal_notices,
			'proofs' => array(
				array(
					'content' => $_POST,
					'form' => $html,
				)
			),
			'preferences' => $data_preferences,
		);		
		//__________________________________________________________________________________________________________________________
		
		$this->write_log('$consent_data'); $this->write_log($consent_data); $this->write_log(json_encode($consent_data));

		$response = wp_remote_post( iubenda()->options['cons']['cons_endpoint'], array(
			'body'    => json_encode( $consent_data ),
			'headers' => array(
				'apikey'       => $public_api_key,
				'Content-Type' => 'application/json',
			),
		) );
		
		$this->write_log('wp_remote_post $response');
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->write_log("Something went wrong: $error_message");
		} else {
			$this->write_log($response);
		}		
	}
	
	public function get_form_html($form_url, $ID){
		if(wp_doing_ajax()) return;
		
		if($html = file_get_contents($form_url)){
			//$this->write_log($html);
			
			$pos = strpos($html, '<form id="give-form-' . $ID . '-1"');
			if($html_1 = substr($html, $pos)){
				//$this->write_log($html_1);
				
				$pos = strpos($html_1, '</form>');
				$html_final = substr($html_1, 0, $pos) . '</form>';
				//$this->write_log($html_final);
				
				if($html_final) return $html_final;
			}
		}
		return $form_url;
	}
	
	public function sync_signature( $data, $id ){
$this->write_log('$_POST'); $this->write_log($_POST);
/*
		DC()->logger('sync_signature DATA', $data); 
		$data = array(
			'petitions_id'      => $petition_id,
			'honorific'			=> $this->honorific,
			'first_name'        => $this->first_name,
			'last_name'         => $this->last_name,
			'email'             => $this->email,
			'date'              => $this->date,
			'confirmation_code' => $this->confirmation_code,
			//'is_confirmed'      => $this->is_confirmed,
			'is_confirmed'      => 1,
			'optin'             => $this->optin,
			'street_address'    => $this->street_address,
			'city'              => $this->city,
			'state'             => $this->state,
			'postcode'          => $this->postcode,
			'country'           => $this->country,
			'custom_field'      => $this->custom_field,
			'custom_message'    => $this->custom_message,
			'language'          => $this->language,
			'IP_address'		=> $_SERVER['REMOTE_ADDR'],
			'campaign'			=> $this->campaign,
		);
*/
		global $wp_version;
		$public_api_key = iubenda()->options['cons']['public_api_key'];
		
		if ( ! $public_api_key ) {
			return;
		}

		$form_id   = (int)$_POST['id'];
		$form_args = array(
			'post_status' 	=> array('mapped'),
			'source'		=> 'speakout',
			'id'			=> $form_id,
		);
$this->write_log('$form_args'); $this->write_log($form_args);

		$form = iubenda()->forms->get_form_by_object_id($form_args);
$this->write_log('$form'); $this->write_log($form);

		if ( ! $form ) {
			return;
		}

		//__________________________________________________________________________________________________________________________
		$value_email = $value_first_name = $value_last_name = $value_full_name = '';
		if($arr_subject = $form->form_subject){
			$field_email = $arr_subject['email'];
			$field_first_name = $arr_subject['first_name'];
			$field_last_name = $arr_subject['last_name'];
			$field_full_name = $arr_subject['full_name'];
			
			$search = array(
				'dk-speakout-email' => 'email',
				'dk-speakout-first-name' => 'first_name',
				'dk-speakout-last-name' => 'last_name',
			);
			
			$field_email = str_replace( array_keys($search), array_values($search), $field_email );
			$field_first_name = str_replace( array_keys($search), array_values($search), $field_first_name );
			$field_last_name = str_replace( array_keys($search), array_values($search), $field_last_name );
			$field_full_name = str_replace( array_keys($search), array_values($search), $field_full_name );
			
			$value_email = ( $field_email && isset($_POST[$field_email]) ) ? $_POST[$field_email] : '';
			$value_first_name = ( $field_first_name && isset($_POST[$field_first_name]) ) ? $_POST[$field_first_name] : '';
			$value_last_name = ( $field_last_name && isset($_POST[$field_last_name]) ) ? $_POST[$field_last_name] : '';
			$value_full_name = ( $field_full_name && isset($_POST[$field_full_name]) ) ? $_POST[$field_full_name] : '';
		}
		
		$data_legal_notices = $data_preferences = false;
		if($arr_legal_notices = $form->form_legal_notices){
			foreach($arr_legal_notices as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);
					
					$the_key = -1;
					if(isset($_POST[$key])){
						$the_key = $key;
					}else{
						$tmp = str_replace( array('_', '-'), '', $key);
						if(isset($_POST[$tmp])){
							$the_key = $tmp;
						}
					}

					if($the_key !== -1){
						$yes_no = ( in_array($_POST[$the_key], array(true, 'on', 'yes', 1)) ) ? true : false;
						$data_preferences[$the_key] = $yes_no;
					}
				}				
			}
		}

		//get html form
		$html = $this->get_speakout_form_from_shortcode($form_id);
		if($html === false) $html = 'speakout form';
		
		$consent_data = array(
			'subject' => array(
				'email' => $value_email,
				'first_name' => $value_first_name,
				'last_name' => $value_last_name,
			),
			'legal_notices' => $data_legal_notices,
			'proofs' => array(
				array(
					'content' => $_POST,
					'form' => $html,
				)
			),
			'preferences' => $data_preferences,
		);		
		//__________________________________________________________________________________________________________________________
		
		$this->write_log('$consent_data'); $this->write_log($consent_data); 

		$response = wp_remote_post( iubenda()->options['cons']['cons_endpoint'], array(
			'body'    => json_encode( $consent_data ),
			'headers' => array(
				'apikey'       => $public_api_key,
				'Content-Type' => 'application/json',
			),
		) );
		
		//$this->write_log('wp_remote_post $response');
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->write_log("Something went wrong: $error_message");
		} else {
			$this->write_log($response);
		}		


return true;
	}
	
	
	//since June 24, 2022
	public function is_charitable_active(){
		return class_exists( 'Charitable' );
	}
	
	public function is_ninjaform_active(){
		return class_exists( 'Ninja_Forms' );
	}

	public function is_gravityform_active(){
		return function_exists( 'gravity_form' );
	}

	public function get_charitable_forms($forms){
		$source = 'charitable';
		
		$form_fields_ref = false;
		
		$restricted_fields = array(
			'submit',
			'file',
			'quiz',
			'recaptcha',
		);
		
		$args = array(
			'post_type'		 => 'campaign',
			'posts_per_page' => -1
		);
		
		$posts = get_posts( $args );
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$url = get_permalink($post->ID);
				
				if ( ! empty( $url ) ) {
					$formdata = array(
						'object_type'	 => 'post', // object type where the form data is stored
						'object_id'		 => $post->ID, // unique object id
						'form_source'	 => $source, // source slug
						'form_title'	 => $post->post_title, // form title
						//'form_date'		 => $post->post_modified, // form last modified date
						'form_fields'	 => array() // form field names array
					);
				}

				if( $form_fields_ref === false ){
					
					$input_fields = array(
						'input',
						'textarea',
						'select'
					);
					
					$input_name_filter = array(
						'first_name',
						'last_name',
						'email',
						'anonymous_donation',
					);

					// Extract form part
					$the_form = $this->_get_form_part_from_url($url, '<form method="post" id="charitable-donation-form"', '</form>');
					///$this->write_log($url);
					///$this->write_log($the_form);

					// Parser
					if( $form_fields = $this->_form_fields_parser($the_form, $input_fields, $input_name_filter) ){
						$form_fields_ref = $form_fields;
						$formdata['form_fields'] = $form_fields_ref;
					}
					
				}else{
					$formdata['form_fields'] = $form_fields_ref;
				}

				$forms[] = $formdata;
			}
		}

		///$this->write_log($forms);
		return $forms;
	}	
	

	
    protected function _get_all_ninjaform(){
        $data = array();

        $forms = Ninja_Forms()->form()->get_forms();

        foreach( $forms as $form ){

             $data[] = array(
                 'id'        => $form->get_id(),
                 'title'     => $form->get_setting( 'title' ),
                 'shortcode' => apply_filters ( 'ninja_forms_form_list_shortcode','[ninja_form id=' . $form->get_id() . ']', $form->get_id() ),
                 'date'      => $form->get_setting( 'created_at' )
             );
        }

        return $data;
    }

    protected function _display_ninjaform_front_end( $id ){
        /*ob_start();
        Ninja_Forms()->display( $id, true );
        return ob_get_clean();*/
		
		$fields = Ninja_Forms()->form( $id )->get_fields();
		return $fields;
    }

	public function DEL_get_ninjaform_forms($forms){
		$source = 'ninjaform';
		
		$form_fields_ref = false;
		
		$restricted_fields = array(
			'submit',
			'file',
			'quiz',
			'recaptcha',
		);
		

		$nj_forms_controller = new NF_Database_FormsController();
		$nj_forms = $nj_forms_controller->getFormsData();
		$this->write_log($nj_forms);
		
		if ( ! empty( $nj_forms ) ) {
			foreach ( $nj_forms as $i => $form ) {
				$formdata = array(
					'object_type'	 => 'post', // object type where the form data is stored
					'object_id'		 => $form->id, // unique object id
					'form_source'	 => $source, // source slug
					'form_title'	 => $form->title, // form title
					//'form_date'		 => $form->created_at, // form last modified date
					'form_fields'	 => array() // form field names array
				);

				if( $form_fields_ref === false ){
					
					$input_fields = array(
						'input',
						'textarea',
						'select'
					);
					
					$input_name_filter = false;

					// Extract form part
					$the_form = $this->_display_ninjaform_front_end($form->id);
					$this->write_log($the_form);

					// Parser
					if( $the_form && $form_fields = $this->_form_fields_parser($the_form, $input_fields, $input_name_filter) ){
						$form_fields_ref = $form_fields;
						$formdata['form_fields'] = $form_fields_ref;
					}
					
				}else{
					$formdata['form_fields'] = $form_fields_ref;
				}

				$forms[] = $formdata;				
			}
		}

		$this->write_log($forms);
		return $forms;
	}

	protected function _get_ninjaform_fields_raw($form_id){
		$fields = array();
		$form_fields = Ninja_Forms()->form( $form_id )->get_fields();
		foreach ($form_fields as $field) {
			if( is_object( $field ) ) {
				$field = array(
					'id' => $field->get_id(),
					'settings' => $field->get_settings()
				);
				
				$fields[] = $field;
			}
		}
		//$this->write_log($fields);		
		return $fields;
	}

	protected function _get_ninjaform_fields($form_id, $filter = false, $input_name_filter = false){
		if( !is_array($filter) ){
			$filter = array(
				'input',
				'textbox',
				'textarea',
				'checkbox',
				'firstname',
				'lastname',
				'email',
				//'phone',
			);
		}

		$out = false;
		$fields = $this->_get_ninjaform_fields_raw($form_id);
		foreach($fields as $i => $arr){
			if( in_array($arr['settings']['type'], $filter) ){
				$the_id = (int)$arr['id'];
				$the_type = $arr['settings']['type'];
				$the_key = $arr['settings']['key'];
				$out[] = sprintf('%s%s%s%s%s', $the_id, $this->_sep, $the_type, $this->_sep, $the_key);
			}
		}
		
		return $out;
	}
	
	protected function _get_ninjaform_html($form_id){
		$out = '<form>';
		if($fields = $this->_get_ninjaform_fields($form_id)){
			foreach($fields as $field){
				$arr_field = explode($this->_sep, $field);
				$the_id = (int)$arr_field[0];
				$the_type = $arr_field[1];
				$the_key = $arr_field[2];
				
				if($the_type == 'checkbox'){
					$out .= sprintf('<input type="checkbox" name="%s_%s">', $the_type, $the_id);
				}else{
					$out .= sprintf('<input type="text" name="%s_%s">', $the_type, $the_id);
				}
			}
		}
		$out .= '<input type="submit" name="submit" value="Submit" />';
		$out .= '</form>';
		return $out;
	}	

	public function get_ninjaform_forms($forms){
		$source = 'ninjaform';
		
		$nj_forms = $this->_get_all_ninjaform();
		//$this->write_log($nj_forms);
		
		if ( ! empty( $nj_forms ) ) {
			foreach ( $nj_forms as $i => $form ) {
				$formdata = array(
					'object_type'	 => 'post', // object type where the form data is stored
					'object_id'		 => $form['id'], // unique object id
					'form_source'	 => $source, // source slug
					'form_title'	 => $form['title'], // form title
					//'form_date'		 => $form->created_at, // form last modified date
					'form_fields'	 => array() // form field names array
				);

				$input_fields = false;
				$input_name_filter = false;

				// Parser
				$form_fields = $this->_get_ninjaform_fields($form['id'], $input_fields, $input_name_filter);
				$formdata['form_fields'] = $form_fields;					

				$forms[] = $formdata;				
			}
		}

		$this->write_log($forms);
		return $forms;
	}




	protected function _get_gravityform_fields($form_id, $filter = false, $input_name_filter = false){
		if( !is_array($filter) ){
			$filter = array(
				'text',
				'textarea',
				'consent',
				'checkbox',
				'radio',
				'lastname',
				'name',
				'hidden',
				'email',
				//'phone',
			);
		}

		$out = false;
		
		$form = GFAPI::get_form( $form_id );
		//$this->write_log($form['fields']);
		if( !isset($form['fields']) || ! is_array($form['fields']) ) return $out;


		foreach ( $form['fields'] as $field ) {
			if( in_array($field->type, $filter) ){
				$the_id = (string)$field->id;
				$the_type = $field->type;
				$the_key = $field->label;
				
				if($the_type == 'consent'){
					if( isset($field->inputs) && is_array($field->inputs) ){
						foreach($field->inputs as $j => $r){
							if(strtolower($r['label']) == 'consent'){
								$the_id = (string)$r['id'];
								break;
							}
						}
					}
					$out[] = sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
				}else{
					$out[] = sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
				}
			}
		}
		
		return $out;
	}
	
    protected function _get_all_gravityform(){
        $data = array();		
        $forms = GFAPI::get_forms();
        foreach ( $forms as $form) {
             $data[] = array(
                 'id'        => $form['id'],
                 'title'     => $form['title'],
                 'shortcode' => '',
                 'date'      => $form['date_created'],
             );
        }

        return $data;
    }

	public function get_gravityform_forms($forms){
		$source = 'gravityform';
		
		$gf_forms = $this->_get_all_gravityform();
		//$this->write_log($gf_forms);
		
		if ( ! empty( $gf_forms ) ) {
			foreach ( $gf_forms as $i => $form ) {
				$formdata = array(
					'object_type'	 => 'post', // object type where the form data is stored
					'object_id'		 => $form['id'], // unique object id
					'form_source'	 => $source, // source slug
					'form_title'	 => $form['title'], // form title
					//'form_date'		 => $form->created_at, // form last modified date
					'form_fields'	 => array() // form field names array
				);

				$input_fields = false;
				$input_name_filter = false;

				// Parser
				$form_fields = $this->_get_gravityform_fields($form['id'], $input_fields, $input_name_filter);
				$formdata['form_fields'] = $form_fields;					

				$forms[] = $formdata;				
			}
		}

		$this->write_log($forms);
		return $forms;
	}


	public function sync_ninja_forms( $data ){
		///$this->write_log('$data'); $this->write_log($data);
		///$this->write_log('$_POST'); $this->write_log($_POST);

		$formData = json_decode(stripslashes($_POST['formData']), true);
		$this->write_log('$formData'); $this->write_log($formData);

		global $wp_version;
		$public_api_key = iubenda()->options['cons']['public_api_key'];

		// Escape on ajax request because it will be handle by injected JS "frontend.js"
		// Or escape if the public api key is not defined
		// Check current WP version is newer than 4.7 to use the wp_doing_ajax function
		if ( ! $public_api_key ) {
			$this->write_log('wp_version: ' . $wp_version);
			$this->write_log('wp_doing_ajax: ' . wp_doing_ajax());
			$this->write_log('public_api_key: ' . $public_api_key);
			return;
		}

		//$form_id   = (int)$_POST['charitable_form_id'];
		$form_id   = (int)$data['form_id'];
		$form_args = array(
			'post_status' 	=> array('mapped'),
			'source'		=> 'ninjaform',
			'id'			=> $form_id,
		);

		$form = iubenda()->forms->get_form_by_object_id($form_args);
		//$this->write_log('$form'); $this->write_log($form);

		if ( ! $form ) {
			return;
		}

		//__________________________________________________________________________________________________________________________
		$value_email = $value_first_name = $value_last_name = $value_full_name = '';
		if($arr_subject = $form->form_subject){
			
			$this->write_log('$arr_subject'); $this->write_log($arr_subject);

			$field_email = $arr_subject['email'];
			$field_first_name = $arr_subject['first_name'];
			$field_last_name = $arr_subject['last_name'];
			$field_full_name = $arr_subject['full_name'];
			
			//sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
			$arr_field_email = explode($this->_sep, $field_email);
			$arr_field_first_name = explode($this->_sep, $field_first_name);
			$arr_field_last_name = explode($this->_sep, $field_last_name);
			$arr_field_full_name = explode($this->_sep, $field_full_name);
			
			//find the value for each field
			if($the_id = $arr_field_email[0]){
				if( isset($formData['fields'][$the_id]['value']) ){
					$value_email = $formData['fields'][$the_id]['value'];
				} 
			}
			
			if($the_id = $arr_field_first_name[0]){
				if( isset($formData['fields'][$the_id]['value']) ){
					$value_first_name = $formData['fields'][$the_id]['value'];
				} 
			}
			
			if($the_id = $arr_field_last_name[0]){
				if( isset($formData['fields'][$the_id]['value']) ){
					$value_last_name = $formData['fields'][$the_id]['value'];
				} 
			}
			
			if($the_id = $arr_field_full_name[0]){
				if( isset($formData['fields'][$the_id]['value']) ){
					$value_full_name = $formData['fields'][$the_id]['value'];
				} 
			}
		}
		
		$data_legal_notices = $data_preferences = false;
		
		$arr_legal_notices = $form->form_legal_notices;
		$this->write_log('$arr_legal_notices'); $this->write_log($arr_legal_notices);
		
		$arr_preferences = $form->form_preferences;
		$this->write_log('$arr_preferences'); $this->write_log($arr_preferences);
		
		if($arr_legal_notices){
			foreach($arr_legal_notices as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);

					$yes_no = ( in_array($_POST[$key], array('on', 'yes', 1)) ) ? true : false;
					$data_preferences[$key] = $yes_no;
				}				
			}
		}

		if($arr_preferences){
			foreach($arr_preferences as $i => $key){
				if($key){
					
					//sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
					$arr_tmp = explode($this->_sep, $key);
					if($the_id = $arr_tmp[0]){
						if( isset($formData['fields'][$the_id]['value']) ){
							$the_value = $formData['fields'][$the_id]['value'];
							$the_key = sprintf('%s_%s', $arr_tmp[1], $the_id);
							
							$data_legal_notices[] = array('identifier' => $the_key);

							$yes_no = ( in_array($the_value, array('on', 'yes', 1)) ) ? true : false;
							$data_preferences[$the_key] = $yes_no;
							
						} 
					}
				}				
			}
		}

		//get html form
		$form_html = $this->_get_ninjaform_html($form_id);
		
		$consent_data = array(
			'subject' => array(
				'email' => $value_email,
				'first_name' => $value_first_name,
				'last_name' => $value_last_name,
			),
			'legal_notices' => $data_legal_notices,
			'proofs' => array(
				array(
					'content' => $_POST,
					'form' => $form_html,
				)
			),
			'preferences' => $data_preferences,
		);		
		//__________________________________________________________________________________________________________________________
		
		$this->write_log('$consent_data'); $this->write_log($consent_data); //$this->write_log(json_encode($consent_data));

		$response = wp_remote_post( iubenda()->options['cons']['cons_endpoint'], array(
			'body'    => json_encode( $consent_data ),
			'headers' => array(
				'apikey'       => $public_api_key,
				'Content-Type' => 'application/json',
			),
		) );
		
		$this->write_log('wp_remote_post $response');
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->write_log("Something went wrong: $error_message");
		} else {
			$this->write_log($response);
			//$this->get_list_consent($_POST['give_email']);
		}
		
	}
	
	public function sync_charitable( $donation_id, $obj ){
		$this->write_log('$donation_id: ' . $donation_id);
		//$this->write_log('$obj'); $this->write_log($obj);
		$this->write_log('$_POST'); $this->write_log($_POST);

		global $wp_version;
		$public_api_key = iubenda()->options['cons']['public_api_key'];

		// Escape on ajax request because it will be handle by injected JS "frontend.js"
		// Or escape if the public api key is not defined
		// Check current WP version is newer than 4.7 to use the wp_doing_ajax function
		//if ( ( version_compare( $wp_version, '4.7', '>=' ) && wp_doing_ajax() ) || ! $public_api_key ) {
		if ( ! $public_api_key ) {
			$this->write_log('wp_version: ' . $wp_version);
			$this->write_log('wp_doing_ajax: ' . wp_doing_ajax());
			$this->write_log('public_api_key: ' . $public_api_key);
			return;
		}

		//$form_id   = (int)$_POST['charitable_form_id'];
		$form_id   = (int)$_POST['campaign_id'];
		$form_args = array(
			'post_status' 	=> array('mapped'),
			'source'		=> 'charitable',
			'id'			=> $form_id,
		);

		$form = iubenda()->forms->get_form_by_object_id($form_args);
		$this->write_log($form_args); $this->write_log($form);

		if ( ! $form ) {
			return;
		}

		//__________________________________________________________________________________________________________________________
		$value_email = $value_first_name = $value_last_name = $value_full_name = '';
		if($arr_subject = $form->form_subject){
			$field_email = $arr_subject['email'];
			$field_first_name = $arr_subject['first_name'];
			$field_last_name = $arr_subject['last_name'];
			$field_full_name = $arr_subject['full_name'];
			
			$value_email = ( $field_email && isset($_POST[$field_email]) ) ? $_POST[$field_email] : '';
			$value_first_name = ( $field_first_name && isset($_POST[$field_first_name]) ) ? $_POST[$field_first_name] : '';
			$value_last_name = ( $field_last_name && isset($_POST[$field_last_name]) ) ? $_POST[$field_last_name] : '';
			$value_full_name = ( $field_full_name && isset($_POST[$field_full_name]) ) ? $_POST[$field_full_name] : '';
		}
		
		$data_legal_notices = $data_preferences = false;
		
		$arr_legal_notices = $form->form_legal_notices;
		$this->write_log('$arr_legal_notices'); $this->write_log($arr_legal_notices);
		
		$arr_preferences = $form->form_preferences;
		$this->write_log('$arr_preferences'); $this->write_log($arr_preferences);
		
		if($arr_legal_notices){
			foreach($arr_legal_notices as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);

					$yes_no = ( in_array($_POST[$key], array('on', 'yes', 1)) ) ? true : false;
					$data_preferences[$key] = $yes_no;
				}				
			}
		}

		if($arr_preferences){
			foreach($arr_preferences as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);

					$yes_no = ( in_array($_POST[$key], array('on', 'yes', 1)) ) ? true : false;
					$data_preferences[$key] = $yes_no;
				}				
			}
		}

		//get html form
		//$html = $this->get_form_html($_POST['give-form-url'], $form_id);
		$url = get_permalink($form_id);
		$html = $this->_get_form_part_from_url($url, '<form method="post" id="charitable-donation-form"', '</form>');
		
		$consent_data = array(
			'subject' => array(
				'email' => $value_email,
				'first_name' => $value_first_name,
				'last_name' => $value_last_name,
			),
			'legal_notices' => $data_legal_notices,
			'proofs' => array(
				array(
					'content' => $_POST,
					'form' => $html,
				)
			),
			'preferences' => $data_preferences,
		);		
		//__________________________________________________________________________________________________________________________
		
		$this->write_log('$consent_data'); $this->write_log($consent_data); //$this->write_log(json_encode($consent_data));

		$response = wp_remote_post( iubenda()->options['cons']['cons_endpoint'], array(
			'body'    => json_encode( $consent_data ),
			'headers' => array(
				'apikey'       => $public_api_key,
				'Content-Type' => 'application/json',
			),
		) );
		
		$this->write_log('wp_remote_post $response');
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->write_log("Something went wrong: $error_message");
		} else {
			$this->write_log($response);
			//$this->get_list_consent($_POST['give_email']);
		}
	}
	

	public function sync_gravity_form($entry, $gf_form){
		///$this->write_log('$entry'); $this->write_log($entry);
		///$this->write_log('$gf_form'); $this->write_log($gf_form);
		
		global $wp_version;
		$source = 'gravityform';

		$public_api_key = iubenda()->options['cons']['public_api_key'];

		if ( ! $public_api_key ) {
			$this->write_log('wp_version: ' . $wp_version);
			$this->write_log('wp_doing_ajax: ' . wp_doing_ajax());
			$this->write_log('public_api_key: ' . $public_api_key);
			return;
		}

		$form_id   = (int)$gf_form['id'];
		$form_args = array(
			'post_status' 	=> array('mapped'),
			'source'		=> $source,
			'id'			=> $form_id,
		);

		$form = iubenda()->forms->get_form_by_object_id($form_args);
		///$this->write_log($form_args); $this->write_log($form);

		if ( ! $form ) {
			return;
		}

		//__________________________________________________________________________________________________________________________
		$value_email = $value_first_name = $value_last_name = $value_full_name = ''; $form_html = '<form>';
		if($arr_subject = $form->form_subject){
			
			$this->write_log('$arr_subject'); $this->write_log($arr_subject);

			$field_email = $arr_subject['email'];
			$field_first_name = $arr_subject['first_name'];
			$field_last_name = $arr_subject['last_name'];
			$field_full_name = $arr_subject['full_name'];
			
			//sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
			$arr_field_email = explode('||', $field_email);
			$arr_field_first_name = explode('||', $field_first_name);
			$arr_field_last_name = explode('||', $field_last_name);
			$arr_field_full_name = explode('||', $field_full_name);
			
			//find the value for each field
			if($the_id = $arr_field_email[0]){
				$value_email = rgar( $entry, (string)$the_id );
				$form_html .= sprintf('<input type="text" name="input_%s">', $the_id);
			}
			
			if($the_id = $arr_field_first_name[0]){
				$value_first_name = rgar( $entry, (string)$the_id );
				$form_html .= sprintf('<input type="text" name="input_%s">', $the_id);
			}
			
			if($the_id = $arr_field_last_name[0]){
				$value_last_name = rgar( $entry, (string)$the_id );
				$form_html .= sprintf('<input type="text" name="input_%s">', $the_id);
			}
			
			if($the_id = $arr_field_full_name[0]){
				$value_full_name = rgar( $entry, (string)$the_id );
				$form_html .= sprintf('<input type="text" name="input_%s">', $the_id);
			}
			
		}
/*
				if($the_type == 'checkbox'){
					$form_html .= sprintf('<input type="checkbox" name="%s_%s">', $the_type, $the_id);
				}else{
					$form_html .= sprintf('<input type="text" name="%s_%s">', $the_type, $the_id);
				}
*/
		
		$data_legal_notices = $data_preferences = false;
		
		$arr_legal_notices = $form->form_legal_notices;
		$this->write_log('$arr_legal_notices'); $this->write_log($arr_legal_notices);
		
		$arr_preferences = $form->form_preferences;
		$this->write_log('$arr_preferences'); $this->write_log($arr_preferences);
		
		if($arr_legal_notices){
			foreach($arr_legal_notices as $i => $key){
				if($key){
					$data_legal_notices[] = array('identifier' => $key);

					$yes_no = ( in_array($_POST[$key], array('on', 'yes', 1)) ) ? true : false;
					$data_preferences[$key] = $yes_no;
				}				
			}
		}

		if($arr_preferences){
			foreach($arr_preferences as $i => $key){
				if($key){
					
					//sprintf('%s||%s||%s', $the_id, $the_type, $the_key);
					$arr_tmp = explode('||', $key);
					if($the_id = (string)$arr_tmp[0]){
						$the_key = sprintf('input_%s', $the_id);
						$the_value = rgar( $entry, $the_id );
						$yes_no = ( in_array($the_value, array('on', 'yes', 1)) ) ? true : false;

						$data_preferences[$the_key] = $yes_no;						
						$data_legal_notices[] = array('identifier' => $the_key);
						
						$form_html .= sprintf('<input type="checkbox" name="input_%s" value="1">', $the_id);
					}
				}				
			}
		}

		$form_html .= '<input type="submit" name="submit" value="Submit">';
		$form_html .= '</form>';
		
		$consent_data = array(
			'subject' => array(
				'email' => $value_email,
				'first_name' => $value_first_name,
				'last_name' => $value_last_name,
			),
			'legal_notices' => $data_legal_notices,
			'proofs' => array(
				array(
					'content' => $_POST,
					'form' => $form_html,
				)
			),
			'preferences' => array('privacy_policy_gform' => true),
		);		
		//__________________________________________________________________________________________________________________________
		
		$this->write_log('$consent_data'); $this->write_log($consent_data);

		$response = wp_remote_post( iubenda()->options['cons']['cons_endpoint'], array(
			'body'    => json_encode( $consent_data ),
			'headers' => array(
				'apikey'       => $public_api_key,
				'Content-Type' => 'application/json',
			),
		) );
		
		$this->write_log('wp_remote_post $response');
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->write_log("Something went wrong: $error_message");
		} else {
			$this->write_log($response);
		}

	}
	
	
}

function iep() {
	return iubenda_extra_class::instance();
}

iep();