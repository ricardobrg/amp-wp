<?php
/**
 * Class AMP_Twitter_Embed_Handler
 *
 * @package AMP
 */

/**
 * Class AMP_Twitter_Embed_Handler
 *
 *  Much of this class is borrowed from Jetpack embeds
 */
class AMP_Twitter_Embed_Handler extends AMP_Base_Embed_Handler {
	const URL_PATTERN = '#http(s|):\/\/twitter\.com(\/\#\!\/|\/)([a-zA-Z0-9_]{1,20})\/status(es)*\/(\d+)#i';

	/**
	 * Tag.
	 *
	 * @var string embed HTML blockquote tag to identify and replace with AMP version.
	 */
	protected $sanitize_tag = 'blockquote';

	/**
	 * Tag.
	 *
	 * @var string AMP amp-facebook tag
	 */
	private $amp_tag = 'amp-twitter';

	/**
	 * Register embed.
	 */
	public function register_embed() {
		add_shortcode( 'tweet', array( $this, 'shortcode' ) );
		wp_embed_register_handler( 'amp-twitter', self::URL_PATTERN, array( $this, 'oembed' ), -1 );
	}

	/**
	 * Unregister embed.
	 */
	public function unregister_embed() {
		remove_shortcode( 'tweet' );
		wp_embed_unregister_handler( 'amp-twitter', -1 );
	}

	/**
	 * Gets AMP-compliant markup for the Twitter shortcode.
	 *
	 * @param array $attr The Twitter attributes.
	 * @return string Twitter shortcode markup.
	 */
	public function shortcode( $attr ) {
		$attr = wp_parse_args( $attr, array(
			'tweet' => false,
		) );

		if ( empty( $attr['tweet'] ) && ! empty( $attr[0] ) ) {
			$attr['tweet'] = $attr[0];
		}

		$id = false;
		if ( is_numeric( $attr['tweet'] ) ) {
			$id = $attr['tweet'];
		} else {
			preg_match( self::URL_PATTERN, $attr['tweet'], $matches );
			if ( isset( $matches[5] ) && is_numeric( $matches[5] ) ) {
				$id = $matches[5];
			}

			if ( empty( $id ) ) {
				return '';
			}
		}

		$this->did_convert_elements = true;

		return AMP_HTML_Utils::build_tag(
			$this->amp_tag,
			array(
				'data-tweetid' => $id,
				'layout'       => 'responsive',
				'width'        => $this->args['width'],
				'height'       => $this->args['height'],
			)
		);
	}

	/**
	 * Render oEmbed.
	 *
	 * @see \WP_Embed::shortcode()
	 *
	 * @param array  $matches URL pattern matches.
	 * @param array  $attr    Shortcode attributes.
	 * @param string $url     URL.
	 * @param string $rawattr Unmodified shortcode attributes.
	 * @return string Rendered oEmbed.
	 */
	public function oembed( $matches, $attr, $url, $rawattr ) {
		$id = false;

		if ( isset( $matches[5] ) && is_numeric( $matches[5] ) ) {
			$id = $matches[5];
		}

		if ( ! $id ) {
			return '';
		}

		return $this->shortcode( array( 'tweet' => $id ) );
	}

	/**
	 * Sanitized <blockquote class="twitter-tweet"> tags to <amp-twitter>.
	 *
	 * @param DOMDocument $dom DOM.
	 */
	public function sanitize_raw_embeds( $dom ) {
		/**
		 * Node list.
		 *
		 * @var DOMNodeList $node
		 */
		$nodes     = $dom->getElementsByTagName( $this->sanitize_tag );
		$num_nodes = $nodes->length;

		if ( 0 === $num_nodes ) {
			return;
		}

		for ( $i = $num_nodes - 1; $i >= 0; $i-- ) {
			$node = $nodes->item( $i );
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			if ( $this->is_tweet_raw_embed( $node ) ) {
				$this->create_amp_twitter_and_replace_node( $dom, $node );
			}
		}
	}

	/**
	 * Checks whether it's a twitter blockquote or not
	 *
	 * @param DOMElement $node The DOMNode to adjust and replace.
	 * @return bool Whether node is for raw embed.
	 */
	private function is_tweet_raw_embed( $node ) {
		$class_attr = $node->getAttribute( 'class' );

		return null !== $class_attr && false !== strpos( $class_attr, 'twitter-tweet' );
	}

	/**
	 * Make final modifications to DOMNode
	 *
	 * @param DOMDocument $dom The HTML Document.
	 * @param DOMElement  $node The DOMNode to adjust and replace.
	 */
	private function create_amp_twitter_and_replace_node( $dom, $node ) {
		$tweet_id = $this->get_tweet_id( $node );
		if ( ! $tweet_id ) {
			return;
		}

		$new_node = AMP_DOM_Utils::create_node( $dom, $this->amp_tag, array(
			'width'        => $this->DEFAULT_WIDTH,
			'height'       => $this->DEFAULT_HEIGHT,
			'layout'       => 'responsive',
			'data-tweetid' => $tweet_id,
		) );

		$this->sanitize_embed_script( $node );

		$node->parentNode->replaceChild( $new_node, $node );

		$this->did_convert_elements = true;
	}

	/**
	 * Extracts Tweet id.
	 *
	 * @param DOMElement $node The DOMNode to adjust and replace.
	 * @return string Tweet ID.
	 */
	private function get_tweet_id( $node ) {
		/**
		 * DOMNode
		 *
		 * @var DOMNode $anchors
		 */
		$anchors = $node->getElementsByTagName( 'a' );

		/**
		 * Anchor.
		 *
		 * @type DOMElement $anchor
		 */
		foreach ( $anchors as $anchor ) {
			$found = preg_match( self::URL_PATTERN, $anchor->getAttribute( 'href' ), $matches );
			if ( $found ) {
				return $matches[5];
			}
		}

		return null;
	}

	/**
	 * Removes Twitter's embed <script> tag.
	 *
	 * @param DOMElement $node The DOMNode to whose sibling is the instagram script.
	 */
	private function sanitize_embed_script( $node ) {
		$next_element_sibling = $node->nextSibling;
		while ( $next_element_sibling && ! ( $next_element_sibling instanceof DOMElement ) ) {
			$next_element_sibling = $next_element_sibling->nextSibling;
		}

		$script_src = 'platform.twitter.com/widgets.js';

		// Handle case where script is wrapped in paragraph by wpautop.
		if ( $next_element_sibling instanceof DOMElement && 'p' === $next_element_sibling->nodeName ) {
			$children = $next_element_sibling->getElementsByTagName( '*' );
			if ( 1 === $children->length && 'script' === $children->item( 0 )->nodeName && false !== strpos( $children->item( 0 )->getAttribute( 'src' ), $script_src ) ) {
				$next_element_sibling->parentNode->removeChild( $next_element_sibling );
				return;
			}
		}

		// Handle case where script is immediately following.
		$is_embed_script = (
			$next_element_sibling
			&&
			'script' === strtolower( $next_element_sibling->nodeName )
			&&
			false !== strpos( $next_element_sibling->getAttribute( 'src' ), $script_src )
		);
		if ( $is_embed_script ) {
			$next_element_sibling->parentNode->removeChild( $next_element_sibling );
		}
	}
}
