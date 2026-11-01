import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Modal, Notice, SearchControl, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card, ErrorNotice } from './ui';
import { glossaryToCsv } from './util';

const EMPTY = { term: '', dnt: true, case_sensitive: false, notes: '', translations: {} };

function TermRow( { term, locales, onSaved, onDelete, isNew } ) {
	const [ form, setForm ] = useState( term );
	const [ busy, setBusy ] = useState( false );
	const dirty = JSON.stringify( form ) !== JSON.stringify( term );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const setTr = ( locale ) => ( v ) => setForm( { ...form, translations: { ...form.translations, [ locale ]: v } } );
	const save = () => {
		setBusy( true );
		request( 'glossary/save', { method: 'POST', data: form } )
			.then( onSaved )
			.finally( () => setBusy( false ) );
	};
	return (
		<tr className={ dirty ? 'is-dirty' : '' }>
			<td><TextControl value={ form.term } onChange={ set( 'term' ) } placeholder={ isNew ? __( 'Add a term…', 'dev3-ai-translator-localizer' ) : '' } aria-label={ __( 'Term', 'dev3-ai-translator-localizer' ) } __nextHasNoMarginBottom /></td>
			<td className="dev3tl-center"><CheckboxControl checked={ form.dnt } onChange={ set( 'dnt' ) } aria-label={ __( 'Do not translate', 'dev3-ai-translator-localizer' ) } __nextHasNoMarginBottom /></td>
			<td className="dev3tl-center"><CheckboxControl checked={ form.case_sensitive } onChange={ set( 'case_sensitive' ) } aria-label={ __( 'Case-sensitive', 'dev3-ai-translator-localizer' ) } __nextHasNoMarginBottom /></td>
			{ locales.map( ( l ) => (
				<td key={ l.locale }>
					<TextControl
						value={ ( form.translations && form.translations[ l.locale ] ) || '' }
						onChange={ setTr( l.locale ) }
						placeholder={ form.dnt ? __( 'kept as is', 'dev3-ai-translator-localizer' ) : __( 'AI decides', 'dev3-ai-translator-localizer' ) }
						aria-label={ l.name }
						__nextHasNoMarginBottom
					/>
				</td>
			) ) }
			<td><TextControl value={ form.notes } onChange={ set( 'notes' ) } aria-label={ __( 'Notes', 'dev3-ai-translator-localizer' ) } __nextHasNoMarginBottom /></td>
			<td className="dev3tl-nowrap">
				<Button variant={ dirty ? 'primary' : 'secondary' } size="small" onClick={ save } isBusy={ busy } disabled={ ! dirty || busy || ! form.term.trim() }>
					{ isNew ? __( 'Add', 'dev3-ai-translator-localizer' ) : __( 'Save', 'dev3-ai-translator-localizer' ) }
				</Button>{ ' ' }
				{ onDelete && (
					<Button variant="tertiary" size="small" isDestructive onClick={ onDelete } aria-label={ __( 'Delete term', 'dev3-ai-translator-localizer' ) }>
						×
					</Button>
				) }
			</td>
		</tr>
	);
}

export default function Glossary() {
	const [ terms, setTerms ] = useState( null );
	const [ locales, setLocales ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ importing, setImporting ] = useState( false );
	const [ csv, setCsv ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ fresh, setFresh ] = useState( 0 );

	const load = () => request( 'glossary' ).then( setTerms ).catch( setError );
	useEffect( () => {
		load();
		request( 'locales' )
			.then( ( d ) => setLocales( d.languages.filter( ( l ) => ! l.default ) ) )
			.catch( setError );
	}, [] );

	if ( ! terms ) {
		return error ? <ErrorNotice error={ error } /> : <Spinner />;
	}

	const remove = ( id ) => request( `glossary/${ id }/delete`, { method: 'POST' } ).then( load ).catch( setError );

	const exportCsv = () => {
		const blob = new window.Blob( [ glossaryToCsv( terms, locales.map( ( l ) => l.locale ) ) ], { type: 'text/csv' } );
		const a = document.createElement( 'a' );
		a.href = window.URL.createObjectURL( blob );
		a.download = 'glossary.csv';
		a.click();
	};

	const doImport = () =>
		request( 'glossary/import', { method: 'POST', data: { csv } } )
			.then( ( r ) => {
				setMessage( sprintf( /* translators: %d: terms */ __( '%d terms imported.', 'dev3-ai-translator-localizer' ), r.imported ) );
				setImporting( false );
				setCsv( '' );
				return load();
			} )
			.catch( setError );

	const shown = terms.filter( ( t ) => ! search || t.term.toLowerCase().includes( search.toLowerCase() ) );

	return (
		<Card
			title={ __( 'Glossary & brand constraints', 'dev3-ai-translator-localizer' ) }
			description={ __( 'Protected terms are sent to the AI with every request and checked in every answer. If a translation breaks a rule, it is retried once with a correction; if it still breaks it, the segment is flagged for review.', 'dev3-ai-translator-localizer' ) }
			actions={
				<>
					<Button variant="secondary" onClick={ () => setImporting( true ) }>{ __( 'Import CSV', 'dev3-ai-translator-localizer' ) }</Button>
					<Button variant="secondary" onClick={ exportCsv } disabled={ ! terms.length }>{ __( 'Export CSV', 'dev3-ai-translator-localizer' ) }</Button>
				</>
			}
		>
			<ErrorNotice error={ error } onRemove={ () => setError( null ) } />
			{ message && <Notice status="success" onRemove={ () => setMessage( '' ) }>{ message }</Notice> }
			<div className="dev3tl-toolbar">
				<SearchControl value={ search } onChange={ setSearch } placeholder={ __( 'Filter terms…', 'dev3-ai-translator-localizer' ) } />
				<span className="dev3tl-muted">{ sprintf( /* translators: %d: count */ _n( '%d term', '%d terms', terms.length, 'dev3-ai-translator-localizer' ), terms.length ) }</span>
			</div>
			<div className="dev3tl-table-wrap">
				<table className="widefat dev3tl-glossary">
					<thead>
						<tr>
							<th>{ __( 'Term', 'dev3-ai-translator-localizer' ) }</th>
							<th className="dev3tl-center" title={ __( 'Do not translate', 'dev3-ai-translator-localizer' ) }>{ __( 'Do not translate', 'dev3-ai-translator-localizer' ) }</th>
							<th className="dev3tl-center">{ __( 'Case-sensitive', 'dev3-ai-translator-localizer' ) }</th>
							{ locales.map( ( l ) => (
								<th key={ l.locale }>{ sprintf( /* translators: %s: language */ __( 'Forced (%s)', 'dev3-ai-translator-localizer' ), l.locale ) }</th>
							) ) }
							<th>{ __( 'Notes', 'dev3-ai-translator-localizer' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<TermRow key={ `new-${ fresh }` } isNew term={ EMPTY } locales={ locales } onSaved={ () => {
							setFresh( fresh + 1 );
							load();
						} } />
						{ shown.map( ( t ) => (
							<TermRow key={ `${ t.id }-${ t.updated }` } term={ t } locales={ locales } onSaved={ load } onDelete={ () => remove( t.id ) } />
						) ) }
					</tbody>
				</table>
			</div>
			<p className="description">
				{ __( '“Do not translate” keeps the term exactly as written (brand names, product names, technical keywords). A forced translation always wins for that language — leave it empty to let the AI translate.', 'dev3-ai-translator-localizer' ) }
			</p>
			{ importing && (
				<Modal title={ __( 'Import glossary CSV', 'dev3-ai-translator-localizer' ) } onRequestClose={ () => setImporting( false ) }>
					<p>{ __( 'Header row: term, dnt, case_sensitive, notes, then one column per locale (e.g. de_DE). Existing terms are not removed.', 'dev3-ai-translator-localizer' ) }</p>
					<TextareaControl value={ csv } onChange={ setCsv } rows={ 8 } placeholder={ 'term,dnt,de_DE\nCloudDesk,yes,\ncheckout,no,Kasse' } />
					<Button variant="primary" onClick={ doImport } disabled={ ! csv.trim() }>{ __( 'Import', 'dev3-ai-translator-localizer' ) }</Button>
				</Modal>
			) }
		</Card>
	);
}
