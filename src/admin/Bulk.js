import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card, Progress, StatusBadge, ErrorNotice, LangChip } from './ui';
import { formatNumber } from './util';

const ACTIVE = [ 'queued', 'running' ];

function JobLog( { id, refresh, onClose } ) {
	const [ job, setJob ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	useEffect( () => {
		request( `jobs/${ id }?page=${ page }` ).then( setJob ).catch( () => {} );
	}, [ id, page, refresh ] );
	if ( ! job ) {
		return <Spinner />;
	}
	const pages = Math.max( 1, Math.ceil( job.items.total / 50 ) );
	return (
		<div className="dev3tl-log">
			<div className="dev3tl-card-head">
				<h3>{ sprintf( /* translators: %d: job id */ __( 'Job #%d — item log', 'dev3-ai-translator-localizer' ), job.id ) }</h3>
				<Button variant="tertiary" onClick={ onClose }>{ __( 'Close', 'dev3-ai-translator-localizer' ) }</Button>
			</div>
			<table className="widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Item', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Language', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Status', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Segments', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Reused / sent', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Tokens', 'dev3-ai-translator-localizer' ) }</th>
						<th>{ __( 'Message', 'dev3-ai-translator-localizer' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ job.items.items.map( ( item ) => (
						<tr key={ item.id }>
							<td>
								<strong>{ item.title || `#${ item.post_id }` }</strong> <span className="dev3tl-muted">{ item.post_type }</span>
							</td>
							<td><LangChip code={ item.lang } /></td>
							<td><StatusBadge status={ item.status } /></td>
							<td>{ item.segments }</td>
							<td>{ item.reused } / { item.sent }</td>
							<td>{ formatNumber( item.tokens ) }</td>
							<td className="dev3tl-log-msg">
								{ item.message }
								{ item.status !== 'pending' && item.status !== 'failed' && (
									<a href={ `#/review?post=${ item.post_id }&lang=${ encodeURIComponent( item.lang ) }` }> { __( 'Review', 'dev3-ai-translator-localizer' ) }</a>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ pages > 1 && (
				<div className="dev3tl-pager">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>‹</Button>
					<span>{ page } / { pages }</span>
					<Button variant="secondary" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>›</Button>
				</div>
			) }
		</div>
	);
}

export default function Bulk( { settings } ) {
	const [ types, setTypes ] = useState( null );
	const [ languages, setLanguages ] = useState( [] );
	const [ selTypes, setSelTypes ] = useState( [] );
	const [ selLangs, setSelLangs ] = useState( [] );
	const [ onlyMissing, setOnlyMissing ] = useState( true );
	const [ force, setForce ] = useState( false );
	const [ estimate, setEstimate ] = useState( null );
	const [ jobs, setJobs ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ log, setLog ] = useState( 0 );
	const ticking = useRef( false );

	const loadJobs = () => request( 'jobs' ).then( setJobs ).catch( setError );

	useEffect( () => {
		Promise.all( [ request( 'post-types' ), request( 'dashboard' ) ] )
			.then( ( [ t, d ] ) => {
				setTypes( t );
				const targets = d.languages.filter( ( l ) => ! l.default );
				setLanguages( targets );
				const preferred = settings && settings.post_types && settings.post_types.length ? settings.post_types : [ 'post', 'page' ];
				setSelTypes( t.filter( ( x ) => x.translatable && preferred.includes( x.name ) ).map( ( x ) => x.name ) );
				setSelLangs( targets.map( ( l ) => l.code ) );
			} )
			.catch( setError );
		loadJobs();
	}, [] );

	// While a job is active: process a slice per poll (works even where WP-Cron loopbacks are blocked).
	const hasActive = jobs.some( ( j ) => ACTIVE.includes( j.status ) );
	useEffect( () => {
		if ( ! hasActive ) {
			return undefined;
		}
		const timer = setInterval( () => {
			if ( ticking.current ) {
				return;
			}
			ticking.current = true;
			request( 'jobs/tick', { method: 'POST' } )
				.catch( () => {} )
				.then( loadJobs )
				.finally( () => {
					ticking.current = false;
				} );
		}, 2500 );
		return () => clearInterval( timer );
	}, [ hasActive ] );

	const toggle = ( list, setList, value ) => ( checked ) => {
		setEstimate( null );
		setList( checked ? [ ...list, value ] : list.filter( ( v ) => v !== value ) );
	};

	const body = { post_types: selTypes, languages: selLangs, only_missing: onlyMissing, force };

	const runEstimate = () => {
		setBusy( 'estimate' );
		setError( null );
		request( 'estimate', { method: 'POST', data: body } )
			.then( setEstimate )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const start = () => {
		setBusy( 'start' );
		setError( null );
		request( 'jobs/create', { method: 'POST', data: body } )
			.then( () => {
				setEstimate( null );
				return loadJobs();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const control = ( id, action ) => request( `jobs/${ id }/${ action }`, { method: 'POST' } ).then( loadJobs ).catch( setError );

	if ( ! types ) {
		return error ? <ErrorNotice error={ error } /> : <Spinner />;
	}
	const ready = selTypes.length > 0 && selLangs.length > 0;

	return (
		<div className="dev3tl-bulk">
			<ErrorNotice error={ error } onRemove={ () => setError( null ) } />
			{ languages.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'No target languages found. Add a second language in your multilingual plugin first.', 'dev3-ai-translator-localizer' ) }
				</Notice>
			) }
			<Card title={ __( 'Bulk translation', 'dev3-ai-translator-localizer' ) } description={ __( 'Translate whole post types into one or more languages. Items run in a background queue; segments already in the translation memory are reused.', 'dev3-ai-translator-localizer' ) }>
				<div className="dev3tl-grid-2 dev3tl-bulk-pick">
					<fieldset>
						<legend>{ __( 'Content', 'dev3-ai-translator-localizer' ) }</legend>
						{ types.map( ( t ) => (
							<CheckboxControl
								key={ t.name }
								label={ `${ t.label } (${ t.count })` }
								help={ t.translatable ? '' : __( 'Not enabled for translation in your multilingual plugin.', 'dev3-ai-translator-localizer' ) }
								checked={ selTypes.includes( t.name ) }
								disabled={ ! t.translatable }
								onChange={ toggle( selTypes, setSelTypes, t.name ) }
							/>
						) ) }
					</fieldset>
					<fieldset>
						<legend>{ __( 'Target languages', 'dev3-ai-translator-localizer' ) }</legend>
						{ languages.map( ( l ) => (
							<CheckboxControl key={ l.code } label={ `${ l.name } (${ l.locale })` } checked={ selLangs.includes( l.code ) } onChange={ toggle( selLangs, setSelLangs, l.code ) } />
						) ) }
						<div className="dev3tl-mt-3">
							<ToggleControl
								label={ __( 'Only items without a translation', 'dev3-ai-translator-localizer' ) }
								checked={ onlyMissing }
								onChange={ ( v ) => {
									setOnlyMissing( v );
									setEstimate( null );
								} }
							/>
							<ToggleControl
								label={ __( 'Re-translate AI segments', 'dev3-ai-translator-localizer' ) }
								help={ __( 'Ignores AI entries in the translation memory. Human-edited and approved segments are always kept.', 'dev3-ai-translator-localizer' ) }
								checked={ force }
								onChange={ setForce }
							/>
						</div>
					</fieldset>
				</div>
				<div className="dev3tl-actions">
					<Button variant="secondary" onClick={ runEstimate } isBusy={ busy === 'estimate' } disabled={ ! ready || !! busy }>
						{ __( 'Estimate cost', 'dev3-ai-translator-localizer' ) }
					</Button>
					<Button variant="primary" onClick={ start } isBusy={ busy === 'start' } disabled={ ! ready || !! busy || ! ( settings && settings.api_key_set ) }>
						{ __( 'Start translation', 'dev3-ai-translator-localizer' ) }
					</Button>
				</div>
				{ estimate && (
					<div className="dev3tl-estimate">
						<div className="dev3tl-stats">
							<div className="dev3tl-stat"><strong>{ formatNumber( estimate.items ) }</strong><span>{ __( 'Items to process', 'dev3-ai-translator-localizer' ) }</span></div>
							<div className="dev3tl-stat"><strong>{ formatNumber( estimate.segments ) }</strong><span>{ __( 'Segments', 'dev3-ai-translator-localizer' ) }</span></div>
							<div className="dev3tl-stat"><strong>{ formatNumber( estimate.reused ) }</strong><span>{ __( 'Reused from memory', 'dev3-ai-translator-localizer' ) }</span></div>
							<div className="dev3tl-stat"><strong>{ formatNumber( estimate.tokens ) }</strong><span>{ __( 'Estimated tokens', 'dev3-ai-translator-localizer' ) }</span></div>
							<div className="dev3tl-stat"><strong>${ estimate.cost_usd.toFixed( 2 ) }</strong><span>{ sprintf( /* translators: %s: model id */ __( 'Approx. cost (%s)', 'dev3-ai-translator-localizer' ), estimate.model ) }</span></div>
						</div>
						<p className="description">
							{ __( 'Estimates use public list prices and typical output length; your actual bill is shown in Google AI Studio.', 'dev3-ai-translator-localizer' ) }
							{ estimate.sampled && ' ' + __( 'Based on a sample of your content.', 'dev3-ai-translator-localizer' ) }
						</p>
					</div>
				) }
			</Card>

			<Card
				title={ __( 'Jobs', 'dev3-ai-translator-localizer' ) }
				description={ settings && settings.auto_sync ? __( 'Auto-sync is on: editing a translated item queues a delta sync of the changed segments.', 'dev3-ai-translator-localizer' ) : __( 'Auto-sync is off (Settings).', 'dev3-ai-translator-localizer' ) }
				actions={ <Button variant="tertiary" onClick={ loadJobs }>{ __( 'Refresh', 'dev3-ai-translator-localizer' ) }</Button> }
			>
				{ jobs.length === 0 && <p className="dev3tl-muted">{ __( 'No jobs yet.', 'dev3-ai-translator-localizer' ) }</p> }
				{ jobs.map( ( job ) => (
					<div key={ job.id } className="dev3tl-job">
						<div className="dev3tl-job-head">
							<div>
								<strong>#{ job.id }</strong> { job.type === 'sync' ? __( 'Auto-sync', 'dev3-ai-translator-localizer' ) : job.post_types.join( ', ' ) } → { job.languages.join( ', ' ) } <StatusBadge status={ job.status } />
							</div>
							<div className="dev3tl-job-actions">
								{ ACTIVE.includes( job.status ) && <Button variant="secondary" size="small" onClick={ () => control( job.id, 'pause' ) }>{ __( 'Pause', 'dev3-ai-translator-localizer' ) }</Button> }
								{ job.status === 'paused' && <Button variant="secondary" size="small" onClick={ () => control( job.id, 'resume' ) }>{ __( 'Resume', 'dev3-ai-translator-localizer' ) }</Button> }
								{ ! [ 'done', 'cancelled' ].includes( job.status ) && <Button variant="tertiary" size="small" isDestructive onClick={ () => control( job.id, 'cancel' ) }>{ __( 'Cancel', 'dev3-ai-translator-localizer' ) }</Button> }
								{ [ 'done', 'cancelled', 'paused' ].includes( job.status ) && <Button variant="tertiary" size="small" onClick={ () => control( job.id, 'delete' ) }>{ __( 'Remove', 'dev3-ai-translator-localizer' ) }</Button> }
								<Button variant="link" onClick={ () => setLog( log === job.id ? 0 : job.id ) }>{ log === job.id ? __( 'Hide log', 'dev3-ai-translator-localizer' ) : __( 'View log', 'dev3-ai-translator-localizer' ) }</Button>
							</div>
						</div>
						<Progress value={ job.progress } />
						<div className="dev3tl-job-meta">
							<span>{ sprintf( /* translators: 1: finished, 2: total */ __( '%1$d of %2$d items', 'dev3-ai-translator-localizer' ), job.done + job.failed + job.skipped, job.total ) }</span>
							{ job.failed > 0 && <span className="dev3tl-bad">{ sprintf( /* translators: %d: failed */ __( '%d failed', 'dev3-ai-translator-localizer' ), job.failed ) }</span> }
							{ job.skipped > 0 && <span>{ sprintf( /* translators: %d: skipped */ __( '%d skipped', 'dev3-ai-translator-localizer' ), job.skipped ) }</span> }
							<span>{ sprintf( /* translators: 1: reused, 2: segments */ __( '%1$s of %2$s segments reused', 'dev3-ai-translator-localizer' ), formatNumber( job.reused ), formatNumber( job.segments ) ) }</span>
							<span>{ sprintf( /* translators: %s: tokens */ __( '%s tokens', 'dev3-ai-translator-localizer' ), formatNumber( job.tokens ) ) }</span>
						</div>
						{ log === job.id && <JobLog id={ job.id } refresh={ job.done + job.failed + job.skipped } onClose={ () => setLog( 0 ) } /> }
					</div>
				) ) }
			</Card>
		</div>
	);
}
