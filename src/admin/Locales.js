import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card, ErrorNotice, LangChip } from './ui';
import { formatDateExample, formatNumberExample } from './util';

const SEPARATORS = [
	{ value: '.', label: '.' },
	{ value: ',', label: ',' },
	{ value: ' ', label: __( 'space', 'dev3-ai-translator-localizer' ) },
	{ value: "'", label: "'" },
	{ value: '', label: __( 'none', 'dev3-ai-translator-localizer' ) },
];

function LocaleCard( { language, formality } ) {
	const [ form, setForm ] = useState( language.profile );
	const [ busy, setBusy ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const lang = String( language.locale ).split( /[_-]/ )[ 0 ].toLowerCase();
	const forms = formality[ lang ];

	const save = () => {
		setBusy( true );
		request( `locales/${ encodeURIComponent( language.locale ) }`, { method: 'POST', data: form } )
			.then( ( p ) => {
				setForm( p );
				setSaved( true );
				setTimeout( () => setSaved( false ), 2000 );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card
			title={
				<>
					<LangChip code={ language.locale } /> { language.name }
				</>
			}
			actions={
				<>
					{ saved && <span className="dev3tl-saved">{ __( 'Saved', 'dev3-ai-translator-localizer' ) }</span> }
					<Button variant="primary" onClick={ save } isBusy={ busy } disabled={ busy }>{ __( 'Save', 'dev3-ai-translator-localizer' ) }</Button>
				</>
			}
			className="dev3tl-locale"
		>
			<div className="dev3tl-locale-grid">
				<section>
					<h3>{ __( 'Tone', 'dev3-ai-translator-localizer' ) }</h3>
					<SelectControl
						label={ __( 'Formality', 'dev3-ai-translator-localizer' ) }
						value={ form.formality }
						onChange={ set( 'formality' ) }
						options={ [
							{ value: 'formal', label: forms ? sprintf( /* translators: %s: pronoun */ __( 'Formal (%s)', 'dev3-ai-translator-localizer' ), forms[ 0 ] ) : __( 'Formal', 'dev3-ai-translator-localizer' ) },
							{ value: 'informal', label: forms ? sprintf( /* translators: %s: pronoun */ __( 'Informal (%s)', 'dev3-ai-translator-localizer' ), forms[ 1 ] ) : __( 'Informal', 'dev3-ai-translator-localizer' ) },
							{ value: 'auto', label: __( 'Follow the source tone', 'dev3-ai-translator-localizer' ) },
						] }
					/>
					<ToggleControl
						label={ __( 'Adapt idioms and cultural references', 'dev3-ai-translator-localizer' ) }
						help={ __( 'Replace idioms, puns and local references with natural equivalents instead of translating them literally.', 'dev3-ai-translator-localizer' ) }
						checked={ !! form.idioms }
						onChange={ set( 'idioms' ) }
					/>
				</section>
				<section>
					<h3>{ __( 'Units & formats', 'dev3-ai-translator-localizer' ) }</h3>
					<SelectControl
						label={ __( 'Measurement units', 'dev3-ai-translator-localizer' ) }
						value={ form.units }
						onChange={ set( 'units' ) }
						help={ __( 'Converted values are pre-calculated and given to the AI.', 'dev3-ai-translator-localizer' ) }
						options={ [
							{ value: 'metric', label: __( 'Metric (cm, kg, °C)', 'dev3-ai-translator-localizer' ) },
							{ value: 'imperial', label: __( 'Imperial / US (in, lb, °F)', 'dev3-ai-translator-localizer' ) },
							{ value: 'keep', label: __( 'Keep as in source', 'dev3-ai-translator-localizer' ) },
						] }
					/>
					<ToggleControl label={ __( 'Keep original measurement in parentheses', 'dev3-ai-translator-localizer' ) } checked={ !! form.units_keep_original } onChange={ set( 'units_keep_original' ) } />
					<TextControl
						label={ __( 'Date format', 'dev3-ai-translator-localizer' ) }
						value={ form.date_format }
						onChange={ set( 'date_format' ) }
						help={ sprintf( /* translators: %s: example date */ __( 'PHP format — example: %s', 'dev3-ai-translator-localizer' ), formatDateExample( form.date_format ) ) }
					/>
					<div className="dev3tl-inline">
						<SelectControl label={ __( 'Decimal separator', 'dev3-ai-translator-localizer' ) } value={ form.decimal_separator } onChange={ set( 'decimal_separator' ) } options={ SEPARATORS } />
						<SelectControl label={ __( 'Thousands separator', 'dev3-ai-translator-localizer' ) } value={ form.thousands_separator } onChange={ set( 'thousands_separator' ) } options={ SEPARATORS } />
					</div>
					<p className="dev3tl-example">
						{ __( 'Numbers look like', 'dev3-ai-translator-localizer' ) } <code>{ formatNumberExample( form.decimal_separator, form.thousands_separator ) }</code>
					</p>
				</section>
				<section>
					<h3>{ __( 'Currency', 'dev3-ai-translator-localizer' ) }</h3>
					<SelectControl
						label={ __( 'Currency mentions', 'dev3-ai-translator-localizer' ) }
						value={ form.currency_mode }
						onChange={ set( 'currency_mode' ) }
						options={ [
							{ value: 'keep', label: __( 'Keep amounts and currency', 'dev3-ai-translator-localizer' ) },
							{ value: 'localize', label: __( 'Keep amounts, localize the format', 'dev3-ai-translator-localizer' ) },
							{ value: 'convert', label: __( 'Convert with a fixed rate', 'dev3-ai-translator-localizer' ) },
						] }
					/>
					{ form.currency_mode === 'convert' && (
						<div className="dev3tl-inline">
							<TextControl label={ __( 'Target currency', 'dev3-ai-translator-localizer' ) } value={ form.currency } onChange={ set( 'currency' ) } maxLength={ 3 } />
							<TextControl label={ __( 'Rate (1 source unit =)', 'dev3-ai-translator-localizer' ) } type="number" step="0.0001" value={ form.currency_rate } onChange={ set( 'currency_rate' ) } />
						</div>
					) }
					{ form.currency_mode !== 'convert' && (
						<p className="description">{ __( 'Prices in your content stay exactly as written. Choose “Convert” for a fixed exchange rate.', 'dev3-ai-translator-localizer' ) }</p>
					) }
				</section>
			</div>
			<div className="dev3tl-grid-2 dev3tl-locale-text">
				<TextareaControl label={ __( 'Brand voice', 'dev3-ai-translator-localizer' ) } value={ form.tone } onChange={ set( 'tone' ) } rows={ 2 } placeholder={ __( 'e.g. Friendly, confident, short sentences.', 'dev3-ai-translator-localizer' ) } />
				<TextareaControl label={ __( 'Custom instructions', 'dev3-ai-translator-localizer' ) } value={ form.instructions } onChange={ set( 'instructions' ) } rows={ 2 } placeholder={ __( 'e.g. Use “Warenkorb”, never “Einkaufswagen”.', 'dev3-ai-translator-localizer' ) } />
			</div>
		</Card>
	);
}

export default function Locales() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		request( 'locales' ).then( setData ).catch( setError );
	}, [] );
	if ( ! data ) {
		return error ? <ErrorNotice error={ error } /> : <Spinner />;
	}
	const targets = data.languages.filter( ( l ) => ! l.default );
	return (
		<div className="dev3tl-locales">
			<p className="dev3tl-intro">
				{ __( 'Cultural adaptation per target language. These rules are added to every translation request for that language.', 'dev3-ai-translator-localizer' ) }
			</p>
			{ targets.length === 0 && (
				<Notice status="warning" isDismissible={ false }>{ __( 'No target languages configured in your multilingual plugin yet.', 'dev3-ai-translator-localizer' ) }</Notice>
			) }
			{ targets.map( ( l ) => (
				<LocaleCard key={ l.code } language={ l } formality={ data.formality } />
			) ) }
		</div>
	);
}
