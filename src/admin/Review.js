import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, SearchControl, SelectControl, Spinner, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card, StatusBadge, Masked, ErrorNotice, LangChip } from './ui';
import { placeholdersMatch, placeholders, reusePercent, decodeEntities, PLACEHOLDER_RE } from './util';

const go = ( post, lang ) => {
	window.location.hash = post ? `#/review?post=${ post }&lang=${ encodeURIComponent( lang ) }` : '#/review';
};

function ReviewList() {
	const [ data, setData ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const t = setTimeout( () => {
			request( `review?page=${ page }&search=${ encodeURIComponent( search ) }` ).then( setData ).catch( setError );
		}, 250 );
		return () => clearTimeout( t );
	}, [ search, page ] );

	return (
		<Card title={ __( 'Review translations', 'dev3-ai-translator-localizer' ) } description={ __( 'Compare source and translation segment by segment, fix wording and approve. Your edits are protected: later syncs never overwrite them.', 'dev3-ai-translator-localizer' ) }>
			<ErrorNotice error={ error } onRemove={ () => setError( null ) } />
			<div className="dev3tl-toolbar">
				<SearchControl value={ search } onChange={ ( v ) => {
					setSearch( v );
					setPage( 1 );
				} } placeholder={ __( 'Search translated items…', 'dev3-ai-translator-localizer' ) } />
			</div>
			{ ! data && <Spinner /> }
			{ data && data.items.length === 0 && <p className="dev3tl-muted">{ __( 'No translated items yet.', 'dev3-ai-translator-localizer' ) }</p> }
			{ data && data.items.length > 0 && (
				<table className="widefat striped dev3tl-review-list">
					<thead>
						<tr>
							<th>{ __( 'Item', 'dev3-ai-translator-localizer' ) }</th>
							<th>{ __( 'Translations', 'dev3-ai-translator-localizer' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<strong>{ item.title || `#${ item.id }` }</strong>
									<div className="dev3tl-muted">{ item.post_type }</div>
								</td>
								<td>
									<div className="dev3tl-lang-cards">
										{ item.languages.map( ( l ) => (
											<button type="button" key={ l.lang } className="dev3tl-lang-card" onClick={ () => go( item.id, l.lang ) }>
												<LangChip code={ l.lang } />
												<span>
													{ sprintf( /* translators: 1: reused, 2: total */ __( '%1$d of %2$d segments reused', 'dev3-ai-translator-localizer' ), l.stats.reused, l.stats.total ) }
												</span>
												{ l.stats.review > 0 && <StatusBadge status="review" /> }
												<span className="dev3tl-lang-card-cta">{ __( 'Review', 'dev3-ai-translator-localizer' ) } →</span>
											</button>
										) ) }
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ data && data.total > 20 && (
				<div className="dev3tl-pager">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>‹</Button>
					<span>{ page }</span>
					<Button variant="secondary" disabled={ page * 20 >= data.total } onClick={ () => setPage( page + 1 ) }>›</Button>
				</div>
			) }
		</Card>
	);
}

function ReviewEditor( { post, lang } ) {
	const [ data, setData ] = useState( null );
	const [ edits, setEdits ] = useState( {} );
	const [ approvals, setApprovals ] = useState( {} );
	const [ filter, setFilter ] = useState( 'all' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( '' );

	const load = () =>
		request( `review/${ post }/${ encodeURIComponent( lang ) }` )
			.then( ( d ) => {
				setData( d );
				setEdits( {} );
				setApprovals( {} );
			} )
			.catch( setError );
	useEffect( () => {
		setData( null );
		load();
	}, [ post, lang ] );

	const segments = useMemo( () => {
		if ( ! data ) {
			return [];
		}
		return data.segments.filter( ( s ) => {
			if ( filter === 'all' ) {
				return true;
			}
			if ( filter === 'todo' ) {
				return s.status === 'machine' || s.status === 'review' || s.status === 'missing';
			}
			return s.status === filter;
		} );
	}, [ data, filter ] );

	if ( ! data ) {
		return error ? <ErrorNotice error={ error } /> : <Spinner />;
	}

	const invalid = Object.keys( edits ).filter( ( hash ) => {
		const seg = data.segments.find( ( s ) => s.hash === hash );
		return seg && ! placeholdersMatch( seg.source, edits[ hash ], seg.kind );
	} );
	const pending = Object.keys( edits ).length + Object.keys( approvals ).length;

	const save = () => {
		setBusy( 'save' );
		setError( null );
		const changes = [
			...Object.keys( edits ).map( ( hash ) => ( { hash, translation: edits[ hash ] } ) ),
			...Object.keys( approvals ).filter( ( h ) => ! ( h in edits ) ).map( ( hash ) => ( { hash, approve: true } ) ),
		];
		request( `review/${ post }/${ encodeURIComponent( lang ) }`, { method: 'POST', data: { segments: changes } } )
			.then( ( d ) => {
				setData( d );
				setEdits( {} );
				setApprovals( {} );
				setNotice( __( 'Saved. The translation was updated from your reviewed segments.', 'dev3-ai-translator-localizer' ) );
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const syncNow = () => {
		setBusy( 'sync' );
		setError( null );
		request( 'translate', { method: 'POST', data: { post_id: post, languages: [ lang ] } } )
			.then( ( res ) => {
				const s = res[ lang ];
				setNotice(
					s && s.status === 'skipped'
						? s.message
						: sprintf( /* translators: 1: reused, 2: total, 3: sent */ __( 'Synced: %1$d of %2$d segments reused, %3$d sent to AI.', 'dev3-ai-translator-localizer' ), s.reused, s.total, s.sent )
				);
				return load();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const approveVisible = () => {
		const next = { ...approvals };
		segments.forEach( ( s ) => {
			if ( s.translation && ( s.status === 'machine' || s.status === 'review' ) ) {
				next[ s.hash ] = true;
			}
		} );
		setApprovals( next );
	};

	const counts = data.segments.reduce( ( acc, s ) => {
		acc[ s.status ] = ( acc[ s.status ] || 0 ) + 1;
		return acc;
	}, {} );

	return (
		<div className="dev3tl-review">
			<div className="dev3tl-review-head">
				<Button variant="link" onClick={ () => go( 0 ) }>← { __( 'All translations', 'dev3-ai-translator-localizer' ) }</Button>
				<h2>
					{ data.post.title } <LangChip code={ lang } name={ data.language && data.language.name } />
				</h2>
				<div className="dev3tl-review-links">
					<a href={ data.post.view_url } target="_blank" rel="noreferrer">{ __( 'View source', 'dev3-ai-translator-localizer' ) }</a>
					{ data.target.view_url && <a href={ data.target.view_url } target="_blank" rel="noreferrer">{ __( 'View translation', 'dev3-ai-translator-localizer' ) }</a> }
					{ data.target.edit_url && <a href={ data.target.edit_url }>{ __( 'Edit translation post', 'dev3-ai-translator-localizer' ) }</a> }
				</div>
				{ data.stats && (
					<p className="dev3tl-sync-line">
						{ sprintf( /* translators: 1: reused, 2: total, 3: percent */ __( 'Last sync: %1$d of %2$d segments reused (%3$d%%)', 'dev3-ai-translator-localizer' ), data.stats.reused, data.stats.total, reusePercent( data.stats ) ) }
						{ ' · ' }
						{ sprintf( /* translators: %d: segments sent */ __( '%d sent to AI', 'dev3-ai-translator-localizer' ), data.stats.sent ) }
					</p>
				) }
			</div>
			{ notice && <Notice status="success" onRemove={ () => setNotice( '' ) }>{ notice }</Notice> }
			<ErrorNotice error={ error } onRemove={ () => setError( null ) } />
			<div className="dev3tl-toolbar">
				<SelectControl
					label={ __( 'Show', 'dev3-ai-translator-localizer' ) }
					value={ filter }
					onChange={ setFilter }
					options={ [
						{ value: 'all', label: sprintf( /* translators: %d: count */ __( 'All segments (%d)', 'dev3-ai-translator-localizer' ), data.segments.length ) },
						{ value: 'todo', label: __( 'Not reviewed yet', 'dev3-ai-translator-localizer' ) },
						{ value: 'review', label: sprintf( /* translators: %d: count */ __( 'Needs review (%d)', 'dev3-ai-translator-localizer' ), counts.review || 0 ) },
						{ value: 'human', label: sprintf( /* translators: %d: count */ __( 'Human-edited (%d)', 'dev3-ai-translator-localizer' ), counts.human || 0 ) },
						{ value: 'approved', label: sprintf( /* translators: %d: count */ __( 'Approved (%d)', 'dev3-ai-translator-localizer' ), counts.approved || 0 ) },
					] }
					__nextHasNoMarginBottom
				/>
				<div className="dev3tl-toolbar-actions">
					<Button variant="secondary" onClick={ approveVisible } disabled={ !! busy }>{ __( 'Approve all shown', 'dev3-ai-translator-localizer' ) }</Button>
					<Button variant="secondary" onClick={ syncNow } isBusy={ busy === 'sync' } disabled={ !! busy }>{ __( 'Sync now', 'dev3-ai-translator-localizer' ) }</Button>
				</div>
			</div>
			<div className="dev3tl-segments">
				<div className="dev3tl-segment dev3tl-segment-head">
					<div>{ __( 'Source', 'dev3-ai-translator-localizer' ) }</div>
					<div>{ data.language ? data.language.name : lang }</div>
				</div>
				{ segments.map( ( s ) => {
					const value = s.hash in edits ? edits[ s.hash ] : decodeEntities( s.translation );
					const bad = s.hash in edits && ! placeholdersMatch( s.source, edits[ s.hash ], s.kind );
					return (
						<div key={ s.hash } className={ `dev3tl-segment ${ s.hash in edits ? 'is-dirty' : '' } ${ approvals[ s.hash ] ? 'is-approved' : '' }` } data-hash={ s.hash }>
							<div className="dev3tl-segment-src">
								<div className="dev3tl-segment-meta">
									<span>{ s.label }</span>
									{ s.context && <span className="dev3tl-muted">{ s.context }</span> }
								</div>
								<Masked text={ s.source } />
							</div>
							<div className="dev3tl-segment-tr">
								<div className="dev3tl-segment-meta">
									<StatusBadge status={ approvals[ s.hash ] && ! ( s.hash in edits ) ? 'approved' : s.hash in edits ? 'human' : s.status } />
									{ s.translation && ! ( s.hash in edits ) && ( s.status === 'machine' || s.status === 'review' ) && ! approvals[ s.hash ] && (
										<Button variant="link" className="dev3tl-approve" onClick={ () => setApprovals( { ...approvals, [ s.hash ]: true } ) }>
											{ __( 'Approve', 'dev3-ai-translator-localizer' ) }
										</Button>
									) }
								</div>
								<TextareaControl
									value={ value }
									onChange={ ( v ) => setEdits( { ...edits, [ s.hash ]: v } ) }
									rows={ Math.min( 8, Math.max( 2, Math.ceil( ( value || s.source ).length / 70 ) ) ) }
									placeholder={ __( 'Not translated yet', 'dev3-ai-translator-localizer' ) }
									__nextHasNoMarginBottom
								/>
								{ placeholders( s.source ).length > 0 && (
									<p className={ `dev3tl-ph-hint ${ bad ? 'is-bad' : '' }` }>
										{ bad ? __( 'Keep exactly these markup placeholders:', 'dev3-ai-translator-localizer' ) : __( 'Markup placeholders:', 'dev3-ai-translator-localizer' ) } <code>{ ( s.source.match( PLACEHOLDER_RE ) || [] ).join( ' ' ) }</code>
									</p>
								) }
							</div>
						</div>
					);
				} ) }
			</div>
			<div className="dev3tl-savebar">
				<span>
					{ pending > 0
						? sprintf( /* translators: %d: changes */ __( '%d unsaved changes', 'dev3-ai-translator-localizer' ), pending )
						: __( 'No unsaved changes', 'dev3-ai-translator-localizer' ) }
				</span>
				<Button variant="primary" onClick={ save } isBusy={ busy === 'save' } disabled={ ! pending || invalid.length > 0 || !! busy }>
					{ __( 'Save & update translation', 'dev3-ai-translator-localizer' ) }
				</Button>
			</div>
		</div>
	);
}

export default function Review( { query } ) {
	if ( query && query.post && query.lang ) {
		return <ReviewEditor post={ parseInt( query.post, 10 ) } lang={ query.lang } />;
	}
	return <ReviewList />;
}
