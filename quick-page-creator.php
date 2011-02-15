<?php
/*
Plugin Name: Quick Page Creator
Plugin URI: http://pnomolos.com/quick-page-creator
Description: Allows you to quickly enter pages
Version: 0.1
Author: Philip Schalm
Author URI: http://pnomolos.com
License: BSD


Copyright 2011  Philip Schalm  (email : pnomolos@gmail.com)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
The name of the author may not be used to endorse or promote products derived from this software without specific prior written permission.
THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
class QuickPageCreator {
	
	public $engines = array(
		'None' => 'none',
		'Markdown' => array( 'file' => 'markdown', 'function' => 'Markdown' )
	);
	
	function __construct() {
		add_action( 'admin_menu', array( $this, '_main') );
	}
	
	function _main() {
		add_submenu_page('plugins.php', __('Quick Page Creator'), __('Quick Page Creator'), 'manage_options', 'quick-page-creator', array( $this, 'main'));
	}
	
	function main() {
		$error = '';
		if ( !isset( $_FILES['quick-page-creator-file'] ) ) {
			$error = '';
		} else if ( !check_admin_referer('quick-page-creator-main') ) {
			$error = 'Invalid file upload attempt';
		} else {
			try {
				$this->process();
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}
		}
		?>
			<h2>Upload a file to create pages from</h2>
			<?php if ( $error ): ?><p class="error"><?php echo $error ?></p><?php endif ?>
			<form action="" enctype="multipart/form-data" method="post" id="quick-page-creator" style="margin: auto; width: 400px;">
				<?php wp_nonce_field('quick-page-creator-main'); ?>
				<p><label>
					Choose your text processing engine:
					<select name="quick-page-creator-engine">
						<?php foreach ( $this->engines as $name => $info ): ?>
							<option value="<?php echo $name ?>"><?php echo $name ?></option>
						<?php endforeach ?>
					</select>
				</label></p>
				<p><label>
					Select your file for upload:
					<input type="file" name="quick-page-creator-file" />
				</label></p>
				<input type="submit" value="Upload File" />
			</form>
		<?php
	}
	
	function process() {
		// Variable prep
		$contents = file_get_contents( $_FILES['quick-page-creator-file']['tmp_name'] );
		
		$engine = '';
		if ( isset( $this->engines[$_POST['quick-page-creator-engine']] ) ) {
			$engine = $this->engines[$_POST['quick-page-creator-engine']];
		}
		
		// Process the file contents, each page should be in the following format
		//   title
		//   /url
		//   optional_meta_key_name:value (repeated if necessary)
		//   content
		//   <-----> (separator)
		//
		$_pages = preg_split('/^<----->\s*$/m', $contents);
		$pages = array();
		foreach ( $_pages as $page ) {
			$lines = preg_split( '/(\r|\r\n|\n)/', trim( $page ) );
			$title = array_shift( $lines );
			$url = array_shift( $lines );
			$content = '';
			$meta_keys = array();
			$in_meta_check = true;
			foreach ( $lines as $line ) {
				$matches = array();
				if ( $in_meta_check ) {
					if ( preg_match( '/^(\w+):(.*?)$/', $line, $matches ) ) {
						$meta_keys[$matches[1]] = $matches[2]; continue;
					} else {
						$in_meta_check = false;
					}
				}
				$content .= $line . "\n";
			}
			
			$pages[] = array(
				'post_title' => $title,
				'url' => $url,
				'post_content' => $content,
				'post_type' => 'page',
				'post_status' => 'publish',
				'meta_keys' => $meta_keys
			);
			
			usort( $pages, array( $this, 'sort_pages') );
		}
		
		// Run any processing on the content
		if ( $engine && $engine != 'none' ) {
			$file = ( isset( $engine['file'] ) ? $engine['file'] : strtolower( $engine ) ) . '.php';
			$function = isset( $engine['function'] ) ? $engine['function'] : $engine;
			@include_once ( dirname( __FILE__ ) . '/engines/' . $file );
			if ( function_exists( $function ) ) {
				for ( $page_counter = 0; $page_counter < count( $pages); $page_counter++ ) {
					$page = $pages[$page_counter];
					$pages[$page_counter]['post_content'] = call_user_func( $function, $page['post_content'] );
				}
			} else {
				throw new Exception( 'Could not include processing engine' );
			}
		}
		
		// Insert the pages in to the db
		foreach ( $pages as $page ) {
			$segments = explode( '/', trim( $page['url'], '/' ) );
			$page['post_name'] = array_pop( $segments );
			$parent_id = 0;
			foreach ( $segments as $seg ) {
				$args = array('post_type' => 'page', 'pagename' => $seg );
				if ( $parent_id ) { 
					$args['post_parent'] = $parent_id;
				}
				$p = get_posts($args);
				if ( count($p) ) {
					$parent_page = $p[0];
					$parent_id = $parent_page->ID;
				} else {
					$parent_id = 0; break;
				}
			}
			
			if ( $parent_id ) {
				$page['post_parent'] = $parent_id;
			}
			
			$post_id = wp_insert_post( $page );
			if ( !$post_id ) {
				throw new Exception( "Error creating page: {$page['title']}" );
			}
			if ( $page['meta_keys'] ) {
				foreach ( $page['meta_keys'] as $name => $value ) {
					add_post_meta( $post_id, $name, $value );
				}
			}
		}
	}
	
	function sort_pages( $a, $b ) {
		return strcmp( $a['url'], $b['url'] );
	}
}

$qpc = new QuickPageCreator();