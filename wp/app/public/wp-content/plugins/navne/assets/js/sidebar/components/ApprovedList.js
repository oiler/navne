// assets/js/sidebar/components/ApprovedList.js
import { __ } from '@wordpress/i18n';

export default function ApprovedList( { suggestions } ) {
	const approved = suggestions.filter( ( s ) => s.status === 'approved' );
	if ( ! approved.length ) return null;
	return (
		<div style={ { marginTop: '16px' } }>
			<p style={ { fontSize: '11px', textTransform: 'uppercase', letterSpacing: '1px', color: '#999', margin: '0 0 8px' } }>
				{ __( 'Linked', 'navne' ) }
			</p>
			{ approved.map( ( s ) => (
				<div key={ s.id } style={ { padding: '3px 0', color: '#2ecc71', fontSize: '13px' } }>
					{ s.entity_name }
				</div>
			) ) }
		</div>
	);
}
