import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Card, Stat, StatusBadge, LangChip, ErrorNotice } from './ui';
import { formatNumber, reusePercent } from './util';

export default function Dashboard( { settings } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ message, setMessage ] = useState( '' );

	const load = () => request( 'dashboard' ).then( setData ).catch( setError );
	useEffect( () => {
		load();
	}, [] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const active = data.active;
	const tm = data.tm;
	const totals = data.totals;
	const targets = data.languages.filter( ( l ) => ! l.default );

	const siteStrings = () => {
		setBusy( 'strings' );
		request( 'strings/site', { method: 'POST' } )
			.then( ( res ) => setMessage( sprintf( /* translators: %d: languages */ __( 'Site title and tagline translated into %d languages.', 'dev3-ai-translator-localizer' ), Object.keys( res.translated ).length ) ) )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="dev3tl-dashboard">
			<ErrorNotice error={ error } onRemove={ () => setError( null ) } />
			{ message && <Notice status="success" onRemove={ () => setMessage( '' ) }>{ message }</Notice> }
			{ settings && ! settings.api_key_set && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Add your Google Gemini API key under API Config to start translating.', 'dev3-ai-translator-localizer' ) } <a href="#/api">{ __( 'Open API Config', 'dev3-ai-translator-localizer' ) }</a>
				</Notice>
			) }
			<div className="dev3tl-grid-2">
				<Card title={ __( 'Multilingual plugin', 'dev3-ai-translator-localizer' ) } description={ __( 'Detected automatically. Translations are stored through its own API.', 'dev3-ai-translator-localizer' ) }>
					<ul className="dev3tl-providers">
						{ data.detected.map( ( p ) => (
							<li key={ p.id } className={ p.active ? 'is-active' : '' }>
								<span className={ `dev3tl-dot ${ p.detected ? 'is-on' : '' }` } />
								<strong>{ p.label }</strong>
								<span className="dev3tl-muted">
									{ p.mode === 'strings' ? __( 'string dictionary', 'dev3-ai-translator-localizer' ) : __( 'one post per language', 'dev3-ai-translator-localizer' ) }
								</span>
								{ p.active && <span className="dev3tl-badge is-ok">{ __( 'In use', 'dev3-ai-translator-localizer' ) }</span> }
								{ ! p.detected && <span className="dev3tl-badge is-muted">{ __( 'Not active', 'dev3-ai-translator-localizer' ) }</span> }
							</li>
						) ) }
					</ul>
					{ ! active && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Install and activate WPML, Polylang or TranslatePress, then add at least one extra language.', 'dev3-ai-translator-localizer' ) }
						</Notice>
					) }
				</Card>
				<Card title={ __( 'Languages', 'dev3-ai-translator-localizer' ) } description={ active ? sprintf( /* translators: %s: plugin */ __( 'Configured in %s.', 'dev3-ai-translator-localizer' ), active.label ) : '' }>
					<ul className="dev3tl-langs">
						{ data.languages.map( ( l ) => (
							<li key={ l.code }>
								<LangChip code={ l.locale } name={ l.name } />
								<strong>{ l.name }</strong>
								{ l.default ? (
									<span className="dev3tl-badge is-info">{ __( 'Source', 'dev3-ai-translator-localizer' ) }</span>
								) : (
									<span className="dev3tl-muted">
										{ l.profile.formality === 'formal' ? __( 'formal', 'dev3-ai-translator-localizer' ) : l.profile.formality === 'informal' ? __( 'informal', 'dev3-ai-translator-localizer' ) : __( 'auto tone', 'dev3-ai-translator-localizer' ) } · { l.profile.units } · { l.profile.currency || '—' }
									</span>
								) }
							</li>
						) ) }
					</ul>
					{ targets.length > 0 && (
						<p className="dev3tl-m-0">
							<a href="#/locales">{ __( 'Edit cultural settings', 'dev3-ai-translator-localizer' ) }</a>
						</p>
					) }
				</Card>
			</div>

			<Card title={ __( 'Translation memory', 'dev3-ai-translator-localizer' ) } description={ __( 'Every translated segment is remembered. Unchanged segments are reused on every sync instead of being sent to the AI again.', 'dev3-ai-translator-localizer' ) }>
				<div className="dev3tl-stats">
					<Stat value={ formatNumber( tm.entries ) } label={ __( 'Segments in memory', 'dev3-ai-translator-localizer' ) } />
					<Stat value={ formatNumber( tm.by_status.human + tm.by_status.approved ) } label={ __( 'Human-reviewed', 'dev3-ai-translator-localizer' ) } />
					<Stat value={ formatNumber( tm.by_status.review ) } label={ __( 'Need review', 'dev3-ai-translator-localizer' ) } />
					<Stat value={ `${ reusePercent( totals ) }%` } label={ __( 'Reused on the last syncs', 'dev3-ai-translator-localizer' ) } hint={ sprintf( /* translators: 1: reused, 2: total */ __( '%1$s of %2$s', 'dev3-ai-translator-localizer' ), formatNumber( totals.reused ), formatNumber( totals.segments ) ) } />
					<Stat value={ formatNumber( totals.tokens ) } label={ __( 'Tokens used', 'dev3-ai-translator-localizer' ) } />
					<Stat value={ formatNumber( data.glossary ) } label={ __( 'Glossary terms', 'dev3-ai-translator-localizer' ) } />
				</div>
				{ tm.by_pair.length > 0 && (
					<p className="dev3tl-pairs">
						{ tm.by_pair.map( ( p ) => (
							<span key={ p.source + p.target } className="dev3tl-pair">
								{ p.source } → { p.target }: <strong>{ formatNumber( p.count ) }</strong>
							</span>
						) ) }
					</p>
				) }
			</Card>

			<div className="dev3tl-grid-2">
				<Card
					title={ __( 'Recently translated', 'dev3-ai-translator-localizer' ) }
					actions={ <Button variant="secondary" href="#/review">{ __( 'Open review', 'dev3-ai-translator-localizer' ) }</Button> }
				>
					{ data.recent.length === 0 && <p className="dev3tl-muted">{ __( 'Nothing translated yet. Start with Bulk Translate.', 'dev3-ai-translator-localizer' ) }</p> }
					<ul className="dev3tl-recent">
						{ data.recent.map( ( item ) => (
							<li key={ item.id }>
								<span className="dev3tl-recent-title">{ item.title }</span>
								<span className="dev3tl-recent-langs">
									{ item.languages.map( ( l ) => (
										<a key={ l.lang } href={ `#/review?post=${ item.id }&lang=${ encodeURIComponent( l.lang ) }` } title={ sprintf( /* translators: 1: reused, 2: total */ __( '%1$d of %2$d segments reused', 'dev3-ai-translator-localizer' ), l.stats.reused, l.stats.total ) }>
											<LangChip code={ l.lang } />
										</a>
									) ) }
								</span>
							</li>
						) ) }
					</ul>
				</Card>
				<Card
					title={ __( 'Queue', 'dev3-ai-translator-localizer' ) }
					description={ data.queue === 'action-scheduler' ? __( 'Runs in the background with Action Scheduler.', 'dev3-ai-translator-localizer' ) : __( 'Runs in the background with WP-Cron.', 'dev3-ai-translator-localizer' ) }
					actions={ <Button variant="secondary" href="#/bulk">{ __( 'Bulk Translate', 'dev3-ai-translator-localizer' ) }</Button> }
				>
					{ data.jobs.length === 0 && <p className="dev3tl-muted">{ __( 'No jobs yet.', 'dev3-ai-translator-localizer' ) }</p> }
					<ul className="dev3tl-recent">
						{ data.jobs.map( ( job ) => (
							<li key={ job.id }>
								<span className="dev3tl-recent-title">
									#{ job.id } · { job.type === 'sync' ? __( 'Auto-sync', 'dev3-ai-translator-localizer' ) : __( 'Bulk', 'dev3-ai-translator-localizer' ) } · { job.languages.join( ', ' ) }
								</span>
								<span>
									{ job.done + job.failed + job.skipped }/{ job.total } <StatusBadge status={ job.status } />
								</span>
							</li>
						) ) }
					</ul>
					{ active && (
						<p className="dev3tl-m-0">
							<Button variant="link" onClick={ siteStrings } isBusy={ busy === 'strings' } disabled={ !! busy }>
								{ __( 'Translate site title & tagline', 'dev3-ai-translator-localizer' ) }
							</Button>
						</p>
					) }
				</Card>
			</div>
		</div>
	);
}
