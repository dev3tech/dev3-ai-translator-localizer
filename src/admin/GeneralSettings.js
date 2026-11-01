import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, TextControl, ToggleControl, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card } from './ui';

const KEYS = [ 'provider', 'auto_sync', 'translated_status', 'translate_slug', 'protect_manual_edits', 'seo_meta', 'woo_fields', 'copy_meta', 'use_action_scheduler', 'custom_fields', 'post_types', 'delete_on_uninstall' ];

export default function GeneralSettings( { settings, save } ) {
	const [ form, setForm ] = useState( () => ( { ...settings, custom_fields: ( settings.custom_fields || [] ).join( ', ' ) } ) );
	const [ types, setTypes ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );

	useEffect( () => {
		request( 'post-types' ).then( setTypes ).catch( () => {} );
	}, [] );

	const submit = () => {
		setBusy( 'save' );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ];
		} );
		save( patch ).finally( () => setBusy( '' ) );
	};

	const clearTm = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete all AI-only entries from the translation memory? Human-edited and approved entries are kept.', 'dev3-ai-translator-localizer' ) ) ) {
			return;
		}
		setBusy( 'clear' );
		request( 'tm/clear', { method: 'POST', data: { status: 'machine' } } )
			.then( ( r ) => setMessage( sprintf( /* translators: %d: entries */ __( '%d entries removed.', 'dev3-ai-translator-localizer' ), r.deleted ) ) )
			.finally( () => setBusy( '' ) );
	};

	const toggleType = ( name ) => ( checked ) => set( 'post_types' )( checked ? [ ...( form.post_types || [] ), name ] : ( form.post_types || [] ).filter( ( t ) => t !== name ) );

	return (
		<>
			{ message && <Notice status="success" onRemove={ () => setMessage( '' ) }>{ message }</Notice> }
			<Card title={ __( 'Translation workflow', 'dev3-ai-translator-localizer' ) }>
				<div className="dev3tl-form-grid">
					<SelectControl
						label={ __( 'Multilingual plugin', 'dev3-ai-translator-localizer' ) }
						value={ form.provider }
						onChange={ set( 'provider' ) }
						help={ __( 'Auto-detect picks WPML, then Polylang, then TranslatePress.', 'dev3-ai-translator-localizer' ) }
						options={ [
							{ value: 'auto', label: __( 'Auto-detect', 'dev3-ai-translator-localizer' ) },
							{ value: 'wpml', label: 'WPML' },
							{ value: 'polylang', label: 'Polylang' },
							{ value: 'translatepress', label: 'TranslatePress' },
						] }
					/>
					<SelectControl
						label={ __( 'Status of new translations', 'dev3-ai-translator-localizer' ) }
						value={ form.translated_status }
						onChange={ set( 'translated_status' ) }
						options={ [
							{ value: 'same', label: __( 'Same as the source', 'dev3-ai-translator-localizer' ) },
							{ value: 'draft', label: __( 'Draft (review before publishing)', 'dev3-ai-translator-localizer' ) },
							{ value: 'pending', label: __( 'Pending review', 'dev3-ai-translator-localizer' ) },
							{ value: 'publish', label: __( 'Published', 'dev3-ai-translator-localizer' ) },
						] }
					/>
					<ToggleControl label={ __( 'Auto-sync on update', 'dev3-ai-translator-localizer' ) } help={ __( 'When a translated item is saved, only new or changed segments are queued for translation.', 'dev3-ai-translator-localizer' ) } checked={ !! form.auto_sync } onChange={ set( 'auto_sync' ) } />
					<ToggleControl label={ __( 'Protect manual edits', 'dev3-ai-translator-localizer' ) } help={ __( 'If someone edits a translation post directly, syncs skip it instead of overwriting.', 'dev3-ai-translator-localizer' ) } checked={ !! form.protect_manual_edits } onChange={ set( 'protect_manual_edits' ) } />
					<ToggleControl label={ __( 'Translate slugs', 'dev3-ai-translator-localizer' ) } checked={ !! form.translate_slug } onChange={ set( 'translate_slug' ) } />
					<ToggleControl label={ __( 'Translate Yoast SEO / Rank Math fields', 'dev3-ai-translator-localizer' ) } checked={ !! form.seo_meta } onChange={ set( 'seo_meta' ) } />
					<ToggleControl label={ __( 'Translate WooCommerce attributes & variations', 'dev3-ai-translator-localizer' ) } checked={ !! form.woo_fields } onChange={ set( 'woo_fields' ) } />
					<ToggleControl label={ __( 'Copy other custom fields to new translations', 'dev3-ai-translator-localizer' ) } checked={ !! form.copy_meta } onChange={ set( 'copy_meta' ) } />
					<ToggleControl label={ __( 'Use Action Scheduler when available', 'dev3-ai-translator-localizer' ) } help={ __( 'Falls back to WP-Cron. Open admin screens also process the queue.', 'dev3-ai-translator-localizer' ) } checked={ !! form.use_action_scheduler } onChange={ set( 'use_action_scheduler' ) } />
				</div>
				<TextControl
					label={ __( 'Custom fields to translate', 'dev3-ai-translator-localizer' ) }
					help={ __( 'Comma-separated meta keys with text values (e.g. subtitle, _my_tagline).', 'dev3-ai-translator-localizer' ) }
					value={ form.custom_fields }
					onChange={ set( 'custom_fields' ) }
				/>
				<fieldset className="dev3tl-mt-3">
					<legend>{ __( 'Default post types for bulk translation', 'dev3-ai-translator-localizer' ) }</legend>
					<div className="dev3tl-checks">
						{ types.filter( ( t ) => t.translatable ).map( ( t ) => (
							<CheckboxControl key={ t.name } label={ t.label } checked={ ( form.post_types || [] ).includes( t.name ) } onChange={ toggleType( t.name ) } />
						) ) }
					</div>
				</fieldset>
				<div className="dev3tl-mt-3">
					<ToggleControl label={ __( 'Delete all plugin data when uninstalling', 'dev3-ai-translator-localizer' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
				</div>
				<div className="dev3tl-actions">
					<Button variant="primary" onClick={ submit } isBusy={ busy === 'save' } disabled={ !! busy }>{ __( 'Save settings', 'dev3-ai-translator-localizer' ) }</Button>
				</div>
			</Card>
			<Card title={ __( 'Translation memory', 'dev3-ai-translator-localizer' ) } description={ __( 'Remove AI-only entries to force fresh translations on the next sync. Human-edited and approved entries are kept.', 'dev3-ai-translator-localizer' ) }>
				<Button variant="secondary" isDestructive onClick={ clearTm } isBusy={ busy === 'clear' } disabled={ !! busy }>{ __( 'Clear AI entries', 'dev3-ai-translator-localizer' ) }</Button>
			</Card>
		</>
	);
}

export function EngineSettings( { settings, save } ) {
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const preset = [ 'pro', 'default' ].includes( form.translation_model ) ? form.translation_model : 'custom';
	const submit = () => {
		setBusy( true );
		save( {
			translation_model: form.translation_model,
			thinking_budget: parseInt( form.thinking_budget, 10 ),
			request_timeout: parseInt( form.request_timeout, 10 ),
			batch_size: parseInt( form.batch_size, 10 ),
			batch_chars: parseInt( form.batch_chars, 10 ),
		} ).finally( () => setBusy( false ) );
	};
	return (
		<Card title={ __( 'Translation engine', 'dev3-ai-translator-localizer' ) } description={ __( 'Segments are sent in batches with a structured response schema, so ids and markup placeholders come back reliably.', 'dev3-ai-translator-localizer' ) }>
			<div className="dev3tl-form-grid">
				<SelectControl
					label={ __( 'Model for translation', 'dev3-ai-translator-localizer' ) }
					value={ preset }
					onChange={ ( v ) => set( 'translation_model' )( v === 'custom' ? settings.pro_model : v ) }
					options={ [
						{ value: 'pro', label: sprintf( /* translators: %s: model */ __( 'Pro model (%s) — best cultural nuance', 'dev3-ai-translator-localizer' ), settings.pro_model ) },
						{ value: 'default', label: sprintf( /* translators: %s: model */ __( 'Fast model (%s) — cheaper', 'dev3-ai-translator-localizer' ), settings.default_model ) },
						{ value: 'custom', label: __( 'Custom model ID…', 'dev3-ai-translator-localizer' ) },
					] }
				/>
				{ preset === 'custom' && <TextControl label={ __( 'Model ID', 'dev3-ai-translator-localizer' ) } value={ form.translation_model } onChange={ set( 'translation_model' ) } /> }
				<TextControl label={ __( 'Thinking budget (tokens)', 'dev3-ai-translator-localizer' ) } type="number" min={ 128 } value={ form.thinking_budget } onChange={ set( 'thinking_budget' ) } help={ __( 'Room to reason about idioms and tone. 1024 is a good default.', 'dev3-ai-translator-localizer' ) } />
				<TextControl label={ __( 'Request timeout (seconds)', 'dev3-ai-translator-localizer' ) } type="number" min={ 30 } value={ form.request_timeout } onChange={ set( 'request_timeout' ) } />
				<TextControl label={ __( 'Segments per request', 'dev3-ai-translator-localizer' ) } type="number" min={ 1 } max={ 150 } value={ form.batch_size } onChange={ set( 'batch_size' ) } />
				<TextControl label={ __( 'Characters per request', 'dev3-ai-translator-localizer' ) } type="number" min={ 500 } value={ form.batch_chars } onChange={ set( 'batch_chars' ) } />
			</div>
			<Button variant="primary" onClick={ submit } isBusy={ busy } disabled={ busy }>{ __( 'Save translation engine', 'dev3-ai-translator-localizer' ) }</Button>
		</Card>
	);
}
