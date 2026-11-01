import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { tokenize, decodeEntities } from './util';

export function Card( { title, description, actions, children, className = '' } ) {
	return (
		<div className={ `dev3tl-card ${ className }` }>
			{ ( title || actions ) && (
				<div className="dev3tl-card-head">
					<div>
						{ title && <h2>{ title }</h2> }
						{ description && <p className="description">{ description }</p> }
					</div>
					{ actions && <div className="dev3tl-card-actions">{ actions }</div> }
				</div>
			) }
			{ children }
		</div>
	);
}

export function Stat( { value, label, hint } ) {
	return (
		<div className="dev3tl-stat">
			<strong>{ value }</strong>
			<span>{ label }</span>
			{ hint && <em className="dev3tl-stat-hint">{ hint }</em> }
		</div>
	);
}

const STATUS = {
	machine: [ 'is-info', __( 'AI', 'dev3-ai-translator-localizer' ) ],
	review: [ 'is-warn', __( 'Needs review', 'dev3-ai-translator-localizer' ) ],
	approved: [ 'is-ok', __( 'Approved', 'dev3-ai-translator-localizer' ) ],
	human: [ 'is-human', __( 'Human-edited', 'dev3-ai-translator-localizer' ) ],
	missing: [ 'is-muted', __( 'Not translated', 'dev3-ai-translator-localizer' ) ],
	queued: [ 'is-muted', __( 'Queued', 'dev3-ai-translator-localizer' ) ],
	running: [ 'is-info', __( 'Running', 'dev3-ai-translator-localizer' ) ],
	paused: [ 'is-warn', __( 'Paused', 'dev3-ai-translator-localizer' ) ],
	cancelled: [ 'is-muted', __( 'Cancelled', 'dev3-ai-translator-localizer' ) ],
	done: [ 'is-ok', __( 'Done', 'dev3-ai-translator-localizer' ) ],
	partial: [ 'is-warn', __( 'Partial', 'dev3-ai-translator-localizer' ) ],
	failed: [ 'is-bad', __( 'Failed', 'dev3-ai-translator-localizer' ) ],
	skipped: [ 'is-muted', __( 'Skipped', 'dev3-ai-translator-localizer' ) ],
	pending: [ 'is-muted', __( 'Pending', 'dev3-ai-translator-localizer' ) ],
};

export function StatusBadge( { status } ) {
	const [ cls, label ] = STATUS[ status ] || [ 'is-muted', status ];
	return <span className={ `dev3tl-badge ${ cls }` }>{ label }</span>;
}

export function Progress( { value } ) {
	return (
		<div className="dev3tl-progress" role="progressbar" aria-valuenow={ value } aria-valuemin={ 0 } aria-valuemax={ 100 }>
			<div style={ { width: `${ Math.max( 0, Math.min( 100, value ) ) }%` } } />
		</div>
	);
}

export function Masked( { text } ) {
	return (
		<span className="dev3tl-masked">
			{ tokenize( text ).map( ( t, i ) =>
				t.type === 'tag' ? (
					<code key={ i } className="dev3tl-ph" title={ __( 'Markup placeholder — keep it in your translation', 'dev3-ai-translator-localizer' ) }>
						{ t.value }
					</code>
				) : (
					<span key={ i }>{ decodeEntities( t.value ) }</span>
				)
			) }
		</span>
	);
}

export function ErrorNotice( { error, onRemove } ) {
	if ( ! error ) {
		return null;
	}
	return (
		<Notice status="error" onRemove={ onRemove }>
			<strong>{ error.message }</strong> { error.hint }
		</Notice>
	);
}

export function LangChip( { code, name } ) {
	return (
		<span className="dev3tl-lang" title={ name }>
			{ String( code ).replace( '_', '-' ) }
		</span>
	);
}
