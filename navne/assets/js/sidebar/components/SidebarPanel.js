// assets/js/sidebar/components/SidebarPanel.js
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { PanelBody, Spinner, Button, Notice } from '@wordpress/components';
import useSuggestions from '../hooks/useSuggestions';
import SuggestionCard from './SuggestionCard';
import ApprovedList   from './ApprovedList';

export default function SidebarPanel() {
	const postId = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostId()
	);
	const { jobStatus, suggestions, isLoading, mode, approve, dismiss, retry } =
		useSuggestions( postId );

	const pending = suggestions.filter( ( s ) => s.status === 'pending' );

	return (
		<PanelBody initialOpen={ true }>
			{ jobStatus === 'idle' && mode !== 'safe' && (
				<p style={ { color: '#666', fontSize: '13px' } }>
					{ __( 'Save the post to detect entities.', 'navne' ) }
				</p>
			) }

			{ jobStatus === 'idle' && mode === 'safe' && (
				<>
					<p style={ { color: '#666', fontSize: '13px' } }>
						{ __( 'Safe mode — linking uses approved entities only.', 'navne' ) }
					</p>
					<Button variant="secondary" onClick={ retry }>
						{ __( 'Process this article', 'navne' ) }
					</Button>
				</>
			) }

			{ ( jobStatus === 'queued' || jobStatus === 'processing' || isLoading ) && (
				<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
					<Spinner />
					<span>{ __( 'Analyzing article\u2026', 'navne' ) }</span>
				</div>
			) }

			{ jobStatus === 'failed' && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ __( 'Entity detection failed.', 'navne' ) }
					</Notice>
					<Button variant="secondary" onClick={ retry } style={ { marginTop: '8px' } }>
						{ __( 'Retry', 'navne' ) }
					</Button>
				</>
			) }

			{ jobStatus === 'complete' && ! pending.length && ! suggestions.some( ( s ) => s.status === 'approved' ) && (
				<p style={ { color: '#666', fontSize: '13px' } }>
					{ __( 'No entity suggestions found.', 'navne' ) }
				</p>
			) }

			{ pending.map( ( s ) => (
				<SuggestionCard
					key={ s.id }
					suggestion={ s }
					onApprove={ approve }
					onDismiss={ dismiss }
				/>
			) ) }

			<ApprovedList suggestions={ suggestions } />
		</PanelBody>
	);
}
