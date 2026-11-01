import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import Dashboard from './Dashboard';
import Bulk from './Bulk';
import Review from './Review';
import Glossary from './Glossary';
import Locales from './Locales';
import GeneralSettings, { EngineSettings } from './GeneralSettings';
import { parseHash } from './util';
import './admin.css';

const TABS = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-translator-localizer' ) },
	{ id: 'bulk', label: __( 'Bulk Translate', 'dev3-ai-translator-localizer' ) },
	{ id: 'review', label: __( 'Review', 'dev3-ai-translator-localizer' ) },
	{ id: 'glossary', label: __( 'Glossary', 'dev3-ai-translator-localizer' ) },
	{ id: 'locales', label: __( 'Locales', 'dev3-ai-translator-localizer' ) },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-translator-localizer' ) },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-translator-localizer' ) },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-translator-localizer' ) },
];
const IDS = TABS.map( ( t ) => t.id );

export default function App() {
	const [ route, setRoute ] = useState( parseHash( window.location.hash, IDS ) );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		const onHash = () => setRoute( parseHash( window.location.hash, IDS ) );
		window.addEventListener( 'hashchange', onHash );
		request( 'settings' ).then( setSettings ).catch( setError );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setSaved( true );
			setTimeout( () => setSaved( false ), 2500 );
			return next;
		} );

	const tab = route.tab;
	return (
		<div className="dev3tl-root">
			<div className="dev3tl-header">
				<span className="dev3tl-logo" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="22" height="22">
						<path fill="currentColor" d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0014.07 6H17V4h-7V2H8v2H1v2h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z" />
					</svg>
				</span>
				<h1 className="wp-heading-inline">{ __( 'DEV3 AI Translator & Cultural Localizer', 'dev3-ai-translator-localizer' ) }</h1>
				<span className="dev3tl-version">v{ config.version }</span>
			</div>
			<nav className="dev3tl-admin-nav">
				{ TABS.map( ( t ) => (
					<a key={ t.id } href={ `#/${ t.id }` } className={ tab === t.id ? 'is-active' : '' }>
						{ t.label }
					</a>
				) ) }
			</nav>
			{ error && <Notice status="error" onRemove={ () => setError( null ) }>{ error.message }</Notice> }
			{ saved && <Notice status="success" isDismissible={ false }>{ __( 'Settings saved.', 'dev3-ai-translator-localizer' ) }</Notice> }
			{ tab === 'dashboard' && <Dashboard settings={ settings } /> }
			{ tab === 'bulk' && <Bulk settings={ settings } /> }
			{ tab === 'review' && <Review query={ route.query } /> }
			{ tab === 'glossary' && <Glossary /> }
			{ tab === 'locales' && <Locales /> }
			{ ! settings && ! error && [ 'settings', 'api' ].includes( tab ) && <Spinner /> }
			{ settings && tab === 'settings' && <GeneralSettings settings={ settings } save={ save } /> }
			{ settings && tab === 'api' && (
				<ApiTab settings={ settings } save={ save }>
					<EngineSettings settings={ settings } save={ save } />
				</ApiTab>
			) }
			{ tab === 'usage' && <UsageTab /> }
		</div>
	);
}
