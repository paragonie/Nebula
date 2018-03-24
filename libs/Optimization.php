<?php

if ( !defined('ABSPATH') ){ die(); } //Exit if accessed directly

if ( !trait_exists('Optimization') ){
	trait Optimization {
		public function hooks(){
			add_filter('clean_url', array($this, 'defer_async_scripts'), 11, 1);
			add_filter('script_loader_tag', array($this, 'defer_async_additional_scripts'), 10);
			add_action('wp_enqueue_scripts', array($this, 'dequeue_lazy_load_styles'));
			add_action('wp_footer', array($this, 'dequeue_lazy_load_scripts'));
			add_filter('style_loader_src', array($this, 'http2_server_push_header'), 99, 1); //@todo "Nebula" 0: try using 'send_headers' hook instead?
			add_filter('script_loader_src', array($this, 'http2_server_push_header'), 99, 1); //@todo "Nebula" 0: try using 'send_headers' hook instead?
			add_filter('script_loader_src', array($this, 'remove_script_version'), 15, 1);
			add_filter('style_loader_src', array($this, 'remove_script_version'), 15, 1);
			add_action('wp_enqueue_scripts', array($this, 'dequeues'), 9999);
			add_action('admin_init', array($this, 'plugin_force_settings'));
			add_action('wp_enqueue_scripts', array($this, 'remove_actions'), 9999);
			add_action('init', array($this, 'disable_wp_emojicons'));
			add_filter('wp_resource_hints', array($this, 'remove_emoji_prefetch'), 10, 2); //Remove dns-prefetch for emojis
			add_filter('tiny_mce_plugins', array($this, 'disable_emojicons_tinymce')); //Remove TinyMCE Emojis too
			add_filter('wpcf7_load_css', '__return_false'); //Disable CF7 CSS resources (in favor of Bootstrap and Nebula overrides)
			add_filter('wp_default_scripts', array($this, 'remove_jquery_migrate'));
			add_action('wp_enqueue_scripts', array($this, 'move_jquery_to_footer'));
			add_action('wp_head', array($this, 'listen_for_jquery_footer_errors'));

			add_action('send_headers', array($this, 'server_timing_header'));
			add_action('wp_footer', array($this, 'output_console_debug_timings'));
			add_action('admin_footer', array($this, 'output_console_debug_timings'));

			add_filter('intermediate_image_sizes_advanced', array($this, 'create_max_width_size_proportionally'), 10, 2);
			add_filter('post_thumbnail_size', array($this, 'limit_thumbnail_size'), 10, 2);
			add_filter('nebula_thumbnail_src_size', array($this, 'limit_image_size'));
			add_filter('max_srcset_image_width', array($this, 'smaller_max_srcset_image_width'), 10, 2);
		}

		//Create max image size for each uploaded image while maintaining aspect ratio
		//This is done regardless of if the option is enabled to make this size ready if the option becomes enabled later
		public function create_max_width_size_proportionally($sizes, $metadata){
			if ( !empty($metadata['width']) && !empty($metadata['height']) ){
				//Create a max size of 1200px wide
				$lg_width = $metadata['width'];
				$lg_height = $metadata['height'];
				if ( $metadata['width'] > 1200 ){
					$lg_width = 1200;
					$lg_height = ($metadata['height']*$lg_width)/$metadata['width']; //Original Height * Desired Width / Original Width = Desired Height
				}

				$sizes['max_size'] = array(
					'width'  => $lg_width,
					'height' => $lg_height,
					'crop'   => true
				);

				//Create a max size of 800px wide for use with the Save Data header
				$sm_width = $metadata['width'];
				$sm_height = $metadata['height'];
				if ( $metadata['width'] > 800 ){
					$sm_width = 800;
					$sm_height = ($metadata['height']*$sm_width)/$metadata['width']; //Original Height * Desired Width / Original Width = Desired Height
				}

				$sizes['max_size_less'] = array(
					'width'  => $sm_width,
					'height' => $sm_height,
					'crop'   => true
				);
			}

			return $sizes;
		}

		//Limit image size when being called
		public function limit_thumbnail_size($size, $id){
			return $this->limit_image_size($size);
		}
		public function limit_image_size($size){
			if ( $this->get_option('limit_image_dimensions') ){
				if ( is_string($size) && $size === 'post-thumbnail' || $size === 'full' ){
					$size = ( $this->is_save_data() )? 'max_size_less' : 'max_size'; //If Save Data header is present (from user) use smaller max size
				}
			}

			return $size;
		}

		//Reduce the max srcset image width from 1600px to 1200px
		function smaller_max_srcset_image_width($size, $size_array){
			if ( $this->get_option('limit_image_dimensions') ){
				$size = ( $this->is_save_data() )? 800 : 1200; //If Save Data header is present (from user) use smaller max size
			}

			return $size;
		}

		//Check if the Save Data header exists (to use less data)
		public function use_less_data(){return $this->is_save_data();}
		public function is_lite(){return $this->is_save_data();}
		public function is_save_data(){
			if ( isset($_SERVER["HTTP_SAVE_DATA"]) && stristr($_SERVER["HTTP_SAVE_DATA"], 'on') !== false ){
				return true;
			}

			return false;
		}

		public function register_script($handle=null, $src=null, $exec=null, $deps=array(), $ver=false, $in_footer=false){
			$path = ( !empty($exec) )? $src . '#' . $exec : $src;
			wp_register_script($handle, $path, $deps, $ver, $in_footer);
		}

		//Remove version query strings from registered/enqueued styles/scripts (to allow caching). Note when troubleshooting: Other plugins may be doing this too.
		//For debugging (see the "add_debug_query_arg" function in /libs/Scripts.php)
		public function remove_script_version($src){
			if ( $this->is_debug() ){
				return $src;
			}

			$src = rtrim(remove_query_arg('ver', $src), '?'); //Remove "?" if it is the last character after removing ?ver parameter
			$src = str_replace('?#', '#', $src); //Remove "?" if it is followed by "#" (when using #defer or #async with Nebula)

			return $src;
		}

		//Control which scripts use defer/async using a hash.
		public function defer_async_scripts($url){
			if ( strpos($url, '#defer') === false && strpos($url, '#async') === false ){
				return $url;
			}

			if ( strpos($url, '#defer') ){
				return str_replace('#defer', '', $url) . "' defer='defer"; //Add the defer attribute while removing the hash
			} elseif ( strpos($url, '#async') ){
				return str_replace('#async', '', $url) . "' async='async"; //Add the async attribute while removing the hash
			}
		}

		//Defer and Async specific scripts. This only works with registered/enqueued scripts!
		public function defer_async_additional_scripts($tag){
			$to_defer = array('jquery-migrate', 'jquery.form', 'contact-form-7', 'wp-embed'); //Scripts to defer. Strings can be anywhere in the filepath.
			$to_async = array(); //Scripts to async. Strings can be anywhere in the filepath.

			//Defer scripts
			if ( !empty($to_defer) ){
				foreach ( $to_defer as $script ){
					if ( strpos($tag, $script) ){
						return str_replace(' src', ' defer="defer" src', $tag);
					}
				}
			}

			//Async scripts
			if ( !empty($to_async) ){
				foreach ( $to_async as $script ){
					if ( strpos($tag, $script) ){
						return str_replace(' src', ' async="async" src', $tag);
					}
				}
			}

			return $tag;
		}

		//Prep assets for lazy loading. Be careful of dependencies!
		//When lazy loading JS files, the window load listener may not trigger! Be careful!
		//Array should be built as: handle => condition
		public function lazy_load_assets(){
			$assets = array(
				'styles' => array(
					'nebula-font_awesome' => 'all',
					'nebula-flags' => '.flag',
					'wp-pagenavi' => '.wp-pagenavi',
				),
				'scripts' => array(),
			);

			return apply_filters('nebula_lazy_load_assets', $assets); //Allow other plugins/themes to lazy-load assets
		}

		//Dequeue styles prepped for lazy-loading
		public function dequeue_lazy_load_styles(){
			$lazy_load_assets = $this->lazy_load_assets();

			foreach ( $lazy_load_assets['styles'] as $handle => $condition ){
				wp_dequeue_style($handle);
			}
		}

		//Dequeue scripts prepped for lazy-loading
		public function dequeue_lazy_load_scripts(){
			$lazy_load_assets = $this->lazy_load_assets();

			foreach ( $lazy_load_assets['scripts'] as $handle => $condition ){
				wp_dequeue_script($handle);
			}
		}

		//Use HTTP2 Server Push to push multiple CSS and JS resources at once
		public function http2_server_push_header($src){
			if ( !$this->is_admin_page(true) && $this->get_option('service_worker') && file_exists($this->sw_location(false)) ){ //If not in the admin section and if Service Worker is enabled (and file exists)
				$filetype = ( strpos($src, '.css') )? 'style' : 'script'; //Determine the resource type
				if ( strpos($src, $this->url_components('sld')) > 0 ){ //If local file
					if ( $this->get_browser() !== 'safari' ){ //Disable HTTP2 Server Push on Safari (at least for now)
						header('Link: <' . esc_url(str_replace($this->url_components('basedomain'), '', strtok($src, '#'))) . '>; rel=preload; as=' . $filetype, false); //Send the header for the HTTP2 Server Push (strtok to remove everything after and including "#")
					}
				}
			}

		    return $src;
		}

		//Set Server Timing header
		public function server_timing_header(){
			$this->finalize_timings();
			$server_timing_header_string = 'Server-Timing: ';

			//Loop through all times
			foreach ( $this->server_timings as $label => $data ){
				if ( !empty($data['time']) ){
					$time = $data['time'];
				} elseif ( intval($data) ){
					$time = intval($data);
				} else {
					continue;
				}

				//Ignore unfinished, 0 timings, or non-logging entries
				if ( $label === 'categories' || !empty($data['active']) || round($time*1000) <= 0 || (!empty($data['log']) && $data['log'] === false) ){
					continue;
				}

				$name = str_replace(array(' ', '(', ')', '[', ']'), '', strtolower($label));
				if ( $label === 'PHP [Total]' ){
					$name = 'total';
				}
				$server_timing_header_string .= $name . ';dur=' . round($time*1000) . ';desc="' . $label . '",';
			}

			header($server_timing_header_string);
		}

		//Include server timings for developers
		public function output_console_debug_timings(){
			//if ( $this->is_dev() ){ //Only output server timings for developers?
				$this->finalize_timings();

				foreach ( $this->server_timings as $label => $data ){
					if ( !empty($data['time']) ){
						$time = $data['time'];
					} elseif ( intval($data) ){
						$time = intval($data);
					} else {
						continue;
					}

					if ( $label === 'categories' || !empty($data['active']) || round($time*1000) <= 0 || (!empty($data['log']) && $data['log'] === false) ){
						continue;
					}

					$start_time = ( !empty($data['start']) )? round(($data['start']-$_SERVER['REQUEST_TIME_FLOAT'])*1000) : -1;

					$testTimes['[PHP] ' . $label] = array(
						'start' => $start_time, //Convert seconds to milliseconds
						'duration' => round($time*1000), //Convert seconds to milliseconds
						'elapsed' => ( is_float($start_time) )? $start_time+round($time*1000) : -1,
					);
				}

				//Sort by elapsed time
				uasort($testTimes, function($a, $b){
					return $a['elapsed'] - $b['elapsed'];
				});

				echo '<script type="text/javascript">nebula.site.timings = ' . json_encode($testTimes) . ';</script>'; //Output the data to <head>
			//}
		}

		//Determing if a page should be prepped using prefetch, preconnect, or prerender.
			//DNS-Prefetch = Resolve the DNS only to a domain.
			//Preconnect = Resolve both DNS and TCP to a domain.
			//Prefetch = Fully request a single resource and store it in cache until needed. Do not combine with preload!
			//Preload = Fully request a single resource before it is needed. Do not combine with prefetch!
			//Prerender = Render an entire page (useful for comment next page navigation). Use Audience > User Flow report in Google Analytics for better predictions.

			//Note: WordPress automatically uses dns-prefetch on enqueued resource domains.
			//Note: Additional preloading for lazy-loaded CSS happens in /libs/Scripts.php

			//To hook into the arrays use:
			/*
				add_filter('nebula_preconnect', 'my_preconnects');
				function my_preconnects($array){
					$array[] = '//example.com';
					return $array;
				}
			*/
		public function prebrowsing(){
			$override = apply_filters('pre_nebula_prebrowsing', null);
			if ( isset($override) ){return;}

			//DNS-Prefetch & Preconnect
			$default_preconnects = array();

			//GCSE on 404 pages
			if ( is_404() && $this->get_option('cse_id') ){
				$default_preconnects[] = '//www.googleapis.com';
			}

			//Disqus commenting
			if ( is_single() && $this->get_option('comments') && $this->get_option('disqus_shortname') ){
				$default_preconnects[] = '//' . $this->get_option('disqus_shortname') . '.disqus.com';
			}

			//Preconnect
			$preconnects = apply_filters('nebula_preconnect', $default_preconnects);
			foreach ( $preconnects as $preconnect ){
				echo '<link rel="preconnect" href="' . $preconnect . '" />';
			}

			//Prefetch
			$default_prefetches = array();
			$prefetches = apply_filters('nebula_prefetches', $default_prefetches);
			foreach ( $prefetches as $prefetch ){
				echo '<link rel="prefetch" href="' . $prefetch . '" />';
			}

			//Prerender
			//If an eligible page is determined after load, use the JavaScript nebulaPrerender(url) function.
			$prerender = false;
			if ( is_404() ){
				$prerender = ( !empty($this->error_404_exact_match) )? $this->error_404_exact_match : home_url('/');
			}

			if ( !empty($prerender) ){
				echo '<link id="prerender" rel="prerender" href="' . $prerender . '" />';
			}
		}

		//Dequeue certain scripts
		public function dequeues(){
			$override = apply_filters('pre_nebula_dequeues', null);
			if ( isset($override) ){return;}

			if ( !is_admin() ){
				//Removing CF7 styles in favor of Bootstrap + Nebula
				wp_dequeue_style('contact-form-7');
				wp_deregister_script('wp-embed'); //WP Core WP-Embed - Override this only if embedding external WordPress posts into this WordPress site. Other oEmbeds are NOT AFFECTED by this!

				//Page specific dequeues
				if ( is_front_page() ){
					wp_deregister_style('thickbox'); //WP Core Thickbox - Override this if thickbox type gallery IS used on the homepage.
					wp_deregister_script('thickbox'); //WP Thickbox - Override this if thickbox type gallery IS used on the homepage.
				}
			}
		}

		//If Nebula Options are set to load jQuery in the footer, move it there.
		public function move_jquery_to_footer(){
			//Let other plugins/themes add to list of pages/posts/whatever when to load jQuery in the <head>
			//Return true to load jQuery from the <head>
			if ( apply_filters('nebula_prevent_jquery_footer', false) ){
				return;
			}

			if ( !$this->is_admin_page(true) && $this->get_option('jquery_version') === 'footer' ){
				wp_script_add_data('jquery', 'group', 1);
				wp_script_add_data('jquery-core', 'group', 1);
				wp_script_add_data('jquery-migrate', 'group', 1);

			}

			return;
		}

		//Listen for "jQuery is not defined" errors to provide help
		public function listen_for_jquery_footer_errors(){
			//Let other plugins/themes add to list of pages/posts/whatever when to load jQuery in the <head>
			//Return true to load jQuery from the <head>
			if ( apply_filters('nebula_prevent_jquery_footer', false) ){
				return;
			}

			if ( !$this->is_admin_page(true) && $this->get_option('jquery_version') === 'footer' ){
				if ( $this->is_dev() ){
					?>
					<script>
						window.addEventListener('error', function(e){
							var errorMessages = ['jQuery is not defined', "Can't find variable: jQuery"];
							errorMessages.forEach(function(element, index){
								if ( e.message.indexOf(element) !== -1 || e.message.indexOf(element.replace('jQuery', '$')) !== -1 ){
									console.error('[Nebula] jquery.min.js has been moved to the footer so it may not be available at this time. Try moving it back to the head in Nebula Options or move this script tag to the footer.');
								}
							});
						});
					</script>
					<?php
				}
			}
		}

		//Remove jQuery Migrate, but keep jQuery
		public function remove_jquery_migrate($scripts){
			if ( $this->get_option('jquery_version') !== 'wordpress' ){
				$scripts->remove('jquery');
				$scripts->add('jquery', false, array('jquery-core'), null);
			}
		}

		//Force settings within plugins
		public function plugin_force_settings(){
			$override = apply_filters('pre_nebula_plugin_force_settings', null);
			if ( isset($override) ){return;}

			//Wordpress SEO (Yoast)
			if ( is_plugin_active('wordpress-seo/wp-seo.php') ){
				remove_submenu_page('wpseo_dashboard', 'wpseo_files'); //Remove the ability to edit files.
				$wpseo = get_option('wpseo');
				$wpseo['ignore_meta_description_warning'] = true; //Disable the meta description warning.
				$wpseo['ignore_tour'] = true; //Disable the tour.
				$wpseo['theme_description_found'] = false; //@TODO "Nebula" 0: Not working because this keeps getting checked/tested at many various times in the plugin.
				$wpseo['theme_has_description'] = false; //@TODO "Nebula" 0: Not working because this keeps getting checked/tested at many various times in the plugin.
				update_option('wpseo', $wpseo);

				//Disable update notifications
				remove_action('admin_notices', array(Yoast_Notification_Center::get(), 'display_notifications'));
				remove_action('all_admin_notices', array(Yoast_Notification_Center::get(), 'display_notifications'));
			}
		}

		//Override existing functions (typcially from plugins)
		public function remove_actions(){ //Note: Priorities much MATCH (not exceed) [default if undeclared, it is 10]
			if ( $this->is_admin_page() ){ //WP Admin
				if ( is_plugin_active('event-espresso/espresso.php') ){
					remove_filter('admin_footer_text', 'espresso_admin_performance'); //Event Espresso - Prevent adding text to WP Admin footer
					remove_filter('admin_footer_text', 'espresso_admin_footer'); //Event Espresso - Prevent adding text to WP Admin footer
				}
			} else { //Frontend
				//remove_action('wpseo_head', 'debug_marker', 2 ); //Remove Yoast comment [not working] (not sure if second comment could be removed without modifying class-frontend.php)

			}
		}

		//Disable Emojis
		public function disable_wp_emojicons(){
			$override = apply_filters('pre_disable_wp_emojicons', null);
			if ( isset($override) ){return;}

			remove_action('admin_print_styles', 'print_emoji_styles');
			remove_action('wp_head', 'print_emoji_detection_script', 7);
			remove_action('admin_print_scripts', 'print_emoji_detection_script');
			remove_action('wp_print_styles', 'print_emoji_styles');
			remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
			remove_filter('the_content_feed', 'wp_staticize_emoji');
			remove_filter('comment_text_rss', 'wp_staticize_emoji');
		}
		public function remove_emoji_prefetch($hints, $relation_type){
			if ( $relation_type === 'dns-prefetch' ){
				$matches = preg_grep('/emoji/', $hints);
				return array_diff($hints, $matches);
			}

			return $hints;
		}
		public function disable_emojicons_tinymce($plugins){
			if ( is_array($plugins) ){
				return array_diff($plugins, array('wpemoji'));
			} else {
				return array();
			}
		}

		//Lazy-load anything
		//This markup can be, and is used hard-coded in other places.
		public function lazy_load($html=''){
			?>
			<samp class="nebula-lazy-position"></samp>
			<noscript class="nebula-lazy">
				<?php echo $html; ?>
			</noscript>
			<?php
		}

		//Lazy-load images
		public function lazy_img($src=false, $attributes=''){
			$this->lazy_load('<img src="' . $src . '" ' . $attributes . ' />');
		}

		//Lazy-load iframes
		public function lazy_iframe($src=false, $attributes=''){
			$this->lazy_load('<iframe src="' . $src . '" ' . $attributes . ' ></iframe>');
		}
	}
}