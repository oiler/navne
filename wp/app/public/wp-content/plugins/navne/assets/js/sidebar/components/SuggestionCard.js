// assets/js/sidebar/components/SuggestionCard.js
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

const TYPE_LABELS = {
	person: __( 'Person', 'navne' ),
	org:    __( 'Org',    'navne' ),
	place:  __( 'Place',  'navne' ),
	other:  __( 'Other',  'navne' ),
};

export default function SuggestionCard( { suggestion, onApprove, onDismiss } ) {
	const confidence = Math.round( suggestion.confidence * 100 );
	return (
		<div style={ { borderLeft: '3px solid #7c5cbf', padding: '10px', marginBottom: '8px', background: '#f9f9f9', borderRadius: '2px' } }>
			<strong>{ suggestion.entity_name }</strong>
			<div style={ { fontSize: '12px', color: '#666', margin: '3px 0' } }>
				{ TYPE_LABELS[ suggestion.entity_type ] ?? suggestion.entity_type } &middot; { confidence }%
			</div>
			<div style={ { display: 'flex', gap: '8px', marginTop: '6px' } }>
				<Button variant="primary" isSmall onClick={ () => onApprove( suggestion.id ) }>
					{ __( 'Approve', 'navne' ) }
				</Button>
				<Button variant="secondary" isSmall onClick={ () => onDismiss( suggestion.id ) }>
					{ __( 'Dismiss', 'navne' ) }
				</Button>
			</div>
		</div>
	);
}
