// assets/js/sidebar/hooks/useSuggestions.js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

export default function useSuggestions( postId ) {
	const [ jobStatus, setJobStatus ]     = useState( 'idle' );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isLoading, setIsLoading ]     = useState( false );
	const pollRef                         = useRef( null );
	const wasSaving                       = useRef( false );

	const isSaving = useSelect( ( select ) =>
		select( 'core/editor' ).isSavingPost()
	);

	const fetchSuggestions = useCallback( async () => {
		try {
			const data = await apiFetch( { path: `/navne/v1/suggestions/${ postId }` } );
			setJobStatus( data.job_status );
			setSuggestions( data.suggestions );
			return data.job_status;
		} catch {
			setJobStatus( 'failed' );
			return 'failed';
		}
	}, [ postId ] );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
		setIsLoading( false );
	}, [] );

	const startPolling = useCallback( () => {
		if ( pollRef.current ) return;
		setIsLoading( true );
		pollRef.current = setInterval( async () => {
			const status = await fetchSuggestions();
			if ( status === 'complete' || status === 'failed' ) {
				stopPolling();
			}
		}, 3000 );
	}, [ fetchSuggestions, stopPolling ] );

	// Start polling after a save completes.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			startPolling();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, startPolling ] );

	// Load existing suggestions on mount.
	useEffect( () => {
		fetchSuggestions();
		return stopPolling;
	}, [ fetchSuggestions, stopPolling ] );

	const approve = useCallback( async ( id ) => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/approve`,
			method: 'POST',
			data:   { id },
		} );
		setSuggestions( ( prev ) =>
			prev.map( ( s ) => ( s.id === id ? { ...s, status: 'approved' } : s ) )
		);
	}, [ postId ] );

	const dismiss = useCallback( async ( id ) => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/dismiss`,
			method: 'POST',
			data:   { id },
		} );
		setSuggestions( ( prev ) =>
			prev.map( ( s ) => ( s.id === id ? { ...s, status: 'dismissed' } : s ) )
		);
	}, [ postId ] );

	const retry = useCallback( async () => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/retry`,
			method: 'POST',
		} );
		setJobStatus( 'queued' );
		startPolling();
	}, [ postId, startPolling ] );

	return { jobStatus, suggestions, isLoading, approve, dismiss, retry };
}
