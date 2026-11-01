<?php
/**
 * Gemini mock through pre_http_request: records every request and answers with a translator callback.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Translation\Prompt_Builder;

/**
 * Usage: $mock = new Dev3tl_Mock_Gemini(); ... $mock->requests; $mock->items(); $mock->stop();
 */
class Dev3tl_Mock_Gemini {

	/**
	 * Recorded requests: { system, prompt, items, body }.
	 *
	 * @var array
	 */
	public $requests = array();

	/**
	 * Translator: fn( string $text, array $item, int $attempt, array $request ) => string|null (null = omit).
	 *
	 * @var callable
	 */
	public $translate;

	/**
	 * Constructor.
	 *
	 * @param callable|null $translate Translator.
	 */
	public function __construct( $translate = null ) {
		$this->translate = $translate ? $translate : static function ( $text ) {
			return '[T] ' . $text;
		};
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Removes the filter.
	 */
	public function stop() {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function handle( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$body   = json_decode( $args['body'], true );
		$prompt = $body['contents'][0]['parts'][0]['text'];
		$system = isset( $body['systemInstruction']['parts'][0]['text'] ) ? $body['systemInstruction']['parts'][0]['text'] : '';
		$json   = substr( $prompt, strpos( $prompt, Prompt_Builder::ITEMS_MARKER ) + strlen( Prompt_Builder::ITEMS_MARKER ) );
		$items  = json_decode( trim( $json ), true );
		$record = array(
			'system' => $system,
			'prompt' => $prompt,
			'items'  => $items,
			'body'   => $body,
			'url'    => $url,
		);
		$this->requests[] = $record;
		$attempt          = count( $this->requests );
		$out              = array();
		foreach ( (array) $items as $item ) {
			$text = call_user_func( $this->translate, $item['text'], $item, $attempt, $record );
			if ( null !== $text ) {
				$out[] = array(
					'id'          => $item['id'],
					'translation' => $text,
				);
			}
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $out ) ) ) ),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 100,
						'candidatesTokenCount' => 50,
						'totalTokenCount'      => 150,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * All items sent across requests.
	 *
	 * @return array
	 */
	public function items() {
		$all = array();
		foreach ( $this->requests as $request ) {
			$all = array_merge( $all, (array) $request['items'] );
		}
		return $all;
	}
}
